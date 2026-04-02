<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpHeaderValidator
{
    private $required_headers = [
        'UCP-Agent',
        'request-id',
        'idempotency-key',
        'request-signature'
    ];

    private $optional_headers = [
        'idempotency-key',
        'request-signature'
    ];

    private $extracted_headers = [];

    public function extractHeaders()
    {
        $headers = [];

        // Get all HTTP headers (case-insensitive)
        $all_headers = getallheaders();

        if ($all_headers === false) {
            $all_headers = [];
        }

        // Normalize and extract required headers
        foreach ($this->required_headers as $header) {
            $value = $this->getHeaderValue($all_headers, $header);
            if ($value !== null) {
                $headers[strtolower($header)] = $value;
            }
        }

        $this->extracted_headers = $headers;
        return $headers;
    }

    private function getHeaderValue($all_headers, $header_name)
    {
        // Case-insensitive header lookup
        foreach ($all_headers as $key => $value) {
            if (strtolower($key) === strtolower($header_name)) {
                return trim($value);
            }
        }
        return null;
    }

    public function validateHeaders($endpoint = null)
    {
        $errors = [];
        $headers = $this->extracted_headers;

        // Determine which headers are required based on endpoint
        $required_for_endpoint = $this->getRequiredHeadersForEndpoint($endpoint);

        // Check for missing required headers
        foreach ($required_for_endpoint as $header) {
            $key = strtolower($header);
            if (!isset($headers[$key]) || empty($headers[$key])) {
                $errors[] = [
                    'header' => $header,
                    'message' => 'Missing or empty required header'
                ];
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        // Validate specific header formats
        if (isset($headers['request-id']) && !$this->isValidUUID($headers['request-id'])) {
            $errors[] = [
                'header' => 'request-id',
                'message' => 'Invalid UUID format'
            ];
        }

        if (isset($headers['ucp-agent']) && !$this->isValidAgentString($headers['ucp-agent'])) {
            $errors[] = [
                'header' => 'UCP-Agent',
                'message' => 'UCP-Agent must be a non-empty string'
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function getRequiredHeadersForEndpoint($endpoint)
    {
        // For catalogue search endpoints, only UCP-Agent is required
        // request-id is optional but recommended for logging
        if ($endpoint && strpos($endpoint, 'items') !== false && isset($_GET['search'])) {
            return ['UCP-Agent'];
        }

        // For all other endpoints, all headers are required
        return $this->required_headers;
    }

    private function isValidUUID($uuid)
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    private function isValidAgentString($agent)
    {
        return is_string($agent) && !empty(trim($agent));
    }

    public function logRequest($endpoint)
    {
        $headers = $this->extracted_headers;

        // Mask idempotency key for security - show only first 8 characters
        $idempotency_key = $headers['idempotency-key'] ?? 'unknown';
        if ($idempotency_key !== 'unknown' && strlen($idempotency_key) > 8) {
            $idempotency_key = substr($idempotency_key, 0, 8) . '...';
        }

        $log_data = [
            'timestamp' => date('c'),
            'request_id' => $headers['request-id'] ?? 'unknown',
            'ucp_agent' => $headers['ucp-agent'] ?? 'unknown',
            'endpoint' => $endpoint,
            'idempotency_key' => $idempotency_key
        ];

        // Log to PrestaShop logger
        PrestaShopLogger::addLog(
            'UCP Request: ' . json_encode($log_data),
            1, // Info level
            null,
            'UCP',
            0,
            true
        );

        return $log_data;
    }

    public function prepareResponseHeaders()
    {
        $headers = [];

        // Echo back request-id if available
        if (isset($this->extracted_headers['request-id'])) {
            $headers['request-id'] = $this->extracted_headers['request-id'];
        }

        // Add UCP protocol response headers
        $headers['UCP-Version'] = '2026-03-13';
        $headers['UCP-Server'] = 'PrestaShop UCP Module';

        return $headers;
    }

    public function sendErrorResponse($errors)
    {
        header('Content-Type: application/json');
        header('HTTP/1.1 400 Bad Request');

        $error_response = [
            'error' => 'Invalid UCP Headers',
            'code' => 400,
            'details' => $errors,
            'timestamp' => date('c')
        ];

        echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function getExtractedHeaders()
    {
        return $this->extracted_headers;
    }
}
