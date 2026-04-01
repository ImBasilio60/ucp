<?php

class UcpucpModuleFrontController extends ModuleFrontController
{
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        header('Content-Type: application/json');

        // Get public key from configuration
        $publicKey = Configuration::get('UCP_PUBLIC_KEY');

        $json = [
            "ucp" => [
                "version" => "2026-03-13",
                "signing_keys" => [
                    "keys" => [
                        [
                            "alg" => "RS256",
                            "key" => $publicKey ?: ""
                        ]
                    ]
                ],
                "supported_versions" => [
                    "2026-03-13" => "https://www.passioncampagne9.projets-omega.net/.well-known/ucp"
                ],
                "services" => [
                    "dev.ucp.shopping" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/specification/overview/",
                            "transport" => "rest",
                            "endpoint" => "https://www.passioncampagne9.projets-omega.net/module/ucp",
                            "schema" => "https://ucp.dev/services/shopping/openrpc.json"
                        ]
                    ]
                ],
                "capabilities" => [
                    "dev.ucp.shopping.checkout" => [
                        [
                            "version" => "2026-03-13",
                            "spec" => "https://ucp.dev/specification/checkout",
                            "schema" => "https://ucp.dev/schemas/shopping/checkout.json"
                        ]
                    ]
                ],
                "payment_handlers" => [
                    "com.stripe" => [
                        [
                            "id" => "stripe",
                            "version" => "2026-03-13",
                            "spec" => "https://stripe.com/payments/ucp/2026-03-13/",
                            "schema" => "https://stripe.com/payments/ucp/2026-03-13/schemas/config.json",
                            "config" => [
                                "api_version" => "2026-03-13",
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
}