<?php

require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpCheckoutSessionValidator.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpSessionManager.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpBuyerManager.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpCartManager.php';

class Ucpwellknowncheckout_sessionsModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $session_validator;
    private $session_manager;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->session_validator = new UcpCheckoutSessionValidator();
        $this->session_manager = new UcpSessionManager();
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
        $sid = $_GET['sid'] ?? null;
        $is_update_request = !empty($sid);

        // Check if this is a finalize request
        $is_finalize_request = false;
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $action = $_GET['action'] ?? '';
        
        if (strpos($request_uri, '/finalize') !== false || strpos($request_uri, 'finalize') !== false || $action === 'finalize') {
            $is_finalize_request = true;
            // Extraire l'ID de la session depuis l'URL ou le paramètre
            if (preg_match('/\/checkout_sessions\/([^\/]+)\/finalize/', $request_uri, $matches)) {
                $sid = $matches[1];
            }
        }

        if ($is_update_request && $method === 'POST' && !$is_finalize_request) {
            // Treat POST with sid as PUT request (but not for finalize)
            $method = 'PUT';
        }

        switch ($method) {
            case 'POST':
                if ($is_finalize_request) {
                    // Handle finalization
                    $input = $this->getJsonInput();
                    return $this->handleFinalizeCheckoutSession($headers, $sid, $input, $log_data);
                } elseif ($is_update_request) {
                    // Handle as PUT request
                    $input = $this->getJsonInput();
                    return $this->handlePutCheckoutSession($headers, $sid, $input, $log_data);
                } else {
                    // Handle as POST request (create)
                    $input = $this->getJsonInput();
                    return $this->handlePostCheckoutSession($headers, $input, $log_data);
                }

            case 'GET':
                return $this->handleGetCheckoutSession($headers, $log_data);

            case 'PUT':
                if (!$sid) {
                    $sid = $this->getCheckoutSessionId();
                }
                $input = $this->getJsonInput();
                return $this->handlePutCheckoutSession($headers, $sid, $input, $log_data);

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

            // Generate unique checkout ID
            $checkout_id = $this->generateCheckoutId();

            // Create temporary UCP session (NO PrestaShop interaction)
            $session_data = $this->session_manager->createSession(
                $checkout_id,
                $input['buyer'],
                $input['line_items'],
                $headers
            );

            // Log successful checkout session creation (temporary)
            PrestaShopLogger::addLog(
                'UCP Temporary Session Created: ' . json_encode([
                    'checkout_id' => $checkout_id,
                    'request_id' => $headers['request-id'],
                    'items_count' => count($input['line_items']),
                    'status' => 'temporary'
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
                'session_type' => 'temporary',
                'line_items' => $session_data['line_items'],
                'buyer' => $session_data['buyer'],
                'totals' => $session_data['totals'],
                'created_at' => $session_data['created_at'],
                'expires_at' => $session_data['expires_at'],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'idempotency_key' => $headers['idempotency-key']
                ],
                'next_steps' => [
                    'modify_session' => 'PUT /checkout_sessions?sid=' . $checkout_id,
                    'finalize_session' => 'POST /checkout_sessions?sid=' . $checkout_id . '&action=finalize'
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
        $sid = $_GET['sid'] ?? null;
        
        if ($sid) {
            // Retrieve specific checkout session details from temporary storage
            $session_data = $this->session_manager->getSession($sid);
            
            if (!$session_data) {
                header('HTTP/1.1 404 Not Found');
                return [
                    'error' => 'Checkout session not found or expired',
                    'code' => 404,
                    'timestamp' => date('c')
                ];
            }

            return [
                'status' => 'success',
                'checkout_id' => $sid,
                'session_type' => $session_data['finalized'] ? 'finalized' : 'temporary',
                'line_items' => $session_data['line_items'],
                'buyer' => $session_data['buyer'],
                'totals' => $session_data['totals'],
                'applied_promo_codes' => $session_data['applied_promo_codes'],
                'created_at' => $session_data['created_at'],
                'updated_at' => $session_data['updated_at'] ?? $session_data['created_at'],
                'expires_at' => $session_data['expires_at'],
                'prestashop_cart_id' => $session_data['prestashop_cart_id'],
                'prestashop_customer_id' => $session_data['prestashop_customer_id'],
                'finalized' => $session_data['finalized'],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'ucp_agent' => $headers['ucp-agent'],
                    'timestamp' => $log_data['timestamp']
                ],
                'next_steps' => $session_data['finalized'] ? [] : [
                    'modify_session' => 'PUT /checkout_sessions?sid=' . $sid,
                    'finalize_session' => 'POST /checkout_sessions?sid=' . $sid . '&action=finalize'
                ]
            ];
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
                'POST /checkout-sessions' => 'Create a new temporary checkout session',
                'GET /checkout-sessions?sid={id}' => 'Retrieve checkout session details',
                'PUT /checkout-sessions?sid={id}' => 'Update checkout session (apply/remove promo codes)',
                'POST /checkout-sessions?sid={id}&action=finalize' => 'Finalize session and create PrestaShop cart'
            ]
        ];
    }

    /**
     * Handle finalization of checkout session
     */
    private function handleFinalizeCheckoutSession($headers, $sid, $input, $log_data)
    {
        try {
            // Validate checkout session ID
            if (empty($sid)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Missing checkout session ID',
                    'code' => 400,
                    'timestamp' => date('c')
                ];
            }

            // Finalize the session (create PrestaShop cart and customer)
            $finalize_result = $this->session_manager->finalizeSession($sid);
            
            if (!$finalize_result['success']) {
                $code = $finalize_result['code'] ?? 500;
                header("HTTP/1.1 $code " . $this->getStatusText($code));
                return [
                    'error' => $finalize_result['error'],
                    'code' => $code,
                    'details' => $finalize_result['details'] ?? [],
                    'timestamp' => date('c')
                ];
            }

            // Log successful finalization
            PrestaShopLogger::addLog(
                'UCP Session Finalized: ' . json_encode([
                    'checkout_id' => $sid,
                    'cart_id' => $finalize_result['cart_id'],
                    'customer_id' => $finalize_result['customer_id'],
                    'request_id' => $headers['request-id']
                ]),
                1, // Info level
                null,
                'UcpWellKnown',
                0,
                true
            );

            return [
                'status' => 'success',
                'checkout_id' => $sid,
                'session_type' => 'finalized',
                'prestashop_cart_id' => $finalize_result['cart_id'],
                'prestashop_customer_id' => $finalize_result['customer_id'],
                'finalized_at' => $finalize_result['session_data']['finalized_at'],
                'message' => 'Session finalized successfully. PrestaShop cart created.',
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

    /**
     * Handle PUT request to update checkout session
     */
    private function handlePutCheckoutSession($headers, $sid, $input, $log_data)
    {
        try {
            // Validate checkout session ID
            if (empty($sid)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Missing checkout session ID',
                    'code' => 400,
                    'timestamp' => date('c')
                ];
            }

            // Update the temporary session
            $session_data = $this->session_manager->updateSession($sid, $input);
            
            if (!$session_data) {
                header('HTTP/1.1 404 Not Found');
                return [
                    'error' => 'Checkout session not found or expired',
                    'code' => 404,
                    'timestamp' => date('c')
                ];
            }

            // Vérifier s'il y a des erreurs de validation (ex: code promo invalide)
            if (!empty($session_data['validation_errors'])) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Validation failed',
                    'code' => 400,
                    'details' => $session_data['validation_errors'],
                    'timestamp' => date('c'),
                    'applied_promo_codes' => $session_data['applied_promo_codes']
                ];
            }

            // Log session update
            PrestaShopLogger::addLog(
                'UCP Temporary Session Updated: ' . json_encode([
                    'checkout_id' => $sid,
                    'request_id' => $headers['request-id'],
                    'items_count' => count($session_data['line_items'])
                ]),
                1, // Info level
                null,
                'UcpWellKnown',
                0,
                true
            );

            return [
                'status' => 'success',
                'checkout_id' => $sid,
                'session_type' => 'temporary',
                'line_items' => $session_data['line_items'],
                'buyer' => $session_data['buyer'],
                'totals' => $session_data['totals'],
                'applied_promo_codes' => $session_data['applied_promo_codes'],
                'updated_at' => $session_data['updated_at'] ?? $session_data['created_at'],
                'expires_at' => $session_data['expires_at'],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'ucp_agent' => $headers['ucp-agent'],
                    'timestamp' => $log_data['timestamp']
                ],
                'next_steps' => [
                    'modify_session' => 'PUT /checkout_sessions?sid=' . $sid,
                    'finalize_session' => 'POST /checkout_sessions?sid=' . $sid . '&action=finalize'
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

    /**
     * Get HTTP status text
     */
    private function getStatusText($code)
    {
        $status_texts = [
            400 => 'Bad Request',
            404 => 'Not Found',
            409 => 'Conflict',
            500 => 'Internal Server Error'
        ];
        
        return $status_texts[$code] ?? 'Error';
    }

    private function generateCheckoutId()
    {
        return 'ucs_' . uniqid() . '_' . time();
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
