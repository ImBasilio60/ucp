<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';

class UcpfulfillmentModuleFrontController extends ModuleFrontController
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

            // Record successful request
            $this->validator->recordSuccess();

            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            // Record 500 error
            $this->validator->recordError500();
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

            default:
                header('HTTP/1.1 405 Method Not Allowed');
                return [
                    'error' => 'Method not allowed',
                    'allowed_methods' => ['GET', 'POST', 'PUT'],
                    'timestamp' => date('c')
                ];
        }
    }

    private function handleGet($headers, $log_data)
    {
        // Get fulfillment information
        return [
            'status' => 'success',
            'message' => 'UCP Fulfillment endpoint',
            'request_info' => [
                'request_id' => $headers['request-id'] ?? 'unknown',
                'ucp_agent' => $headers['ucp-agent'] ?? 'unknown',
                'idempotency_key' => $headers['idempotency-key'] ?? 'unknown',
                'timestamp' => $log_data['timestamp'] ?? date('c')
            ],
            'capabilities' => [
                'shipping_methods' => ['standard', 'express', 'pickup'],
                'tracking_supported' => true,
                'carriers' => ['dhl', 'ups', 'fedex', 'local'],
                'delivery_estimates' => true
            ],
            'endpoints' => [
                'create_shipment' => '/module/ucp/fulfillment/shipment',
                'track_package' => '/module/ucp/fulfillment/track/{tracking_id}',
                'update_status' => '/module/ucp/fulfillment/status/{shipment_id}'
            ]
        ];
    }

    private function handlePost($headers, $input, $log_data)
    {
        // Handle shipment creation
        $shipment_id = 'shp_' . uniqid();

        return [
            'status' => 'success',
            'message' => 'Shipment created successfully',
            'request_id' => $headers['request-id'] ?? 'unknown',
            'shipment' => [
                'id' => $shipment_id,
                'status' => 'created',
                'tracking_number' => 'TRK' . strtoupper(substr(md5($shipment_id), 0, 10)),
                'carrier' => $input['carrier'] ?? 'standard',
                'estimated_delivery' => date('Y-m-d', strtotime('+3 days')),
                'created_at' => date('c')
            ]
        ];
    }

    private function handlePut($headers, $input, $log_data)
    {
        // Handle shipment status updates
        return [
            'status' => 'success',
            'message' => 'Shipment status updated',
            'request_id' => $headers['request-id'] ?? 'unknown',
            'shipment' => [
                'id' => $input['shipment_id'] ?? 'unknown',
                'status' => $input['status'] ?? 'updated',
                'updated_at' => date('c')
            ]
        ];
    }

    private function getJsonInput()
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            throw new Exception('Empty request body. Fulfillment endpoints accept JSON data for processing.');
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
