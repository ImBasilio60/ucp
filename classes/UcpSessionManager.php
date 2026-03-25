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
        // Utiliser le répertoire temp du module par défaut
        $this->storage_path = dirname(__FILE__) . '/../temp/sessions/';
        
        // Créer le répertoire s'il n'existe pas
        if (!file_exists($this->storage_path)) {
            if (!mkdir($this->storage_path, 0755, true)) {
                error_log('UCP ERROR: Failed to create session directory: ' . $this->storage_path);
                // Fallback sur le répertoire temporaire système
                $system_temp = sys_get_temp_dir();
                $this->storage_path = $system_temp . '/ucp_sessions/';
                if (!file_exists($this->storage_path)) {
                    mkdir($this->storage_path, 0755, true);
                }
            }
        }
        
        // Vérifier si le répertoire est accessible en écriture (sans essayer de changer les permissions)
        if (!is_writable($this->storage_path)) {
            error_log('UCP WARNING: Session directory is not writable: ' . $this->storage_path . ', using fallback');
            // Fallback sur le répertoire temporaire système
            $system_temp = sys_get_temp_dir();
            $this->storage_path = $system_temp . '/ucp_sessions/';
            if (!file_exists($this->storage_path)) {
                mkdir($this->storage_path, 0755, true);
            }
        }
    }

    /**
     * Obtenir le chemin de stockage des sessions
     */
    public function getStoragePath()
    {
        return $this->storage_path;
    }

    /**
     * Créer une nouvelle session UCP temporaire
     */
    public function createSession($sid, $buyer_data, $line_items, $headers)
    {
        // Valider les champs obligatoires du buyer avant de continuer
        $buyer_validation = $this->validateRequiredBuyerFields($buyer_data);
        if (!$buyer_validation['valid']) {
            throw new Exception('Missing required buyer fields: ' . implode(', ', $buyer_validation['missing_fields']));
        }
        
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
            // Valider les champs obligatoires du buyer avant de mettre à jour
            $buyer_validation = $this->validateRequiredBuyerFields($updates['buyer']);
            if (!$buyer_validation['valid']) {
                // Ajouter l'erreur de validation aux données de session
                $this->session_data['validation_errors'][] = [
                    'field' => 'buyer',
                    'message' => 'Missing required buyer fields: ' . implode(', ', $buyer_validation['missing_fields']),
                    'missing_fields' => $buyer_validation['missing_fields']
                ];
                $this->saveSession();
                return $this->session_data;
            }
            
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

        // Créer la commande PrestaShop
        $order_result = $this->createPrestaShopOrder($cart_result['cart_id'], $buyer_result['customer_id']);
        if (!$order_result['success']) {
            // Log l'erreur mais continuer avec le panier créé
            error_log('UCP: Failed to create order from cart ' . $cart_result['cart_id'] . ': ' . $order_result['error']);
        }

        // Marquer comme finalisé
        $this->session_data['finalized'] = true;
        $this->session_data['prestashop_cart_id'] = $cart_result['cart_id'];
        $this->session_data['prestashop_customer_id'] = $buyer_result['customer_id'];
        $this->session_data['prestashop_order_id'] = $order_result['success'] ? $order_result['order_id'] : null;
        $this->session_data['prestashop_order_reference'] = $order_result['success'] ? $order_result['order_reference'] : null;
        $this->session_data['finalized_at'] = date('c');

        // Sauvegarder l'état final avant suppression
        $this->saveSession();
        
        // Supprimer le fichier JSON après finalisation réussie
        $this->deleteSession($sid);

        return [
            'success' => true,
            'cart_id' => $cart_result['cart_id'],
            'customer_id' => $buyer_result['customer_id'],
            'order_id' => $order_result['success'] ? $order_result['order_id'] : null,
            'order_reference' => $order_result['success'] ? $order_result['order_reference'] : null,
            'order_created' => $order_result['success'],
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
     * Valider les champs obligatoires du buyer
     */
    private function validateRequiredBuyerFields($buyer_data)
    {
        $required_fields = [
            'email',
            'first_name', 
            'last_name',
            'address',
            'city',
            'postal_code',
            'country',
            'phone'
        ];
        
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (!isset($buyer_data[$field]) || empty(trim($buyer_data[$field]))) {
                $missing_fields[] = $field;
            }
        }
        
        return [
            'valid' => empty($missing_fields),
            'missing_fields' => $missing_fields
        ];
    }

    /**
     * Normaliser les données du buyer
     */
    private function normalizeBuyerData($buyer_data)
    {
        return [
            'email' => isset($buyer_data['email']) ? strtolower(trim($buyer_data['email'])) : '',
            'first_name' => isset($buyer_data['first_name']) ? trim(ucwords(strtolower($buyer_data['first_name']))) : '',
            'last_name' => isset($buyer_data['last_name']) ? trim(ucwords(strtolower($buyer_data['last_name']))) : '',
            'phone' => isset($buyer_data['phone']) ? trim($buyer_data['phone']) : null,
            'company' => isset($buyer_data['company']) ? trim($buyer_data['company']) : null,
            'address' => isset($buyer_data['address']) ? trim($buyer_data['address']) : '',
            'city' => isset($buyer_data['city']) ? trim(ucwords(strtolower($buyer_data['city']))) : '',
            'postal_code' => isset($buyer_data['postal_code']) ? trim($buyer_data['postal_code']) : '',
            'country' => isset($buyer_data['country']) ? trim(strtoupper($buyer_data['country'])) : ''
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
            $sql = 'SELECT cr.code, cr.id_cart_rule, cr.active, cr.date_from, cr.date_to
                    FROM ' . _DB_PREFIX_ . 'cart_rule cr
                    WHERE cr.active = 1 
                    AND cr.code IS NOT NULL 
                    AND cr.code != ""
                    AND (cr.date_from IS NULL OR cr.date_from <= NOW())
                    AND (cr.date_to IS NULL OR cr.date_to >= NOW())
                    ORDER BY cr.code ASC';
            
            $codes = Db::getInstance()->executeS($sql);
            
            // Debug: logger les résultats de la requête
            error_log('UCP DEBUG: Requête promo codes: ' . $sql);
            error_log('UCP DEBUG: Résultat brut: ' . print_r($codes, true));
            
            if (!$codes) {
                error_log('UCP DEBUG: Aucun code promo trouvé dans la base de données');
                return [];
            }
            
            // Extraire uniquement les codes en majuscules
            $valid_codes = [];
            foreach ($codes as $code_row) {
                $code = strtoupper(trim($code_row['code']));
                if (!empty($code)) {
                    $valid_codes[] = $code;
                    error_log('UCP DEBUG: Code promo trouvé: ' . $code . ' (ID: ' . $code_row['id_cart_rule'] . ')');
                }
            }
            
            error_log('UCP DEBUG: Total codes promo valides: ' . count($valid_codes));
            return $valid_codes;
            
        } catch (Exception $e) {
            // Logger l'erreur et retourner un tableau vide
            error_log('UCP ERROR: Erreur lors de la récupération des codes promo: ' . $e->getMessage());
            return [];
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
        
        // Créer le répertoire si nécessaire
        if (!file_exists($this->storage_path)) {
            if (!mkdir($this->storage_path, 0755, true)) {
                error_log('UCP ERROR: Failed to create session directory: ' . $this->storage_path);
                return false;
            }
        }
        
        // Vérifier les permissions d'écriture
        if (!is_writable($this->storage_path)) {
            // Tenter de changer les permissions
            if (!chmod($this->storage_path, 0755)) {
                error_log('UCP ERROR: Session directory is not writable and cannot change permissions: ' . $this->storage_path);
                // Continuer quand même et essayer d'écrire
            }
        }
        
        $result = file_put_contents($file_path, json_encode($this->session_data, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            error_log('UCP ERROR: Failed to write session file: ' . $file_path);
            return false;
        }
        
        return true;
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
     * Créer une commande PrestaShop à partir du panier
     */
    private function createPrestaShopOrder($cart_id, $customer_id)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return [
                    'success' => false,
                    'error' => 'Cart not found'
                ];
            }

            $customer = new Customer($customer_id);
            if (!Validate::isLoadedObject($customer)) {
                return [
                    'success' => false,
                    'error' => 'Customer not found'
                ];
            }

            // Vérifier que le panier a des produits
            $products = $cart->getProducts();
            if (empty($products)) {
                return [
                    'success' => false,
                    'error' => 'Cart is empty'
                ];
            }

            // Créer l'adresse de livraison si nécessaire
            $address_id = $this->ensureCustomerAddress($customer_id);
            if (!$address_id) {
                return [
                    'success' => false,
                    'error' => 'Failed to create customer address'
                ];
            }

            // Mettre à jour le panier avec l'adresse et le transporteur
            $cart->id_address_delivery = $address_id;
            $cart->id_address_invoice = $address_id;
            
            // Définir un transporteur par défaut
            $cart->id_carrier = $this->getDefaultCarrier();
            $cart->update();

            // Créer la commande
            $this->context = Context::getContext();
            $this->context->cart = $cart;
            $this->context->customer = $customer;

            // S'assurer que le panier a une devise définie
            if (!$cart->id_currency) {
                $cart->id_currency = Configuration::get('PS_CURRENCY_DEFAULT');
                $cart->update();
            }

            // Obtenir la devise
            $currency = new Currency($cart->id_currency);
            if (!Validate::isLoadedObject($currency)) {
                return [
                    'success' => false,
                    'error' => 'Invalid currency for cart'
                ];
            }
            $this->context->currency = $currency;

            // Ajouter la commande en utilisant le processus standard de PrestaShop
            $order = new Order();
            $order->id_customer = $customer_id;
            $order->id_address_delivery = $address_id;
            $order->id_address_invoice = $address_id;
            $order->id_cart = $cart_id;
            $order->id_currency = $cart->id_currency;
            $order->id_lang = $cart->id_lang;
            $order->id_shop = $cart->id_shop;
            $order->id_shop_group = $cart->id_shop_group;
            $order->id_carrier = $cart->id_carrier; // Ajouter le transporteur
            $order->conversion_rate = 1; // Taux de conversion par défaut
            $order->secure_key = $customer->secure_key ?: md5(time() . mt_rand()); // Clé sécurisée du client ou générée
            
            // Statut de paiement (en attente)
            $order->current_state = Configuration::get('PS_OS_PREPARATION'); // En préparation
            $order->module = 'ucp'; // Module source
            $order->payment = 'UCP Payment'; // Méthode de paiement
            $order->total_paid = $cart->getOrderTotal(true);
            $order->total_paid_real = $cart->getOrderTotal(true);
            $order->total_paid_tax_incl = $cart->getOrderTotal(true); // Ajout du champ manquant
            $order->total_paid_tax_excl = $cart->getOrderTotal(false); // Ajout du champ manquant
            $order->total_products = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
            $order->total_products_wt = $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            $order->total_shipping = 0; // Livraison gratuite pour éviter les warnings
            $order->total_shipping_tax_excl = 0;
            $order->total_shipping_tax_incl = 0;
            $order->total_wrapping = 0;
            $order->total_wrapping_tax_excl = 0;
            $order->total_wrapping_tax_incl = 0;
            $order->total_discounts = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
            $order->total_discounts_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS);
            $order->total_discounts_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
            
            // Générer la référence de commande
            $order->reference = Order::generateReference();
            $order->date_add = date('Y-m-d H:i:s');
            $order->date_upd = date('Y-m-d H:i:s');
            
            // Utiliser une méthode simple pour créer la commande avec les produits
            try {
                $order_status = Configuration::get('PS_OS_PREPARATION');
                $order->valid = 1;
                
                if (!$order->add()) {
                    return [
                        'success' => false,
                        'error' => 'Failed to create order in database'
                    ];
                }
                
                // Ajouter les détails de la commande (produits)
                if (!$this->addOrderDetails($order, $cart)) {
                    return [
                        'success' => false,
                        'error' => 'Failed to add order details'
                    ];
                }
                
                // Ajouter l'historique des statuts
                $history = new OrderHistory();
                $history->id_order = $order->id;
                $history->id_employee = 0;
                $history->id_order_state = $order_status;
                if (!$history->add()) {
                    error_log('UCP: Failed to add order history for order ' . $order->id);
                }
                
                // Mettre à jour les quantités de produits
                $this->updateProductQuantities($cart);
                
                // Le panier est déjà confirmé par la création de la commande
                
            } catch (Exception $e) {
                error_log('UCP: Exception during order creation: ' . $e->getMessage());
                return [
                    'success' => false,
                    'error' => 'Exception during order creation: ' . $e->getMessage()
                ];
            }

            // Logger la création de commande
            try {
                PrestaShopLogger::addLog(
                    'UCP Order Created: Order ID ' . $order->id . ', Reference: ' . $order->reference . ', Cart ID: ' . $cart_id . ', Customer ID: ' . $customer_id,
                    1, // Info level
                    null,
                    'UCP',
                    0,
                    true
                );
            } catch (Exception $e) {
                error_log('UCP: Failed to log order creation: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'order_id' => $order->id,
                'order_reference' => $order->reference,
                'order_total' => $order->total_paid
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception creating order: ' . $e->getMessage()
            ];
        }
    }

    /**
     * S'assurer que le client a une adresse
     */
    private function ensureCustomerAddress($customer_id)
    {
        try {
            $customer = new Customer($customer_id);
            if (!Validate::isLoadedObject($customer)) {
                return false;
            }

            // Vérifier si le client a déjà une adresse
            $addresses = $customer->getAddresses(Configuration::get('PS_LANG_DEFAULT'));
            if (!empty($addresses)) {
                return $addresses[0]['id_address'];
            }

            // Créer une adresse avec les données de la session
            $buyer_data = $this->session_data['buyer'];
            
            $address = new Address();
            $address->id_customer = $customer_id;
            $address->firstname = $buyer_data['first_name'];
            $address->lastname = $buyer_data['last_name'];
            $address->address1 = $buyer_data['address'];
            $address->city = $buyer_data['city'];
            $address->postcode = $buyer_data['postal_code'];
            $address->phone = $buyer_data['phone'];
            $address->company = $buyer_data['company'] ?? '';
            
            // Récupérer l'ID du pays
            $country_id = 8; // France par défaut
            if (!empty($buyer_data['country'])) {
                $sql = 'SELECT id_country FROM ' . _DB_PREFIX_ . 'country WHERE iso_code = "' . pSQL(strtoupper($buyer_data['country'])) . '" AND active = 1';
                $result = Db::getInstance()->getValue($sql);
                if ($result) {
                    $country_id = $result;
                } else {
                    // Si le pays n'est pas trouvé, essayer avec le nom
                    $sql = 'SELECT id_country FROM ' . _DB_PREFIX_ . 'country WHERE name LIKE "%' . pSQL($buyer_data['country']) . '%" AND active = 1';
                    $result = Db::getInstance()->getValue($sql);
                    if ($result) {
                        $country_id = $result;
                    }
                }
            }
            $address->id_country = $country_id;
            
            // Récupérer l'ID de l'état si applicable
            if (!empty($buyer_data['state'])) {
                $sql = 'SELECT id_state FROM ' . _DB_PREFIX_ . 'state WHERE iso_code = "' . pSQL(strtoupper($buyer_data['state'])) . '" AND id_country = ' . (int)$country_id;
                $result = Db::getInstance()->getValue($sql);
                if ($result) {
                    $address->id_state = $result;
                }
            }
            
            // Définir comme adresse par défaut
            $address->alias = 'UCP Default Address';
            $address->active = 1;
            
            if ($address->add()) {
                // L'adresse est automatiquement associée au client
                // Pas besoin de mettre à jour customer->id_address car cette propriété n'existe pas dans PrestaShop
                
                return $address->id;
            }

            return false;

        } catch (Exception $e) {
            error_log('UCP Address Creation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer une session
     */
    public function deleteSession($sid)
    {
        $file_path = $this->storage_path . $sid . '.json';
        if (file_exists($file_path)) {
            unlink($file_path);
            // Log la suppression
            error_log("UCP: Session file deleted: $sid");
            return true;
        }
        return false;
    }

    /**
     * Nettoyer les sessions expirées
     */
    public function cleanupExpiredSessions()
    {
        $files = glob($this->storage_path . '*.json');
        $deleted_count = 0;
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['expires_at'])) {
                if (strtotime($data['expires_at']) < time()) {
                    unlink($file);
                    $deleted_count++;
                }
            }
        }
        
        return $deleted_count;
    }

    /**
     * Test method pour créer directement une commande sans authentification
     */
    public function testCreateOrder($sid)
    {
        if (!$this->loadSession($sid)) {
            return [
                'success' => false,
                'error' => 'Session not found'
            ];
        }

        // Créer un nouveau client directement (bypass authentification pour les tests)
        $buyer_manager = new UcpBuyerManager();
        
        // Créer le client directement sans authentification
        $customer_result = $buyer_manager->createCustomerForTest($this->session_data['buyer']);
        
        if (!$customer_result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create customer: ' . implode(', ', $customer_result['errors'] ?? ['Unknown error'])
            ];
        }
        
        $customer_id = $customer_result['customer_id'];
        
        // Simulation de création panier
        $cart_id = $this->createTestCart($customer_id);
        if (!$cart_id) {
            return [
                'success' => false,
                'error' => 'Failed to create test cart'
            ];
        }

        // Créer la commande
        $order_result = $this->createPrestaShopOrder($cart_id, $customer_id);
        
        return [
            'success' => $order_result['success'],
            'cart_id' => $cart_id,
            'customer_id' => $customer_id,
            'order_id' => $order_result['success'] ? $order_result['order_id'] : null,
            'order_reference' => $order_result['success'] ? $order_result['order_reference'] : null,
            'error' => $order_result['success'] ? null : $order_result['error']
        ];
    }

    /**
     * Obtenir le transporteur par défaut
     */
    private function getDefaultCarrier()
    {
        try {
            // D'abord, essayer de créer un transporteur UCP valide
            $ucp_carrier_id = $this->createDefaultCarrier();
            
            // Vérifier que le transporteur est bien créé et actif
            $sql = 'SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE id_carrier = ' . (int)$ucp_carrier_id . ' AND active = 1 AND deleted = 0';
            $carrier_id = Db::getInstance()->getValue($sql);
            
            if ($carrier_id) {
                return (int)$carrier_id;
            }
            
            // Sinon, prendre le premier transporteur actif disponible
            $sql = 'SELECT id_carrier FROM ' . _DB_PREFIX_ . 'carrier WHERE active = 1 AND deleted = 0 ORDER BY position ASC';
            $carriers = Db::getInstance()->executeS($sql);
            
            if (!empty($carriers)) {
                return (int)$carriers[0]['id_carrier'];
            }
            
            return 1; // Fallback ultime
        } catch (Exception $e) {
            error_log('UCP: Error getting default carrier: ' . $e->getMessage());
            return 1; // Fallback
        }
    }

    /**
     * Créer un transporteur par défaut
     */
    private function createDefaultCarrier()
    {
        try {
            $carrier = new Carrier();
            $carrier->name = 'UCP Standard Delivery';
            $carrier->id_tax_rules_group = 0;
            $carrier->active = 1;
            $carrier->deleted = 0;
            $carrier->shipping_handling = 1;
            $carrier->range_behavior = 0;
            $carrier->is_module = 0;
            $carrier->is_free = 1;
            $carrier->shipping_external = 0;
            $carrier->need_range = 0;
            $carrier->position = 1;
            $carrier->max_width = 0;
            $carrier->max_height = 0;
            $carrier->max_depth = 0;
            $carrier->max_weight = 0;
            $carrier->grade = 0;
            
            foreach (Language::getLanguages() as $lang) {
                $carrier->delay[$lang['id_lang']] = 'UCP Standard Delivery';
            }
            
            if ($carrier->add()) {
                // Ajouter toutes les zones pour être sûr que le transporteur soit disponible
                $zones = Zone::getZones();
                foreach ($zones as $zone) {
                    Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'carrier_zone (id_carrier, id_zone) VALUES (' . (int)$carrier->id . ', ' . (int)$zone['id_zone'] . ')');
                }
                
                // Ajouter les prix par défaut (gratuit) pour toutes les zones
                $range_price = new RangePrice();
                $range_price->id_carrier = $carrier->id;
                $range_price->delimiter1 = 0;
                $range_price->delimiter2 = 10000;
                if ($range_price->add()) {
                    foreach ($zones as $zone) {
                        Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'delivery (id_carrier, id_range_price, id_range_weight, id_zone, price) VALUES (' . (int)$carrier->id . ', ' . (int)$range_price->id . ', 0, ' . (int)$zone['id_zone'] . ', 0)');
                    }
                }
                
                // Ajouter les poids aussi
                $range_weight = new RangeWeight();
                $range_weight->id_carrier = $carrier->id;
                $range_weight->delimiter1 = 0;
                $range_weight->delimiter2 = 1000;
                if ($range_weight->add()) {
                    foreach ($zones as $zone) {
                        Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'delivery (id_carrier, id_range_price, id_range_weight, id_zone, price) VALUES (' . (int)$carrier->id . ', 0, ' . (int)$range_weight->id . ', ' . (int)$zone['id_zone'] . ', 0)');
                    }
                }
                
                return $carrier->id;
            }
        } catch (Exception $e) {
            error_log('UCP: Error creating default carrier: ' . $e->getMessage());
        }
        
        return 1; // Fallback
    }

    /**
     * Ajouter les détails de la commande (produits)
     */
    private function addOrderDetails($order, $cart)
    {
        try {
            $products = $cart->getProducts();
            
            foreach ($products as $product) {
                $order_detail = new OrderDetail();
                
                $order_detail->id_order = $order->id;
                $order_detail->product_id = $product['id_product'];
                $order_detail->product_attribute_id = $product['id_product_attribute'] ?? 0;
                $order_detail->product_name = $product['name'];
                $order_detail->product_quantity = $product['quantity'];
                $order_detail->product_price = $product['price'];
                $order_detail->product_quantity_in_stock = $product['quantity_available'];
                $order_detail->product_quantity_refunded = 0;
                $order_detail->product_quantity_return = 0;
                $order_detail->product_quantity_reinjected = 0;
                $order_detail->id_shop = $order->id_shop;
                $order_detail->id_tax_rules_group = $product['id_tax_rules_group'] ?? 0;
                $order_detail->id_customization = $product['id_customization'] ?? 0;
                $order_detail->product_ean13 = $product['ean13'] ?? '';
                $order_detail->product_upc = $product['upc'] ?? '';
                $order_detail->product_reference = $product['reference'] ?? '';
                $order_detail->product_supplier_reference = $product['supplier_reference'] ?? '';
                $order_detail->product_weight = $product['weight'];
                $order_detail->id_warehouse = 0;
                $order_detail->unit_price_tax_excl = $product['price'];
                $order_detail->unit_price_tax_incl = $product['price_wt'];
                $order_detail->total_price_tax_excl = $product['total'];
                $order_detail->total_price_tax_incl = $product['total_wt'];
                $order_detail->total_shipping_price_tax_excl = 0;
                $order_detail->total_shipping_price_tax_incl = 0;
                $order_detail->purchase_supplier_price = 0;
                $order_detail->original_product_price = $product['price'];
                $order_detail->original_wholesale_price = $product['wholesale_price'];
                $order_detail->product_quantity_discount = 0;
                $order_detail->discount_quantity_applied = 0;
                $order_detail->discount_type = 'amount';
                $order_detail->discount_value = 0;
                $order_detail->discount_quantity = 0;
                $order_detail->id_order_invoice = 0;
                $order_detail->id_order_detail = 0;
                $order_detail->ecotax = 0;
                $order_detail->ecotax_tax_rate = 0;
                $order_detail->download_hash = '';
                $order_detail->download_nb = 0;
                $order_detail->download_deadline = '0000-00-00 00:00:00';
                
                if (!$order_detail->add()) {
                    error_log('UCP: Failed to add order detail for product ' . $product['id_product']);
                    return false;
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log('UCP: Exception adding order details: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mettre à jour les quantités de produits
     */
    private function updateProductQuantities($cart)
    {
        try {
            $products = $cart->getProducts();
            
            foreach ($products as $product) {
                // Utiliser une méthode plus simple pour mettre à jour le stock
                $sql = 'UPDATE ' . _DB_PREFIX_ . 'product 
                        SET quantity = quantity - ' . (int)$product['quantity'] . '
                        WHERE id_product = ' . (int)$product['id_product'];
                Db::getInstance()->execute($sql);
                
                // Mettre à jour aussi les attributs si nécessaire
                if ($product['id_product_attribute'] > 0) {
                    $sql = 'UPDATE ' . _DB_PREFIX_ . 'product_attribute 
                            SET quantity = quantity - ' . (int)$product['quantity'] . '
                            WHERE id_product = ' . (int)$product['id_product'] . '
                            AND id_product_attribute = ' . (int)$product['id_product_attribute'];
                    Db::getInstance()->execute($sql);
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log('UCP: Exception updating product quantities: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Créer un panier de test
     */
    private function createTestCart($customer_id)
    {
        try {
            $cart = new Cart();
            $cart->id_customer = $customer_id;
            $cart->id_currency = Configuration::get('PS_CURRENCY_DEFAULT');
            $cart->id_lang = Configuration::get('PS_LANG_DEFAULT');
            $cart->id_shop = 1;
            $cart->id_shop_group = 1;
            
            // Ajouter une adresse par défaut si nécessaire
            $address_id = $this->ensureCustomerAddress($customer_id);
            if ($address_id) {
                $cart->id_address_delivery = $address_id;
                $cart->id_address_invoice = $address_id;
            }
            
            // Définir un transporteur
            $cart->id_carrier = $this->getDefaultCarrier();
            
            if ($cart->add()) {
                // Ajouter des produits de test
                foreach ($this->session_data['line_items'] as $item) {
                    $cart->updateQty($item['quantity'], $item['product_id']);
                }
                
                // Mettre à jour le panier après ajout des produits
                $cart->update();
                
                return $cart->id;
            }
        } catch (Exception $e) {
            error_log('Test cart creation error: ' . $e->getMessage());
        }
        return false;
    }
}
