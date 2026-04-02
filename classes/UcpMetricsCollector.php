<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpMetricsCollector
{
    private $metrics_file;
    private $request_start_time;

    public function __construct()
    {
        $this->metrics_file = _PS_MODULE_DIR_ . 'ucp/metrics.json';
        $this->request_start_time = microtime(true);
    }

    /**
     * Record a successful request
     */
    public function recordSuccess()
    {
        $this->updateMetrics('success');
    }

    /**
     * Record a 400 error
     */
    public function recordError400()
    {
        $this->updateMetrics('error_400');
    }

    /**
     * Record a 500 error
     */
    public function recordError500()
    {
        $this->updateMetrics('error_500');
    }

    /**
     * Update metrics based on request type
     */
    private function updateMetrics($type)
    {
        $metrics = $this->loadMetrics();
        
        // Calculate response time
        $response_time = (microtime(true) - $this->request_start_time) * 1000; // in milliseconds
        
        // Update total requests
        $metrics['total_requests']++;
        
        // Update error counts
        switch ($type) {
            case 'error_400':
                $metrics['error_400_count']++;
                break;
            case 'error_500':
                $metrics['error_500_count']++;
                break;
        }
        
        // Update response time metrics
        $this->updateResponseTimeMetrics($metrics, $response_time);
        
        // Update timestamps
        $metrics['last_request_timestamp'] = date('c');
        
        // Update requests per minute
        $this->updateRequestsPerMinute($metrics);
        
        // Save metrics
        $this->saveMetrics($metrics);
    }

    /**
     * Update response time statistics
     */
    private function updateResponseTimeMetrics(&$metrics, $response_time)
    {
        // Initialize if first request
        if (!isset($metrics['avg_response_time_ms'])) {
            $metrics['avg_response_time_ms'] = $response_time;
            $metrics['min_response_time_ms'] = $response_time;
            $metrics['max_response_time_ms'] = $response_time;
            return;
        }
        
        // Update average
        $total_requests = $metrics['total_requests'];
        $current_avg = $metrics['avg_response_time_ms'];
        $metrics['avg_response_time_ms'] = (($current_avg * ($total_requests - 1)) + $response_time) / $total_requests;
        
        // Update min and max
        $metrics['min_response_time_ms'] = min($metrics['min_response_time_ms'], $response_time);
        $metrics['max_response_time_ms'] = max($metrics['max_response_time_ms'], $response_time);
    }

    /**
     * Update requests per minute counter
     */
    private function updateRequestsPerMinute(&$metrics)
    {
        $current_minute = date('Y-m-d H:i');
        
        if (!isset($metrics['requests_per_minute'])) {
            $metrics['requests_per_minute'] = [];
        }
        
        if (!isset($metrics['requests_per_minute'][$current_minute])) {
            $metrics['requests_per_minute'][$current_minute] = 0;
        }
        
        $metrics['requests_per_minute'][$current_minute]++;
        
        // Clean old data (keep only last 60 minutes)
        $this->cleanupOldMetrics($metrics);
    }

    /**
     * Clean up old metrics data
     */
    private function cleanupOldMetrics(&$metrics)
    {
        $cutoff_time = time() - 3600; // 1 hour ago
        
        if (isset($metrics['requests_per_minute'])) {
            $metrics['requests_per_minute'] = array_filter(
                $metrics['requests_per_minute'],
                function($minute) use ($cutoff_time) {
                    return strtotime($minute) >= $cutoff_time;
                },
                ARRAY_FILTER_USE_KEY
            );
        }
    }

    /**
     * Load existing metrics or create default
     */
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

        // Return default metrics
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

    /**
     * Save metrics to file
     */
    private function saveMetrics($metrics)
    {
        $json = json_encode($metrics);
        if ($json !== false) {
            file_put_contents($this->metrics_file, $json, LOCK_EX);
        }
    }

    /**
     * Reset all metrics (useful for testing)
     */
    public function resetMetrics()
    {
        $default_metrics = [
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
        
        $this->saveMetrics($default_metrics);
    }

    /**
     * Get current metrics without modifying them
     */
    public function getCurrentMetrics()
    {
        return $this->loadMetrics();
    }
}
