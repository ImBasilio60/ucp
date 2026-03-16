<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpOrderConverter
{
    private $context;
    private $default_language_id;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->default_language_id = (int) Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * Convert a PrestaShop Cart to UCP Order format
     * 
     * @param Cart $cart PrestaShop cart object
     * @param int $language_id Language ID (optional, uses default if not provided)
     * @return array UCP Order structure
     */
    public function convertCartToUcpOrder($cart, $language_id = null)
    {
        $language_id = $language_id ?: $this->default_language_id;
        
        if (!Validate::isLoadedObject($cart)) {
            throw new Exception("Invalid cart object provided");
        }

        // Get currency
        $currency = new Currency($cart->id_currency);
        
        // Build UCP Order structure
        $ucp_order = [
            'id' => (string) $cart->id,
            'status' => $this->getOrderStatus($cart),
            'customer' => $this->getCustomerInfo($cart, $language_id),
            'lines' => $this->getOrderLines($cart, $language_id),
            'shipping' => $this->getShippingInfo($cart, $language_id),
            'discounts' => $this->getDiscounts($cart, $language_id),
            'totals' => $this->calculateTotals($cart, $currency),
            'metadata' => [
                'prestashop_cart_id' => (int) $cart->id,
                'currency' => $currency->iso_code,
                'language' => $language_id,
                'created_at' => $cart->date_add,
                'updated_at' => $cart->date_upd,
                'guest_mode' => (bool) $cart->id_guest,
                'secure_key' => $cart->secure_key
            ]
        ];

        return $ucp_order;
    }

    /**
     * Get order status based on cart state
     */
    private function getOrderStatus($cart)
    {
        // Cart doesn't have order status, but we can infer from cart state
        if ($cart->orderExists()) {
            return 'converted';
        } elseif ($cart->nbProducts() > 0) {
            return 'active';
        } else {
            return 'empty';
        }
    }

    /**
     * Get customer information
     */
    private function getCustomerInfo($cart, $language_id)
    {
        $customer = new Customer($cart->id_customer);
        
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }

        return [
            'id' => (string) $customer->id,
            'email' => $customer->email,
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'metadata' => [
                'prestashop_customer_id' => (int) $customer->id,
                'birthday' => $customer->birthday,
                'newsletter' => (bool) $customer->newsletter,
                'optin' => (bool) $customer->optin
            ]
        ];
    }

    /**
     * Get order lines from cart products
     */
    private function getOrderLines($cart, $language_id)
    {
        $lines = [];
        $products = $cart->getProducts();
        
        foreach ($products as $product) {
            // Get product details
            $product_obj = new Product($product['id_product'], false, $language_id);
            
            // Calculate taxes for this line
            $tax_calculator = $product_obj->getTaxesRate(new Address($cart->id_address_delivery));
            $tax_rate = (float) $tax_calculator;
            
            $unit_price_tax_excl = (float) $product['price'];
            $unit_price_tax_incl = (float) $product['price_wt'];
            $total_price_tax_excl = (float) $product['total'];
            $total_price_tax_incl = (float) $product['total_wt'];
            
            $line = [
                'item_id' => (string) $product['id_product'],
                'variant_id' => isset($product['id_product_attribute']) ? (string) $product['id_product_attribute'] : null,
                'title' => $product['name'],
                'description' => $product_obj->description ?: $product_obj->description_short ?: '',
                'quantity' => (int) $product['quantity'],
                'unit_price' => [
                    'amount' => $unit_price_tax_incl,
                    'currency' => $this->context->currency->iso_code,
                    'tax_exclusive' => $unit_price_tax_excl,
                    'tax_inclusive' => $unit_price_tax_incl
                ],
                'total_price' => [
                    'amount' => $total_price_tax_incl,
                    'currency' => $this->context->currency->iso_code,
                    'tax_exclusive' => $total_price_tax_excl,
                    'tax_inclusive' => $total_price_tax_incl
                ],
                'taxes' => [
                    [
                        'name' => 'VAT',
                        'rate' => $tax_rate,
                        'amount' => $total_price_tax_incl - $total_price_tax_excl
                    ]
                ],
                'metadata' => [
                    'prestashop_product_id' => (int) $product['id_product'],
                    'prestashop_attribute_id' => isset($product['id_product_attribute']) ? (int) $product['id_product_attribute'] : null,
                    'reference' => $product['reference'] ?: '',
                    'ean13' => $product['ean13'] ?: '',
                    'upc' => $product['upc'] ?: '',
                    'weight' => (float) $product['weight'],
                    'is_virtual' => (bool) $product['is_virtual'],
                    'downloadable' => (bool) $product['is_virtual']
                ]
            ];

            // Add variant information if applicable
            if (isset($product['id_product_attribute']) && $product['id_product_attribute']) {
                $combination = new Combination($product['id_product_attribute']);
                if (Validate::isLoadedObject($combination)) {
                    $line['variant'] = $this->getVariantInfo($combination, $language_id);
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Get variant information for product combinations
     */
    private function getVariantInfo($combination, $language_id)
    {
        $attributes = $combination->getAttributesName($language_id);
        
        return [
            'id' => (string) $combination->id,
            'reference' => $combination->reference ?: '',
            'ean13' => $combination->ean13 ?: '',
            'upc' => $combination->upc ?: '',
            'weight' => (float) $combination->weight,
            'attributes' => array_map(function($attr) {
                return [
                    'group' => $attr['group'] ?? '',
                    'name' => $attr['name'] ?? '',
                    'group_id' => (string) ($attr['id_attribute_group'] ?? 0),
                    'attribute_id' => (string) ($attr['id_attribute'] ?? 0)
                ];
            }, $attributes)
        ];
    }

    /**
     * Get shipping information
     */
    private function getShippingInfo($cart, $language_id)
    {
        $shipping = [];
        
        // Get shipping costs
        $total_shipping_tax_incl = $cart->getTotalShippingCost();
        $total_shipping_tax_excl = $cart->getTotalShippingCost(null, false);
        
        if ($total_shipping_tax_incl > 0) {
            $carrier = new Carrier($cart->id_carrier);
            
            $shipping = [
                'method' => Validate::isLoadedObject($carrier) ? $carrier->name : 'Standard Shipping',
                'cost' => [
                    'amount' => $total_shipping_tax_incl,
                    'currency' => $this->context->currency->iso_code,
                    'tax_exclusive' => $total_shipping_tax_excl,
                    'tax_inclusive' => $total_shipping_tax_incl
                ],
                'taxes' => [
                    [
                        'name' => 'Shipping Tax',
                        'rate' => $total_shipping_tax_excl > 0 ? (($total_shipping_tax_incl - $total_shipping_tax_excl) / $total_shipping_tax_excl * 100) : 0,
                        'amount' => $total_shipping_tax_incl - $total_shipping_tax_excl
                    ]
                ],
                'address' => $this->getShippingAddress($cart, $language_id)
            ];
        }

        return $shipping;
    }

    /**
     * Get shipping address
     */
    private function getShippingAddress($cart, $language_id)
    {
        if (!$cart->id_address_delivery) {
            return null;
        }

        $address = new Address($cart->id_address_delivery);
        
        if (!Validate::isLoadedObject($address)) {
            return null;
        }

        $country = new Country($address->id_country, $language_id);
        $state = $address->id_state ? new State($address->id_state, $language_id) : null;

        return [
            'street' => $address->address1 . ($address->address2 ? ' ' . $address->address2 : ''),
            'city' => $address->city,
            'postal_code' => $address->postcode,
            'country' => Validate::isLoadedObject($country) ? $country->name : '',
            'state' => Validate::isLoadedObject($state) ? $state->name : '',
            'metadata' => [
                'prestashop_address_id' => (int) $address->id,
                'phone' => $address->phone,
                'phone_mobile' => $address->phone_mobile,
                'company' => $address->company,
                'vat_number' => $address->vat_number
            ]
        ];
    }

    /**
     * Get cart discounts and vouchers
     */
    private function getDiscounts($cart, $language_id)
    {
        $discounts = [];
        $cart_rules = $cart->getCartRules();
        
        foreach ($cart_rules as $cart_rule) {
            $discounts[] = [
                'id' => (string) $cart_rule['id_cart_rule'],
                'code' => $cart_rule['code'],
                'name' => $cart_rule['name'],
                'description' => $cart_rule['description'] ?: '',
                'amount' => [
                    'value' => (float) $cart_rule['value_real'],
                    'currency' => $this->context->currency->iso_code,
                    'type' => $cart_rule['reduction_amount'] > 0 ? 'fixed' : 'percentage',
                    'percentage' => $cart_rule['reduction_percent'] > 0 ? (float) $cart_rule['reduction_percent'] : null
                ],
                'metadata' => [
                    'prestashop_cart_rule_id' => (int) $cart_rule['id_cart_rule'],
                    'free_shipping' => (bool) $cart_rule['free_shipping'],
                    'minimum_amount' => (float) $cart_rule['minimum_amount']
                ]
            ];
        }

        return $discounts;
    }

    /**
     * Calculate order totals
     */
    private function calculateTotals($cart, $currency)
    {
        $total_products_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        $total_products_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $total_shipping_tax_excl = $cart->getTotalShippingCost(null, false);
        $total_shipping_tax_incl = $cart->getTotalShippingCost();
        $total_wrapping_tax_excl = $cart->getOrderTotal(false, Cart::ONLY_WRAPPING);
        $total_wrapping_tax_incl = $cart->getOrderTotal(true, Cart::ONLY_WRAPPING);
        $total_discounts = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        
        $total_tax_excl = $total_products_tax_excl + $total_shipping_tax_excl + $total_wrapping_tax_excl;
        $total_tax_incl = $total_products_tax_incl + $total_shipping_tax_incl + $total_wrapping_tax_incl;
        $total_taxes = $total_tax_incl - $total_tax_excl;
        
        $grand_total = $total_tax_incl - $total_discounts;

        return [
            'subtotal' => [
                'amount' => $total_products_tax_incl,
                'currency' => $currency->iso_code,
                'tax_exclusive' => $total_products_tax_excl,
                'tax_inclusive' => $total_products_tax_incl
            ],
            'shipping' => [
                'amount' => $total_shipping_tax_incl,
                'currency' => $currency->iso_code,
                'tax_exclusive' => $total_shipping_tax_excl,
                'tax_inclusive' => $total_shipping_tax_incl
            ],
            'discounts' => [
                'amount' => $total_discounts,
                'currency' => $currency->iso_code
            ],
            'taxes' => [
                'amount' => $total_taxes,
                'currency' => $currency->iso_code,
                'breakdown' => [
                    'products_tax' => $total_products_tax_incl - $total_products_tax_excl,
                    'shipping_tax' => $total_shipping_tax_incl - $total_shipping_tax_excl,
                    'wrapping_tax' => $total_wrapping_tax_incl - $total_wrapping_tax_excl
                ]
            ],
            'grand_total' => [
                'amount' => $grand_total,
                'currency' => $currency->iso_code,
                'tax_exclusive' => $total_tax_excl - $total_discounts,
                'tax_inclusive' => $grand_total
            ]
        ];
    }

    /**
     * Convert multiple carts to UCP Orders
     */
    public function convertMultipleCarts($cart_ids, $language_id = null)
    {
        $ucp_orders = [];
        
        foreach ($cart_ids as $cart_id) {
            try {
                $cart = new Cart($cart_id);
                if (Validate::isLoadedObject($cart)) {
                    $ucp_orders[] = $this->convertCartToUcpOrder($cart, $language_id);
                }
            } catch (Exception $e) {
                // Log error and continue with next cart
                PrestaShopLogger::addLog(
                    'UCP conversion error for cart ' . $cart_id . ': ' . $e->getMessage(),
                    3, // Error level
                    null,
                    'UcpOrderConverter',
                    0,
                    true
                );
            }
        }
        
        return $ucp_orders;
    }
}
