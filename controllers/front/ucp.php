<?php

class UcpucpModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        header('Content-Type: application/json');

        // Get public key from configuration
        $publicKeyPem = Configuration::get('UCP_PUBLIC_KEY');
        
        // Convert PEM to JWK
        $signingKeys = [];
        if ($publicKeyPem) {
            $jwk = $this->pemToJwk($publicKeyPem);
            if ($jwk) {
                $signingKeys = [$jwk];
            }
        }

        $json = [
            "ucp" => [
                "version" => "2026-01-23",
                "signing_keys" => $signingKeys,
                "services" => [
                    "dev.ucp.shopping" => [
                        [
                            "version" => "2026-01-23",
                            "spec" => "https://ucp.dev/2026-01-23/specification/overview",
                            "transport" => "rest",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp",
                            "schema" => "https://ucp.dev/2026-01-23/services/shopping/openapi.json"
                        ]
                    ]
                ],
                "capabilities" => [
                    "dev.ucp.shopping.checkout" => [
                        [
                            "version" => "2026-01-23",
                            "spec" => "https://ucp.dev/2026-01-23/specification/checkout",
                            "schema" => "https://ucp.dev/2026-01-23/schemas/shopping/checkout.json"
                        ]
                    ],
                    "dev.ucp.shopping.catalog" => [
                        [
                            "version" => "2026-01-23",
                            "spec" => "https://ucp.dev/2026-01-23/specification/catalog",
                            "schema" => "https://ucp.dev/2026-01-23/schemas/shopping/catalog.json"
                        ]
                    ],
                    "dev.ucp.shopping.identity" => [
                        [
                            "version" => "2026-01-23",
                            "spec" => "https://ucp.dev/2026-01-23/specification/identity",
                            "schema" => "https://ucp.dev/2026-01-23/schemas/shopping/identity.json"
                        ]
                    ],
                    "dev.ucp.shopping.order" => [
                        [
                            "version" => "2026-01-23",
                            "spec" => "https://ucp.dev/2026-01-23/specification/order",
                            "schema" => "https://ucp.dev/2026-01-23/schemas/shopping/order.json"
                        ]
                    ]
                ],
                "payment_handlers" => [
                    "com.stripe" => [
                        [
                            "id" => "stripe",
                            "version" => "2026-01-23",
                            "spec" => "https://stripe.com/payments/ucp/2026-01-23/",
                            "schema" => "https://stripe.com/payments/ucp/2026-01-23/schemas/config.json",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/payment/stripe/webhook",
                            "config" => [
                                "api_version" => "2026-01-23",
                                "merchant_info" => [
                                    "merchant_name" => "Randevteam",
                                    "merchant_id" => "acct_123456789",
                                    "merchant_origin" => "https://www.passioncampagne9.projets-omega.net"
                                ],
                                "payment_methods" => [
                                    "card",
                                    "alipay",
                                    "apple_pay"
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function pemToJwk($pemKey)
    {
        // Use OpenSSL to parse the public key
        $publicKey = openssl_pkey_get_public($pemKey);
        if (!$publicKey) {
            return null;
        }
        
        $details = openssl_pkey_get_details($publicKey);
        if (!$details || !isset($details['rsa'])) {
            openssl_free_key($publicKey);
            return null;
        }
        
        $rsa = $details['rsa'];
        
        // Convert to Base64URL
        $n = rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '=');
        
        // Generate stable key ID from modulus
        $kid = substr(hash('sha256', $rsa['n'], true), 0, 8);
        $kid = rtrim(strtr(base64_encode($kid), '+/', '-_'), '=');
        
        openssl_free_key($publicKey);
        
        return [
            'kid' => $kid,
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $n,
            'e' => $e
        ];
    }
}