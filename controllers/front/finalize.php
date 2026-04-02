<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpSessionManager.php';

class UcpfinalizeModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $session_manager;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->session_manager = new UcpSessionManager();
    }

    public function initContent()
    {
        header('Content-Type: application/json');

        try {
            // Extract and validate UCP headers
            $this->validator->extractHeaders();
            $endpoint = $this->getEndpointPath();
            $validation = $this->validator->validateHeaders($endpoint);

            if (!$validation['valid']) {
                $this->validator->sendErrorResponse($validation['errors']);
                return;
            }

            // Get checkout session ID from URL
            $sid = $this->getCheckoutSessionId();
            
            if (empty($sid)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode([
                    'error' => 'Missing checkout session ID in URL',
                    'code' => 400,
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
                exit;
            }

            // Log the request
            $endpoint = $this->getEndpointPath();
            $log_data = $this->validator->logRequest($endpoint);

            // Set response headers
            $response_headers = $this->validator->prepareResponseHeaders();
            foreach ($response_headers as $name => $value) {
                header($name . ': ' . $value);
            }

            // Get input
            $input = $this->getJsonInput();

            // Handle finalization
            $response = $this->handleFinalize($sid, $input, $log_data);

            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            $this->sendServerError($e->getMessage());
        }

        exit;
    }

    private function handleFinalize($sid, $input, $log_data)
    {
        try {
            $headers = $this->validator->getExtractedHeaders();

            // Validate checkout session ID
            if (empty($sid)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Missing checkout session ID',
                    'code' => 400,
                    'timestamp' => date('c')
                ];
            }

            // Validate required payment and confirmation fields
            $validation = $this->validatePaymentConfirmation($input);
            if (!$validation['valid']) {
                if ($validation['terms_not_accepted'] ?? false) {
                    header('HTTP/1.1 400 Bad Request');
                    return [
                        'error' => [
                            'code' => 'TERMS_NOT_ACCEPTED',
                            'message' => 'User must accept terms and conditions before finalizing checkout'
                        ],
                        'timestamp' => date('c')
                    ];
                } elseif ($validation['payment_not_confirmed'] ?? false) {
                    header('HTTP/1.1 400 Bad Request');
                    return [
                        'error' => [
                            'code' => 'PAYMENT_NOT_CONFIRMED',
                            'message' => 'Payment must be confirmed before finalizing checkout',
                            'details' => [
                                'current_status' => $validation['current_status'],
                                'expected_status' => 'paid'
                            ]
                        ],
                        'timestamp' => date('c')
                    ];
                } else {
                    header('HTTP/1.1 400 Bad Request');
                    return [
                        'error' => 'Missing required fields',
                        'code' => 400,
                        'details' => $validation['errors'],
                        'timestamp' => date('c')
                    ];
                }
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
                'UCP',
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
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'ucp_agent' => $headers['ucp-agent'] ?? 'unknown',
                    'timestamp' => $log_data['timestamp'] ?? date('c')
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

        // Extract checkout session ID from URL pattern: /finalize/ucs_xxx
        $pattern = '/\/finalize\/(ucs_[a-f0-9]+_[\d_]+)$/';
        if (preg_match($pattern, $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getEndpointPath()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($request_uri);
        return $parsed_url['path'] ?? 'unknown';
    }

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

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Empty request body. Required fields for finalization:
{
  "payment": {
    "method": "card"
    "provider": "stripe"
    "transaction_id": "unique_transaction_id",
    "status": "paid"
  },
  "confirmation": {
    "accepted_terms": true
  }
}');
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg() . '. Expected valid JSON format.');
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

    /**
     * Valider les champs payment et confirmation requis
     */
    private function validatePaymentConfirmation($input)
    {
        $errors = [];

        // Validation du champ payment
        if (empty($input['payment'])) {
            $errors[] = 'Payment information is required';
        } else {
            $payment = $input['payment'];
            
            // Validation des sous-champs de payment
            if (empty($payment['method'])) {
                $errors[] = 'Payment method is required';
            }
            if (empty($payment['provider'])) {
                $errors[] = 'Payment provider is required';
            }
            if (empty($payment['transaction_id'])) {
                $errors[] = 'Payment transaction_id is required';
            }
            if (empty($payment['status'])) {
                $errors[] = 'Payment status is required';
            } else {
                // Vérifier si le statut est "paid"
                if ($payment['status'] !== 'paid') {
                    return [
                        'valid' => false,
                        'payment_not_confirmed' => true,
                        'current_status' => $payment['status'],
                        'errors' => ['Payment must be confirmed before finalizing checkout']
                    ];
                }
            }
        }

        // Validation du champ confirmation
        if (empty($input['confirmation'])) {
            $errors[] = 'Confirmation information is required';
        } else {
            $confirmation = $input['confirmation'];
            
            // Validation des sous-champs de confirmation
            if (!isset($confirmation['accepted_terms']) || $confirmation['accepted_terms'] !== true) {
                return [
                    'valid' => false,
                    'terms_not_accepted' => true,
                    'errors' => ['User must accept terms and conditions before finalizing checkout']
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'terms_not_accepted' => false,
            'payment_not_confirmed' => false,
            'errors' => $errors
        ];
    }
}
