<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpSessionManager
{
    private $session_data = [];
    private $session_id = null;
    private $storage_path;

    public function __construct()
    {
        $this->storage_path = dirname(__FILE__) . '/../temp/sessions/';
        if (!file_exists($this->storage_path)) {
            mkdir($this->storage_path, 0755, true);
        }
    }

    /**
     * Créer une nouvelle session UCP temporaire
     */
    public function createSession($sid, $buyer_data, $line_items, $headers)
    {
        $this->session_id = $sid;
        
        $this->session_data = [
            'checkout_session_id' => $sid,
            'created_at' => date('c'),
            'expires_at' => date('c', strtotime('+1 hour')),
            'status' => 'temporary',
            'headers' => $headers,
            'buyer' => $this->normalizeBuyerData($buyer_data),
            'line_items' => $this->normalizeLineItems($line_items),
            'totals' => [],
            'applied_promo_codes' => [],
            'validation_errors' => [],
            'prestashop_cart_id' => null,
            'prestashop_customer_id' => null,
            'finalized' => false
        ];

        // Calculer les totaux initiaux
        $this->calculateTotals();

        $this->saveSession();
        return $this->session_data;
    }

    /**
     * Mettre à jour une session existante
     */
    public function updateSession($sid, $updates)
    {
        if (!$this->loadSession($sid)) {
            return false;
        }

        // Mettre à jour les champs autorisés
        if (isset($updates['line_items'])) {
            $this->session_data['line_items'] = $this->normalizeLineItems($updates['line_items']);
        }

        if (isset($updates['buyer'])) {
            $this->session_data['buyer'] = $this->normalizeBuyerData($updates['buyer']);
        }

        if (isset($updates['promo_code'])) {
            // Nettoyer les erreurs de validation précédentes
            $this->session_data['validation_errors'] = [];
            
            $promo_result = $this->handlePromoCode($updates['promo_code']);
            if (!$promo_result['valid']) {
                // Ajouter l'erreur de validation aux données de session
                $this->session_data['validation_errors'] = $promo_result['errors'];
                $this->session_data['applied_promo_codes'] = []; // Vider les codes promo en cas d'erreur
            }
        }

        // Recalculer les totaux
        $this->calculateTotals();

        // Mettre à jour le timestamp
        $this->session_data['updated_at'] = date('c');

        $this->saveSession();
        return $this->session_data;
    }

    /**
     * Finaliser la session : créer le Cart PrestaShop
     */
    public function finalizeSession($sid)
    {
        if (!$this->loadSession($sid)) {
            return [
                'success' => false,
                'error' => 'Session not found',
                'code' => 404
            ];
        }

        if ($this->session_data['finalized']) {
            return [
                'success' => false,
                'error' => 'Session already finalized',
                'code' => 409
            ];
        }

        // Validation finale
        $validation = $this->performFinalValidation();
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'code' => 400,
                'details' => $validation['errors']
            ];
        }

        // Créer le client PrestaShop si nécessaire
        $buyer_result = $this->createOrUpdatePrestaShopCustomer();
        if (!$buyer_result['success']) {
            return $buyer_result;
        }

        // Créer le panier PrestaShop
        $cart_result = $this->createPrestaShopCart($buyer_result['customer_id']);
        if (!$cart_result['success']) {
            return $cart_result;
        }

        // Appliquer les codes promo si présents
        if (!empty($this->session_data['applied_promo_codes'])) {
            $promo_result = $this->applyPromoCodesToCart($cart_result['cart_id'], $this->session_data['applied_promo_codes']);
            if (!$promo_result['success']) {
                // Log l'erreur mais ne pas bloquer la finalisation
                error_log('UCP: Failed to apply promo codes to cart ' . $cart_result['cart_id'] . ': ' . implode(', ', $promo_result['errors']));
            }
        }

        // Marquer comme finalisé
        $this->session_data['finalized'] = true;
        $this->session_data['prestashop_cart_id'] = $cart_result['cart_id'];
        $this->session_data['prestashop_customer_id'] = $buyer_result['customer_id'];
        $this->session_data['finalized_at'] = date('c');

        $this->saveSession();

        return [
            'success' => true,
            'cart_id' => $cart_result['cart_id'],
            'customer_id' => $buyer_result['customer_id'],
            'session_data' => $this->session_data
        ];
    }

    /**
     * Récupérer une session
     */
    public function getSession($sid)
    {
        if (!$this->loadSession($sid)) {
            return null;
        }

        return $this->session_data;
    }

    /**
     * Normaliser les données du buyer
     */
    private function normalizeBuyerData($buyer_data)
    {
        return [
            'email' => strtolower(trim($buyer_data['email'])),
            'first_name' => trim(ucwords(strtolower($buyer_data['first_name']))),
            'last_name' => trim(ucwords(strtolower($buyer_data['last_name']))),
            'phone' => !empty($buyer_data['phone']) ? trim($buyer_data['phone']) : null,
            'company' => !empty($buyer_data['company']) ? trim($buyer_data['company']) : null,
            'address' => trim($buyer_data['address']),
            'city' => trim(ucwords(strtolower($buyer_data['city']))),
            'postal_code' => trim($buyer_data['postal_code']),
            'country' => trim(strtoupper($buyer_data['country']))
        ];
    }

    /**
     * Normaliser les line items
     */
    private function normalizeLineItems($line_items)
    {
        $normalized = [];
        foreach ($line_items as $item) {
            $normalized[] = [
                'product_id' => (int)$item['product_id'],
                'quantity' => (int)$item['quantity'],
                'customization_data' => $item['customization_data'] ?? null
            ];
        }
        return $normalized;
    }

    /**
     * Calculer les totaux (simulation)
     */
    private function calculateTotals()
    {
        $subtotal = 0;
        $tax_amount = 0;
        $items_count = 0;
        $items_quantity = 0;

        foreach ($this->session_data['line_items'] as $item) {
            $unit_price = $this->getProductPrice($item['product_id']);
            $total_price = $unit_price * $item['quantity'];
            
            $subtotal += $total_price;
            $tax_amount += $total_price * 0.2; // TVA 20%
            $items_count++;
            $items_quantity += $item['quantity'];
        }

        // Calculer la réduction promo
        $discount_amount = 0;
        if (!empty($this->session_data['applied_promo_codes'])) {
            foreach ($this->session_data['applied_promo_codes'] as $promo_code) {
                $promo_details = $this->getPromoCodeDetails($promo_code);
                
                if ($promo_details) {
                    // Utiliser les vraies données du code promo depuis PrestaShop
                    if ($promo_details['reduction_percent'] > 0) {
                        // Réduction en pourcentage
                        $discount_amount += $subtotal * ($promo_details['reduction_percent'] / 100);
                    } else {
                        // Réduction en montant fixe
                        $discount_amount += $promo_details['reduction_value'];
                    }
                    
                    // Ajouter la réduction pour livraison gratuite si applicable
                    if ($promo_details['free_shipping']) {
                        // La réduction pour livraison sera gérée séparément
                        // Pour l'instant, on l'ignore dans le calcul
                    }
                } else {
                    // Fallback sur les codes de test si non trouvé dans PrestaShop
                    switch (strtoupper($promo_code)) {
                        case 'TEST10':
                        case 'SAVE10':
                            $discount_amount += $subtotal * 0.10; // 10% de réduction
                            break;
                        case 'TEST20':
                        case 'PROMO20':
                            $discount_amount += $subtotal * 0.20; // 20% de réduction
                            break;
                        case 'WELCOME':
                            $discount_amount += 5.00; // 5€ de réduction fixe
                            break;
                        case 'PROMO2026':
                            $discount_amount += $subtotal * 0.15; // 15% de réduction
                            break;
                    }
                }
            }
        }

        // Limiter la réduction au sous-total
        $discount_amount = min($discount_amount, $subtotal);
        
        $total_after_discount = $subtotal - $discount_amount;
        $total = $total_after_discount + $tax_amount;

        $this->session_data['totals'] = [
            'subtotal' => [
                'amount' => round($subtotal, 2),
                'currency' => 'MGA',
                'formatted' => number_format(round($subtotal, 2), 2, ',', ' ') . ' MGA'
            ],
            'tax' => [
                'amount' => round($tax_amount, 2),
                'currency' => 'MGA',
                'formatted' => number_format(round($tax_amount, 2), 2, ',', ' ') . ' MGA'
            ],
            'shipping' => [
                'amount' => 0,
                'currency' => 'MGA',
                'formatted' => '0,00 MGA'
            ],
            'discount' => [
                'amount' => round($discount_amount, 2),
                'currency' => 'MGA',
                'formatted' => '-' . number_format(round($discount_amount, 2), 2, ',', ' ') . ' MGA'
            ],
            'total' => [
                'amount' => round($total, 2),
                'currency' => 'MGA',
                'formatted' => number_format(round($total, 2), 2, ',', ' ') . ' MGA'
            ],
            'items_count' => $items_count,
            'items_quantity' => $items_quantity
        ];
    }

    /**
     * Simuler le prix d'un produit
     */
    private function getProductPrice($product_id)
    {
        // Simulation de prix pour différents produits
        $product_prices = [
            1 => 19.12, // T-shirt imprimé colibri
            2 => 25.50, // Pull molletonné
            3 => 32.99, // Jean denim
            4 => 15.00, // Casquette
            5 => 45.00, // Veste cuir
        ];
        
        return $product_prices[$product_id] ?? 20.00; // Prix par défaut si non trouvé
    }

    /**
     * Gérer les codes promo avec validation
     */
    private function handlePromoCode($promo_code)
    {
        if (empty($promo_code)) {
            // Supprimer tous les codes promo
            $this->session_data['applied_promo_codes'] = [];
            return ['valid' => true];
        } else {
            // Valider le code promo
            $validation = $this->validatePromoCode($promo_code);
            
            if ($validation['valid']) {
                // Vérifier si le code n'est pas déjà appliqué
                if (!in_array($promo_code, $this->session_data['applied_promo_codes'])) {
                    $this->session_data['applied_promo_codes'][] = $promo_code;
                }
                return ['valid' => true];
            } else {
                return $validation;
            }
        }
    }

    /**
     * Valider un code promo
     */
    private function validatePromoCode($promo_code)
    {
        $errors = [];
        
        // Vérifier le format du code
        if (strlen($promo_code) < 3) {
            $errors[] = 'Promo code too short (minimum 3 characters)';
        }
        
        if (strlen($promo_code) > 20) {
            $errors[] = 'Promo code too long (maximum 20 characters)';
        }
        
        if (!preg_match('/^[A-Z0-9_-]+$/', strtoupper($promo_code))) {
            $errors[] = 'Promo code format invalid (only letters, numbers, underscore and hyphen allowed)';
        }
        
        // Récupérer les codes promo valides depuis PrestaShop
        $valid_promo_codes = $this->getValidPromoCodesFromPrestaShop();
        
        // Vérifier si c'est un code promo valide dans PrestaShop
        if (!in_array(strtoupper($promo_code), $valid_promo_codes)) {
            $errors[] = 'Invalid or expired promo code: ' . $promo_code;
        }
        
        // Vérifier si le code est déjà utilisé (simulation)
        if (in_array(strtoupper($promo_code), array_map('strtoupper', $this->session_data['applied_promo_codes']))) {
            $errors[] = 'Promo code already applied: ' . $promo_code;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Récupérer les codes promo valides depuis PrestaShop
     */
    private function getValidPromoCodesFromPrestaShop()
    {
        try {
            // Récupérer les codes promo actifs depuis la table ps_cart_rule
            $sql = 'SELECT cr.code 
                    FROM ' . _DB_PREFIX_ . 'cart_rule cr
                    WHERE cr.active = 1 
                    AND cr.code IS NOT NULL 
                    AND cr.code != ""
                    AND (cr.date_from IS NULL OR cr.date_from <= NOW())
                    AND (cr.date_to IS NULL OR cr.date_to >= NOW())
                    ORDER BY cr.code ASC';
            
            $codes = Db::getInstance()->executeS($sql);
            
            if (!$codes) {
                // En cas d'erreur ou si aucun code trouvé, retourner les codes de test
                return ['TEST10', 'TEST20', 'SAVE10', 'WELCOME', 'PROMO2026', 'PROMO20'];
            }
            
            // Extraire uniquement les codes en majuscules
            $valid_codes = [];
            foreach ($codes as $code_row) {
                $valid_codes[] = strtoupper(trim($code_row['code']));
            }
            
            // Ajouter les codes de test si aucun code réel trouvé
            if (empty($valid_codes)) {
                return ['TEST10', 'TEST20', 'SAVE10', 'WELCOME', 'PROMO2026', 'PROMO20'];
            }
            
            return $valid_codes;
            
        } catch (Exception $e) {
            // En cas d'erreur de base de données, utiliser les codes de test
            error_log('UCP Promo Code Error: ' . $e->getMessage());
            return ['TEST10', 'TEST20', 'SAVE10', 'WELCOME', 'PROMO2026', 'PROMO20'];
        }
    }

    /**
     * Récupérer les détails d'un code promo depuis PrestaShop
     */
    private function getPromoCodeDetails($promo_code)
    {
        try {
            $sql = 'SELECT cr.*
                    FROM ' . _DB_PREFIX_ . 'cart_rule cr
                    WHERE cr.active = 1 
                    AND cr.code = "' . pSQL($promo_code) . '"
                    AND (cr.date_from IS NULL OR cr.date_from <= NOW())
                    AND (cr.date_to IS NULL OR cr.date_to >= NOW())';
            
            $promo_details = Db::getInstance()->getRow($sql);
            
            if (!$promo_details) {
                return null;
            }
            
            return [
                'id_cart_rule' => $promo_details['id_cart_rule'],
                'code' => $promo_details['code'],
                'description' => $promo_details['description'],
                'reduction_type' => $promo_details['reduction_percent'] > 0 ? 'percent' : 'amount',
                'reduction_value' => (float)$promo_details['reduction_amount'],
                'reduction_percent' => (float)$promo_details['reduction_percent'],
                'minimum_amount' => (float)$promo_details['minimum_amount'],
                'quantity_per_user' => (int)$promo_details['quantity_per_user'],
                'quantity' => (int)$promo_details['quantity'],
                'free_shipping' => (bool)$promo_details['free_shipping']
            ];
            
        } catch (Exception $e) {
            error_log('UCP Promo Code Details Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validation finale avant création du panier
     */
    private function performFinalValidation()
    {
        $errors = [];

        // Validation du buyer
        if (empty($this->session_data['buyer']['email'])) {
            $errors[] = 'Buyer email is required';
        }

        // Validation des produits
        if (empty($this->session_data['line_items'])) {
            $errors[] = 'At least one line item is required';
        }

        // Validation du stock (simulation)
        foreach ($this->session_data['line_items'] as $item) {
            if (!$this->isProductAvailable($item['product_id'], $item['quantity'])) {
                $errors[] = 'Product ' . $item['product_id'] . ' not available in quantity ' . $item['quantity'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Vérifier la disponibilité d'un produit
     */
    private function isProductAvailable($product_id, $quantity)
    {
        // Simulation - en réalité, vérifier dans PrestaShop
        return true;
    }

    /**
     * Créer ou mettre à jour le client PrestaShop
     */
    private function createOrUpdatePrestaShopCustomer()
    {
        try {
            $buyer_manager = new UcpBuyerManager();
            return $buyer_manager->handleBuyerIdentity($this->session_data['buyer'], $this->session_data['headers']);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create customer: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    /**
     * Créer le panier PrestaShop
     */
    private function createPrestaShopCart($customer_id)
    {
        try {
            $cart_manager = new UcpCartManager();
            return $cart_manager->createCartWithItems(
                $this->session_data['line_items'],
                $this->session_data['buyer'],
                $customer_id
            );
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create cart: ' . $e->getMessage(),
                'code' => 500
            ];
        }
    }

    /**
     * Appliquer les codes promo au panier PrestaShop
     */
    private function applyPromoCodesToCart($cart_id, $promo_codes)
    {
        try {
            $cart = new Cart($cart_id);
            if (!$cart->id) {
                return [
                    'success' => false,
                    'errors' => ['Cart not found']
                ];
            }

            $applied_codes = [];
            $errors = [];

            foreach ($promo_codes as $promo_code) {
                // Récupérer les détails du code promo
                $promo_details = $this->getPromoCodeDetails($promo_code);
                
                if (!$promo_details) {
                    $errors[] = 'Invalid promo code: ' . $promo_code;
                    continue;
                }

                // Vérifier si le code est déjà appliqué
                $existing_rules = $cart->getCartRules();
                $already_applied = false;
                foreach ($existing_rules as $rule) {
                    if ($rule['id_cart_rule'] == $promo_details['id_cart_rule']) {
                        $already_applied = true;
                        break;
                    }
                }
                
                if ($already_applied) {
                    $errors[] = 'Promo code already applied: ' . $promo_code;
                    continue;
                }

                // Appliquer la règle avec la bonne structure de table
                try {
                    // Insérer dans la base de données avec la structure correcte
                    $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'cart_cart_rule 
                            (id_cart, id_cart_rule) 
                            VALUES (' . (int)$cart->id . ', ' . (int)$promo_details['id_cart_rule'] . ')';
                    
                    $result = Db::getInstance()->execute($sql);
                    
                    if ($result) {
                        $applied_codes[] = $promo_code;
                        
                        // Mettre à jour les totaux du panier
                        $cart->update();
                        
                        // Forcer le recalcul des totaux
                        CartRule::autoAddToCart();
                        
                    } else {
                        $errors[] = 'Failed to insert cart rule: ' . $promo_code;
                    }
                } catch (Exception $e) {
                    $errors[] = 'Exception applying promo code ' . $promo_code . ': ' . $e->getMessage();
                }
            }

            return [
                'success' => empty($errors),
                'applied_codes' => $applied_codes,
                'errors' => $errors
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => ['Exception: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Sauvegarder la session en fichier
     */
    private function saveSession()
    {
        $file_path = $this->storage_path . $this->session_id . '.json';
        file_put_contents($file_path, json_encode($this->session_data, JSON_PRETTY_PRINT));
    }

    /**
     * Charger une session depuis le fichier
     */
    private function loadSession($sid)
    {
        $file_path = $this->storage_path . $sid . '.json';
        
        if (!file_exists($file_path)) {
            return false;
        }

        $data = json_decode(file_get_contents($file_path), true);
        if (!$data) {
            return false;
        }

        // Vérifier l'expiration
        if (strtotime($data['expires_at']) < time()) {
            unlink($file_path); // Supprimer la session expirée
            return false;
        }

        $this->session_id = $sid;
        $this->session_data = $data;
        return true;
    }

    /**
     * Supprimer une session
     */
    public function deleteSession($sid)
    {
        $file_path = $this->storage_path . $sid . '.json';
        if (file_exists($file_path)) {
            unlink($file_path);
            return true;
        }
        return false;
    }
}
