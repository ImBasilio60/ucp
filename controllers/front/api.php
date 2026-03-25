<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';

class UcpapiModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
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

        switch ($method) {
            case 'GET':
                return $this->handleGet($headers, $log_data);

            case 'POST':
                $input = $this->getJsonInput();
                return $this->handlePost($headers, $input, $log_data);

            case 'PUT':
                $input = $this->getJsonInput();
                return $this->handlePut($headers, $input, $log_data);

            case 'DELETE':
                return $this->handleDelete($headers, $log_data);

            default:
                header('HTTP/1.1 405 Method Not Allowed');
                return [
                    'error' => 'Method not allowed',
                    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
                    'timestamp' => date('c')
                ];
        }
    }

    private function handleGet($headers, $log_data)
    {
        return [
            'status' => 'success',
            'message' => 'UCP API endpoint',
            'request_info' => [
                'request_id' => $headers['request-id'],
                'ucp_agent' => $headers['ucp-agent'],
                'idempotency_key' => $headers['idempotency-key'],
                'timestamp' => $log_data['timestamp']
            ],
            'server_info' => [
                'ucp_version' => '2026-03-13',
                'prestashop_version' => _PS_VERSION_,
                'module_version' => '1.0.0'
            ]
        ];
    }

    private function handlePost($headers, $input, $log_data)
    {
        // Example POST handler - could be extended for actual UCP operations
        return [
            'status' => 'success',
            'message' => 'UCP POST request processed',
            'request_id' => $headers['request-id'],
            'idempotency_key' => $headers['idempotency-key'],
            'processed_data' => $input,
            'timestamp' => date('c')
        ];
    }

    private function handlePut($headers, $input, $log_data)
    {
        // Example PUT handler
        return [
            'status' => 'success',
            'message' => 'UCP PUT request processed',
            'request_id' => $headers['request-id'],
            'idempotency_key' => $headers['idempotency-key'],
            'updated_data' => $input,
            'timestamp' => date('c')
        ];
    }

    private function handleDelete($headers, $log_data)
    {
        // Example DELETE handler
        return [
            'status' => 'success',
            'message' => 'UCP DELETE request processed',
            'request_id' => $headers['request-id'],
            'idempotency_key' => $headers['idempotency-key'],
            'timestamp' => date('c')
        ];
    }

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Empty request body. API endpoints accept JSON data for testing. Example:
{
  "test_field": "test_value",
  "data": {...}
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
}
