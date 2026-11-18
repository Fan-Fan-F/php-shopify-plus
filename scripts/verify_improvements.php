<?php
/**
 * php-shopify-plus Automated Verification Test Suite
 *
 * Runs offline unit & behavior tests across all 5 substantial improvements.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPShopify\ShopifySDK;
use PHPShopify\GraphQL;
use PHPShopify\CurlRequest;
use PHPShopify\CurlResponse;
use PHPShopify\HttpRequestJson;
use PHPShopify\HttpRequestGraphQL;
use PHPShopify\AuthHelper;
use PHPShopify\Product;
use PHPShopify\Order;
use PHPShopify\Customer;
use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\AuthenticationException;
use PHPShopify\Exception\NotFoundException;
use PHPShopify\Exception\ValidationException;
use PHPShopify\Exception\RateLimitException;
use PHPShopify\Exception\ResourceRateLimitException;
use PHPShopify\Exception\ServerException;
use PHPShopify\Exception\GraphQLException;
use PHPShopify\Exception\CurlException;
use PHPShopify\Exception\SdkException;

class TestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $currentSuite = '';

    public function suite($name)
    {
        $this->currentSuite = $name;
        echo "\n=== [Suite] $name ===\n";
    }

    public function assert($condition, $description)
    {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] $description\n";
        } else {
            $this->failed++;
            echo "  [FAIL] $description\n";
        }
    }

    public function summary()
    {
        echo "\n==================================================\n";
        echo "Test Results: {$this->passed} passed, {$this->failed} failed\n";
        echo "==================================================\n";
        return $this->failed === 0;
    }
}

$tester = new TestRunner();

// ---------------------------------------------------------
// Suite 1: GraphQL Support & Enhancements
// ---------------------------------------------------------
$tester->suite('GraphQL Support & Enhancements');

// 1.1 Test GraphQL query & mutate helper existence and signatures
$shopify = new ShopifySDK([
    'ShopUrl' => 'test-shop.myshopify.com',
    'AccessToken' => 'test_access_token_placeholder',
]);
$graphql = $shopify->GraphQL;
$tester->assert($graphql instanceof GraphQL, 'ShopifySDK->GraphQL returns GraphQL instance');
$tester->assert(method_exists($graphql, 'query'), 'GraphQL has query() method');
$tester->assert(method_exists($graphql, 'mutate'), 'GraphQL has mutate() method');
$tester->assert(method_exists($graphql, 'raw'), 'GraphQL has raw() method');
$tester->assert(method_exists($graphql, 'getCost'), 'GraphQL has getCost() method');
$tester->assert(method_exists($graphql, 'getThrottleStatus'), 'GraphQL has getThrottleStatus() method');

// 1.2 Test GraphQL private app authentication fallback
$privateShopify = new ShopifySDK([
    'ShopUrl' => 'test-shop.myshopify.com',
    'ApiKey' => 'test_api_key',
    'Password' => 'shppa_test_private_password',
]);
$prepMethod = new ReflectionMethod(HttpRequestGraphQL::class, 'prepareRequest');
$prepMethod->setAccessible(true);
$prepMethod->invoke(null, [], '{ shop { name } }', null, 'GetShop');

$httpHeadersProp = new ReflectionProperty(HttpRequestJson::class, 'httpHeaders');
$httpHeadersProp->setAccessible(true);
$headers = $httpHeadersProp->getValue();
$tester->assert(
    isset($headers['X-Shopify-Access-Token']) && $headers['X-Shopify-Access-Token'] === 'shppa_test_private_password',
    'Private app Password automatically maps to X-Shopify-Access-Token for GraphQL'
);

$postDataProp = new ReflectionProperty(HttpRequestGraphQL::class, 'postDataGraphQL');
$postDataProp->setAccessible(true);
$payload = json_decode($postDataProp->getValue(), true);
$tester->assert($payload['query'] === '{ shop { name } }', 'GraphQL query string correctly encoded in JSON');
$tester->assert($payload['operationName'] === 'GetShop', 'GraphQL operationName correctly included in request payload');

// 1.3 Test GraphQLException handling
$mockResponse = [
    'errors' => [
        [
            'message' => "Field 'invalidField' doesn't exist on type 'Shop'",
            'locations' => [['line' => 2, 'column' => 3]],
            'path' => ['shop', 'invalidField'],
        ]
    ],
    'extensions' => [
        'cost' => [
            'requestedQueryCost' => 1,
            'actualQueryCost' => 1,
            'throttleStatus' => [
                'maximumAvailable' => 1000.0,
                'currentlyAvailable' => 999.0,
                'restoreRate' => 50.0,
            ]
        ]
    ]
];

$recordMethod = new ReflectionMethod(GraphQL::class, 'recordMetadata');
$recordMethod->setAccessible(true);
$recordMethod->invoke($graphql, $mockResponse);
$tester->assert($graphql->getCost()['actualQueryCost'] === 1, 'GraphQL getCost() captures actualQueryCost from extensions');
$tester->assert($graphql->getThrottleStatus()['currentlyAvailable'] === 999.0, 'GraphQL getThrottleStatus() captures throttleStatus');

$gqlException = new GraphQLException(
    "GraphQL request failed: Field 'invalidField' doesn't exist",
    200,
    null,
    200,
    ['x-request-id' => 'req_gql_123'],
    json_encode($mockResponse),
    $mockResponse['errors'],
    null,
    $mockResponse['extensions']
);
$tester->assert($gqlException instanceof ApiException, 'GraphQLException is an instance of ApiException');
$tester->assert(count($gqlException->getGraphQLErrors()) === 1, 'GraphQLException returns structured error list');
$tester->assert($gqlException->getCost()['requestedQueryCost'] === 1, 'GraphQLException provides getCost()');

// ---------------------------------------------------------
// Suite 2: Error Handling & Typed Exception Hierarchy
// ---------------------------------------------------------
$tester->suite('Error Handling & Typed Exception Hierarchy');

// 2.1 Test error message formatting
$formattedSimple = HttpRequestJson::formatErrorMessage('Invalid API key');
$tester->assert($formattedSimple === 'Invalid API key', 'formatErrorMessage handles plain string');

$formattedNested = HttpRequestJson::formatErrorMessage([
    'title' => ["can't be blank", "is too short"],
    'vendor' => ["is invalid"]
]);
$tester->assert(
    strpos($formattedNested, "title: can't be blank, is too short") !== false &&
    strpos($formattedNested, "vendor: is invalid") !== false,
    'formatErrorMessage formats nested field errors with colon and semicolons'
);

// 2.2 Test ApiException rich properties
$apiEx = new ApiException(
    "Test error message",
    422,
    null,
    422,
    ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_abc_123'],
    '{"errors":{"title":["can\'t be blank"]}}',
    ['title' => ["can't be blank"]]
);
$tester->assert($apiEx->getHttpCode() === 422, 'ApiException getHttpCode() returns 422');
$tester->assert($apiEx->getRequestId() === 'req_abc_123', 'ApiException getRequestId() extracts X-Request-Id header');
$tester->assert($apiEx->hasError('title') === true, 'ApiException hasError("title") returns true');
$tester->assert($apiEx->hasError('unknown') === false, 'ApiException hasError("unknown") returns false');
$tester->assert($apiEx->getResponseBody() === '{"errors":{"title":["can\'t be blank"]}}', 'ApiException getResponseBody() returns raw body');

// 2.3 Test processResponse exception dispatching for various HTTP codes
CurlRequest::$lastHttpResponseHeaders = ['X-Request-Id' => 'req_test_401'];
CurlRequest::$lastHttpCode = 401;
$procMethod = new ReflectionMethod(HttpRequestJson::class, 'processResponse');
$procMethod->setAccessible(true);

$caughtAuth = false;
try {
    $procMethod->invoke(null, '{"errors":"Invalid API key or access token"}');
} catch (AuthenticationException $e) {
    $caughtAuth = true;
    $tester->assert($e->getHttpCode() === 401, 'AuthenticationException has HTTP 401 code');
    $tester->assert(strpos($e->getMessage(), 'Invalid API key') !== false, 'AuthenticationException message contains error details');
}
$tester->assert($caughtAuth, 'HTTP 401 dispatches AuthenticationException');

CurlRequest::$lastHttpCode = 404;
$caughtNotFound = false;
try {
    $procMethod->invoke(null, '{"errors":"Not Found"}');
} catch (NotFoundException $e) {
    $caughtNotFound = true;
    $tester->assert($e->getHttpCode() === 404, 'NotFoundException has HTTP 404 code');
}
$tester->assert($caughtNotFound, 'HTTP 404 dispatches NotFoundException');

CurlRequest::$lastHttpCode = 422;
$caughtValidation = false;
try {
    $procMethod->invoke(null, '{"errors":{"email":["has already been taken"]}}');
} catch (ValidationException $e) {
    $caughtValidation = true;
    $tester->assert($e->getHttpCode() === 422, 'ValidationException has HTTP 422 code');
    $tester->assert($e->hasFieldError('email'), 'ValidationException hasFieldError("email") is true');
    $tester->assert(isset($e->getFieldErrors()['email']), 'ValidationException getFieldErrors() returns array');
}
$tester->assert($caughtValidation, 'HTTP 422 dispatches ValidationException');

CurlRequest::$lastHttpCode = 429;
CurlRequest::$lastHttpResponseHeaders = ['Retry-After' => '2.5'];
$caughtRateLimit = false;
try {
    $procMethod->invoke(null, '{"errors":"Exceeded 2 calls per second for api client."}');
} catch (RateLimitException $e) {
    $caughtRateLimit = true;
    $tester->assert($e->getHttpCode() === 429, 'RateLimitException has HTTP 429 code');
    $tester->assert($e->getRetryAfter() == 2.5, 'RateLimitException parses Retry-After header');
}
$tester->assert($caughtRateLimit, 'HTTP 429 dispatches RateLimitException');

CurlRequest::$lastHttpCode = 503;
$caughtServer = false;
try {
    $procMethod->invoke(null, '{"errors":"Service Unavailable"}');
} catch (ServerException $e) {
    $caughtServer = true;
    $tester->assert($e->getHttpCode() === 503, 'ServerException has HTTP 503 code');
}
$tester->assert($caughtServer, 'HTTP 503 dispatches ServerException');

// 2.4 Test account already enabled pass-through
CurlRequest::$lastHttpCode = 422;
$result = $procMethod->invoke(null, '{"errors":"account already enabled"}');
$tester->assert(is_array($result) && $result['errors'] === 'account already enabled', 'Pass-through for "account already enabled" response');

// ---------------------------------------------------------
// Suite 3: Retry Mechanism & Resilience
// ---------------------------------------------------------
$tester->suite('Retry Mechanism & Resilience');

// 3.1 Test default MaxRetries = 0 does not retry
ShopifySDK::config([
    'ShopUrl' => 'test-retry.myshopify.com',
    'AccessToken' => 'token123',
    'MaxRetries' => 0,
]);
$serverError = new ServerException("500 Internal Server Error", 500, null, 500);
$shouldRetry = HttpRequestJson::shouldRetry(null, $serverError, 0);
$tester->assert($shouldRetry === false, 'HttpRequestJson::shouldRetry returns false when MaxRetries = 0');

// 3.2 Test MaxRetries = 2 allows retrying on 500 and stops after max
ShopifySDK::config([
    'ShopUrl' => 'test-retry.myshopify.com',
    'AccessToken' => 'token123',
    'MaxRetries' => 2,
    'RetryDelay' => 1, // 1ms for test speed
    'RetryBackoff' => 'fixed',
]);
$retry0 = HttpRequestJson::shouldRetry(null, $serverError, 0);
$retry1 = HttpRequestJson::shouldRetry(null, $serverError, 1);
$retry2 = HttpRequestJson::shouldRetry(null, $serverError, 2);
$tester->assert($retry0 === true, 'Retry allowed on attempt 0 (< MaxRetries)');
$tester->assert($retry1 === true, 'Retry allowed on attempt 1 (< MaxRetries)');
$tester->assert($retry2 === false, 'Retry denied on attempt 2 (>= MaxRetries)');

// 3.3 Test CurlException triggers retry
$curlError = new CurlException("28 : Connection timed out after 5000 milliseconds");
$curlRetry = HttpRequestJson::shouldRetry(null, $curlError, 0);
$tester->assert($curlRetry === true, 'CurlException network timeout triggers retry');

// 3.4 Test 404 does NOT retry
$notFoundError = new NotFoundException("Not Found", 404, null, 404);
$notFoundRetry = HttpRequestJson::shouldRetry(null, $notFoundError, 0);
$tester->assert($notFoundRetry === false, '404 NotFound does not trigger retry');

// 3.5 Test custom RequestRetryCallback override
$customCalled = false;
ShopifySDK::config([
    'ShopUrl' => 'test-retry.myshopify.com',
    'AccessToken' => 'token123',
    'RequestRetryCallback' => function ($resp, $err, $retry) use (&$customCalled) {
        $customCalled = true;
        return $retry < 1;
    }
]);
$customResult = HttpRequestJson::shouldRetry(null, $serverError, 0);
$tester->assert($customCalled === true, 'Custom RequestRetryCallback is executed');
$tester->assert($customResult === true, 'Custom RequestRetryCallback decision is respected');

// ---------------------------------------------------------
// Suite 4: Core Bug Fixes & Security Hardening
// ---------------------------------------------------------
$tester->suite('Core Bug Fixes & Security Hardening');

// 4.1 CurlResponse body separation with double newlines in body
$rawResponseWithDoubleNewlines = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Shopify-Shop-Api-Call-Limit: 1/40\r\n\r\n" .
    "<p>First paragraph</p>\r\n\r\n<p>Second paragraph</p>";

$headerSize = strlen("HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Shopify-Shop-Api-Call-Limit: 1/40\r\n\r\n");
$curlResp = new CurlResponse($rawResponseWithDoubleNewlines, $headerSize);

$tester->assert($curlResp->getHeader('content-type') === 'text/html', 'CurlResponse correctly parses header');
$body = $curlResp->getBody();
$tester->assert(
    strpos($body, "<p>First paragraph</p>") !== false &&
    strpos($body, "<p>Second paragraph</p>") !== false,
    'CurlResponse preserves body containing \\r\\n\\r\\n without corruption or truncation'
);

// Fallback parse without headerSize
$fallbackResp = new CurlResponse($rawResponseWithDoubleNewlines);
$fallbackBody = $fallbackResp->getBody();
$tester->assert(
    strpos($fallbackBody, "<p>First paragraph</p>") !== false &&
    strpos($fallbackBody, "<p>Second paragraph</p>") !== false,
    'CurlResponse fallback parser correctly preserves body containing \\r\\n\\r\\n'
);

// 4.2 ShopifyApiFeatures multiple headers array merge
ShopifySDK::config([
    'ShopUrl' => 'test-features.myshopify.com',
    'AccessToken' => 'token_feat',
    'ShopifyApiFeatures' => ['include-presentment-prices', 'resource-feedback']
]);
$productResource = new Product();
$refProp = new ReflectionProperty($productResource, 'httpHeaders');
$refProp->setAccessible(true);
$productHeaders = $refProp->getValue($productResource);
$tester->assert(
    isset($productHeaders['X-Shopify-Api-Features']) &&
    $productHeaders['X-Shopify-Api-Features'] === 'include-presentment-prices, resource-feedback',
    'Multiple ShopifyApiFeatures are concatenated with comma instead of overwriting'
);

// 4.3 Link header pagination parsing with stripos
$linkHeader = '<https://shop.myshopify.com/admin/api/2025-01/products.json?page_info=eyJsYXN0X2lkIjoxfQ&limit=50>; rel="next", ' .
              '<https://shop.myshopify.com/admin/api/2025-01/products.json?page_info=eyJsYXN0X2lkIjoyfQ&limit=50>; rel="previous"';
$headersWithLink = [
    'link' => $linkHeader,
    'x-shopify-api-version' => '2025-01'
];
$nextLink = $productResource->getLink($headersWithLink, 'next');
$prevLink = $productResource->getLink($headersWithLink, 'previous');
$tester->assert(
    $nextLink === 'https://shop.myshopify.com/admin/api/2025-01/products.json?page_info=eyJsYXN0X2lkIjoxfQ&limit=50',
    'getLink correctly extracts next cursor link'
);
$tester->assert(
    $prevLink === 'https://shop.myshopify.com/admin/api/2025-01/products.json?page_info=eyJsYXN0X2lkIjoyfQ&limit=50',
    'getLink correctly extracts previous cursor link'
);

$noMatchLink = $productResource->getLink(['link' => '<http://example.com>; rel="next"'], 'previous');
$tester->assert($noMatchLink === null, 'getLink returns null when link type not present');

// 4.4 AuthHelper verifyShopifyRequest parameter sorting & hash_equals
$secret = 'hush_shopify_secret_123';
ShopifySDK::config([
    'ShopUrl' => 'auth-shop.myshopify.com',
    'ApiKey' => 'key123',
    'SharedSecret' => $secret,
]);

// Prepare query params in non-alphabetical order
$testParams = [
    'shop' => 'auth-shop.myshopify.com',
    'timestamp' => '1234567890',
    'code' => 'auth_code_xyz',
];
// Correct HMAC calculated with sorted keys: code, shop, timestamp
ksort($testParams);
$expectedDataString = "code=auth_code_xyz&shop=auth-shop.myshopify.com&timestamp=1234567890";
$validHmac = hash_hmac('sha256', $expectedDataString, $secret);

// Test with unsorted $_GET
$_GET = [
    'timestamp' => '1234567890',
    'hmac' => $validHmac,
    'shop' => 'auth-shop.myshopify.com',
    'code' => 'auth_code_xyz',
];
$authVerified = AuthHelper::verifyShopifyRequest();
$tester->assert($authVerified === true, 'AuthHelper::verifyShopifyRequest passes even when $_GET parameters arrive out of order');

$_GET['hmac'] = 'invalid_hmac_signature';
$authFailed = AuthHelper::verifyShopifyRequest();
$tester->assert($authFailed === false, 'AuthHelper::verifyShopifyRequest rejects invalid HMAC');

// 4.5 AuthHelper verifyWebhookRequest
$webhookPayload = '{"id":12345678,"email":"buyer@example.com","total_price":"99.00"}';
$validWebhookHmac = base64_encode(hash_hmac('sha256', $webhookPayload, $secret, true));

$webhookVerified = AuthHelper::verifyWebhookRequest($webhookPayload, $validWebhookHmac);
$tester->assert($webhookVerified === true, 'AuthHelper::verifyWebhookRequest verifies valid webhook HMAC signature');

$webhookTampered = AuthHelper::verifyWebhookRequest($webhookPayload, 'invalid_webhook_hmac');
$tester->assert($webhookTampered === false, 'AuthHelper::verifyWebhookRequest rejects invalid webhook HMAC signature');

// ---------------------------------------------------------
// Suite 5: Resource Object Initialization & URL Generation
// ---------------------------------------------------------
$tester->suite('Resource Object Initialization & URL Generation');

$shopify = new ShopifySDK([
    'ShopUrl' => 'my-store.myshopify.com',
    'AccessToken' => 'valid_access_token_placeholder',
    'ApiVersion' => '2025-01'
]);

$tester->assert(ShopifySDK::getAdminUrl() === 'https://my-store.myshopify.com/admin/', 'AdminUrl generated correctly');
$tester->assert(ShopifySDK::getApiUrl() === 'https://my-store.myshopify.com/admin/api/2025-01/', 'ApiUrl contains configured ApiVersion');

$product = $shopify->Product;
$tester->assert($product instanceof Product, 'Product resource accessible via magic property');
$tester->assert($product->generateUrl() === 'https://my-store.myshopify.com/admin/api/2025-01/products.json', 'Product list URL generated correctly');

$singleProduct = $shopify->Product(12345);
$tester->assert($singleProduct->generateUrl() === 'https://my-store.myshopify.com/admin/api/2025-01/products/12345.json', 'Single product URL generated correctly');

$order = $shopify->Order(9876);
$tester->assert($order instanceof Order, 'Order resource accessible via magic method with ID');
$tester->assert($order->generateUrl() === 'https://my-store.myshopify.com/admin/api/2025-01/orders/9876.json', 'Single order URL generated correctly');

$customer = $shopify->Customer;
$tester->assert($customer instanceof Customer, 'Customer resource accessible');
$tester->assert($customer->generateUrl(['limit' => 10]) === 'https://my-store.myshopify.com/admin/api/2025-01/customers.json?limit=10', 'Query parameters formatted in URL correctly');

$webhook = $shopify->Webhook;
$tester->assert($webhook->generateUrl() === 'https://my-store.myshopify.com/admin/api/2025-01/webhooks.json', 'Webhook URL generated correctly');

// Finish
$success = $tester->summary();
exit($success ? 0 : 1);
