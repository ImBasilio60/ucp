<?php

require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpOrderConverter.php';

class UcpOrderConverterTest
{
    private $converter;
    private $test_results = [];
    private $mock_cart_id = 1;

    public function __construct()
    {
        $this->converter = new UcpOrderConverter();
    }

    public function runAllTests()
    {
        echo "Running UCP Order Converter Tests...\n\n";

        $this->testCartWithMultipleProducts();
        $this->testCartWithDiscounts();
        $this->testCartWithShippingFees();
        $this->testCartWithCombinations();
        $this->testEmptyCart();
        $this->testInvalidCart();

        $this->printResults();
    }

    private function testCartWithMultipleProducts()
    {
        $test_name = "Cart with Multiple Products Test";
        
        try {
            // Mock a cart with multiple products
            $this->mockCartWithMultipleProducts();
            
            $cart = new Cart($this->mock_cart_id);
            $ucp_order = $this->converter->convertCartToUcpOrder($cart);
            
            // Validate structure
            $required_fields = ['id', 'status', 'customer', 'lines', 'shipping', 'discounts', 'totals', 'metadata'];
            $missing_fields = [];
            
            foreach ($required_fields as $field) {
                if (!array_key_exists($field, $ucp_order)) {
                    $missing_fields[] = $field;
                }
            }
            
            if (empty($missing_fields) && 
                isset($ucp_order['lines']) && 
                is_array($ucp_order['lines']) && 
                count($ucp_order['lines']) > 1) {
                
                // Check line structure
                $line = $ucp_order['lines'][0];
                $line_fields = ['item_id', 'title', 'quantity', 'unit_price', 'total_price', 'taxes'];
                $missing_line_fields = [];
                
                foreach ($line_fields as $field) {
                    if (!array_key_exists($field, $line)) {
                        $missing_line_fields[] = $field;
                    }
                }
                
                if (empty($missing_line_fields)) {
                    $this->test_results[$test_name] = 'PASS';
                } else {
                    $this->test_results[$test_name] = 'FAIL - Line missing fields: ' . implode(', ', $missing_line_fields);
                }
            } else {
                $this->test_results[$test_name] = 'FAIL - Missing fields: ' . implode(', ', $missing_fields);
            }
            
        } catch (Exception $e) {
            $this->test_results[$test_name] = 'FAIL - Exception: ' . $e->getMessage();
        }
    }

    private function testCartWithDiscounts()
    {
        $test_name = "Cart with Discounts Test";
        
        try {
            // Mock a cart with discounts
            $this->mockCartWithDiscounts();
            
            $cart = new Cart($this->mock_cart_id);
            $ucp_order = $this->converter->convertCartToUcpOrder($cart);
            
            if (isset($ucp_order['discounts']) && 
                is_array($ucp_order['discounts']) && 
                !empty($ucp_order['discounts'])) {
                
                // Check discount structure
                $discount = $ucp_order['discounts'][0];
                $discount_fields = ['id', 'code', 'name', 'amount'];
                $missing_discount_fields = [];
                
                foreach ($discount_fields as $field) {
                    if (!array_key_exists($field, $discount)) {
                        $missing_discount_fields[] = $field;
                    }
                }
                
                if (empty($missing_discount_fields)) {
                    $this->test_results[$test_name] = 'PASS';
                } else {
                    $this->test_results[$test_name] = 'FAIL - Discount missing fields: ' . implode(', ', $missing_discount_fields);
                }
            } else {
                $this->test_results[$test_name] = 'FAIL - Cart should have discounts';
            }
            
        } catch (Exception $e) {
            $this->test_results[$test_name] = 'FAIL - Exception: ' . $e->getMessage();
        }
    }

    private function testCartWithShippingFees()
    {
        $test_name = "Cart with Shipping Fees Test";
        
        try {
            // Mock a cart with shipping fees
            $this->mockCartWithShipping();
            
            $cart = new Cart($this->mock_cart_id);
            $ucp_order = $this->converter->convertCartToUcpOrder($cart);
            
            if (isset($ucp_order['shipping']) && 
                is_array($ucp_order['shipping']) && 
                !empty($ucp_order['shipping'])) {
                
                // Check shipping structure
                $shipping = $ucp_order['shipping'];
                $shipping_fields = ['method', 'cost', 'taxes'];
                $missing_shipping_fields = [];
                
                foreach ($shipping_fields as $field) {
                    if (!array_key_exists($field, $shipping)) {
                        $missing_shipping_fields[] = $field;
                    }
                }
                
                if (empty($missing_shipping_fields)) {
                    $this->test_results[$test_name] = 'PASS';
                } else {
                    $this->test_results[$test_name] = 'FAIL - Shipping missing fields: ' . implode(', ', $missing_shipping_fields);
                }
            } else {
                $this->test_results[$test_name] = 'FAIL - Cart should have shipping';
            }
            
        } catch (Exception $e) {
            $this->test_results[$test_name] = 'FAIL - Exception: ' . $e->getMessage();
        }
    }

    private function testCartWithCombinations()
    {
        $test_name = "Cart with Combinations Test";
        
        try {
            // Mock a cart with product combinations
            $this->mockCartWithCombinations();
            
            $cart = new Cart($this->mock_cart_id);
            $ucp_order = $this->converter->convertCartToUcpOrder($cart);
            
            if (isset($ucp_order['lines']) && !empty($ucp_order['lines'])) {
                $has_variant = false;
                foreach ($ucp_order['lines'] as $line) {
                    if (isset($line['variant']) && is_array($line['variant'])) {
                        $has_variant = true;
                        
                        // Check variant structure
                        $variant = $line['variant'];
                        $variant_fields = ['id', 'attributes'];
                        $missing_variant_fields = [];
                        
                        foreach ($variant_fields as $field) {
                            if (!array_key_exists($field, $variant)) {
                                $missing_variant_fields[] = $field;
                            }
                        }
                        
                        if (!empty($missing_variant_fields)) {
                            $this->test_results[$test_name] = 'FAIL - Variant missing fields: ' . implode(', ', $missing_variant_fields);
                            return;
                        }
                    }
                }
                
                if ($has_variant) {
                    $this->test_results[$test_name] = 'PASS';
                } else {
                    $this->test_results[$test_name] = 'FAIL - Cart should have variants';
                }
            } else {
                $this->test_results[$test_name] = 'FAIL - Cart should have lines';
            }
            
        } catch (Exception $e) {
            $this->test_results[$test_name] = 'FAIL - Exception: ' . $e->getMessage();
        }
    }

    private function testEmptyCart()
    {
        $test_name = "Empty Cart Test";
        
        try {
            // Mock an empty cart
            $this->mockEmptyCart();
            
            $cart = new Cart($this->mock_cart_id);
            $ucp_order = $this->converter->convertCartToUcpOrder($cart);
            
            if ($ucp_order['status'] === 'empty' && 
                empty($ucp_order['lines'])) {
                $this->test_results[$test_name] = 'PASS';
            } else {
                $this->test_results[$test_name] = 'FAIL - Empty cart should have empty status and no lines';
            }
            
        } catch (Exception $e) {
            $this->test_results[$test_name] = 'FAIL - Exception: ' . $e->getMessage();
        }
    }

    private function testInvalidCart()
    {
        $test_name = "Invalid Cart Test";
        
        try {
            $ucp_order = $this->converter->convertCartToUcpOrder(null);
            
            $this->test_results[$test_name] = 'FAIL - Should throw exception for invalid cart';
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Invalid cart object') !== false) {
                $this->test_results[$test_name] = 'PASS';
            } else {
                $this->test_results[$test_name] = 'FAIL - Wrong exception: ' . $e->getMessage();
            }
        }
    }

    // Mock methods for testing
    private function mockCartWithMultipleProducts()
    {
        // In a real test environment, you would use dependency injection
        // or mocking framework. For this example, we'll simulate the behavior.
    }

    private function mockCartWithDiscounts()
    {
        // Mock cart with discounts
    }

    private function mockCartWithShipping()
    {
        // Mock cart with shipping fees
    }

    private function mockCartWithCombinations()
    {
        // Mock cart with product combinations
    }

    private function mockEmptyCart()
    {
        // Mock empty cart
    }

    private function printResults()
    {
        echo "Test Results:\n";
        echo "============\n\n";

        $pass_count = 0;
        $total_count = count($this->test_results);

        foreach ($this->test_results as $test => $result) {
            echo "$test: $result\n";
            if ($result === 'PASS') {
                $pass_count++;
            }
        }

        echo "\nSummary: $pass_count/$total_count tests passed\n";
        
        if ($pass_count === $total_count) {
            echo "All tests PASSED! ✓\n";
        } else {
            echo "Some tests FAILED! ✗\n";
        }
    }
}

// Example usage demonstration
class UcpOrderConverterDemo
{
    public static function demonstrateUsage()
    {
        echo "\n=== UCP Order Converter Usage Demo ===\n\n";
        
        try {
            $converter = new UcpOrderConverter();
            
            // Convert a single cart
            echo "1. Converting single cart (ID: 1):\n";
            $cart = new Cart(1);
            if (Validate::isLoadedObject($cart)) {
                $ucp_order = $converter->convertCartToUcpOrder($cart);
                echo "Cart converted to UCP Order format\n";
                echo "Order ID: " . $ucp_order['id'] . "\n";
                echo "Status: " . $ucp_order['status'] . "\n";
                echo "Lines: " . count($ucp_order['lines']) . " products\n";
                echo "Grand Total: " . $ucp_order['totals']['grand_total']['amount'] . " " . $ucp_order['totals']['grand_total']['currency'] . "\n\n";
            } else {
                echo "Cart not found\n\n";
            }
            
            // Convert multiple carts
            echo "2. Converting multiple carts (IDs: [1, 2, 3]):\n";
            $cart_ids = [1, 2, 3];
            $ucp_orders = $converter->convertMultipleCarts($cart_ids);
            echo "Converted " . count($ucp_orders) . " carts\n\n";
            
        } catch (Exception $e) {
            echo "Demo failed: " . $e->getMessage() . "\n";
        }
    }
}

// Run tests if this file is executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new UcpOrderConverterTest();
    $test->runAllTests();
    
    // Run demo
    UcpOrderConverterDemo::demonstrateUsage();
}
