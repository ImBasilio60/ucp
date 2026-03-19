<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpCartManager
{
    private $context;

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    public function createCartWithItems($validated_items, $buyer, $customer_id = 0)
    {
        try {
            // Create new cart
            $cart = new Cart();
            $cart->id_shop_group = $this->context->shop->id_shop_group;
            $cart->id_shop = $this->context->shop->id;
            $cart->id_lang = $this->context->language->id;
            $cart->id_currency = $this->context->currency->id;

            // Set customer ID (0 for guest, customer ID for logged in)
            $cart->id_customer = (int)$customer_id;

            // Set cart as guest cart only if no customer ID
            if ($customer_id == 0) {
                $cart->id_guest = $this->createOrGetGuest($buyer['email']);
            }

            if (!$cart->add()) {
                return [
                    'success' => false,
                    'error' => 'Failed to create cart'
                ];
            }

            // Add products to cart
            foreach ($validated_items as $item) {
                $add_result = $this->addProductToCart($cart, $item);
                if (!$add_result['success']) {
                    // Clean up cart on failure
                    $cart->delete();
                    return [
                        'success' => false,
                        'error' => 'Failed to add product ' . $item['product_id'] . ': ' . $add_result['error']
                    ];
                }
            }

            // Update cart totals
            $cart->update();

            return [
                'success' => true,
                'cart_id' => $cart->id,
                'cart' => $cart
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function addProductToCart($cart, $item)
    {
        try {
            // Get product attributes
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $product_attribute_id = 0; // Default product combination

            // Check if product has combinations
            $product = new Product($product_id);
            if ($product->hasAttributes()) {
                // For products with attributes, we might need to handle combinations
                // For now, we'll use the default combination
                $combinations = $product->getAttributeCombinations($this->context->language->id);
                if (!empty($combinations)) {
                    $product_attribute_id = $combinations[0]['id_product_attribute'];
                }
            }

            // Add product to cart
            $update_quantity = false;
            $result = $cart->updateQty(
                $quantity,
                $product_id,
                $product_attribute_id,
                false,
                'up',
                0,
                null,
                false,
                true
            );

            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Failed to update cart quantity'
                ];
            }

            // Handle customization if provided
            if (isset($item['customization_data']) && !empty($item['customization_data'])) {
                $this->addCustomizationToCart($cart, $product_id, $product_attribute_id, $item['customization_data']);
            }

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function addCustomizationToCart($cart, $product_id, $product_attribute_id, $customization_data)
    {
        try {
            // Create customization
            $customization = new Customization();
            $customization->id_product_attribute = $product_attribute_id;
            $customization->id_cart = $cart->id;
            $customization->quantity = 1;
            $customization->in_cart = true;

            if (!$customization->add()) {
                throw new Exception('Failed to create customization');
            }

            // Add customization fields if provided
            if (isset($customization_data['fields']) && is_array($customization_data['fields'])) {
                foreach ($customization_data['fields'] as $index => $field) {
                    if (isset($field['value']) && !empty($field['value'])) {
                        $customization_field = new CustomizationField();
                        $customization_field->id_customization = $customization->id;
                        $customization_field->id_product = $product_id;
                        $customization_field->type = (isset($field['type']) ? $field['type'] : 0); // 0 = text field
                        $customization_field->required = (isset($field['required']) ? $field['required'] : 0);
                        $customization_field->is_module = 0;

                        if ($customization_field->add()) {
                            // Add customization value
                            $customization_value = new CustomizationValue();
                            $customization_value->id_customization = $customization->id;
                            $customization_value->id_customization_field = $customization_field->id;
                            $customization_value->value = $field['value'];
                            $customization_value->add();
                        }
                    }
                }
            }

        } catch (Exception $e) {
            // Log customization error but don't fail the whole process
            PrestaShopLogger::addLog(
                'UCP Customization Error: ' . $e->getMessage(),
                2, // Warning level
                null,
                'UcpWellKnown',
                0,
                true
            );
        }
    }

    private function createOrGetGuest($email)
    {
        // Create new guest (simplified approach)
        $guest = new Guest();
        $guest->email = $email;

        if ($guest->add()) {
            return $guest->id;
        }

        throw new Exception('Failed to create guest customer');
    }

    public function calculateCartTotals($cart_id)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Cart not found');
            }

            // Get cart products
            $products = $cart->getProducts();

            $subtotal = 0;
            $total_tax = 0;
            $total_shipping = 0;
            $total_discount = 0;
            $total = 0;

            // Calculate products total
            foreach ($products as $product) {
                $product_total = $product['total_wt']; // Price with tax
                $product_price = $product['total']; // Price without tax
                $subtotal += $product_price;
                $total_tax += ($product_total - $product_price);
            }

            // Get shipping costs
            $total_shipping = $cart->getTotalShippingCost();

            // Get discounts
            $total_discount = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);

            // Calculate final total
            $total = $cart->getOrderTotal(true);

            return [
                'subtotal' => [
                    'amount' => (float)$subtotal,
                    'currency' => $this->context->currency->iso_code,
                    'formatted' => number_format($subtotal, 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                ],
                'tax' => [
                    'amount' => (float)$total_tax,
                    'currency' => $this->context->currency->iso_code,
                    'formatted' => number_format($total_tax, 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                ],
                'shipping' => [
                    'amount' => (float)$total_shipping,
                    'currency' => $this->context->currency->iso_code,
                    'formatted' => number_format($total_shipping, 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                ],
                'discount' => [
                    'amount' => (float)$total_discount,
                    'currency' => $this->context->currency->iso_code,
                    'formatted' => number_format($total_discount, 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                ],
                'total' => [
                    'amount' => (float)$total,
                    'currency' => $this->context->currency->iso_code,
                    'formatted' => number_format($total, 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                ],
                'items_count' => count($products),
                'items_quantity' => $cart->nbProducts()
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to calculate cart totals: ' . $e->getMessage());
        }
    }

    public function getCartDetails($cart_id)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                throw new Exception('Cart not found');
            }

            $products = $cart->getProducts();
            $cart_products = [];

            foreach ($products as $product) {
                $cart_products[] = [
                    'product_id' => (int)$product['id_product'],
                    'product_attribute_id' => (int)$product['id_product_attribute'],
                    'name' => $product['name'],
                    'reference' => $product['reference'],
                    'quantity' => (int)$product['quantity'],
                    'unit_price' => [
                        'amount' => (float)$product['price'],
                        'currency' => $this->context->currency->iso_code,
                        'formatted' => number_format($product['price'], 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                    ],
                    'unit_price_with_tax' => [
                        'amount' => (float)$product['price_wt'],
                        'currency' => $this->context->currency->iso_code,
                        'formatted' => number_format($product['price_wt'], 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                    ],
                    'total' => [
                        'amount' => (float)$product['total'],
                        'currency' => $this->context->currency->iso_code,
                        'formatted' => number_format($product['total'], 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                    ],
                    'total_with_tax' => [
                        'amount' => (float)$product['total_wt'],
                        'currency' => $this->context->currency->iso_code,
                        'formatted' => number_format($product['total_wt'], 2, ',', ' ') . ' ' . $this->context->currency->iso_code
                    ]
                ];
            }

            return [
                'cart_id' => (int)$cart->id,
                'products' => $cart_products,
                'totals' => $this->calculateCartTotals($cart_id),
                'created_at' => date('c', strtotime($cart->date_add)),
                'updated_at' => date('c', strtotime($cart->date_upd))
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to get cart details: ' . $e->getMessage());
        }
    }

    public function getCartByCheckoutSessionId($sid)
    {
        // Valider le format de l'ID de session
        if (!preg_match('/^ucs_[a-zA-Z0-9]+_\d+_\d+$/', $sid)) {
            return [
                'success' => false,
                'error' => 'Invalid checkout session ID format'
            ];
        }

        // Extract cart_id from checkout session ID
        $parts = explode('_', $sid);
        if (count($parts) < 4) {
            return [
                'success' => false,
                'error' => 'Invalid checkout session ID structure'
            ];
        }

        $cart_id = (int)$parts[count($parts) - 2];

        // Verify cart exists
        $cart = new Cart($cart_id);
        if (!Validate::isLoadedObject($cart)) {
            return [
                'success' => false,
                'error' => 'Cart not found'
            ];
        }

        return [
            'success' => true,
            'cart_id' => $cart_id,
            'cart' => $cart
        ];
    }

    public function applyPromoCode($cart_id, $promo_code)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return [
                    'success' => false,
                    'error' => 'Cart not found'
                ];
            }

            // Get cart rule ID from code
            $cart_rule_id = CartRule::getIdByCode($promo_code);
            if (!$cart_rule_id) {
                return [
                    'success' => false,
                    'error' => 'Promo code not found'
                ];
            }

            $cart_rule = new CartRule($cart_rule_id);
            if (!Validate::isLoadedObject($cart_rule)) {
                return [
                    'success' => false,
                    'error' => 'Invalid promo code'
                ];
            }

            // Check if already applied to cart
            $cart_rules = $cart->getCartRules();
            foreach ($cart_rules as $rule) {
                if ($rule['id_cart_rule'] == $cart_rule_id) {
                    return [
                        'success' => false,
                        'error' => 'Promo code already applied'
                    ];
                }
            }

            // Try to add cart rule directly without complex validation
            $result = $cart->addCartRule($cart_rule_id);
            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Failed to apply promo code to cart'
                ];
            }

            // Update cart to recalculate totals
            $cart->update();

            return [
                'success' => true,
                'cart_rule_id' => $cart_rule_id,
                'discount_amount' => $cart_rule->reduction_amount,
                'discount_type' => $cart_rule->reduction_percent ? 'percentage' : 'amount'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function removePromoCode($cart_id, $promo_code = null)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return [
                    'success' => false,
                    'error' => 'Cart not found'
                ];
            }

            $cart_rules = $cart->getCartRules();
            $removed = false;

            if ($promo_code) {
                // Remove specific promo code
                $cart_rule_id = CartRule::getIdByCode($promo_code);
                if ($cart_rule_id) {
                    foreach ($cart_rules as $rule) {
                        if ($rule['id_cart_rule'] == $cart_rule_id) {
                            $cart->removeCartRule($cart_rule_id);
                            $removed = true;
                            break;
                        }
                    }
                }

                if (!$removed) {
                    return [
                        'success' => false,
                        'error' => 'Promo code not found in cart'
                    ];
                }
            } else {
                // Remove all promo codes
                foreach ($cart_rules as $rule) {
                    $cart->removeCartRule($rule['id_cart_rule']);
                    $removed = true;
                }
            }

            if ($removed) {
                // Update cart to recalculate totals
                $cart->update();
            }

            return [
                'success' => true,
                'removed' => $removed
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getAppliedRules($cart_id)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return [];
            }

            $cart_rules = $cart->getCartRules();
            $applied_rules = [];

            foreach ($cart_rules as $rule) {
                $cart_rule = new CartRule($rule['id_cart_rule']);
                if (Validate::isLoadedObject($cart_rule)) {
                    $applied_rules[] = [
                        'id' => $cart_rule->id,
                        'name' => $cart_rule->name,
                        'code' => $cart_rule->code,
                        'description' => $cart_rule->description,
                        'discount_type' => $cart_rule->reduction_percent ? 'percentage' : 'amount',
                        'discount_value' => $cart_rule->reduction_percent ?: $cart_rule->reduction_amount,
                        'free_shipping' => $cart_rule->free_shipping,
                        'applied_at' => date('c', strtotime($rule['date_add']))
                    ];
                }
            }

            return $applied_rules;

        } catch (Exception $e) {
            return [];
        }
    }

    public function deleteCart($cart_id)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return ['success' => false, 'error' => 'Cart not found'];
            }

            if ($cart->delete()) {
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Failed to delete cart'];
            }

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
