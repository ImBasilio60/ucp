<?php

require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucp/classes/UcpOAuthConfig.php';

class UcpoauthModuleFrontController extends ModuleFrontController
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
        header('Cache-Control: public, max-age=3600'); // Cache 1h

        try {
            $metadata = $this->buildOAuthMetadata();

            // Validation stricte
            $this->validateMetadata($metadata);

            echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage());
        }

        exit;
    }

    /**
     * Construit les métadonnées OAuth dynamiquement
     *
     * @return array
     */
    private function buildOAuthMetadata()
    {
        $baseUrl = $this->getBaseUrl();
        $defaultUrls = UcpOAuthConfig::generateDefaultUrls();

        return [
            'issuer' => UcpOAuthConfig::getValue('UCP_OAUTH_ISSUER', $baseUrl),
            'authorization_endpoint' => UcpOAuthConfig::getValue('UCP_OAUTH_AUTH_ENDPOINT', $defaultUrls['UCP_OAUTH_AUTH_ENDPOINT']),
            'token_endpoint' => UcpOAuthConfig::getValue('UCP_OAUTH_TOKEN_ENDPOINT', $defaultUrls['UCP_OAUTH_TOKEN_ENDPOINT']),
            'jwks_uri' => UcpOAuthConfig::getValue('UCP_OAUTH_JWKS_URI', $defaultUrls['UCP_OAUTH_JWKS_URI']),
            'response_types_supported' => UcpOAuthConfig::getValue('UCP_OAUTH_RESPONSE_TYPES'),
            'grant_types_supported' => UcpOAuthConfig::getValue('UCP_OAUTH_GRANT_TYPES'),
            'token_endpoint_auth_methods_supported' => UcpOAuthConfig::getValue('UCP_OAUTH_AUTH_METHODS'),
            'scopes_supported' => UcpOAuthConfig::getValue('UCP_OAUTH_SCOPES'),
            'code_challenge_methods_supported' => ['S256'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'userinfo_endpoint' => $baseUrl . '/module/ucp/oauth/userinfo',
            'revocation_endpoint' => $baseUrl . '/module/ucp/oauth/revoke',
            'end_session_endpoint' => $baseUrl . '/module/ucp/oauth/logout'
        ];
    }

    /**
     * Récupère l'URL de base de la boutique
     *
     * @return string
     */
    private function getBaseUrl()
    {
        $context = Context::getContext();
        $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();

        return $ssl
            ? 'https://' . $context->shop->domain_ssl
            : 'http://' . $context->shop->domain;
    }

    /**
     * Validation stricte des métadonnées OAuth
     *
     * @param array $metadata
     * @throws Exception
     */
    private function validateMetadata($metadata)
    {
        $requiredFields = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'jwks_uri',
            'response_types_supported',
            'grant_types_supported',
            'token_endpoint_auth_methods_supported'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($metadata[$field]) || empty($metadata[$field])) {
                throw new Exception("Required field '{$field}' is missing or empty");
            }
        }

        // Validation des URLs
        $urlFields = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];
        foreach ($urlFields as $field) {
            if (!$this->isValidUrl($metadata[$field])) {
                throw new Exception("Field '{$field}' must be a valid URL");
            }
        }

        // Validation des tableaux
        $arrayFields = ['response_types_supported', 'grant_types_supported', 'token_endpoint_auth_methods_supported'];
        foreach ($arrayFields as $field) {
            if (!is_array($metadata[$field]) || empty($metadata[$field])) {
                throw new Exception("Field '{$field}' must be a non-empty array");
            }
        }
    }

    /**
     * Validation d'URL
     *
     * @param string $url
     * @return bool
     */
    private function isValidUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Envoie une réponse d'erreur structurée
     *
     * @param string $message
     */
    private function sendErrorResponse($message)
    {
        header('Content-Type: application/json');
        header('HTTP/1.1 500 Internal Server Error');

        $error_response = [
            'error' => true,
            'message' => 'OAuth metadata configuration invalid',
            'details' => $message,
            'timestamp' => date('c')
        ];

        echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
