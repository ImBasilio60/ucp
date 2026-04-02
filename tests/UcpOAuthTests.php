<?php

/**
 * Tests automatisés pour le module UCP avec OAuth metadata
 */
class UcpOAuthTests
{
    private $baseUrl;
    private $testResults = [];

    public function __construct($baseUrl = 'http://localhost')
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Exécute tous les tests
     *
     * @return array
     */
    public function runAllTests()
    {
        echo "=== DÉBUT DES TESTS UCP OAUTH ===\n\n";

        $this->testOAuthEndpointAccessible();
        $this->testOAuthJsonValid();
        $this->testOAuthRequiredFields();
        $this->testOAuthUrlsValid();
        $this->testUcpManifestIntegrity();
        $this->testAuthSectionPresent();
        $this->testIdentityCapabilityPresent();
        $this->testSecurityNoSecrets();

        $this->printSummary();
        return $this->testResults;
    }

    /**
     * Test 1: Endpoint OAuth accessible
     */
    private function testOAuthEndpointAccessible()
    {
        $testName = "Endpoint OAuth accessible";
        $url = $this->baseUrl . '/prestashop/module/ucp/oauth';
        
        $response = $this->httpGet($url);
        $success = $response['status_code'] === 200;
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "HTTP {$response['status_code']}" : "HTTP {$response['status_code']} - Erreur"
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 2: JSON OAuth valide
     */
    private function testOAuthJsonValid()
    {
        $testName = "JSON OAuth valide";
        $url = $this->baseUrl . '/prestashop/module/ucp/oauth';
        
        $response = $this->httpGet($url);
        $success = $response['status_code'] === 200 && json_decode($response['body']) !== null;
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "JSON valide" : "JSON invalide"
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 3: Champs requis présents
     */
    private function testOAuthRequiredFields()
    {
        $testName = "Champs OAuth requis présents";
        $url = $this->baseUrl . '/prestashop/module/ucp/oauth';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $requiredFields = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'jwks_uri',
            'response_types_supported',
            'grant_types_supported',
            'token_endpoint_auth_methods_supported'
        ];
        
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        $success = empty($missing);
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Tous les champs présents" : "Manquants: " . implode(', ', $missing)
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 4: URLs valides
     */
    private function testOAuthUrlsValid()
    {
        $testName = "URLs OAuth valides";
        $url = $this->baseUrl . '/prestashop/module/ucp/oauth';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $urlFields = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];
        $invalidUrls = [];
        
        foreach ($urlFields as $field) {
            if (isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_URL)) {
                $invalidUrls[] = $field;
            }
        }
        
        $success = empty($invalidUrls);
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Toutes les URLs valides" : "URLs invalides: " . implode(', ', $invalidUrls)
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 5: Intégrité du manifest UCP
     */
    private function testUcpManifestIntegrity()
    {
        $testName = "Intégrité manifest UCP";
        $url = $this->baseUrl . '/prestashop/module/ucp/ucp';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $requiredSections = ['version', 'services', 'capabilities', 'payment', 'auth'];
        $missing = [];
        
        foreach ($requiredSections as $section) {
            if (!isset($data['ucp'][$section])) {
                $missing[] = $section;
            }
        }
        
        $success = empty($missing);
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Manifest complet" : "Sections manquantes: " . implode(', ', $missing)
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 6: Section auth présente
     */
    private function testAuthSectionPresent()
    {
        $testName = "Section auth présente";
        $url = $this->baseUrl . '/prestashop/module/ucp/ucp';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $authSection = $data['ucp']['auth'] ?? null;
        $success = $authSection && 
                  isset($authSection['type']) && 
                  isset($authSection['metadata_url']) &&
                  $authSection['type'] === 'oauth2';
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Section auth correcte" : "Section auth manquante ou incorrecte"
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 7: Capability identity présente
     */
    private function testIdentityCapabilityPresent()
    {
        $testName = "Capability identity présente";
        $url = $this->baseUrl . '/prestashop/module/ucp/ucp';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $identityCap = $data['ucp']['capabilities']['dev.ucp.shopping.identity'] ?? null;
        $success = !empty($identityCap);
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Capability identity présente" : "Capability identity manquante"
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Test 8: Aucun secret exposé
     */
    private function testSecurityNoSecrets()
    {
        $testName = "Aucun secret exposé";
        $url = $this->baseUrl . '/prestashop/module/ucp/oauth';
        
        $response = $this->httpGet($url);
        $data = json_decode($response['body'], true);
        
        $dangerousPatterns = ['secret', 'password', 'private_key', 'api_key', 'admin'];
        $allowedFields = [
            'token_endpoint', 
            'token_endpoint_auth_methods_supported',
            'authorization_endpoint',
            'revocation_endpoint'
        ];
        $foundSecrets = [];
        
        foreach ($data as $key => $value) {
            // Ignorer les champs OAuth standard
            if (in_array($key, $allowedFields)) {
                continue;
            }
            
            $keyLower = strtolower($key);
            $valueStr = is_array($value) ? json_encode($value) : (string)$value;
            $valueLower = strtolower($valueStr);
            
            foreach ($dangerousPatterns as $pattern) {
                if (strpos($keyLower, $pattern) !== false || strpos($valueLower, $pattern) !== false) {
                    $foundSecrets[] = $key;
                }
            }
        }
        
        $success = empty($foundSecrets);
        
        $this->testResults[$testName] = [
            'success' => $success,
            'details' => $success ? "Aucun secret exposé" : "Champs suspects: " . implode(', ', $foundSecrets)
        ];
        
        echo "✅ $testName: " . ($success ? "PASS" : "FAIL") . "\n";
    }

    /**
     * Effectue une requête HTTP GET
     *
     * @param string $url
     * @return array
     */
    private function httpGet($url)
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET'
            ]
        ]);
        
        $body = @file_get_contents($url, false, $context);
        $status_code = 200;
        
        if ($body === false) {
            $status_code = 500;
            $body = 'Connection failed';
        }
        
        return [
            'status_code' => $status_code,
            'body' => $body
        ];
    }

    /**
     * Affiche le résumé des tests
     */
    private function printSummary()
    {
        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults, fn($r) => $r['success']));
        $failed = $total - $passed;
        
        echo "\n=== RÉSUMÉ DES TESTS ===\n";
        echo "Total: $total, Passés: $passed, Échoués: $failed\n";
        
        if ($failed > 0) {
            echo "\n=== TESTS ÉCHOUÉS ===\n";
            foreach ($this->testResults as $test => $result) {
                if (!$result['success']) {
                    echo "❌ $test: {$result['details']}\n";
                }
            }
        }
        
        echo "\n" . ($failed === 0 ? "🎉 TOUS LES TESTS PASSÉS" : "⚠️  CERTAINS TESTS ONTS ÉCHOUÉ") . "\n";
    }
}

// Exécution des tests si appelé directement
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $tests = new UcpOAuthTests();
    $tests->runAllTests();
}
