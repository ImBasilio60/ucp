<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpOrderConverter.php';

class UcpordersModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $converter;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->converter = new UcpOrderConverter();
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

        switch ($method) {
            case 'GET':
                return $this->handleGet($headers, $log_data);

            case 'POST':
                $input = $this->getJsonInput();
                return $this->handlePost($headers, $input, $log_data);

            default:
                header('HTTP/1.1 405 Method Not Allowed');
                return [
                    'error' => 'Method not allowed',
                    'allowed_methods' => ['GET', 'POST'],
                    'timestamp' => date('c')
                ];
        }
    }

    private function handleGet($headers, $log_data)
    {
        $input = $_GET;

        // Handle different GET endpoints
        if (isset($input['cart_id'])) {
            return $this->getSingleCart($input['cart_id'], $headers, $log_data);
        } elseif (isset($input['customer_id'])) {
            return $this->getCustomerCarts($input['customer_id'], $headers, $log_data);
        } else {
            return $this->getActiveCarts($headers, $log_data);
        }
    }

    private function handlePost($headers, $input, $log_data)
    {
        // Handle batch cart conversion
        if (isset($input['cart_ids']) && is_array($input['cart_ids'])) {
            return $this->getBatchCarts($input['cart_ids'], $headers, $log_data);
        } else {
            return [
                'error' => 'Invalid POST request',
                'message' => 'Expected cart_ids array in request body',
                'timestamp' => date('c')
            ];
        }
    }

    private function getSingleCart($cart_id, $headers, $log_data)
    {
        try {
            $cart_id = (int) $cart_id;
            $language_id = $this->getLanguageId($headers);

            $cart = new Cart($cart_id);

            if (!Validate::isLoadedObject($cart)) {
                header('HTTP/1.1 404 Not Found');
                return [
                    'error' => 'Cart not found',
                    'message' => "Cart with ID $cart_id not found",
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'timestamp' => date('c')
                ];
            }

            $ucp_order = $this->converter->convertCartToUcpOrder($cart, $language_id);

            return [
                'status' => 'success',
                'data' => $ucp_order,
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'cart_id' => $cart_id,
                    'language_id' => $language_id,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Cart processing error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getCustomerCarts($customer_id, $headers, $log_data)
    {
        try {
            $customer_id = (int) $customer_id;
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            // Get customer carts
            $sql = new DbQuery();
            $sql->select('c.id_cart');
            $sql->from('cart', 'c');
            $sql->where('c.id_customer = ' . $customer_id);
            $sql->orderBy('c.date_add', 'DESC');
            $sql->limit($limit, $offset);

            $carts = Db::getInstance()->executeS($sql);
            $cart_ids = array_column($carts, 'id_cart');

            if (empty($cart_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'limit' => $limit,
                        'offset' => $offset
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'] ?? 'unknown',
                        'customer_id' => $customer_id,
                        'timestamp' => $log_data['timestamp'] ?? date('c')
                    ]
                ];
            }

            $ucp_orders = $this->converter->convertMultipleCarts($cart_ids, $language_id);

            return [
                'status' => 'success',
                'data' => $ucp_orders,
                'pagination' => [
                    'total' => count($ucp_orders),
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'customer_id' => $customer_id,
                    'language_id' => $language_id,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Customer carts error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getActiveCarts($headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            // Get active carts (with products)
            $sql = new DbQuery();
            $sql->select('DISTINCT c.id_cart');
            $sql->from('cart', 'c');
            $sql->leftJoin('cart_product', 'cp', 'cp.id_cart = c.id_cart');
            $sql->where('cp.id_product IS NOT NULL');
            $sql->where('c.date_add > DATE_SUB(NOW(), INTERVAL 30 DAY)'); // Last 30 days
            $sql->orderBy('c.date_add', 'DESC');
            $sql->limit($limit, $offset);

            $carts = Db::getInstance()->executeS($sql);
            $cart_ids = array_column($carts, 'id_cart');

            if (empty($cart_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'limit' => $limit,
                        'offset' => $offset
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'] ?? 'unknown',
                        'timestamp' => $log_data['timestamp'] ?? date('c')
                    ]
                ];
            }

            $ucp_orders = $this->converter->convertMultipleCarts($cart_ids, $language_id);

            return [
                'status' => 'success',
                'data' => $ucp_orders,
                'pagination' => [
                    'total' => count($ucp_orders),
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'language_id' => $language_id,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Active carts error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getBatchCarts($cart_ids, $headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);

            // Validate cart IDs
            $valid_cart_ids = array_filter($cart_ids, function($id) {
                return is_numeric($id) && $id > 0;
            });

            if (empty($valid_cart_ids)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid cart IDs',
                    'message' => 'No valid cart IDs provided',
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'timestamp' => date('c')
                ];
            }

            $ucp_orders = $this->converter->convertMultipleCarts($valid_cart_ids, $language_id);

            return [
                'status' => 'success',
                'data' => $ucp_orders,
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'requested_ids' => $cart_ids,
                    'converted_ids' => array_column($ucp_orders, function($order) {
                        return $order['metadata']['prestashop_cart_id'];
                    }),
                    'language_id' => $language_id,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Batch conversion error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getLanguageId($headers)
    {
        // Try to get language from headers
        if (isset($headers['accept-language'])) {
            $language_code = substr($headers['accept-language'], 0, 2);
            $language_id = Language::getIdByIso($language_code);
            if ($language_id) {
                return (int) $language_id;
            }
        }

        // Fall back to default language
        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
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
