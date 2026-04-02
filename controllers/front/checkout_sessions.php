<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpCheckoutSessionValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpSessionManager.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpBuyerManager.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpCartManager.php';

class Ucpcheckout_sessionsModuleFrontController extends ModuleFrontController
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
            // Validate Content-Type for transactional endpoint
            $content_type_validation = $this->validator->validateContentType();
            if (!$content_type_validation['valid']) {
                $this->validator->sendContentTypeErrorResponse($content_type_validation['error']);
                return;
            }

            // Extract and validate UCP headers
            $this->validator->extractHeaders();
            $endpoint = $this->getEndpointPath();
            $validation = $this->validator->validateHeaders($endpoint);

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

            // Valider le pays AVANT de créer la session
            try {
                $this->validateCountry($input['buyer']['country'] ?? '');
            } catch (Exception $e) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid country',
                    'code' => 400,
                    'details' => [$e->getMessage()],
                    'timestamp' => date('c')
                ];
            }

            // Create temporary UCP session (NO PrestaShop interaction)
            try {
                $session_data = $this->session_manager->createSession(
                    $checkout_id,
                    $input['buyer'],
                    $input['line_items'],
                    $headers
                );
            } catch (Exception $e) {
                // Intercepter les erreurs de validation des champs buyer
                if (strpos($e->getMessage(), 'Missing required buyer fields') !== false) {
                    header('HTTP/1.1 400 Bad Request');
                    return [
                        'error' => 'Missing required buyer fields',
                        'code' => 400,
                        'message' => $e->getMessage(),
                        'required_fields' => [
                            'email',
                            'first_name',
                            'last_name', 
                            'address',
                            'city',
                            'postal_code',
                            'country',
                            'phone'
                        ],
                        'timestamp' => date('c')
                    ];
                }
                throw $e; // Relancer les autres exceptions
            }

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
                'UCP',
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
                'UCP',
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
                'prestashop_order_id' => $finalize_result['order_id'],
                'prestashop_order_reference' => $finalize_result['order_reference'],
                'order_created' => $finalize_result['order_created'],
                'finalized_at' => $finalize_result['session_data']['finalized_at'],
                'message' => 'Session finalized successfully. ' . ($finalize_result['order_created'] ? 'PrestaShop cart and order created.' : 'PrestaShop cart created, order creation failed.'),
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
                'UCP',
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

    /**
     * Valider le pays et retourner une erreur avec la liste des pays valides si invalide
     */
    private function validateCountry($country)
    {
        if (empty($country)) {
            throw new Exception('Country is required');
        }

        $country_input = strtoupper(trim($country));
        
        // Essayer de trouver le pays par code ISO d'abord
        $sql = 'SELECT id_country FROM ' . _DB_PREFIX_ . 'country WHERE iso_code = "' . pSQL($country_input) . '"';
        $result = Db::getInstance()->getValue($sql);
        if ($result) {
            return true; // Pays valide
        }

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
                return true; // Pays valide après conversion
            }
        }

        // Si le pays n'est toujours pas trouvé, retourner une erreur avec la liste des pays valides
        $sql_countries = 'SELECT iso_code FROM ' . _DB_PREFIX_ . 'country WHERE active = 1 ORDER BY iso_code';
        $valid_countries = Db::getInstance()->executeS($sql_countries);
        
        $country_list = array_map(function($country) {
            return $country['iso_code'];
        }, $valid_countries);
        
        // Ajouter les noms complets supportés
        $supported_names = array_keys($country_mapping);
        $full_list = array_merge($country_list, $supported_names);
        sort($full_list);
        
        throw new Exception('Invalid country "' . $country . '". Valid countries are: ' . implode(', ', $full_list));
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
            $request_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            
            // Déterminer le type de requête pour un message d'erreur spécifique
            if (strpos($request_uri, 'finalize') !== false || (isset($_GET['action']) && $_GET['action'] === 'finalize')) {
                throw new Exception('Empty request body. Required fields for finalization:
{
  "payment": {
    "method": "card|paypal|...",
    "provider": "stripe|adyen|...", 
    "transaction_id": "unique_transaction_id",
    "status": "paid"
  },
  "confirmation": {
    "accepted_terms": true
  }
}');
            } elseif ($request_method === 'POST') {
                throw new Exception('Empty request body. Required fields for session creation:
{
  "line_items": [
    {
      "product_id": 123,
      "quantity": 1,
      "customization_data": {...}
    }
  ],
  "buyer": {
    "email": "client@example.com",
    "first_name": "Jean",
    "last_name": "Dupont", 
    "address": "123 rue de la Paix",
    "city": "Paris",
    "postal_code": "75001",
    "country": "France",
    "phone": "+33612345678"
  }
}');
            } elseif ($request_method === 'PUT') {
                throw new Exception('Empty request body. Required fields for session update:
{
  "line_items": [
    {
      "product_id": 123,
      "quantity": 1
    }
  ],
  "promo_code": "OPTIONAL_PROMO_CODE"
}');
            } else {
                throw new Exception('Empty request body. Please provide valid JSON data.');
            }
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
