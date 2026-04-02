<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';

class UcpmetricsModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    private $validator;
    private $metrics_file;

    public function __construct()
    {
        parent::__construct();
        $this->validator = new UcpHeaderValidator();
        $this->metrics_file = _PS_MODULE_DIR_ . 'ucp/metrics.json';
    }

    public function initContent()
    {
        header('Content-Type: application/json');

        try {
            // Extract and validate UCP headers (lighter validation for metrics endpoint)
            $this->validator->extractHeaders();
            $endpoint = '/metrics';
            
            // For metrics endpoint, only validate basic headers
            $validation = $this->validateMetricsHeaders();

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

        // Prevent template rendering
        exit;
    }

    private function validateMetricsHeaders()
    {
        $errors = [];
        $headers = $this->validator->getExtractedHeaders();

        // Only require UCP-Agent for metrics endpoint
        if (!isset($headers['ucp-agent']) || empty(trim($headers['ucp-agent']))) {
            $errors[] = [
                'header' => 'UCP-Agent',
                'message' => 'UCP-Agent header is required for metrics endpoint'
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function processRequest($method, $log_data)
    {
        $headers = $this->validator->getExtractedHeaders();

        switch ($method) {
            case 'GET':
                return $this->getMetrics($headers, $log_data);

            default:
                header('HTTP/1.1 405 Method Not Allowed');
                return [
                    'error' => 'Method not allowed',
                    'code' => 405,
                    'message' => 'Only GET method is allowed for metrics endpoint',
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'timestamp' => date('c')
                ];
        }
    }

    private function getMetrics($headers, $log_data)
    {
        try {
            // Load existing metrics
            $metrics = $this->loadMetrics();
            
            // Update metrics with current request
            $this->updateMetrics($metrics);

            // Calculate additional metrics
            $error_rate = $metrics['total_requests'] > 0 ? 
                round(($metrics['error_400_count'] / $metrics['total_requests']) * 100, 2) : 0;

            $response = [
                'status' => 'success',
                'timestamp' => date('c'),
                'metrics' => [
                    'requests' => [
                        'total' => $metrics['total_requests'],
                        'success' => $metrics['total_requests'] - $metrics['error_400_count'] - $metrics['error_500_count'],
                        'error_400' => $metrics['error_400_count'],
                        'error_500' => $metrics['error_500_count']
                    ],
                    'performance' => [
                        'avg_response_time_ms' => $metrics['avg_response_time_ms'],
                        'min_response_time_ms' => $metrics['min_response_time_ms'],
                        'max_response_time_ms' => $metrics['max_response_time_ms']
                    ],
                    'rates' => [
                        'error_rate_percent' => $error_rate,
                        'requests_per_minute' => $this->calculateRequestsPerMinute($metrics)
                    ],
                    'uptime' => [
                        'module_uptime_seconds' => $this->getModuleUptime(),
                        'last_request' => $metrics['last_request_timestamp']
                    ]
                ],
                'request_info' => [
                    'request_id' => $headers['request-id'] ?? 'unknown',
                    'ucp_agent' => $headers['ucp-agent'] ?? 'unknown',
                    'timestamp' => $log_data['timestamp'] ?? date('c')
                ]
            ];

            return $response;

        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            return [
                'error' => 'Metrics collection failed',
                'code' => 500,
                'message' => $e->getMessage(),
                'request_id' => $headers['request-id'] ?? 'unknown',
                'timestamp' => date('c')
            ];
        }
    }

    private function loadMetrics()
    {
        if (file_exists($this->metrics_file)) {
            $content = file_get_contents($this->metrics_file);
            if ($content !== false) {
                $metrics = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $metrics;
                }
            }
        }

        // Return default metrics if file doesn't exist or is invalid
        return [
            'total_requests' => 0,
            'error_400_count' => 0,
            'error_500_count' => 0,
            'avg_response_time_ms' => 0,
            'min_response_time_ms' => PHP_INT_MAX,
            'max_response_time_ms' => 0,
            'last_request_timestamp' => null,
            'start_time' => time(),
            'requests_per_minute' => []
        ];
    }

    private function updateMetrics(&$metrics)
    {
        // Update request count
        $metrics['total_requests']++;
        
        // Update last request timestamp
        $metrics['last_request_timestamp'] = date('c');
        
        // Update requests per minute
        $current_minute = date('Y-m-d H:i');
        if (!isset($metrics['requests_per_minute'][$current_minute])) {
            $metrics['requests_per_minute'][$current_minute] = 0;
        }
        $metrics['requests_per_minute'][$current_minute]++;
        
        // Clean old data (keep only last 60 minutes)
        $cutoff_time = time() - 3600; // 1 hour ago
        $metrics['requests_per_minute'] = array_filter(
            $metrics['requests_per_minute'],
            function($minute) use ($cutoff_time) {
                return strtotime($minute) >= $cutoff_time;
            },
            ARRAY_FILTER_USE_KEY
        );
        
        // Save updated metrics
        $this->saveMetrics($metrics);
    }

    private function saveMetrics($metrics)
    {
        $json = json_encode($metrics);
        if ($json !== false) {
            file_put_contents($this->metrics_file, $json, LOCK_EX);
        }
    }

    private function calculateRequestsPerMinute($metrics)
    {
        $total = 0;
        $count = 0;
        
        foreach ($metrics['requests_per_minute'] as $minute => $requests) {
            $total += $requests;
            $count++;
        }
        
        return $count > 0 ? round($total / $count, 2) : 0;
    }

    private function getModuleUptime()
    {
        $metrics = $this->loadMetrics();
        return isset($metrics['start_time']) ? time() - $metrics['start_time'] : 0;
    }

    private function sendServerError($message)
    {
        header('Content-Type: application/json');
        header('HTTP/1.1 500 Internal Server Error');

        $error_response = [
            'error' => 'Internal server error',
            'code' => 500,
            'message' => $message,
            'timestamp' => date('c')
        ];

        echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
