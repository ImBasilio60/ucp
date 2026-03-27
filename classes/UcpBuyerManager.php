<?php

class UcpBuyerManager
{
    private $errors = [];

    /**
     * Gère l'identité du buyer lors de la création d'une checkout session
     *
     * @param array $buyer_data Données du buyer depuis le payload
     * @param array $headers En-têtes UCP pour validation
     * @return array Résultat avec customer_id et informations
     */
    public function handleBuyerIdentity($buyer_data, $headers)
    {
        try {
            // 1. Validation du payload buyer
            $validation = $this->validateBuyerPayload($buyer_data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid buyer information',
                    'code' => 400,
                    'details' => $validation['errors']
                ];
            }

            // 2. Validation de l'authentification UCP
            $auth_validation = $this->validateUcpAuthentication($headers);
            if (!$auth_validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                    'code' => 401,
                    'details' => $auth_validation['errors']
                ];
            }

            // 3. Validation idempotence
            $idempotency_validation = $this->validateIdempotency($headers);
            if (!$idempotency_validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Idempotency validation failed',
                    'code' => 409,
                    'details' => $idempotency_validation['errors']
                ];
            }

            // 4. Normalisation des données
            $normalized_buyer = $this->normalizeBuyerData($buyer_data);

            // 5. Récupération ou création du client
            $customer_result = $this->getOrCreateCustomer($normalized_buyer);

            if (!$customer_result['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to manage customer',
                    'code' => 500,
                    'details' => $customer_result['errors']
                ];
            }

            // 6. Log de l'opération
            $this->logBuyerOperation($customer_result['customer_id'], $normalized_buyer, $headers);

            return [
                'success' => true,
                'customer_id' => $customer_result['customer_id'],
                'customer_data' => $customer_result['customer_data'],
                'is_new_customer' => $customer_result['is_new_customer'],
                'buyer_info' => $normalized_buyer
            ];

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'UCP Buyer Manager Error: ' . $e->getMessage(),
                3, // Error level
                null,
                'UCP',
                0,
                true
            );

            return [
                'success' => false,
                'error' => 'Internal server error',
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validation du payload buyer
     */
    private function validateBuyerPayload($buyer_data)
    {
        $this->errors = [];

        // Vérifier si le champ buyer est présent
        if (empty($buyer_data)) {
            $this->errors[] = 'buyer information is required';
            return ['valid' => false, 'errors' => $this->errors];
        }

        // Champs obligatoires
        $required_fields = ['email', 'first_name', 'last_name', 'address', 'city', 'postal_code', 'country'];
        foreach ($required_fields as $field) {
            if (empty($buyer_data[$field])) {
                $this->errors[] = "Field '{$field}' is required in buyer information";
            }
        }

        // Validation email
        if (!empty($buyer_data['email'])) {
            if (!filter_var($buyer_data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = 'Invalid email format';
            }
        }

        // Validation téléphone si présent
        if (!empty($buyer_data['phone'])) {
            $phone = preg_replace('/[^0-9+]/', '', $buyer_data['phone']);
            if (strlen($phone) < 10) {
                $this->errors[] = 'Phone number must be at least 10 digits';
            }
        }

        // Validation code postal (obligatoire maintenant)
        if (!empty($buyer_data['postal_code'])) {
            if (!preg_match('/^[0-9]{3,10}$/', $buyer_data['postal_code'])) {
                $this->errors[] = 'Postal code must contain only digits (3-10 characters)';
            }
        }

        return empty($this->errors) ?
            ['valid' => true] :
            ['valid' => false, 'errors' => $this->errors];
    }

    /**
     * Validation de l'authentification UCP
     */
    private function validateUcpAuthentication($headers)
    {
        $this->errors = [];

        // Vérifier UCP-Agent
        if (empty($headers['ucp-agent'])) {
            $this->errors[] = 'UCP-Agent header is required';
        }

        // Vérifier request-signature
        if (empty($headers['request-signature'])) {
            $this->errors[] = 'request-signature header is required';
        } else {
            // Validation basique de la signature (format)
            if (empty($headers['request-signature'])) {
                $this->errors[] = 'Invalid request-signature format';
            }
        }

        // Accepter tous les agents UCP (vérification simple de présence)
        if (empty($headers['ucp-agent'])) {
            $this->errors[] = 'UCP-Agent header is required';
        }

        return empty($this->errors) ?
            ['valid' => true] :
            ['valid' => false, 'errors' => $this->errors];
    }

    /**
     * Validation idempotence
     */
    private function validateIdempotency($headers)
    {
        $this->errors = [];

        if (empty($headers['idempotency-key'])) {
            $this->errors[] = 'idempotency-key header is required';
            return ['valid' => false, 'errors' => $this->errors];
        }

        // Pour l'instant, nous allons simplement valider que la clé existe
        // Dans une vraie implémentation, on utiliserait une base de données ou Redis
        $idempotency_key = $headers['idempotency-key'];

        // Validation basique du format de la clé
        if (strlen($idempotency_key) < 8) {
            $this->errors[] = 'idempotency-key must be at least 8 characters long';
        }

        return empty($this->errors) ?
            ['valid' => true] :
            ['valid' => false, 'errors' => $this->errors];
    }

    /**
     * Normalisation des données buyer
     */
    private function normalizeBuyerData($buyer_data)
    {
        return [
            'email' => strtolower(trim($buyer_data['email'])),
            'first_name' => trim(ucwords(strtolower($buyer_data['first_name']))),
            'last_name' => trim(ucwords(strtolower($buyer_data['last_name']))),
            'phone' => !empty($buyer_data['phone']) ? trim($buyer_data['phone']) : null,
            'company' => !empty($buyer_data['company']) ? trim($buyer_data['company']) : null,
            'address' => trim($buyer_data['address']), // Obligatoire maintenant
            'city' => trim(ucwords(strtolower($buyer_data['city']))), // Obligatoire maintenant
            'postal_code' => trim($buyer_data['postal_code']), // Obligatoire maintenant
            'country' => trim(strtoupper($buyer_data['country'])) // Obligatoire maintenant
        ];
    }

    /**
     * Récupération ou création du client
     */
    private function getOrCreateCustomer($normalized_buyer)
    {
        try {
            // 1. Vérifier si un client avec cet email existe
            $existing_customer = $this->findCustomerByEmail($normalized_buyer['email']);

            if ($existing_customer) {
                // Client existe déjà - vérifier si on peut le réutiliser
                $reuse_validation = $this->validateCustomerReuse($existing_customer, $normalized_buyer);

                if (!$reuse_validation['valid']) {
                    return [
                        'success' => false,
                        'errors' => $reuse_validation['errors']
                    ];
                }

                // Mettre à jour les informations si nécessaire (sans écraser)
                $updated_customer = $this->updateCustomerSafely($existing_customer, $normalized_buyer);

                return [
                    'success' => true,
                    'customer_id' => $updated_customer->id,
                    'customer_data' => $this->formatCustomerData($updated_customer),
                    'is_new_customer' => false
                ];
            }

            // 2. Créer un nouveau client
            $new_customer_result = $this->createNewCustomer($normalized_buyer);

            if (!$new_customer_result) {
                return [
                    'success' => false,
                    'errors' => ['Failed to create new customer']
                ];
            }

            // Vérifier si la création a échoué à cause de l'adresse
            if (isset($new_customer_result['success']) && !$new_customer_result['success']) {
                return $new_customer_result;
            }

            $new_customer = $new_customer_result['customer'];

            return [
                'success' => true,
                'customer_id' => $new_customer->id,
                'customer_data' => $this->formatCustomerData($new_customer),
                'is_new_customer' => true
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => ['Database error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Rechercher un client par email
     */
    private function findCustomerByEmail($email)
    {
        // Utiliser une requête SQL directe pour trouver le client
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'customer WHERE email = "' . pSQL($email) . '" AND deleted = 0';
        $result = Db::getInstance()->getRow($sql);

        if ($result) {
            // Créer l'objet Customer proprement
            $customer = new Customer($result['id_customer']);
            
            if (Validate::isLoadedObject($customer)) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * Valider si on peut réutiliser un client existant
     */
    private function validateCustomerReuse($customer, $normalized_buyer)
    {
        $this->errors = [];

        // Vérifier que le nom correspond (sécurité)
        if (!empty($customer->firstname) && !empty($normalized_buyer['first_name'])) {
            if (strtolower($customer->firstname) !== strtolower($normalized_buyer['first_name'])) {
                $this->errors[] = 'First name does not match existing customer';
            }
        }

        if (!empty($customer->lastname) && !empty($normalized_buyer['last_name'])) {
            if (strtolower($customer->lastname) !== strtolower($normalized_buyer['last_name'])) {
                $this->errors[] = 'Last name does not match existing customer';
            }
        }

        // Vérifier si le client est actif
        if (!$customer->active) {
            $this->errors[] = 'Existing customer account is disabled';
        }

        // Vérifier si le client n'est pas supprimé
        if ($customer->deleted) {
            $this->errors[] = 'Existing customer account is deleted';
        }

        return empty($this->errors) ?
            ['valid' => true] :
            ['valid' => false, 'errors' => $this->errors];
    }

    /**
     * Mettre à jour le client en toute sécurité (sans écraser de données importantes)
     */
    private function updateCustomerSafely($customer, $normalized_buyer)
    {
        $updated = false;

        // Mettre à jour seulement les champs vides ou si les nouvelles données sont plus complètes
        if (empty($customer->phone) && !empty($normalized_buyer['phone'])) {
            $customer->phone = $normalized_buyer['phone'];
            $updated = true;
        }

        if (empty($customer->company) && !empty($normalized_buyer['company'])) {
            $customer->company = $normalized_buyer['company'];
            $updated = true;
        }

        // S'assurer que le mot de passe n'est pas vide pour la mise à jour
        if (empty($customer->passwd)) {
            $customer->passwd = md5(time() . uniqid());
            $updated = true;
        }

        if ($updated) {
            $customer->update();
        }

        return $customer;
    }

    /**
     * Créer un nouveau client
     */
    private function createNewCustomer($normalized_buyer)
    {
        try {
            $customer = new Customer();

            $customer->email = $normalized_buyer['email'];
            $customer->firstname = $normalized_buyer['first_name'];
            $customer->lastname = $normalized_buyer['last_name'];
            $customer->phone = $normalized_buyer['phone'];
            $customer->company = $normalized_buyer['company'];
            $customer->passwd = substr(md5(time() . uniqid()), 0, 32); // Mot de passe aléatoire
            $customer->active = 1;
            $customer->is_guest = 0; // Client normal, pas invité

            // Définir le groupe par défaut (vérifier qu'il existe)
            $default_group = Configuration::get('PS_CUSTOMER_GROUP');
            if (!$default_group) {
                $default_group = 3; // Groupe client par défaut dans PrestaShop
            }
            $customer->id_default_group = $default_group;

            // Sauvegarder d'abord le client pour obtenir l'ID
            if (!$customer->add()) {
                return null;
            }
            
            // Ajouter le client au groupe
            $customer->addGroups([$default_group]);

            // Créer l'adresse après la sauvegarde du client
            if (!empty($normalized_buyer['address']) && !empty($normalized_buyer['city'])) {
                $address_result = $this->createCustomerAddress($customer->id, $normalized_buyer);
                
                // Vérifier si la création d'adresse a échoué
                if (!$address_result['success']) {
                    // Supprimer le client créé si l'adresse échoue
                    $customer->delete();
                    return [
                        'success' => false,
                        'errors' => $address_result['details'] ?? [$address_result['error']]
                    ];
                }
            }

            return [
                'success' => true,
                'customer' => $customer
            ];

        } catch (Exception $e) {
            error_log('UCP: Error creating new customer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Créer une adresse pour le client
     */
    private function createCustomerAddress($customer_id, $normalized_buyer)
    {
        try {
            $address = new Address();

            $address->id_customer = $customer_id;
            $address->firstname = $normalized_buyer['first_name'];
            $address->lastname = $normalized_buyer['last_name'];
            $address->address1 = $normalized_buyer['address'];
            $address->city = $normalized_buyer['city'];
            $address->postcode = $normalized_buyer['postal_code'];
            $address->phone = $normalized_buyer['phone'];
            $address->company = $normalized_buyer['company'];

            // Récupérer l'ID du pays avec validation stricte
            $country_id = null;
            if (!empty($normalized_buyer['country'])) {
                $country_input = strtoupper(trim($normalized_buyer['country']));
                
                // Essayer de trouver le pays par code ISO d'abord
                $sql = 'SELECT id_country FROM ' . _DB_PREFIX_ . 'country WHERE iso_code = "' . pSQL($country_input) . '"';
                $result = Db::getInstance()->getValue($sql);
                if ($result) {
                    $country_id = $result;
                } else {
                    // Si non trouvé, essayer avec une table de conversion nom->ISO
                    $country_mapping = [
                        'FRANCE' => 'FR',
                        'BELGIQUE' => 'BE',
                        'SUISSE' => 'CH',
                        'ESPAGNE' => 'ES',
                        'ITALIE' => 'IT',
                        'MADAGASCAR' => 'MG',
                        'GERMANY' => 'DE',
                        'DEUTSCHLAND' => 'DE',
                        'ALLEMAGNE' => 'DE',
                        'UNITED KINGDOM' => 'GB',
                        'UK' => 'GB',
                        'ROYAUME-UNI' => 'GB',
                        'PORTUGAL' => 'PT',
                        'NETHERLANDS' => 'NL',
                        'PAYS-BAS' => 'NL',
                        'LUXEMBOURG' => 'LU',
                        'AUSTRIA' => 'AT',
                        'AUTRICHE' => 'AT',
                        'CANADA' => 'CA',
                        'CHINA' => 'CN',
                        'CHINE' => 'CN',
                        'JAPAN' => 'JP',
                        'JAPON' => 'JP',
                        'POLAND' => 'PL',
                        'POLOGNE' => 'PL',
                        'GREECE' => 'GR',
                        'GRÈCE' => 'GR',
                        'FINLAND' => 'FI',
                        'FINLANDE' => 'FI',
                        'SWEDEN' => 'SE',
                        'SUÈDE' => 'SE',
                        'DENMARK' => 'DK',
                        'DANEMARK' => 'DK',
                        'CZECH REPUBLIC' => 'CZ',
                        'RÉPUBLIQUE TCHÈQUE' => 'CZ'
                    ];
                    
                    $iso_code = $country_mapping[$country_input] ?? null;
                    if ($iso_code) {
                        $sql = 'SELECT id_country FROM ' . _DB_PREFIX_ . 'country WHERE iso_code = "' . pSQL($iso_code) . '"';
                        $result = Db::getInstance()->getValue($sql);
                        if ($result) {
                            $country_id = $result;
                        }
                    }
                }
                
                // Si le pays n'est toujours pas trouvé, retourner une erreur avec la liste des pays valides
                if (!$country_id) {
                    // Récupérer la liste de tous les pays valides
                    $sql_countries = 'SELECT iso_code FROM ' . _DB_PREFIX_ . 'country WHERE active = 1 ORDER BY iso_code';
                    $valid_countries = Db::getInstance()->executeS($sql_countries);
                    
                    $country_list = array_map(function($country) {
                        return $country['iso_code'];
                    }, $valid_countries);
                    
                    // Ajouter les noms complets supportés
                    $supported_names = array_keys($country_mapping);
                    $full_list = array_merge($country_list, $supported_names);
                    sort($full_list);
                    
                    // Retourner une erreur structurée
                    return [
                        'success' => false,
                        'error' => 'Invalid country',
                        'code' => 400,
                        'details' => [
                            'Invalid country "' . $normalized_buyer['country'] . '". Valid countries are: ' . implode(', ', $full_list)
                        ]
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error' => 'Missing country',
                    'code' => 400,
                    'details' => [
                        'Country is required'
                    ]
                ];
            }
            
            $address->id_country = $country_id;

            // Définir comme adresse par défaut
            $address->alias = 'My Address';

            if ($address->add()) {
                // Logger la création d'adresse
                PrestaShopLogger::addLog(
                    'UCP Address Created: Customer ID ' . $customer_id . ', Address ID ' . $address->id,
                    1, // Info level
                    null,
                    'UCP',
                    0,
                    true
                );
                return ['success' => true, 'address_id' => $address->id];
            }

            return ['success' => false, 'error' => 'Failed to create address'];

        } catch (Exception $e) {
            // Log simple
            file_put_contents(
                dirname(__FILE__) . '/../logs/ucp_errors.log',
                'UCP Address Creation Error: ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
            return ['success' => false, 'error' => 'Address creation error: ' . $e->getMessage()];
        }
    }

    /**
     * Formater les données du client pour la réponse
     */
    private function formatCustomerData($customer)
    {
        // Obtenir l'adresse par défaut du client
        $default_address_id = 0;
        $addresses = $customer->getAddresses(Configuration::get('PS_LANG_DEFAULT'));
        if (!empty($addresses)) {
            $default_address_id = $addresses[0]['id_address'];
        }
        
        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'first_name' => $customer->firstname,
            'last_name' => $customer->lastname,
            'phone' => $customer->phone,
            'company' => $customer->company,
            'is_guest' => (bool)$customer->is_guest,
            'date_add' => $customer->date_add,
            'default_address_id' => $default_address_id
        ];
    }

    /**
     * Logger l'opération buyer
     */
    private function logBuyerOperation($customer_id, $buyer_data, $headers)
    {
        // Créer un log simple si PrestaShopLogger n'est pas disponible
        $log_message = sprintf(
            '[%s] UCP Buyer Operation: customer_id=%d, email=%s, request_id=%s, ucp_agent=%s',
            date('Y-m-d H:i:s'),
            $customer_id,
            $buyer_data['email'],
            $headers['request-id'] ?? 'unknown',
            $headers['ucp-agent'] ?? 'unknown'
        );

        // Essayer d'écrire dans un fichier log, mais ignorer les erreurs de permissions
        $log_file = dirname(__FILE__) . '/../logs/ucp_buyer.log';
        @file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Obtenir les erreurs
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Créer un client pour les tests (bypass authentification)
     */
    public function createCustomerForTest($buyer_data)
    {
        try {
            // Normaliser les données
            $normalized_buyer = $this->normalizeBuyerData($buyer_data);
            
            // Créer le client
            $customer_result = $this->getOrCreateCustomer($normalized_buyer);
            
            return $customer_result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => ['Test customer creation error: ' . $e->getMessage()]
            ];
        }
    }
}
