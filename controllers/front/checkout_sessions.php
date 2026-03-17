<?php

require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpCheckoutSessionValidator.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpCartManager.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpBuyerManager.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpCheckoutSessionUpdater.php';

class Ucpwellknowncheckout_sessionsModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $session_validator;
    private $cart_manager;
    private $buyer_manager;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->session_validator = new UcpCheckoutSessionValidator();
        $this->cart_manager = new UcpCartManager();
        $this->buyer_manager = new UcpBuyerManager();
    }

    public function initContent()
    {
        header('Content-Type: application/json');

        try {
            // Extract and validate UCP headers
            $this->validator->extractHeaders();
            $validation = $this->validator->validateHeaders();

            if (!$validation['valid']) {
                $this->validator->sendErrorResponse($validation['errors']);
                return;
            }

            // Log the request
            $endpoint = $this->getEndpointPath();
            $log_data = $this->validator->logRequest($endpoint);

            // Set response headers
            $response_headers = $this->validator->prepareResponseHeaders();
            foreach ($response_headers as $name => $value) {
                header($name . ': ' . $value);
            }

            // Process the request based on method
            $method = $_SERVER['REQUEST_METHOD'];
            $response = $this->processRequest($method, $log_data);

            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            $this->sendServerError($e->getMessage());
        }

        exit;
    }

    private function getEndpointPath()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($request_uri);
        return $parsed_url['path'] ?? 'unknown';
    }

    private function processRequest($method, $log_data)
    {
        $headers = $this->validator->getExtractedHeaders();

        // Check if this is an update request via query parameter
        $checkout_session_id = $_GET['checkout_session_id'] ?? null;
        $is_update_request = !empty($checkout_session_id);

        if ($is_update_request && $method === 'POST') {
            // Treat POST with checkout_session_id as PUT request
            $method = 'PUT';
        }

        switch ($method) {
            case 'POST':
                if ($is_update_request) {
                    // Handle as PUT request
                    $input = $this->getJsonInput();
                    return $this->handlePutCheckoutSession($headers, $checkout_session_id, $input, $log_data);
                } else {
                    // Handle as POST request (create)
                    $input = $this->getJsonInput();
                    return $this->handlePostCheckoutSession($headers, $input, $log_data);
                }

            case 'GET':
                return $this->handleGetCheckoutSession($headers, $log_data);

            case 'PUT':
                if (!$checkout_session_id) {
                    $checkout_session_id = $this->getCheckoutSessionId();
                }
                $input = $this->getJsonInput();
                return $this->handlePutCheckoutSession($headers, $checkout_session_id, $input, $log_data);

            default:
                header('HTTP/1.1 405 Method Not Allowed');
                return [
                    'error' => 'Method not allowed',
                    'allowed_methods' => ['GET', 'POST', 'PUT'],
                    'timestamp' => date('c')
                ];
        }
    }

    private function handlePostCheckoutSession($headers, $input, $log_data)
    {
        try {
            // Validate input structure
            $validation_result = $this->session_validator->validateCheckoutSessionRequest($input);

            if (!$validation_result['valid']) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid request data',
                    'code' => 400,
                    'details' => $validation_result['errors'],
                    'timestamp' => date('c')
                ];
            }

            // Gérer l'identité du buyer avec toutes les validations
            $buyer_result = $this->buyer_manager->handleBuyerIdentity($input['buyer'], $headers);

            if (!$buyer_result['success']) {
                header('HTTP/1.1 ' . $buyer_result['code']);
                return [
                    'error' => $buyer_result['error'],
                    'code' => $buyer_result['code'],
                    'details' => $buyer_result['details'] ?? [],
                    'timestamp' => date('c')
                ];
            }

            // Process line items and validate products
            $validated_items = $this->session_validator->validateLineItems($input['line_items']);

            if (!$validated_items['valid']) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid line items',
                    'code' => 400,
                    'details' => $validated_items['errors'],
                    'timestamp' => date('c')
                ];
            }

            // Create cart and add products avec le customer_id
            $cart_result = $this->cart_manager->createCartWithItems(
                $validated_items['items'],
                $input['buyer'],
                $buyer_result['customer_id']
            );

            if (!$cart_result['success']) {
                header('HTTP/1.1 500 Internal Server Error');
                return [
                    'error' => 'Failed to create cart',
                    'code' => 500,
                    'message' => $cart_result['error'],
                    'timestamp' => date('c')
                ];
            }

            // Calculate totals
            $totals = $this->cart_manager->calculateCartTotals($cart_result['cart_id']);

            // Generate unique checkout ID
            $checkout_id = $this->generateCheckoutId($cart_result['cart_id']);

            // Log successful checkout session creation
            PrestaShopLogger::addLog(
                'UCP Checkout Session Created: ' . json_encode([
                    'checkout_id' => $checkout_id,
                    'cart_id' => $cart_result['cart_id'],
                    'customer_id' => $buyer_result['customer_id'],
                    'request_id' => $headers['request-id'],
                    'items_count' => count($validated_items['items']),
                    'is_new_customer' => $buyer_result['is_new_customer']
                ]),
                1, // Info level
                null,
                'UcpWellKnown',
                0,
                true
            );

            return [
                'status' => 'success',
                'checkout_id' => $checkout_id,
                'cart_id' => $cart_result['cart_id'],
                'customer_id' => $buyer_result['customer_id'],
                'customer_info' => [
                    'id' => $buyer_result['customer_data']['id'],
                    'email' => $buyer_result['customer_data']['email'],
                    'first_name' => $buyer_result['customer_data']['first_name'],
                    'last_name' => $buyer_result['customer_data']['last_name'],
                    'is_new_customer' => $buyer_result['is_new_customer']
                ],
                'line_items' => $validated_items['items'],
                'buyer' => $input['buyer'],
                'totals' => $totals,
                'created_at' => date('c'),
                'expires_at' => date('c', strtotime('+1 hour')),
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'idempotency_key' => $headers['idempotency-key']
                ]
            ];

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'UCP Checkout Session Error: ' . $e->getMessage(),
                3, // Error level
                null,
                'UcpWellKnown',
                0,
                true
            );

            throw $e;
        }
    }

    private function handleGetCheckoutSession($headers, $log_data)
    {
        // Check if a specific checkout session ID is provided
        $checkout_session_id = $_GET['checkout_session_id'] ?? null;
        
        if ($checkout_session_id) {
            // Retrieve specific checkout session details
            return $this->getCheckoutSessionDetails($checkout_session_id, $headers, $log_data);
        }
        
        // Return general endpoint information
        return [
            'status' => 'success',
            'message' => 'UCP Checkout Sessions endpoint',
            'request_info' => [
                'request_id' => $headers['request-id'],
                'ucp_agent' => $headers['ucp-agent'],
                'idempotency_key' => $headers['idempotency-key'],
                'timestamp' => $log_data['timestamp']
            ],
            'endpoints' => [
                'POST /checkout-sessions' => 'Create a new checkout session',
                'GET /checkout-sessions?checkout_session_id={id}' => 'Retrieve checkout session details',
                'PUT /checkout-sessions?checkout_session_id={id}' => 'Update checkout session (apply/remove promo codes)'
            ]
        ];
    }

    private function getCheckoutSessionDetails($checkout_session_id, $headers, $log_data)
    {
        try {
            // Validate checkout session ID format
            if (empty($checkout_session_id) || !preg_match('/^ucs_[a-f0-9]+_\d+_\d+$/', $checkout_session_id)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid checkout session ID format',
                    'code' => 400,
                    'timestamp' => date('c')
                ];
            }

            // Get cart details using the checkout session ID
            $cart_result = $this->cart_manager->getCartByCheckoutSessionId($checkout_session_id);
            
            if (!$cart_result['success']) {
                header('HTTP/1.1 404 Not Found');
                return [
                    'error' => 'Checkout session not found',
                    'code' => 404,
                    'timestamp' => date('c')
                ];
            }

            $cart = $cart_result['cart'];
            
            // Get cart details and totals
            $cart_details = $this->cart_manager->getCartDetails($cart->id);
            $cart_totals = $this->cart_manager->calculateCartTotals($cart->id);
            
            // Get applied promotional rules
            $applied_rules = $this->cart_manager->getAppliedRules($cart->id);
            
            // Build response
            return [
                'status' => 'success',
                'checkout_id' => $checkout_session_id,
                'cart_id' => $cart->id,
                'customer_id' => $cart->id_customer,
                'items' => $cart_details['products'],
                'totals' => $cart_totals,
                'applied_rules' => $applied_rules,
                'created_at' => date('c', strtotime($cart->date_add)),
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'ucp_agent' => $headers['ucp-agent'],
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Internal server error',
                'code' => 500,
                'message' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }

    private function getCheckoutSessionId()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($request_uri);
        $path = $parsed_url['path'] ?? '';

        // Extract checkout session ID from URL pattern: /checkout-sessions/{id}
        $pattern = '/\/checkout-sessions\/([^\/]+)/';
        if (preg_match($pattern, $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function handlePutCheckoutSession($headers, $checkout_session_id, $input, $log_data)
    {
        try {
            // Validate checkout session ID
            if (empty($checkout_session_id)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Missing checkout session ID',
                    'code' => 400,
                    'timestamp' => date('c')
                ];
            }

            // Initialize checkout session updater
            $session_updater = new UcpCheckoutSessionUpdater();
            
            // Update the checkout session
            $update_result = $session_updater->updateCheckoutSession($checkout_session_id, $input, $headers);
            
            if (!$update_result['success']) {
                $code = $update_result['code'] ?? 500;
                header("HTTP/1.1 $code " . $this->getStatusText($code));
                return [
                    'error' => $update_result['error'],
                    'code' => $code,
                    'details' => $update_result['details'] ?? [],
                    'timestamp' => date('c')
                ];
            }

            return $update_result;

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Internal server error',
                'code' => 500,
                'message' => $e->getMessage(),
                'timestamp' => date('c')
            ];
        }
    }
    
    /**
     * Get HTTP status text
     */
    private function getStatusText($code)
    {
        $status_texts = [
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error'
        ];
        
        return $status_texts[$code] ?? 'Error';
    }

    private function generateCheckoutId($cart_id)
    {
        return 'ucs_' . uniqid() . '_' . $cart_id . '_' . time();
    }

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Empty request body');
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function sendServerError($message)
    {
        header('Content-Type: application/json');
        header('HTTP/1.1 500 Internal Server Error');

        $error_response = [
            'error' => 'Internal Server Error',
            'code' => 500,
            'message' => $message,
            'timestamp' => date('c')
        ];

        echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
