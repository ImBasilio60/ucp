# UCP Protocol Header Validation Module

This PrestaShop module implements middleware and controller logic for handling UCP (Universal Commerce Protocol) specific HTTP headers.

## Features

- **Header Extraction**: Reads and normalizes UCP-specific HTTP headers
- **Validation**: Validates required headers with proper format checking
- **Structured Logging**: Logs request information for debugging distributed agents
- **Error Handling**: Returns appropriate HTTP errors for missing/malformed headers
- **Response Headers**: Prepares UCP protocol response headers

## Supported Headers

### Required Headers
- `UCP-Agent`: Non-empty string identifying the client agent
- `request-id`: Unique request identifier (UUID format preferred)
- `idempotency-key`: String used to avoid duplicate operations
- `request-signature`: Cryptographic signature string

### Response Headers
- `request-id`: Echoed back from request
- `UCP-Version`: Protocol version (2026-03-13)
- `UCP-Server`: Server identification

## File Structure

```
ucpwellknown/
├── classes/
│   ├── UcpHeaderValidator.php     # Header validation middleware
│   ├── UcpItemConverter.php       # Product to UCP Item converter
│   └── UcpOrderConverter.php      # Cart to UCP Order converter
├── controllers/
│   ├── front/
│   │   ├── ucp.php               # Well-known endpoint
│   │   ├── api.php               # UCP API endpoint with header validation
│   │   ├── items.php             # UCP Items API endpoint
│   │   └── orders.php            # UCP Orders API endpoint
├── tests/
│   ├── UcpHeaderValidatorTest.php # Unit tests for header validation
│   ├── UcpItemConverterTest.php   # Unit tests for item conversion
│   └── UcpOrderConverterTest.php  # Unit tests for order conversion
└── README.md
```

## Usage

### API Endpoints

#### Header Validation API
Access the UCP API at: `/prestashop/module/ucpwellknown/api`

#### Items API
Access the UCP Items API at: `/prestashop/module/ucpwellknown/items`

#### Orders API
Access the UCP Orders API at: `/prestashop/module/ucpwellknown/orders`

### Example Request

```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/api" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

### UCP Item Conversion

#### Get Single Product
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/items?product_id=1" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Get Products by Category
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/items?category_id=5&limit=10" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Search Products
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/items?search=t-shirt&limit=5" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Batch Product Conversion
```bash
curl -X POST "http://localhost/prestashop/module/ucpwellknown/items" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..." \
  -H "Content-Type: application/json" \
  -d '{"product_ids": [1, 2, 3]}'
```

### UCP Order Conversion

#### Get Single Cart
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/orders?cart_id=1" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Get Customer Carts
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/orders?customer_id=5&limit=10" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Get Active Carts
```bash
curl -X GET "http://localhost/prestashop/module/ucpwellknown/orders" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..."
```

#### Batch Cart Conversion
```bash
curl -X POST "http://localhost/prestashop/module/ucpwellknown/orders" \
  -H "UCP-Agent: test-client/1.0" \
  -H "request-id: 550e8400-e29b-41d4-a716-446655440000" \
  -H "idempotency-key: order-12345-unique-key" \
  -H "request-signature: sha256=abc123def456..." \
  -H "Content-Type: application/json" \
  -d '{"cart_ids": [1, 2, 3]}'
```

### Valid Response
```json
{
    "status": "success",
    "message": "UCP API endpoint",
    "request_info": {
        "request_id": "550e8400-e29b-41d4-a716-446655440000",
        "ucp_agent": "test-client/1.0",
        "idempotency_key": "order-12345-unique-key",
        "timestamp": "2026-03-16T08:30:00+00:00"
    },
    "server_info": {
        "ucp_version": "2026-03-13",
        "prestashop_version": "1.7.8.0",
        "module_version": "1.0.0"
    }
}
```

### UCP Item Response Example
```json
{
    "status": "success",
    "data": {
        "id": "1",
        "title": "T-Shirt Premium",
        "description": "High quality cotton t-shirt with modern design",
        "price": {
            "amount": 29.99,
            "currency": "EUR",
            "formatted": "29.99 €"
        },
        "currency": "EUR",
        "availability": {
            "status": "in_stock",
            "quantity": 50
        },
        "images": [
            {
                "id": "1",
                "url": "http://localhost/prestashop/img/p/1/1-large_default/t-shirt.jpg",
                "thumbnail": "http://localhost/prestashop/img/p/1/1-small_default/t-shirt.jpg",
                "alt_text": "T-Shirt Premium",
                "position": 1,
                "cover": true
            }
        ],
        "metadata": {
            "prestashop_id": 1,
            "reference": "TSH001",
            "ean13": "1234567890123",
            "upc": "",
            "width": 30.0,
            "height": 50.0,
            "depth": 5.0,
            "weight": 0.2,
            "condition": "New",
            "categories": [
                {
                    "id": "3",
                    "name": "Clothing",
                    "depth_level": 2,
                    "active": true
                }
            ],
            "tags": ["t-shirt", "cotton", "premium"],
            "manufacturer": {
                "id": "1",
                "name": "BrandName"
            },
            "created_at": "2026-03-15 10:00:00",
            "updated_at": "2026-03-16 08:00:00"
        },
        "variants": [
            {
                "id": "1",
                "title": "Small, Blue",
                "price": {
                    "amount": 29.99,
                    "currency": "EUR",
                    "formatted": "29.99 €"
                },
                "currency": "EUR",
                "availability": {
                    "status": "in_stock",
                    "quantity": 15
                },
                "attributes": [
                    {
                        "group": "Size",
                        "name": "Small",
                        "group_id": "1",
                        "attribute_id": "1"
                    },
                    {
                        "group": "Color",
                        "name": "Blue",
                        "group_id": "2",
                        "attribute_id": "3"
                    }
                ],
                "images": [],
                "metadata": {
                    "prestashop_id": 1,
                    "reference": "TSH001-S-BLUE",
                    "ean13": "",
                    "upc": "",
                    "weight": 0.2,
                    "default_on": false,
                    "minimal_quantity": 1
                }
            }
        ],
        "has_variants": true
    },
    "request_info": {
        "request_id": "550e8400-e29b-41d4-a716-446655440000",
        "product_id": 1,
        "language_id": 1,
        "include_combinations": true,
        "timestamp": "2026-03-16T08:30:00+00:00"
    }
}
```

### UCP Order Response Example
```json
{
    "status": "success",
    "data": {
        "id": "1",
        "status": "active",
        "customer": {
            "id": "5",
            "email": "customer@example.com",
            "firstname": "John",
            "lastname": "Doe",
            "metadata": {
                "prestashop_customer_id": 5,
                "birthday": "1990-01-01",
                "newsletter": true,
                "optin": true
            }
        },
        "lines": [
            {
                "item_id": "1",
                "variant_id": "1",
                "title": "T-Shirt Premium",
                "description": "High quality cotton t-shirt",
                "quantity": 2,
                "unit_price": {
                    "amount": 29.99,
                    "currency": "EUR",
                    "tax_exclusive": 24.99,
                    "tax_inclusive": 29.99
                },
                "total_price": {
                    "amount": 59.98,
                    "currency": "EUR",
                    "tax_exclusive": 49.98,
                    "tax_inclusive": 59.98
                },
                "taxes": [
                    {
                        "name": "VAT",
                        "rate": 20.0,
                        "amount": 10.0
                    }
                ],
                "variant": {
                    "id": "1",
                    "reference": "TSH001-S-BLUE",
                    "attributes": [
                        {
                            "group": "Size",
                            "name": "Small",
                            "group_id": "1",
                            "attribute_id": "1"
                        },
                        {
                            "group": "Color",
                            "name": "Blue",
                            "group_id": "2",
                            "attribute_id": "3"
                        }
                    ]
                },
                "metadata": {
                    "prestashop_product_id": 1,
                    "prestashop_attribute_id": 1,
                    "reference": "TSH001-S-BLUE",
                    "weight": 0.2
                }
            }
        ],
        "shipping": {
            "method": "Standard Shipping",
            "cost": {
                "amount": 5.99,
                "currency": "EUR",
                "tax_exclusive": 5.00,
                "tax_inclusive": 5.99
            },
            "taxes": [
                {
                    "name": "Shipping Tax",
                    "rate": 20.0,
                    "amount": 0.99
                }
            ],
            "address": {
                "street": "123 Main Street",
                "city": "Paris",
                "postal_code": "75001",
                "country": "France",
                "metadata": {
                    "prestashop_address_id": 10,
                    "phone": "+33123456789"
                }
            }
        },
        "discounts": [
            {
                "id": "1",
                "code": "WELCOME10",
                "name": "Welcome Discount",
                "description": "10% off for new customers",
                "amount": {
                    "value": 6.60,
                    "currency": "EUR",
                    "type": "percentage",
                    "percentage": 10.0
                },
                "metadata": {
                    "prestashop_cart_rule_id": 1,
                    "free_shipping": false,
                    "minimum_amount": 50.0
                }
            }
        ],
        "totals": {
            "subtotal": {
                "amount": 59.98,
                "currency": "EUR",
                "tax_exclusive": 49.98,
                "tax_inclusive": 59.98
            },
            "shipping": {
                "amount": 5.99,
                "currency": "EUR",
                "tax_exclusive": 5.00,
                "tax_inclusive": 5.99
            },
            "discounts": {
                "amount": 6.60,
                "currency": "EUR"
            },
            "taxes": {
                "amount": 10.99,
                "currency": "EUR",
                "breakdown": {
                    "products_tax": 10.0,
                    "shipping_tax": 0.99,
                    "wrapping_tax": 0.0
                }
            },
            "grand_total": {
                "amount": 59.37,
                "currency": "EUR",
                "tax_exclusive": 48.38,
                "tax_inclusive": 59.37
            }
        },
        "metadata": {
            "prestashop_cart_id": 1,
            "currency": "EUR",
            "language": 1,
            "created_at": "2026-03-16 09:00:00",
            "updated_at": "2026-03-16 09:30:00",
            "guest_mode": false,
            "secure_key": "abc123def456789"
        }
    },
    "request_info": {
        "request_id": "550e8400-e29b-41d4-a716-446655440000",
        "cart_id": 1,
        "language_id": 1,
        "timestamp": "2026-03-16T09:30:00+00:00"
    }
}
```

### Error Response (Missing Headers)
```json
{
    "error": "Invalid UCP Headers",
    "code": 400,
    "details": [
        {
            "header": "UCP-Agent",
            "message": "Missing or empty required header"
        },
        {
            "header": "request-id",
            "message": "Missing or empty required header"
        }
    ],
    "timestamp": "2026-03-16T08:30:00+00:00"
}
```

## Validation Rules

### UCP-Agent
- Must be a non-empty string
- Identifies the client agent making the request

### Request-ID
- Must be a valid UUID format (RFC 4122)
- Pattern: `^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`
- Used for request tracing

### Idempotency-Key
- Non-empty string
- Used to prevent duplicate operations
- Should be unique per operation

### Request-Signature
- Non-empty string
- Cryptographic signature for request authentication

## Logging

All requests are logged with the following information:
- Timestamp (ISO 8601 format)
- Request ID
- UCP Agent
- Endpoint being called
- Idempotency Key

Logs are stored in PrestaShop's logging system for debugging distributed requests.

## Testing

### Header Validation Tests

Run the unit tests to validate header validation functionality:

```bash
php tests/UcpHeaderValidatorTest.php
```

### Item Converter Tests

Run the unit tests to validate UCP Item conversion:

```bash
php tests/UcpItemConverterTest.php
```

### Order Converter Tests

Run the unit tests to validate UCP Order conversion:

```bash
php tests/UcpOrderConverterTest.php
```

### Test Coverage

#### Header Validation Tests
- Missing required headers
- Valid request processing
- Malformed request-id validation
- Empty UCP-Agent validation
- Case-insensitive header handling
- Response header preparation

#### Item Converter Tests
- Simple product conversion
- Product with multiple combinations
- Product with missing images
- Product with missing description
- Price formatting validation
- Availability status checking
- Multiple product conversion
- Invalid product ID handling

#### Order Converter Tests
- Cart with multiple products
- Cart with discounts
- Cart with shipping fees
- Cart with combinations/variants
- Empty cart handling
- Invalid cart handling

## Implementation Details

### UcpHeaderValidator Class

The `UcpHeaderValidator` class provides:

- `extractHeaders()`: Extracts and normalizes HTTP headers
- `validateHeaders()`: Validates header presence and format
- `logRequest()`: Logs request information
- `prepareResponseHeaders()`: Prepares response headers
- `sendErrorResponse()`: Sends standardized error responses

### UcpItemConverter Class

The `UcpItemConverter` class provides:

- `convertProductToUcpItem()`: Converts a single PrestaShop product to UCP Item format
- `convertMultipleProducts()`: Converts multiple products to UCP Items
- `getProductCombinations()`: Handles product variants/combinations
- `formatPrice()`: Formats price with currency information
- `getProductImages()`: Retrieves product images with URLs

### UcpOrderConverter Class

The `UcpOrderConverter` class provides:

- `convertCartToUcpOrder()`: Converts a single PrestaShop cart to UCP Order format
- `convertMultipleCarts()`: Converts multiple carts to UCP Orders
- `getOrderLines()`: Extracts and formats cart products as order lines
- `calculateTotals()`: Computes order totals with tax breakdown
- `getShippingInfo()`: Retrieves shipping information and costs
- `getDiscounts()`: Extracts cart rules and discounts

#### UCP Item Structure

```php
$ucp_item = [
    'id' => (string) $product_id,
    'title' => $product->name,
    'description' => $product->description,
    'price' => [
        'amount' => (float) $price,
        'currency' => $currency->iso_code,
        'formatted' => $formatted_price
    ],
    'currency' => $currency->iso_code,
    'availability' => [
        'status' => 'in_stock|out_of_stock|not_available',
        'quantity' => (int) $quantity
    ],
    'images' => [
        [
            'id' => (string) $image_id,
            'url' => $image_url,
            'thumbnail' => $thumbnail_url,
            'alt_text' => $alt_text,
            'position' => $position,
            'cover' => $is_cover
        ]
    ],
    'metadata' => [
        'prestashop_id' => (int) $product_id,
        'reference' => $product->reference,
        'ean13' => $product->ean13,
        'upc' => $product->upc,
        'width' => $product->width,
        'height' => $product->height,
        'depth' => $product->depth,
        'weight' => $product->weight,
        'condition' => 'New|Used|Refurbished',
        'categories' => [...],
        'tags' => [...],
        'manufacturer' => [...],
        'created_at' => $product->date_add,
        'updated_at' => $product->date_upd
    ],
    'variants' => [...], // Optional: product combinations
    'has_variants' => true // Optional: indicates variants presence
];
```

#### UCP Order Structure

```php
$ucp_order = [
    'id' => (string) $cart_id,
    'status' => 'active|converted|empty',
    'customer' => [
        'id' => (string) $customer_id,
        'email' => $customer->email,
        'firstname' => $customer->firstname,
        'lastname' => $customer->lastname,
        'metadata' => [...]
    ],
    'lines' => [
        [
            'item_id' => (string) $product_id,
            'variant_id' => (string) $attribute_id,
            'title' => $product_name,
            'description' => $product_description,
            'quantity' => (int) $quantity,
            'unit_price' => [
                'amount' => (float) $price_incl,
                'currency' => $currency->iso_code,
                'tax_exclusive' => (float) $price_excl,
                'tax_inclusive' => (float) $price_incl
            ],
            'total_price' => [
                'amount' => (float) $total_incl,
                'currency' => $currency->iso_code,
                'tax_exclusive' => (float) $total_excl,
                'tax_inclusive' => (float) $total_incl
            ],
            'taxes' => [
                [
                    'name' => 'VAT',
                    'rate' => (float) $tax_rate,
                    'amount' => (float) $tax_amount
                ]
            ],
            'variant' => [...], // Optional: product combination info
            'metadata' => [...]
        ]
    ],
    'shipping' => [
        'method' => $carrier_name,
        'cost' => [
            'amount' => (float) $shipping_cost_incl,
            'currency' => $currency->iso_code,
            'tax_exclusive' => (float) $shipping_cost_excl,
            'tax_inclusive' => (float) $shipping_cost_incl
        ],
        'taxes' => [...],
        'address' => [...]
    ],
    'discounts' => [
        [
            'id' => (string) $cart_rule_id,
            'code' => $discount_code,
            'name' => $discount_name,
            'amount' => [
                'value' => (float) $discount_value,
                'currency' => $currency->iso_code,
                'type' => 'fixed|percentage',
                'percentage' => (float) $percentage
            ],
            'metadata' => [...]
        ]
    ],
    'totals' => [
        'subtotal' => [
            'amount' => (float) $subtotal_incl,
            'currency' => $currency->iso_code,
            'tax_exclusive' => (float) $subtotal_excl,
            'tax_inclusive' => (float) $subtotal_incl
        ],
        'shipping' => [...],
        'discounts' => [
            'amount' => (float) $total_discounts,
            'currency' => $currency->iso_code
        ],
        'taxes' => [
            'amount' => (float) $total_taxes,
            'currency' => $currency->iso_code,
            'breakdown' => [
                'products_tax' => (float) $products_tax,
                'shipping_tax' => (float) $shipping_tax,
                'wrapping_tax' => (float) $wrapping_tax
            ]
        ],
        'grand_total' => [
            'amount' => (float) $grand_total,
            'currency' => $currency->iso_code,
            'tax_exclusive' => (float) $grand_total_excl,
            'tax_inclusive' => (float) $grand_total
        ]
    ],
    'metadata' => [
        'prestashop_cart_id' => (int) $cart_id,
        'currency' => $currency->iso_code,
        'language' => (int) $language_id,
        'created_at' => $cart->date_add,
        'updated_at' => $cart->date_upd,
        'guest_mode' => (bool) $is_guest,
        'secure_key' => $cart->secure_key
    ]
];
```

### UcpWellKnownApiModuleFrontController

The API controller integrates header validation with request processing:

- Validates headers before processing
- Logs all requests
- Supports GET, POST, PUT, DELETE methods
- Returns structured JSON responses
- Handles errors gracefully

### UcpWellKnownItemsModuleFrontController

The Items API controller handles UCP Item conversion:

- Single product retrieval: `GET /items?product_id={id}`
- Category-based retrieval: `GET /items?category_id={id}`
- Product search: `GET /items?search={query}`
- Batch conversion: `POST /items` with product_ids array
- Pagination support with `limit` and `offset` parameters
- Language detection from `Accept-Language` header
- Configurable combination inclusion

### UcpWellKnownOrdersModuleFrontController

The Orders API controller handles UCP Order conversion:

- Single cart retrieval: `GET /orders?cart_id={id}`
- Customer carts retrieval: `GET /orders?customer_id={id}`
- Active carts retrieval: `GET /orders` (last 30 days)
- Batch conversion: `POST /orders` with cart_ids array
- Pagination support with `limit` and `offset` parameters
- Language detection from `Accept-Language` header
- Complete order totals calculation with tax breakdown

## HTTP Methods Supported

- **GET**: Retrieve API information and request details
- **POST**: Process UCP operations with JSON payload
- **PUT**: Update UCP resources with JSON payload
- **DELETE**: Remove UCP resources

## Error Codes

- `400 Bad Request`: Missing or invalid headers
- `405 Method Not Allowed`: Unsupported HTTP method
- `500 Internal Server Error`: Server processing error

## Security Considerations

- All required headers must be present and valid
- Request signatures should be validated according to UCP specification
- Idempotency keys prevent duplicate operations
- Structured logging helps with security auditing

## Integration

### Header Validation Integration

To integrate with existing PrestaShop controllers:

```php
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpHeaderValidator.php';

$validator = new UcpHeaderValidator();
$validator->extractHeaders();
$validation = $validator->validateHeaders();

if (!$validation['valid']) {
    $validator->sendErrorResponse($validation['errors']);
}

// Continue with your controller logic
$validator->logRequest($endpoint);
$response_headers = $validator->prepareResponseHeaders();
```

### Item Converter Integration

To integrate UCP Item conversion in your controllers:

```php
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpItemConverter.php';

$converter = new UcpItemConverter();

// Convert single product
$ucp_item = $converter->convertProductToUcpItem($product_id, $language_id, $include_combinations);

// Convert multiple products
$product_ids = [1, 2, 3];
$ucp_items = $converter->convertMultipleProducts($product_ids, $language_id, $include_combinations);
```

### Combined Integration Example

```php
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpHeaderValidator.php';
require_once _PS_MODULE_DIR_ . 'ucpwellknown/classes/UcpItemConverter.php';

// Validate headers first
$validator = new UcpHeaderValidator();
$validator->extractHeaders();
$validation = $validator->validateHeaders();

if (!$validation['valid']) {
    $validator->sendErrorResponse($validation['errors']);
}

// Log request
$validator->logRequest($endpoint);

// Convert products
$converter = new UcpItemConverter();
$product_ids = [1, 2, 3];
$ucp_items = $converter->convertMultipleProducts($product_ids);

// Prepare response
$response = [
    'status' => 'success',
    'data' => $ucp_items,
    'request_info' => [
        'request_id' => $validator->getExtractedHeaders()['request-id'],
        'timestamp' => date('c')
    ]
];

// Set response headers
$response_headers = $validator->prepareResponseHeaders();
foreach ($response_headers as $name => $value) {
    header($name . ': ' . $value);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```
