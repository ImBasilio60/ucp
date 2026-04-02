<?php

class UcpucpModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        $headers = apache_request_headers();
        header('Content-Type: application/json');
        header('ucp-agent: ' . ($headers['ucp-agent'] ?? 'unknown'));
        header('idempotency-key: ' . ($headers['idempotency-key'] ?? 'unknown'));
        header('request-id: ' . ($headers['request-id'] ?? 'unknown'));

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
                "version" => "2026-03-13",
                "signing_keys" => $signingKeys,
                "services" => [
                    "dev.ucp.shopping" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/overview",
                            "transport" => "rest",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp",
                            "schema" => "https://ucp.dev/2026-03-13/services/shopping/openapi.json"
                        ]
                    ],
                    "order" => [
                        "version" => "2026-03-13",
                        "rest" => [
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp/orders",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/order.json"
                        ]
                    ],
                    "fulfillment" => [
                        "version" => "2026-03-13",
                        "rest" => [
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp/fulfillment",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/fulfillment.json"
                        ]
                    ]
                ],
                "capabilities" => [
                    "dev.ucp.shopping.checkout" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/checkout",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/checkout.json"
                        ]
                    ],
                    "dev.ucp.shopping.catalog" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/catalog",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/catalog.json"
                        ]
                    ],
                    "dev.ucp.shopping.identity" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/identity",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/identity.json"
                        ]
                    ],
                    "dev.ucp.shopping.order" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/order",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/order.json"
                        ]
                    ],
                    "dev.ucp.shopping.fulfillment" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/2026-03-13/specification/fulfillment",
                            "schema" => "https://ucp.dev/2026-03-13/schemas/shopping/fulfillment.json"
                        ]
                    ]
                ],
                "payment" => [
                    "handlers" => [
                        [
                            "provider" => "stripe",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp/payment"
                        ],
                        [
                            "provider" => "paypal",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp/payment"
                        ]
                    ]
                ],
                "auth" => [
                    "type" => "oauth2",
                    "metadata_url" => "https://www.passioncampagne9.projets-omega.net/module/ucp/oauth"
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