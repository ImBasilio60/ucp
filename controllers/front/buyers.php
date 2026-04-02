<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpBuyerConverter.php';

class UcpbuyersModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $converter;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->converter = new UcpBuyerConverter();
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
        if (isset($input['customer_id'])) {
            return $this->getSingleCustomer($input['customer_id'], $headers, $log_data);
        } elseif (isset($input['search'])) {
            return $this->searchCustomers($input['search'], $headers, $log_data);
        } else {
            return $this->getAllCustomers($headers, $log_data);
        }
    }

    private function handlePost($headers, $input, $log_data)
    {
        // Handle batch customer conversion
        if (isset($input['customer_ids']) && is_array($input['customer_ids'])) {
            return $this->getBatchCustomers($input['customer_ids'], $headers, $log_data);
        } else {
            return [
                'error' => 'Invalid POST request',
                'message' => 'Expected customer_ids array in request body',
                'timestamp' => date('c')
            ];
        }
    }

    private function getSingleCustomer($customer_id, $headers, $log_data)
    {
        try {
            $customer_id = (int) $customer_id;
            $language_id = $this->getLanguageId($headers);
            $anonymize = isset($_GET['anonymize']) && $_GET['anonymize'] === 'true';

            $options = [
                'language_id' => $language_id,
                'anonymize' => $anonymize,
                'include_billing_address' => isset($_GET['include_billing']) && $_GET['include_billing'] !== 'false',
                'include_shipping_address' => isset($_GET['include_shipping']) && $_GET['include_shipping'] !== 'false'
            ];

            $buyer = $this->converter->getCustomerById($customer_id, $options);

            if (!$buyer) {
                header('HTTP/1.1 404 Not Found');
                return [
                    'error' => 'Customer not found',
                    'message' => "Customer with ID $customer_id not found",
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'timestamp' => date('c')
                ];
            }

            return [
                'status' => 'success',
                'data' => $buyer,
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'customer_id' => $customer_id,
                    'language_id' => $language_id,
                    'anonymized' => $anonymize,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Customer processing error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getAllCustomers($headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
            $anonymize = isset($_GET['anonymize']) && $_GET['anonymize'] === 'true';

            // Get all active customers
            $sql = new DbQuery();
            $sql->select('c.id_customer');
            $sql->from('customer', 'c');
            $sql->where('c.active = 1');
            $sql->orderBy('c.date_add', 'DESC');
            $sql->limit($limit, $offset);

            $customers = Db::getInstance()->executeS($sql);
            $customer_ids = array_column($customers, 'id_customer');

            // Get total count for pagination
            $count_sql = new DbQuery();
            $count_sql->select('COUNT(DISTINCT c.id_customer) as total');
            $count_sql->from('customer', 'c');
            $count_sql->where('c.active = 1');

            $total_result = Db::getInstance()->getRow($count_sql);
            $total = (int) $total_result['total'];

            if (empty($customer_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => $total,
                        'limit' => $limit,
                        'offset' => $offset
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'] ?? 'unknown',
                        'timestamp' => $log_data['timestamp'] ?? date('c')
                    ]
                ];
            }

            $options = [
                'language_id' => $language_id,
                'anonymize' => $anonymize,
                'include_billing_address' => isset($_GET['include_billing']) && $_GET['include_billing'] !== 'false',
                'include_shipping_address' => isset($_GET['include_shipping']) && $_GET['include_shipping'] !== 'false'
            ];

            $buyers = $this->converter->convertMultipleCustomers($customer_ids, $options);

            return [
                'status' => 'success',
                'data' => $buyers,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'language_id' => $language_id,
                    'anonymized' => $anonymize,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Customers list error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function searchCustomers($search_query, $headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
            $anonymize = isset($_GET['anonymize']) && $_GET['anonymize'] === 'true';

            $options = [
                'language_id' => $language_id,
                'anonymize' => $anonymize,
                'limit' => $limit,
                'offset' => $offset,
                'include_billing_address' => isset($_GET['include_billing']) && $_GET['include_billing'] !== 'false',
                'include_shipping_address' => isset($_GET['include_shipping']) && $_GET['include_shipping'] !== 'false'
            ];

            $buyers = $this->converter->searchCustomers($search_query, $options);

            return [
                'status' => 'success',
                'data' => $buyers,
                'search_info' => [
                    'query' => $search_query,
                    'total_results' => count($buyers)
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'language_id' => $language_id,
                    'anonymized' => $anonymize,
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Customer search error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function getBatchCustomers($customer_ids, $headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $anonymize = isset($_GET['anonymize']) && $_GET['anonymize'] === 'true';

            // Validate customer IDs
            $valid_customer_ids = array_filter($customer_ids, function($id) {
                return is_numeric($id) && $id > 0;
            });

            if (empty($valid_customer_ids)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid customer IDs',
                    'message' => 'No valid customer IDs provided',
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'timestamp' => date('c')
                ];
            }

            $options = [
                'language_id' => $language_id,
                'anonymize' => $anonymize,
                'include_billing_address' => true,
                'include_shipping_address' => true
            ];

            $buyers = $this->converter->convertMultipleCustomers($valid_customer_ids, $options);

            return [
                'status' => 'success',
                'data' => $buyers,
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'requested_ids' => $customer_ids,
                    'converted_ids' => array_column($buyers, 'id'),
                    'language_id' => $language_id,
                    'anonymized' => $anonymize,
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
