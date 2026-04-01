<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpItemConverter.php';

class UcpitemsModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $converter;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->converter = new UcpItemConverter();
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

            // Log the request
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
        if (isset($input['product_id'])) {
            return $this->getSingleProduct($input['product_id'], $headers, $log_data);
        } elseif (isset($input['category_id'])) {
            return $this->getProductsByCategory($input['category_id'], $headers, $log_data);
        } elseif (isset($input['search'])) {
            return $this->searchProducts($input['search'], $headers, $log_data);
        } else {
            return $this->getProductList($headers, $log_data);
        }
    }

    private function handlePost($headers, $input, $log_data)
    {
        // Handle batch product conversion
        if (isset($input['product_ids']) && is_array($input['product_ids'])) {
            return $this->getBatchProducts($input['product_ids'], $headers, $log_data);
        } else {
            return [
                'error' => 'Invalid POST request',
                'message' => 'Expected product_ids array in request body',
                'timestamp' => date('c')
            ];
        }
    }

    private function getSingleProduct($product_id, $headers, $log_data)
    {
        try {
            $product_id = (int) $product_id;
            $language_id = $this->getLanguageId($headers);
            $include_combinations = $this->shouldIncludeCombinations($_GET);

            $ucp_item = $this->converter->convertProductToUcpItem($product_id, $language_id, $include_combinations);

            return [
                'status' => 'success',
                'data' => $ucp_item,
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'product_id' => $product_id,
                    'language_id' => $language_id,
                    'include_combinations' => $include_combinations,
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 404 Not Found');
            return [
                'error' => 'Product not found',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'],
                'timestamp' => date('c')
            ];
        }
    }

    private function getProductsByCategory($category_id, $headers, $log_data)
    {
        try {
            $category_id = (int) $category_id;
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            // Get active products in category using a more precise query
            $sql = new DbQuery();
            $sql->select('p.id_product');
            $sql->from('product', 'p');
            $sql->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = ' . (int)$language_id);
            $sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $sql->leftJoin('category_product', 'cp', 'cp.id_product = p.id_product');
            $sql->where('p.active = 1');
            $sql->where('ps.active = 1');
            $sql->where('pl.name IS NOT NULL AND pl.name != ""');
            $sql->where('cp.id_category = ' . $category_id);
            $sql->orderBy('pl.name', 'ASC');
            $sql->limit($limit, $offset);

            $products = Db::getInstance()->executeS($sql);
            $product_ids = array_column($products, 'id_product');

            // Get total count for pagination
            $count_sql = new DbQuery();
            $count_sql->select('COUNT(DISTINCT p.id_product) as total');
            $count_sql->from('product', 'p');
            $count_sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $count_sql->leftJoin('category_product', 'cp', 'cp.id_product = p.id_product');
            $count_sql->where('p.active = 1');
            $count_sql->where('ps.active = 1');
            $count_sql->where('cp.id_category = ' . $category_id);

            $total_result = Db::getInstance()->getRow($count_sql);
            $total = (int) $total_result['total'];

            if (empty($product_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => $total,
                        'limit' => $limit,
                        'offset' => $offset
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'],
                        'category_id' => $category_id,
                        'timestamp' => $log_data['timestamp']
                    ]
                ];
            }

            $include_combinations = $this->shouldIncludeCombinations($_GET);
            $ucp_items = $this->converter->convertMultipleProducts($product_ids, $language_id, $include_combinations);

            return [
                'status' => 'success',
                'data' => $ucp_items,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'category_id' => $category_id,
                    'language_id' => $language_id,
                    'include_combinations' => $include_combinations,
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Category processing error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'],
                'timestamp' => date('c')
            ];
        }
    }

    private function searchProducts($search_query, $headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            // Search active products using a more precise query
            $sql = new DbQuery();
            $sql->select('p.id_product');
            $sql->from('product', 'p');
            $sql->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = ' . (int)$language_id);
            $sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $sql->where('p.active = 1');
            $sql->where('ps.active = 1');
            $sql->where('pl.name IS NOT NULL AND pl.name != ""');
            $sql->where('(pl.name LIKE "%' . pSQL($search_query) . '%" OR pl.description LIKE "%' . pSQL($search_query) . '%" OR pl.description_short LIKE "%' . pSQL($search_query) . '%")');
            $sql->orderBy('pl.name', 'ASC');
            $sql->limit($limit, $offset);

            $products = Db::getInstance()->executeS($sql);
            $product_ids = array_column($products, 'id_product');

            // Get total count for pagination
            $count_sql = new DbQuery();
            $count_sql->select('COUNT(DISTINCT p.id_product) as total');
            $count_sql->from('product', 'p');
            $count_sql->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = ' . (int)$language_id);
            $count_sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $count_sql->where('p.active = 1');
            $count_sql->where('ps.active = 1');
            $count_sql->where('(pl.name LIKE "%' . pSQL($search_query) . '%" OR pl.description LIKE "%' . pSQL($search_query) . '%" OR pl.description_short LIKE "%' . pSQL($search_query) . '%")');

            $total_result = Db::getInstance()->getRow($count_sql);
            $total = (int) $total_result['total'];

            if (empty($product_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'search_info' => [
                        'query' => $search_query,
                        'total_results' => $total
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'],
                        'timestamp' => $log_data['timestamp']
                    ]
                ];
            }

            $include_combinations = $this->shouldIncludeCombinations($_GET);
            $ucp_items = $this->converter->convertMultipleProducts($product_ids, $language_id, $include_combinations);

            return [
                'status' => 'success',
                'data' => $ucp_items,
                'search_info' => [
                    'query' => $search_query,
                    'total_results' => $total
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'language_id' => $language_id,
                    'include_combinations' => $include_combinations,
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Search error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'],
                'timestamp' => date('c')
            ];
        }
    }

    private function getProductList($headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

            // Get all active and visible products using a more precise query
            $sql = new DbQuery();
            $sql->select('p.id_product');
            $sql->from('product', 'p');
            $sql->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = ' . (int)$language_id);
            $sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $sql->where('p.active = 1');
            $sql->where('ps.active = 1');
            $sql->where('pl.name IS NOT NULL AND pl.name != ""');
            $sql->orderBy('pl.name', 'ASC');
            $sql->limit($limit, $offset);

            $products = Db::getInstance()->executeS($sql);
            $product_ids = array_column($products, 'id_product');

            // Get total count for pagination
            $count_sql = new DbQuery();
            $count_sql->select('COUNT(DISTINCT p.id_product) as total');
            $count_sql->from('product', 'p');
            $count_sql->leftJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)Context::getContext()->shop->id);
            $count_sql->where('p.active = 1');
            $count_sql->where('ps.active = 1');

            $total_result = Db::getInstance()->getRow($count_sql);
            $total = (int) $total_result['total'];

            if (empty($product_ids)) {
                return [
                    'status' => 'success',
                    'data' => [],
                    'pagination' => [
                        'total' => $total,
                        'limit' => $limit,
                        'offset' => $offset
                    ],
                    'request_info' => [
                        'request_id' => $headers['request-id'],
                        'timestamp' => $log_data['timestamp']
                    ]
                ];
            }

            $include_combinations = $this->shouldIncludeCombinations($_GET);
            $ucp_items = $this->converter->convertMultipleProducts($product_ids, $language_id, $include_combinations);

            return [
                'status' => 'success',
                'data' => $ucp_items,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'language_id' => $language_id,
                    'include_combinations' => $include_combinations,
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Product list error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'],
                'timestamp' => date('c')
            ];
        }
    }

    private function getBatchProducts($product_ids, $headers, $log_data)
    {
        try {
            $language_id = $this->getLanguageId($headers);
            $include_combinations = $this->shouldIncludeCombinations($_GET);

            // Validate product IDs
            $valid_product_ids = array_filter($product_ids, function($id) {
                return is_numeric($id) && $id > 0;
            });

            if (empty($valid_product_ids)) {
                header('HTTP/1.1 400 Bad Request');
                return [
                    'error' => 'Invalid product IDs',
                    'message' => 'No valid product IDs provided',
                    'request_id' => $headers['request-id'],
                    'timestamp' => date('c')
                ];
            }

            $ucp_items = $this->converter->convertMultipleProducts($valid_product_ids, $language_id, $include_combinations);

            return [
                'status' => 'success',
                'data' => $ucp_items,
                'request_info' => [
                    'request_id' => $headers['request-id'],
                    'requested_ids' => $product_ids,
                    'converted_ids' => array_column($ucp_items, 'id'),
                    'language_id' => $language_id,
                    'include_combinations' => $include_combinations,
                    'timestamp' => $log_data['timestamp']
                ]
            ];

        } catch (Exception $e) {
            header('HTTP/1.1 400 Bad Request');
            return [
                'error' => 'Batch conversion error',
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'],
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

    private function shouldIncludeCombinations($params)
    {
        // Default to true unless explicitly set to false
        return !isset($params['include_combinations']) || $params['include_combinations'] !== 'false';
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
