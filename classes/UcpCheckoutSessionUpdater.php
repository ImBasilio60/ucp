<?php

/**
 * UCP Checkout Session Updater
 * 
 * Handles updating existing checkout sessions with product management
 * while respecting UCP protocol requirements
 */
class UcpCheckoutSessionUpdater
{
    private $cart_manager;
    private $errors = [];
    
    public function __construct()
    {
        $this->cart_manager = new UcpCartManager();
    }
    
    /**
     * Main method to update checkout session
     */
    public function updateCheckoutSession($checkout_session_id, $update_data, $headers)
    {
        try {
            // 1. Validate checkout session ID format
            $this->validateCheckoutSessionId($checkout_session_id);
            
            // 2. Get existing cart
            $cart_result = $this->cart_manager->getCartByCheckoutSessionId($checkout_session_id);
            if (!$cart_result['success']) {
                return [
                    'success' => false,
                    'error' => 'Checkout session not found',
                    'code' => 404
                ];
            }
            
            $cart = $cart_result['cart'];
            
            // 3. Process line items updates
            $items_result = $this->processLineItems($cart, $update_data);
            if (!$items_result['success']) {
                return $items_result;
            }
            
            // 4. Process promo codes if provided
            if (isset($update_data['promo_code'])) {
                $promo_result = $this->processPromoCode($cart, $update_data['promo_code']);
                if (!$promo_result['success']) {
                    return $promo_result;
                }
            }
            
            // 5. Get updated cart details
            $cart_details = $this->cart_manager->getCartDetails($cart->id);
            $cart_totals = $this->cart_manager->calculateCartTotals($cart->id);
            $applied_rules = $this->cart_manager->getAppliedRules($cart->id);
            
            return [
                'success' => true,
                'checkout_id' => $checkout_session_id,
                'cart_id' => $cart->id,
                'customer_id' => $cart->id_customer,
                'items' => $cart_details['products'],
                'totals' => $cart_totals,
                'applied_rules' => $applied_rules,
                'updated_at' => date('c'),
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'ucp_agent' => $headers['ucp-agent'] ?? 'unknown',
                    'timestamp' => date('c')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Internal server error',
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate checkout session ID format
     */
    private function validateCheckoutSessionId($checkout_session_id)
    {
        if (empty($checkout_session_id)) {
            throw new Exception('Checkout session ID is required');
        }
        
        if (!preg_match('/^ucs_[a-f0-9]+_\d+_\d+$/', $checkout_session_id)) {
            throw new Exception('Invalid checkout session ID format');
        }
    }
    
    /**
     * Process line items updates
     */
    private function processLineItems($cart, $update_data)
    {
        if (!isset($update_data['line_items'])) {
            // No line items update requested
            return ['success' => true];
        }
        
        $line_items = $update_data['line_items'];
        if (!is_array($line_items) || empty($line_items)) {
            return [
                'success' => false,
                'error' => 'Invalid line_items format',
                'code' => 400
            ];
        }
        
        // First, validate all products before modifying the cart
        foreach ($line_items as $item) {
            $validation_result = $this->validateProductForCart($item);
            if (!$validation_result['success']) {
                return $validation_result;
            }
        }
        
        // Only if all validations pass, clear and add products
        $cart_products = $cart->getProducts();
        foreach ($cart_products as $product) {
            $cart->deleteProduct($product['id_product'], $product['id_product_attribute']);
        }
        
        // Add each product
        foreach ($line_items as $item) {
            $item_result = $this->addProductToCart($cart, $item);
            if (!$item_result['success']) {
                return $item_result;
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Validate product without adding to cart
     */
    private function validateProductForCart($item)
    {
        // Validate required fields
        if (!isset($item['product_id']) || !isset($item['quantity'])) {
            return [
                'success' => false,
                'error' => 'Product ID and quantity are required',
                'code' => 400
            ];
        }
        
        $product_id = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        
        // Validate product exists
        $product = new Product($product_id);
        if (!Validate::isLoadedObject($product)) {
            return [
                'success' => false,
                'error' => 'Product not found',
                'code' => 404,
                'details' => ['product_id' => $product_id]
            ];
        }
        
        // Check stock availability using real-time stock data
        $real_stock = $this->getRealProductStock($product_id, $product);
        if ($real_stock < $quantity) {
            return [
                'success' => false,
                'error' => 'Insufficient stock',
                'code' => 400,
                'details' => [
                    'product_id' => $product_id,
                    'requested_quantity' => $quantity,
                    'available_stock' => $real_stock
                ]
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Get real-time product stock from database
     */
    private function getRealProductStock($product_id, $product = null)
    {
        try {
            // Method 1: Try to get stock from StockAvailable (most reliable)
            if (class_exists('StockAvailable')) {
                $stock = StockAvailable::getQuantityAvailableByProduct($product_id);
                if ($stock !== false) {
                    return (int)$stock;
                }
            }
            
            // Method 2: Direct database query
            $sql = 'SELECT sa.quantity FROM ' . _DB_PREFIX_ . 'stock_available sa 
                    WHERE sa.id_product = ' . (int)$product_id . ' 
                    AND sa.id_product_attribute = 0';
            $result = Db::getInstance()->getValue($sql);
            if ($result !== false) {
                return (int)$result;
            }
            
            // Method 3: Fallback to product object quantity
            if ($product && isset($product->quantity)) {
                return (int)$product->quantity;
            }
            
            // Method 4: Last resort - assume unlimited stock
            return 999999;
            
        } catch (Exception $e) {
            // In case of error, assume unlimited stock to avoid blocking
            return 999999;
        }
    }
    
    /**
     * Add product to cart (separate method for clarity)
     */
    private function addProductToCart($cart, $item)
    {
        $product_id = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        
        // Handle customization data
        $customization_data = null;
        if (isset($item['customization_data']) && is_array($item['customization_data'])) {
            $customization_data = $item['customization_data'];
        }
        
        // Add product to cart
        try {
            $result = $this->addProductToCartDirectly($cart->id, $product_id, $quantity, $customization_data);
            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to add product to cart',
                    'code' => 500,
                    'details' => $result['errors'] ?? []
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Product addition failed',
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Process promo code application/removal
     */
    private function processPromoCode($cart, $promo_code)
    {
        try {
            if (empty($promo_code)) {
                // Remove all promo codes
                $result = $this->cart_manager->removePromoCode($cart->id);
            } else {
                // Apply promo code
                $result = $this->cart_manager->applyPromoCode($cart->id, $promo_code);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Promo code processing failed',
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Add product directly to cart using PrestaShop Cart methods
     */
    private function addProductToCartDirectly($cart_id, $product_id, $quantity, $customization_data = null)
    {
        try {
            $cart = new Cart($cart_id);
            if (!Validate::isLoadedObject($cart)) {
                return [
                    'success' => false,
                    'error' => 'Cart not found',
                    'errors' => ['cart_id' => $cart_id]
                ];
            }
            
            // Add product using PrestaShop's updateQty method with correct parameters
            $result = $cart->updateQty(
                $quantity,                      // quantity
                $product_id,                    // id_product
                0,                              // id_product_attribute (0 for default)
                null,                           // id_customization
                'up',                           // operator ('up' = add)
                null,                           // id_address_delivery
                null,                           // id_shop
                null,                           // auto_add_with_package_quantity
                null,                           // shopping_cart_update_quantity_option
                false                           // use_existing_customization (must be bool)
            );
            
            if ($result === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to add product to cart',
                    'errors' => ['product_id' => $product_id, 'quantity' => $quantity]
                ];
            }
            
            // Handle customization if provided
            if ($customization_data && isset($customization_data['fields'])) {
                $this->addCustomizationToCart($cart, $product_id, $customization_data);
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception during product addition',
                'errors' => ['message' => $e->getMessage()]
            ];
        }
    }
    
    /**
     * Add customization data to cart product
     */
    private function addCustomizationToCart($cart, $product_id, $customization_data)
    {
        // This is a simplified version - full customization would require more complex logic
        if (isset($customization_data['fields']) && is_array($customization_data['fields'])) {
            foreach ($customization_data['fields'] as $field) {
                if (isset($field['value']) && !empty($field['value'])) {
                    // Create customization record
                    $customization = new Customization();
                    $customization->id_product = $product_id;
                    $customization->id_product_attribute = 0;
                    $customization->quantity = 1;
                    $customization->in_cart = 1;
                    $customization->add();
                    
                    // Add customization field
                    $customization_field = new CustomizationField();
                    $customization_field->id_customization = $customization->id;
                    $customization_field->id_product = $product_id;
                    $customization_field->type = $field['type'] ?? 0;
                    $customization_field->required = $field['required'] ?? 0;
                    $customization_field->is_module = 0;
                    $customization_field->add();
                    
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
    
    /**
     * Get errors
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
