# php-shopify-plus

> 现代增强版 Shopify PHP SDK / Modern & Resilient Shopify PHP SDK  
> 面向现代生产环境的高性能 Shopify PHP SDK，在原生优雅链式调用的基础上，全方位增强了 GraphQL 深度支持、强类型分层异常体系、自动化弹性退避重试与安全鉴权加固。  
> A production-ready, highly resilient Shopify PHP SDK featuring first-class GraphQL capabilities, structured typed exceptions, automated backoff retry policies, and core security enhancements.

---

## 目录 / Table of Contents
- [中文文档](#中文文档)
  - [功能与改进点](#功能与改进点)
  - [快速开始](#快速开始)
  - [来源与致谢](#来源与致谢)
  - [许可证](#许可证)
- [English Documentation](#english-documentation)
  - [Features & Improvements](#features--improvements)
  - [Quick Start](#quick-start)
  - [Credits & Acknowledgements](#credits--acknowledgements)
  - [License](#license)

---

<a name="中文文档"></a>
## 中文文档

### 功能与改进点

本项目严格对应 [CHANGES.md](CHANGES.md) 的 5 大核心改进计划：

1. **GraphQL 深度支持与现代化操作**
   - **语义化操作**：提供 `query()`、`mutate()`、`raw()` 等语义化方法，原生支持 GraphQL 变量绑定（`variables`）与操作名称（`operationName`）；
   - **私有应用无缝兼容**：彻底解除了原 SDK 对 GraphQL 仅支持公共应用 AccessToken 的限制，自动将私有应用凭据（Password）映射为认证凭证，免去手动配置的繁琐；
   - **配额开销追踪**：新增 `getCost()` 与 `getThrottleStatus()` 快捷方法，实时解析 Shopify GraphQL 桶配额与查询消耗（`actualQueryCost`、`currentlyAvailable`、`restoreRate`）；
   - **专用结构化异常**：引入 `GraphQLException`，结构化保存位置信息（`locations`）、路径（`path`）与扩展字段（`extensions`）。

2. **错误处理体系与结构化异常规范化**
   - **富属性异常基类**：重构 `ApiException`，支持 `$e->getHttpCode()`、`$e->getResponseHeaders()`、`$e->getResponseBody()`、`$e->getErrors()`、`$e->getRequestId()`；
   - **细粒度强类型异常**：
     - `AuthenticationException`（401 Unauthorized / 403 Forbidden 鉴权失败）；
     - `NotFoundException`（404 Not Found 资源不存在）；
     - `ValidationException`（422 Unprocessable Entity 数据校验失败，提供 `$e->getFieldErrors()` 与 `$e->hasFieldError('field')`）；
     - `RateLimitException`（429 Too Many Requests 限流，提供 `$e->getRetryAfter()`）；
     - `ServerException`（500/502/503/504 服务器故障）；
   - **错误明细零丢弃**：修复原 SDK 在 422 校验失败时丢弃 `errors` 键内容、仅抛出模糊 HTTP 状态码的缺陷，格式化提取嵌套字段错误信息。

3. **自动化弹性重试引擎与 429 漏桶保护**
   - **配置驱动重试引擎**：支持 `MaxRetries`、`RetryStatusCodes`、`RetryDelay`、`RetryBackoff`（指数退避 `exponential` / 固定间隔 `fixed`）；
   - **智能瞬时故障恢复**：自动对网络超时、底层 cURL 连接异常与 429/5xx 临时故障执行休眠退避与重试；
   - **429 限流保护加固**：增加最大限流重试轮次上限，杜绝死循环挂死 PHP 进程；在 `Retry-After` 头缺失时提供安全平滑回退（默认 1.0 秒）。

4. **核心 Bug 修复与协议安全加固**
   - **正文分包解析修复**：使用 cURL `CURLINFO_HEADER_SIZE` 字节偏移精确分割 Header 与 Body，彻底修复原 SDK 在处理包含 `\r\n\r\n` 换行的内容（商品描述、HTML 模板、邮件内容等）时正文截断损坏并将正文误当 Header 解析的严重缺陷；
   - **API Features 请求头覆盖修复**：修复配置多个 `ShopifyApiFeatures` 时仅最后一个生效的 Bug，支持数组自动合并为逗号分隔的合法请求头；
   - **Link 分页头解析修复**：使用严格字符定位替代错误使用 `stristr() > -1` 的类型混淆比较，确保在 PHP 8 环境下游标分页链接（`next` / `previous`）稳定解析；
   - **OAuth 与 Webhook 鉴权加固**：OAuth HMAC 校验增加 `ksort` 参数字典序重排与原生 `hash_equals()` 恒定时间安全比对；新增 `AuthHelper::verifyWebhookRequest()` 原生支持 Webhook HMAC 验签。

5. **自测套件与现代环境兼容**
   - **离线自动化测试套件**：内置 `scripts/verify_improvements.php`，涵盖 5 大套件 65 项全量通过的离线自动化测试，零外部网络依赖；
   - **现代 PHP 8.3 全面兼容**：消除废弃语法，升级 `composer.json` 依赖声明，全面支持 PHP >= 5.6 至 PHP 8.3+。

---

### 快速开始

#### 1. 安装
```bash
composer require phpclassic/php-shopify
```

#### 2. 初始化配置
```php
use PHPShopify\ShopifySDK;

// 私有应用 (Private App)
$config = [
    'ShopUrl' => 'your-shop.myshopify.com',
    'ApiKey' => 'your_api_key',
    'Password' => 'your_private_api_password',
    'ApiVersion' => '2025-01',
    // 弹性重试配置
    'MaxRetries' => 3,             // 遇到 429 或 5xx 时最多重试 3 次
    'RetryDelay' => 500,           // 基础等待 500ms
    'RetryBackoff' => 'exponential'// 指数退避 (500ms, 1000ms, 2000ms...)
];

$shopify = new ShopifySDK($config);
```

#### 3. GraphQL 查询与变更
```php
// 执行 GraphQL 查询并绑定参数与操作名称
$query = <<<'GQL'
query GetProducts($first: Int!) {
    products(first: $first) {
        edges {
            node {
                id
                title
            }
        }
    }
}
GQL;

$result = $shopify->GraphQL->query($query, ['first' => 5], 'GetProducts');

// 获取当前调用的配额消耗与桶状态
$cost = $shopify->GraphQL->getCost();
echo "消耗配额：" . $cost['actualQueryCost'];
echo "剩余配额：" . $cost['throttleStatus']['currentlyAvailable'];

// 执行 GraphQL Mutation
$mutation = <<<'GQL'
mutation createProduct($input: ProductInput!) {
    productCreate(input: $input) {
        product { id title }
        userErrors { field message }
    }
}
GQL;

$response = $shopify->GraphQL->mutate($mutation, [
    'input' => ['title' => 'New Awesome Product']
]);
```

#### 4. 强类型异常处理
```php
use PHPShopify\Exception\AuthenticationException;
use PHPShopify\Exception\ValidationException;
use PHPShopify\Exception\NotFoundException;
use PHPShopify\Exception\RateLimitException;
use PHPShopify\Exception\ServerException;
use PHPShopify\Exception\ApiException;

try {
    $shopify->Product->post(['body_html' => 'Missing title!']);
} catch (ValidationException $e) {
    // 捕获 422 业务校验异常
    echo "验证失败：" . $e->getMessage() . "\n";
    print_r($e->getFieldErrors()); // ['title' => ["can't be blank"]]
} catch (AuthenticationException $e) {
    // 捕获 401 / 403 权限异常
    echo "认证失败，请检查密钥或访问令牌\n";
} catch (NotFoundException $e) {
    // 捕获 404 资源不存在
    echo "未找到对应资源\n";
} catch (RateLimitException $e) {
    // 捕获 429 访问受限
    echo "请求超限，建议等待秒数：" . $e->getRetryAfter() . "\n";
} catch (ApiException $e) {
    // 捕获其他通用 API 异常
    echo "API 请求失败 [HTTP {$e->getHttpCode()}]，RequestId: {$e->getRequestId()}\n";
}
```

#### 5. Webhook 签名快速校验
```php
use PHPShopify\AuthHelper;

$rawPayload = file_get_contents('php://input');
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? null;
$sharedSecret = 'your_app_shared_secret';

if (AuthHelper::verifyWebhookRequest($rawPayload, $hmacHeader, $sharedSecret)) {
    // Webhook 签名合法，处理业务数据
    $data = json_decode($rawPayload, true);
} else {
    // 非法请求，拒绝处理
    http_response_code(401);
    exit('Unauthorized');
}
```

---

### 来源与致谢

本项目基于 [php-shopify](https://github.com/phpclassic/php-shopify) 二次开发，原项目由 phpclassic 以 Apache-2.0 许可证开源。

---

### 许可证

本项目沿用原许可证，全文见根目录 [LICENSE](LICENSE)（Apache License 2.0）。

---

<a name="english-documentation"></a>
## English Documentation

### Features & Improvements

This project implements the 5 core enhancements planned in [CHANGES.md](CHANGES.md):

1. **Deep GraphQL Support & Modernized Operations**
   - **Semantic API Methods**: Convenient `query()`, `mutate()`, and `raw()` methods with built-in parameter binding (`variables`) and operation selection (`operationName`);
   - **Seamless Private App Authentication**: Removed the limitation where GraphQL only accepted public app access tokens, automatically resolving private app passwords into valid authentication credentials;
   - **Query Cost & Throttle Tracking**: Added `getCost()` and `getThrottleStatus()` methods to inspect Shopify leaky-bucket query usage (`actualQueryCost`, `currentlyAvailable`, `restoreRate`);
   - **Structured GraphQL Exceptions**: Introduced `GraphQLException` to preserve error `locations`, `path`, and `extensions`.

2. **Standardized Error Handling & Typed Exception Hierarchy**
   - **Rich Base Exception**: Enhanced `ApiException` with `$e->getHttpCode()`, `$e->getResponseHeaders()`, `$e->getResponseBody()`, `$e->getErrors()`, and `$e->getRequestId()`;
   - **Granular Typed Exceptions**:
     - `AuthenticationException` (401 Unauthorized / 403 Forbidden);
     - `NotFoundException` (404 Not Found);
     - `ValidationException` (422 Unprocessable Entity, providing `$e->getFieldErrors()` and `$e->hasFieldError('field')`);
     - `RateLimitException` (429 Too Many Requests, providing `$e->getRetryAfter()`);
     - `ServerException` (500/502/503/504 Server Failures);
   - **Complete Error Preservation**: Fixed the issue where HTTP 422 validation errors discarded the `errors` payload, ensuring detailed nested validation failures are preserved.

3. **Automated Resilience Retry Engine & 429 Loop Protection**
   - **Config-driven Retries**: Configurable `MaxRetries`, `RetryStatusCodes`, `RetryDelay`, and `RetryBackoff` (`exponential` or `fixed`);
   - **Transient Fault Recovery**: Automatically retries transient network timeouts, low-level cURL connection failures, and 429/5xx server hiccups;
   - **Rate Limit Safety Guard**: Added maximum retry bounds on 429 responses to prevent infinite loops, with safe fallbacks (default 1.0s) when `Retry-After` is missing.

4. **Core Bug Fixes & Security Hardening**
   - **HTTP Body Splitting Fix**: Leveraged cURL `CURLINFO_HEADER_SIZE` to slice headers and body accurately, fixing the bug where double newlines (`\r\n\r\n`) in response bodies (product descriptions, templates, emails) truncated the body and corrupted headers;
   - **Multiple API Features Fix**: Fixed the header overwrite bug when configuring multiple `ShopifyApiFeatures`, merging them into a valid comma-separated string;
   - **Cursor Pagination Fix**: Replaced type-confused `stristr() > -1` comparisons with strict `stripos() !== false` checks, ensuring correct cursor extraction across all PHP versions;
   - **OAuth & Webhook Security**: Added lexicographical `ksort` and constant-time `hash_equals()` string comparison to OAuth verification; added `AuthHelper::verifyWebhookRequest()` for native webhook HMAC validation.

5. **Offline Test Suite & Modern Platform Compatibility**
   - **Offline Automated Test Suite**: Built-in `scripts/verify_improvements.php` testing 65 assertions across 5 suites with 100% pass rate and zero network dependency;
   - **PHP 8.3 Ready**: Fully compatible with PHP >= 5.6 through PHP 8.3+.

---

### Quick Start

#### 1. Installation
```bash
composer require phpclassic/php-shopify
```

#### 2. Configuration
```php
use PHPShopify\ShopifySDK;

$config = [
    'ShopUrl' => 'your-shop.myshopify.com',
    'ApiKey' => 'your_api_key',
    'Password' => 'your_private_api_password',
    'ApiVersion' => '2025-01',
    // Retry settings
    'MaxRetries' => 3,
    'RetryDelay' => 500,
    'RetryBackoff' => 'exponential',
];

$shopify = new ShopifySDK($config);
```

#### 3. GraphQL Query & Mutation
```php
$query = <<<'GQL'
query GetProducts($first: Int!) {
    products(first: $first) {
        edges {
            node {
                id
                title
            }
        }
    }
}
GQL;

$data = $shopify->GraphQL->query($query, ['first' => 5], 'GetProducts');

$cost = $shopify->GraphQL->getCost();
echo "Actual query cost: " . $cost['actualQueryCost'];
```

#### 4. Handling Typed Exceptions
```php
use PHPShopify\Exception\ValidationException;
use PHPShopify\Exception\AuthenticationException;
use PHPShopify\Exception\ApiException;

try {
    $shopify->Product->post(['body_html' => 'No title']);
} catch (ValidationException $e) {
    echo "Validation failed: " . $e->getMessage() . "\n";
    print_r($e->getFieldErrors());
} catch (AuthenticationException $e) {
    echo "Invalid credentials\n";
} catch (ApiException $e) {
    echo "API call failed [HTTP {$e->getHttpCode()}]: " . $e->getMessage() . "\n";
}
```

#### 5. Webhook Signature Verification
```php
use PHPShopify\AuthHelper;

$payload = file_get_contents('php://input');
$hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? null;
$secret = 'your_shared_secret';

if (AuthHelper::verifyWebhookRequest($payload, $hmac, $secret)) {
    $event = json_decode($payload, true);
} else {
    http_response_code(401);
    exit('Unauthorized');
}
```

---

### Credits & Acknowledgements

This project is a secondary development based on [php-shopify](https://github.com/phpclassic/php-shopify), originally open-sourced by phpclassic under the Apache-2.0 license.

---

### License

This project retains the original license. See [LICENSE](LICENSE) (Apache License 2.0) for details.
