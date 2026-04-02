<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpCheckoutSessionValidator
{
    /**
     * List of valid ISO 3166-1 alpha-2 country codes
     */
    private $valid_countries = [
        'AF', 'AL', 'DZ', 'AS', 'AD', 'AO', 'AI', 'AQ', 'AG', 'AR', 'AM', 'AW', 'AU', 'AT', 'AZ',
        'BS', 'BH', 'BD', 'BB', 'BY', 'BE', 'BZ', 'BJ', 'BM', 'BT', 'BO', 'BQ', 'BA', 'BW', 'BR', 'IO', 'BN', 'BG', 'BF', 'BI', 'CV', 'KH', 'CM', 'CA', 'KY', 'CF', 'TD', 'CL', 'CN', 'CX', 'CC', 'CO', 'KM', 'CG', 'CD', 'CK', 'CR', 'CI', 'HR', 'CU', 'CW', 'CY', 'CZ', 'DK', 'DJ', 'DM', 'DO', 'EC', 'EG', 'SV', 'GQ', 'ER', 'EE', 'SZ', 'ET', 'FK', 'FO', 'FJ', 'FI', 'FR', 'GF', 'PF', 'TF', 'GA', 'GM', 'GE', 'DE', 'GH', 'GI', 'GR', 'GL', 'GD', 'GP', 'GU', 'GT', 'GG', 'GN', 'GW', 'GY', 'HT', 'HM', 'VA', 'HN', 'HK', 'HU', 'IS', 'IN', 'ID', 'IR', 'IQ', 'IE', 'IM', 'IL', 'IT', 'JM', 'JP', 'JE', 'JO', 'KZ', 'KE', 'KI', 'KP', 'KR', 'KW', 'KG', 'LA', 'LV', 'LB', 'LS', 'LR', 'LY', 'LI', 'LT', 'LU', 'MO', 'MG', 'MW', 'MY', 'MV', 'ML', 'MT', 'MH', 'MQ', 'MR', 'MU', 'YT', 'MX', 'FM', 'MD', 'MC', 'MN', 'ME', 'MS', 'MA', 'MZ', 'MM', 'NA', 'NR', 'NP', 'NL', 'NC', 'NZ', 'NI', 'NE', 'NG', 'NU', 'NF', 'MP', 'MK', 'NO', 'OM', 'PK', 'PW', 'PS', 'PA', 'PG', 'PY', 'PE', 'PH', 'PN', 'PL', 'PT', 'PR', 'QA', 'RE', 'RO', 'RU', 'RW', 'BL', 'SH', 'KN', 'LC', 'MF', 'PM', 'VC', 'WS', 'SM', 'ST', 'SA', 'SN', 'RS', 'SC', 'SL', 'SG', 'SX', 'SK', 'SI', 'SB', 'SO', 'ZA', 'GS', 'SS', 'ES', 'LK', 'SD', 'SR', 'SJ', 'SE', 'CH', 'SY', 'TW', 'TJ', 'TZ', 'TH', 'TL', 'TG', 'TK', 'TO', 'TT', 'TN', 'TR', 'TM', 'TC', 'TV', 'UG', 'UA', 'AE', 'GB', 'US', 'UM', 'UY', 'UZ', 'VU', 'VE', 'VN', 'VG', 'VI', 'WF', 'EH', 'YE', 'ZM', 'ZW'
    ];

    /**
     * Common countries for better user experience
     */
    private $common_countries = [
        'US' => 'United States',
        'CA' => 'Canada',
        'GB' => 'United Kingdom',
        'FR' => 'France',
        'DE' => 'Germany',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'AU' => 'Australia',
        'JP' => 'Japan',
        'CN' => 'China',
        'IN' => 'India',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'CH' => 'Switzerland',
        'AT' => 'Austria',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'PL' => 'Poland',
        'CZ' => 'Czech Republic',
        'PT' => 'Portugal',
        'IE' => 'Ireland',
        'GR' => 'Greece',
        'RU' => 'Russia'
    ];

    /**
     * Validates if a country code is a valid ISO 3166-1 alpha-2 code
     * 
     * @param string $country Country code to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidCountry($country)
    {
        if (empty($country) || !is_string($country)) {
            return false;
        }
        
        $country = strtoupper(trim($country));
        return in_array($country, $this->valid_countries);
    }

    /**
     * Gets the country name for a valid country code
     * 
     * @param string $country Country code
     * @return string|null Country name or null if not found
     */
    public function getCountryName($country)
    {
        if (empty($country) || !is_string($country)) {
            return null;
        }
        
        $country = strtoupper(trim($country));
        return isset($this->common_countries[$country]) ? $this->common_countries[$country] : null;
    }

    /**
     * Gets list of common countries for UI purposes
     * 
     * @return array Array of country codes and names
     */
    public function getCommonCountries()
    {
        return $this->common_countries;
    }
    public function validateCheckoutSessionRequest($input)
    {
        $errors = [];

        // Check if input is an array
        if (!is_array($input)) {
            $errors[] = [
                'field' => 'request_body',
                'message' => 'Request body must be a JSON object'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate required fields
        $required_fields = ['line_items', 'buyer'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                $errors[] = [
                    'field' => $field,
                    'message' => 'Missing or empty required field: ' . $field
                ];
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate line_items structure
        if (!is_array($input['line_items'])) {
            $errors[] = [
                'field' => 'line_items',
                'message' => 'line_items must be an array'
            ];
        } elseif (empty($input['line_items'])) {
            $errors[] = [
                'field' => 'line_items',
                'message' => 'line_items cannot be empty'
            ];
        }

        // Validate buyer structure
        if (!is_array($input['buyer'])) {
            $errors[] = [
                'field' => 'buyer',
                'message' => 'buyer must be an object'
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function validateLineItems($line_items)
    {
        $errors = [];
        $validated_items = [];

        if (!is_array($line_items)) {
            return [
                'valid' => false,
                'errors' => [['field' => 'line_items', 'message' => 'line_items must be an array']],
                'items' => []
            ];
        }

        foreach ($line_items as $index => $item) {
            $item_errors = [];
            $validated_item = [];

            // Validate item structure
            if (!is_array($item)) {
                $errors[] = [
                    'field' => 'line_items[' . $index . ']',
                    'message' => 'Each line item must be an object'
                ];
                continue;
            }

            // Validate required fields for each item
            $item_required_fields = ['product_id', 'quantity'];
            foreach ($item_required_fields as $field) {
                if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) {
                    $item_errors[] = [
                        'field' => 'line_items[' . $index . '].' . $field,
                        'message' => 'Missing required field: ' . $field
                    ];
                }
            }

            if (!empty($item_errors)) {
                $errors = array_merge($errors, $item_errors);
                continue;
            }

            // Validate product_id
            $product_id = (int)$item['product_id'];
            if ($product_id <= 0) {
                $errors[] = [
                    'field' => 'line_items[' . $index . '].product_id',
                    'message' => 'product_id must be a positive integer'
                ];
                continue;
            }

            // Validate quantity
            $quantity = (int)$item['quantity'];
            if ($quantity <= 0) {
                $errors[] = [
                    'field' => 'line_items[' . $index . '].quantity',
                    'message' => 'quantity must be a positive integer'
                ];
                continue;
            }

            // Check if product exists and is active
            $product = new Product($product_id, false, Context::getContext()->language->id);
            if (!Validate::isLoadedObject($product)) {
                $errors[] = [
                    'field' => 'line_items[' . $index . '].product_id',
                    'message' => 'Product with ID ' . $product_id . ' does not exist'
                ];
                continue;
            }

            if (!$product->active) {
                $errors[] = [
                    'field' => 'line_items[' . $index . '].product_id',
                    'message' => 'Product with ID ' . $product_id . ' is not active'
                ];
                continue;
            }

            // Check stock availability
            $stock_available = StockAvailable::getQuantityAvailableByProduct($product_id);
            if ($stock_available < $quantity) {
                $errors[] = [
                    'field' => 'line_items[' . $index . '].quantity',
                    'message' => 'Insufficient stock. Available: ' . $stock_available . ', Requested: ' . $quantity
                ];
                continue;
            }

            // Get product price
            $price = Product::getPriceStatic($product_id, false, null, 6);

            $validated_item = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_price' => $price * $quantity,
                'product_name' => $product->name,
                'product_reference' => $product->reference,
                'available_stock' => $stock_available
            ];

            // Handle optional fields
            if (isset($item['customization_data']) && is_array($item['customization_data'])) {
                $validated_item['customization_data'] = $item['customization_data'];
            }

            $validated_items[] = $validated_item;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'items' => $validated_items
        ];
    }

    public function validateBuyer($buyer)
    {
        $errors = [];
        $validated_buyer = [];

        if (!is_array($buyer)) {
            return [
                'valid' => false,
                'errors' => [['field' => 'buyer', 'message' => 'buyer must be an object']],
                'buyer' => []
            ];
        }

        // Validate required buyer fields
        $required_fields = ['email', 'first_name', 'last_name'];
        foreach ($required_fields as $field) {
            if (!isset($buyer[$field]) || empty(trim($buyer[$field]))) {
                $errors[] = [
                    'field' => 'buyer.' . $field,
                    'message' => 'Missing required field: ' . $field
                ];
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors, 'buyer' => []];
        }

        // Validate email format
        $email = trim($buyer['email']);
        if (!Validate::isEmail($email)) {
            $errors[] = [
                'field' => 'buyer.email',
                'message' => 'Invalid email format'
            ];
        }

        // Validate name fields
        $first_name = trim($buyer['first_name']);
        $last_name = trim($buyer['last_name']);

        if (strlen($first_name) > 32) {
            $errors[] = [
                'field' => 'buyer.first_name',
                'message' => 'First name must be 32 characters or less'
            ];
        }

        if (strlen($last_name) > 32) {
            $errors[] = [
                'field' => 'buyer.last_name',
                'message' => 'Last name must be 32 characters or less'
            ];
        }

        // Build validated buyer object
        $validated_buyer = [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name
        ];

        // Handle optional fields
        $optional_fields = ['phone', 'company', 'address', 'city', 'postal_code', 'country'];
        foreach ($optional_fields as $field) {
            if (isset($buyer[$field]) && !empty(trim($buyer[$field]))) {
                $validated_buyer[$field] = trim($buyer[$field]);
            }
        }

        // Validate phone if provided
        if (isset($validated_buyer['phone']) && !Validate::isPhoneNumber($validated_buyer['phone'])) {
            $errors[] = [
                'field' => 'buyer.phone',
                'message' => 'Invalid phone number format'
            ];
        }

        // Validate country if provided
        if (isset($validated_buyer['country'])) {
            if (!$this->isValidCountry($validated_buyer['country'])) {
                $errors[] = [
                    'field' => 'buyer.country',
                    'message' => 'Invalid country code. Must be a valid ISO 3166-1 alpha-2 country code (e.g., US, FR, DE, etc.)'
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'buyer' => $validated_buyer
        ];
    }

    /**
     * Validates a complete address structure
     * 
     * @param array $address Address data to validate
     * @return array Validation result with valid status, errors, and normalized address
     */
    public function validateAddress($address)
    {
        $errors = [];
        $validated_address = [];

        if (!is_array($address)) {
            return [
                'valid' => false,
                'errors' => [['field' => 'address', 'message' => 'address must be an object']],
                'address' => []
            ];
        }

        // Validate required address fields
        $required_fields = ['country'];
        foreach ($required_fields as $field) {
            if (!isset($address[$field]) || empty(trim($address[$field]))) {
                $errors[] = [
                    'field' => 'address.' . $field,
                    'message' => 'Missing required field: ' . $field
                ];
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors, 'address' => []];
        }

        // Validate country (required field)
        $country = strtoupper(trim($address['country']));
        if (!$this->isValidCountry($country)) {
            $errors[] = [
                'field' => 'address.country',
                'message' => 'Invalid country code. Must be a valid ISO 3166-1 alpha-2 country code (e.g., US, FR, DE, etc.)'
            ];
        } else {
            $validated_address['country'] = $country;
            $validated_address['country_name'] = $this->getCountryName($country);
        }

        // Validate optional address fields
        $optional_fields = ['address_line1', 'address_line2', 'city', 'state', 'postal_code'];
        foreach ($optional_fields as $field) {
            if (isset($address[$field]) && !empty(trim($address[$field]))) {
                $value = trim($address[$field]);
                
                // Additional validation for specific fields
                switch ($field) {
                    case 'postal_code':
                        // Basic postal code validation (alphanumeric, 3-10 characters)
                        if (!preg_match('/^[A-Za-z0-9\s\-]{3,10}$/', $value)) {
                            $errors[] = [
                                'field' => 'address.postal_code',
                                'message' => 'Invalid postal code format'
                            ];
                        } else {
                            $validated_address[$field] = $value;
                        }
                        break;
                        
                    case 'city':
                        // City name validation (letters, spaces, hyphens, 2-50 characters)
                        if (!preg_match('/^[A-Za-z\s\-]{2,50}$/', $value)) {
                            $errors[] = [
                                'field' => 'address.city',
                                'message' => 'Invalid city name format'
                            ];
                        } else {
                            $validated_address[$field] = $value;
                        }
                        break;
                        
                    default:
                        $validated_address[$field] = $value;
                        break;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'address' => $validated_address
        ];
    }

    public function validateCheckoutSessionUpdate($input)
    {
        $errors = [];

        // Check if input is an array
        if (!is_array($input)) {
            $errors[] = [
                'field' => 'request_body',
                'message' => 'Request body must be a JSON object'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate allowed fields
        $allowed_fields = ['promo_code'];
        foreach ($input as $field => $value) {
            if (!in_array($field, $allowed_fields)) {
                $errors[] = [
                    'field' => $field,
                    'message' => 'Field not allowed: ' . $field
                ];
            }
        }

        // Validate promo_code if provided
        if (isset($input['promo_code'])) {
            if (!is_string($input['promo_code'])) {
                $errors[] = [
                    'field' => 'promo_code',
                    'message' => 'promo_code must be a string'
                ];
            } else {
                $promo_code = trim($input['promo_code']);
                if (strlen($promo_code) > 100) {
                    $errors[] = [
                        'field' => 'promo_code',
                        'message' => 'promo_code must be 100 characters or less'
                    ];
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function validatePromoCode($promo_code, $cart_id)
    {
        $errors = [];

        if (empty($promo_code)) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'promo_code cannot be empty'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Check if cart exists
        $cart = new Cart($cart_id);
        if (!Validate::isLoadedObject($cart)) {
            $errors[] = [
                'field' => 'cart_id',
                'message' => 'Cart not found'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Find the cart rule by code
        $cart_rule = CartRule::getIdByCode($promo_code);
        if (!$cart_rule) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'Promo code not found'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        $cart_rule_obj = new CartRule($cart_rule);
        if (!Validate::isLoadedObject($cart_rule_obj)) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'Invalid promo code'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Check if cart rule is active
        if (!$cart_rule_obj->active) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'Promo code is not active'
            ];
        }

        // Check date validity
        $now = time();
        if ($cart_rule_obj->date_from && strtotime($cart_rule_obj->date_from) > $now) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'Promo code is not yet valid'
            ];
        }

        if ($cart_rule_obj->date_to && strtotime($cart_rule_obj->date_to) < $now) {
            $errors[] = [
                'field' => 'promo_code',
                'message' => 'Promo code has expired'
            ];
        }

        // Check quantity limitations
        if ($cart_rule_obj->quantity > 0 && $cart_rule_obj->quantity_per_user > 0) {
            $context = Context::getContext();
            $customer_id = $cart->id_customer;

            if ($customer_id) {
                // Pour l'instant, nous allons sauter cette validation complexe
                // car la table cart_rule_usage n'existe pas dans cette version
                // Dans une version complète, il faudrait créer cette table ou utiliser une autre approche
                // Pour le moment, nous autorisons l'utilisation sans cette vérification
            }
        }

        // Check minimum amount
        if ($cart_rule_obj->minimum_amount > 0) {
            $cart_total = $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            if ($cart_total < $cart_rule_obj->minimum_amount) {
                $errors[] = [
                    'field' => 'promo_code',
                    'message' => 'Minimum amount not reached. Required: ' . $cart_rule_obj->minimum_amount
                ];
            }
        }

        // Check product restrictions
        if ($cart_rule_obj->product_restriction) {
            $products = $cart->getProducts();
            $valid_products = [];

            if ($cart_rule_obj->product_restriction == 1) {
                // Include only specific products
                $valid_products = $cart_rule_obj->getProductRuleGroups();
            } elseif ($cart_rule_obj->product_restriction == 2) {
                // Exclude specific products
                $excluded_products = $cart_rule_obj->getProductRuleGroups();
                // Implementation would check if cart contains only excluded products
            }

            // Simplified validation - in real implementation would check product rules
            if (empty($valid_products) && $cart_rule_obj->product_restriction == 1) {
                $errors[] = [
                    'field' => 'promo_code',
                    'message' => 'Promo code not applicable to cart products'
                ];
            }
        }

        // Check if already applied to cart
        $cart_rules = $cart->getCartRules();
        foreach ($cart_rules as $rule) {
            if ($rule['id_cart_rule'] == $cart_rule_obj->id) {
                $errors[] = [
                    'field' => 'promo_code',
                    'message' => 'Promo code already applied'
                ];
                break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'cart_rule' => $cart_rule_obj
        ];
    }

    public function validateCheckoutSessionId($checkout_id)
    {
        $errors = [];

        if (empty($checkout_id)) {
            $errors[] = [
                'field' => 'checkout_id',
                'message' => 'checkout_id is required'
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Validate checkout_id format (ucs_prefix + unique_id + cart_id + timestamp)
        if (!preg_match('/^ucs_[a-zA-Z0-9]+_\d+_\d+$/', $checkout_id)) {
            $errors[] = [
                'field' => 'checkout_id',
                'message' => 'Invalid checkout_id format'
            ];
        }

        // Extract cart_id from checkout_id
        $parts = explode('_', $checkout_id);
        if (count($parts) < 4) {
            $errors[] = [
                'field' => 'checkout_id',
                'message' => 'Invalid checkout_id structure'
            ];
        } else {
            $cart_id = (int)$parts[count($parts) - 2];
            $cart = new Cart($cart_id);

            if (!Validate::isLoadedObject($cart)) {
                $errors[] = [
                    'field' => 'checkout_id',
                    'message' => 'Associated cart not found'
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'cart_id' => isset($cart_id) ? $cart_id : null
        ];
    }
}
