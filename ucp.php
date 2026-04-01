<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ucp extends Module
{
    public function __construct()
    {
        $this->name = 'ucp';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Basilio';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = 'UCP endpoint';
        $this->description = 'Expose /.well-known/ucp endpoint';
    }

    public function install()
    {
        // Generate RSA key pair if not exists
        $privateKeyPath = dirname(__FILE__) . '/private.pem';
        $publicKeyPath = dirname(__FILE__) . '/public.pem';
        
        if (!file_exists($privateKeyPath) || !file_exists($publicKeyPath)) {
            // Generate private key
            $config = [
                "digest_alg" => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ];
            $privateKey = openssl_pkey_new($config);
            
            // Save private key
            openssl_pkey_export_to_file($privateKey, $privateKeyPath);
            
            // Extract and save public key
            $publicKey = openssl_pkey_get_details($privateKey);
            file_put_contents($publicKeyPath, $publicKey['key']);
        }
        
        // Read public key
        $publicKeyPem = file_get_contents($publicKeyPath);
        
        if (!parent::install() ||
            !$this->registerHook('moduleRoutes') ||
            !Configuration::updateValue('UCP_PUBLIC_KEY', $publicKeyPem)
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Clean up configuration
        Configuration::deleteByName('UCP_PUBLIC_KEY');
        
        return parent::uninstall();
    }
}