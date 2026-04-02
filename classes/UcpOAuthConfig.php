<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Gestionnaire de configuration OAuth pour le module UCP
 */
class UcpOAuthConfig
{
    // Clés de configuration
    const CONFIG_KEYS = [
        'UCP_OAUTH_ISSUER' => 'string',
        'UCP_OAUTH_AUTH_ENDPOINT' => 'url',
        'UCP_OAUTH_TOKEN_ENDPOINT' => 'url',
        'UCP_OAUTH_JWKS_URI' => 'url',
        'UCP_OAUTH_RESPONSE_TYPES' => 'array',
        'UCP_OAUTH_GRANT_TYPES' => 'array',
        'UCP_OAUTH_AUTH_METHODS' => 'array',
        'UCP_OAUTH_SCOPES' => 'array'
    ];

    // Valeurs par défaut
    const DEFAULT_VALUES = [
        'UCP_OAUTH_RESPONSE_TYPES' => ['code'],
        'UCP_OAUTH_GRANT_TYPES' => ['authorization_code', 'refresh_token'],
        'UCP_OAUTH_AUTH_METHODS' => ['client_secret_post'],
        'UCP_OAUTH_SCOPES' => ['openid', 'profile', 'email', 'ucp']
    ];

    /**
     * Initialise les valeurs par défaut lors de l'installation
     *
     * @return bool
     */
    public static function installDefaults()
    {
        $success = true;

        foreach (self::DEFAULT_VALUES as $key => $value) {
            if (!Configuration::hasKey($key)) {
                $success &= Configuration::updateValue($key, json_encode($value));
            }
        }

        return $success;
    }

    /**
     * Nettoie les configurations lors de la désinstallation
     *
     * @return bool
     */
    public static function uninstall()
    {
        $success = true;

        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            $success &= Configuration::deleteByName($key);
        }

        return $success;
    }

    /**
     * Valide une valeur de configuration
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function validateValue($key, $value)
    {
        if (!isset(self::CONFIG_KEYS[$key])) {
            return false;
        }

        $type = self::CONFIG_KEYS[$key];

        switch ($type) {
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;

            case 'string':
                return is_string($value) && !empty(trim($value));

            case 'array':
                return is_array($value) && !empty($value);

            default:
                return true;
        }
    }

    /**
     * Met à jour une valeur de configuration avec validation
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function updateValue($key, $value)
    {
        if (!self::validateValue($key, $value)) {
            return false;
        }

        if (is_array($value)) {
            $value = json_encode($value);
        }

        return Configuration::updateValue($key, $value);
    }

    /**
     * Récupère une valeur de configuration
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getValue($key, $default = null)
    {
        $value = Configuration::get($key);

        if ($value === false || empty($value)) {
            return $default ?? self::DEFAULT_VALUES[$key] ?? null;
        }

        // Décoder les tableaux
        if (isset(self::CONFIG_KEYS[$key]) && self::CONFIG_KEYS[$key] === 'array') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $default;
        }

        return $value;
    }

    /**
     * Génère les URLs par défaut basées sur l'URL de la boutique
     *
     * @return array
     */
    public static function generateDefaultUrls()
    {
        $context = Context::getContext();
        $ssl = Configuration::get('PS_SSL_ENABLED') || Tools::usingSecureMode();
        $baseUrl = $ssl
            ? 'https://' . $context->shop->domain_ssl
            : 'http://' . $context->shop->domain;

        return [
            'UCP_OAUTH_ISSUER' => $baseUrl,
            'UCP_OAUTH_AUTH_ENDPOINT' => $baseUrl . '/module/ucp/oauth/authorize',
            'UCP_OAUTH_TOKEN_ENDPOINT' => $baseUrl . '/module/ucp/oauth/token',
            'UCP_OAUTH_JWKS_URI' => $baseUrl . '/module/ucp/oauth/jwks'
        ];
    }

    /**
     * Récupère toutes les configurations OAuth
     *
     * @return array
     */
    public static function getAllConfigurations()
    {
        $config = [];

        foreach (array_keys(self::CONFIG_KEYS) as $key) {
            $config[$key] = self::getValue($key);
        }

        return $config;
    }

    /**
     * Sauvegarde un tableau de configurations
     *
     * @param array $config
     * @return bool
     */
    public static function saveAllConfigurations($config)
    {
        $success = true;

        foreach ($config as $key => $value) {
            if (isset(self::CONFIG_KEYS[$key])) {
                $success &= self::updateValue($key, $value);
            }
        }

        return $success;
    }
}
