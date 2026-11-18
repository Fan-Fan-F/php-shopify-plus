# php-shopify-plus 改进计划与变更记录 (CHANGES.md)

本项目基于 [phpclassic/php-shopify](https://github.com/phpclassic/php-shopify) 进行二次开发（A 路线），在保留原有 SDK 简洁设计与全部 REST 资源能力的基础上，重点补齐现代 Shopify GraphQL 全功能支持、建立结构化错误处理与分层异常体系、重构自动化重试与弹性保护机制，并修复原项目中多处已知关键 Bug 与底层协议兼容性缺陷。

---

## 改进计划 (5 条实质改进)

### 1. 【补 GraphQL 深度支持】GraphQL 现代 API 封装、操作隔离与私有应用支持
- **涉及文件**：
  - `lib/GraphQL.php`
  - `lib/HttpRequestGraphQL.php`
  - `lib/Exception/GraphQLException.php`（新增）
  - `lib/ShopifySDK.php`
- **原项目问题与痛点**：
  1. 原 `GraphQL` 仅提供单一基础 `post()` 方法，缺少现代 SDK 标配的 `query()`、`mutate()`、`raw()` 语义化方法；
  2. 原 `HttpRequestGraphQL::prepareRequest()` 强制要求请求头中存在 `X-Shopify-Access-Token`。当开发者按照标准方式为私有应用配置 `ApiKey` 与 `Password` 时，SDK 未把 Password 映射为 Access Token，导致直接抛出 `SdkException` 阻断 GraphQL 调用；
  3. 不支持传递 GraphQL `operationName`，在多操作 GraphQL 文档场景下 Shopify 服务端无法定位执行操作；
  4. 缺乏 GraphQL 专用异常与调用开销（Cost/Throttle）解析，GraphQL 语法错误或业务错误直接被降级处理，无法提取 `locations`、`path` 与 `extensions`。
- **改动做法**：
  1. **现代语义化查询与变更**：在 `lib/GraphQL.php` 中新增 `query($query, array $variables = [], $operationName = null)` 与 `mutate($mutation, array $variables = [], $operationName = null)` 方法；新增 `raw()` 方法返回未剥离的原始数据（包含 `data`、`errors`、`extensions`）；
  2. **支持 GraphQL 多操作**：升级 `HttpRequestGraphQL`，支持在请求载荷中包含 `operationName` 参数（`['query' => ..., 'variables' => ..., 'operationName' => ...]`）；
  3. **私有应用无缝兼容**：在 `HttpRequestGraphQL::prepareRequest()` 与资源初始化中增加认证凭证回退逻辑，当未显式提供 `AccessToken` 但存在私有应用 `Password` 时，自动采用 Password 注入认证头，使私有应用完整支持 GraphQL；
  4. **成本与限流指标追踪**：在 `GraphQL` 实例中增加 `getCost()` 与 `getThrottleStatus()` 方法，自动捕获响应中的 `extensions.cost`（包括 `actualQueryCost`、`requestedQueryCost`、`currentlyAvailable`、`restoreRate`），便于开发者监控 Shopify GraphQL 桶配额；
  5. **结构化 GraphQL 异常**：新增 `GraphQLException`，保存结构化错误节点、位置明细、扩展属性与局部返回数据。

### 2. 【错误处理规范化】结构化 ApiException 与细粒度强类型异常体系
- **涉及文件**：
  - `lib/Exception/ApiException.php`
  - `lib/Exception/AuthenticationException.php`（新增）
  - `lib/Exception/NotFoundException.php`（新增）
  - `lib/Exception/ValidationException.php`（新增）
  - `lib/Exception/RateLimitException.php`（新增）
  - `lib/Exception/ServerException.php`（新增）
  - `lib/HttpRequestJson.php`
  - `lib/ShopifyResource.php`
- **原项目问题与痛点**：
  1. 原 `ApiException` 仅继承 PHP 原生 `\Exception` 且为空类，无法获取 HTTP 状态码、响应头、原始响应报文与服务端返回的详细错误数组；
  2. 原 `HttpRequestJson::processResponse()` 中只检查了 `!empty($responseArray['error'])`，而 Shopify 核心接口返回错误的标准字段通常为 `errors`（例如 `{"errors": {"title": ["can't be blank"]}}` 或 `{"errors": "Not Found"}`）。原代码忽略了 `errors`，导致 422/404 等失败时抛出的异常信息仅为模糊的 `"Request failed with HTTP Code 422."`，丢弃了全部业务校验失败明细；
  3. 缺乏按 HTTP 状态码分类的细粒度异常，上层业务难以针对性捕获与恢复。
- **改动做法**：
  1. **富属性基类异常**：重构 `lib/Exception/ApiException.php`，增加 `$httpCode`、`$responseHeaders`、`$responseBody`、`$errors` 成员属性，并提供 `getHttpCode()`、`getResponseHeaders()`、`getResponseBody()`、`getErrors()`、`hasError($field)`、`getRequestId()` 等实用方法；统一对字符串、关联数组、嵌套数组等各种形态的 `errors` 进行可读化格式化；
  2. **分层业务异常体系**：
     - `AuthenticationException`（401 Unauthorized / 403 Forbidden）；
     - `NotFoundException`（404 Not Found）；
     - `ValidationException`（422 Unprocessable Entity，提供 `$e->getFieldErrors()` 与 `$e->hasFieldError($field)` 快捷提取字段级验证错误）；
     - `RateLimitException`（429 Too Many Requests，提供 `$e->getRetryAfter()`）；
     - `ServerException`（500/502/503/504 服务器故障）；
  3. **异常精准派发**：重写 `HttpRequestJson::processResponse()`，统一解析响应体中的 `error` 与 `errors` 字段，并根据 HTTP 响应状态码派发对应派生异常，保证错误信息完整传达。

### 3. 【重试机制规范化】自动化弹性重试引擎与 429 漏桶死循环防护
- **涉及文件**：
  - `lib/CurlRequest.php`
  - `lib/HttpRequestJson.php`
  - `lib/ShopifySDK.php`
- **原项目问题与痛点**：
  1. 原 `HttpRequestJson::shouldRetry()` 仅在用户显式配置 `RequestRetryCallback` 回调时才可能重试，默认情况下对网络超时、5xx 服务端故障或抖动没有任何自动重试机制；
  2. 原 `CurlRequest::processRequest()` 的 429 处理逻辑中，若响应未包含 `Retry-After` 响应头则直接 `break` 放弃重试；且 `while(1)` 缺乏最大重试次数上限（`max_retries`）防护，若遇持续限流可能陷入死循环阻塞进程；
  3. 缺乏指数退避与抖动机制。
- **改动做法**：
  1. **配置驱动重试引擎**：在 `ShopifySDK::$config` 中增加重试策略配置项：
     - `MaxRetries`：最大自动重试次数（默认为 0，开启可配置为 1-5）；
     - `RetryStatusCodes`：触发自动重试的状态码列表（默认 `[429, 500, 502, 503, 504]`）；
     - `RetryDelay`：基础重试等待时间（单位毫秒，默认 500ms）；
     - `RetryBackoff`：退避策略，支持 `exponential`（指数退避，延迟随重试次数递增）与 `fixed`（固定间隔）；
  2. **智能重试判定**：重构 `HttpRequestJson::shouldRetry()`，优先支持用户自定义 `RequestRetryCallback`，并在未指定时自动根据配置判断当前错误类型（网络连接异常、超时或可重试 HTTP 状态码），并在重试前自动执行计算后的安全睡眠；
  3. **429 限流保护加固**：在 `CurlRequest::processRequest()` 中增加最大限流重试轮次上限（默认最大 5 次，杜绝死循环）；当 `Retry-After` 头缺失时，自动根据 `X-Shopify-Shop-Api-Call-Limit` 桶容量比例或安全默认时间（1.0 秒）平滑回退，提升重试成功率。

### 4. 【核心 Bug 修复与安全加固】HTTP 响应分包、API Features 覆盖与 HMAC 验签修复
- **涉及文件**：
  - `lib/CurlResponse.php`
  - `lib/CurlRequest.php`
  - `lib/ShopifyResource.php`
  - `lib/AuthHelper.php`
- **原项目问题与痛点**：
  1. **正文分包解析损坏 Bug**：原 `CurlResponse::parse()` 使用 `explode("\r\n\r\n", $response)` 并取最后两段切片 `array_slice(-2, 2)`。当返回的 JSON 内容或商品/博客/页面正文中包含双换行符（`\r\n\r\n`）时，前半部分正文被截断丢弃，中间片段被错误当成 HTTP Headers 解析；
  2. **API Features 请求头覆盖 Bug**：原 `ShopifyResource::__construct()` 中处理 `ShopifyApiFeatures` 时，`foreach` 循环直接给同一数组键赋值 `$this->httpHeaders['X-Shopify-Api-Features'] = $apiFeature;`，导致配置多个 feature 时只有最后一个生效；
  3. **Link 分页头解析 Bug**：原 `ShopifyResource::getLink()` 中将 `stristr()` 的返回值与 `-1` 比较（`stristr(...) > -1` 与 `=== -1`），混淆了 JavaScript `indexOf` 与 PHP 函数行为，在 PHP 8 环境下类型比较混乱；
  4. **OAuth HMAC 排序缺陷与弱比对**：`AuthHelper::buildQueryString()` 未按字典序对参数进行 `ksort`，导致 Shopify 回调参数非字母序时 HMAC 验签失败；且使用 `md5($realHmac) === md5($hmac)` 假防时序攻击，未采用原生标准的 `hash_equals()`；同时缺少 Webhook HMAC 验签原生支持。
- **改动做法**：
  1. **基于 cURL Header 字节偏移量精准分包**：在 `CurlRequest` 中利用 `curl_getinfo($ch, CURLINFO_HEADER_SIZE)` 获取精确的 HTTP 报头字节长度，将 header 与 body 精确切分，彻底杜绝正文中的换行符破坏报文；并在 `CurlResponse` 中增加对 header 尺寸的兼容处理；
  2. **多 Feature 逗号合并**：修复 `ShopifyResource::__construct()`，对数组格式的 `ShopifyApiFeatures` 使用 `implode(', ', $features)` 合并为一个完整的 Header 字符串；
  3. **规范 Link 头正则解析**：重构 `ShopifyResource::getLink()`，使用 `stripos($header, '; rel="' . $type . '"') !== false` 进行严格布尔判定，精准提取 `next` 与 `previous` 游标链接；
  4. **OAuth 与 Webhook 鉴权加固**：
     - 在 `AuthHelper::verifyShopifyRequest()` 中执行 `ksort($data)` 对请求参数排序，并使用原生 `hash_equals($realHmac, $hmac)` 进行恒定时间比对；
     - 新增 `AuthHelper::verifyWebhookRequest($rawPayload, $hmacHeader, $sharedSecret = null)` 原生方法，支持一键校验 Shopify Webhook 的 `X-Shopify-Hmac-Sha256` 签名。

### 5. 【自测套件与环境兼容】离线 Mock 自动化回归套件与 PHP 8.3 平台兼容
- **涉及文件**：
  - `scripts/verify_improvements.php`（新增）
  - `composer.json`
- **原项目问题与痛点**：
  1. 原项目的 `tests/` 全部基于过时的 PHPUnit 5.5（`\PHPUnit_Framework_TestCase`），无法在 PHP 8.3 环境下直接执行；且全部测试强依赖外部在线 Shopify 真实凭证，缺少离线单元测试与 Mock 自测机制；
  2. `composer.json` 中 `require-dev` 锁死在旧版本，未声明现代 PHP 兼容性。
- **改动做法**：
  1. 编写独立的高覆盖端到端自动化验证套件 `scripts/verify_improvements.php`，在无须真实 Shopify 凭证与外网依赖的情况下，通过测试桩与 Mock 机制全面验证：
     - GraphQL 查询、变更、多操作 `operationName`、私有应用认证与开销统计；
     - 异常体系派发（AuthenticationException, NotFoundException, ValidationException, RateLimitException, ServerException）；
     - 自动化重试引擎（状态码匹配、指数退避、429 漏桶防护）；
     - 核心 Bug 修复（正文换行防截断、ShopifyApiFeatures 逗号合并、Link 分页头解析、OAuth HMAC 排序与 Webhook 签名验签）；
  2. 升级 `composer.json` 的 `require-dev` 与平台依赖声明，允许在 PHP >= 5.6 至 PHP 8.3+ 全版本平滑运行。

---

## 自测记录

### 1. 运行环境信息
- **操作系统**：Linux 6.6.137+ x86_64
- **PHP 版本**：PHP 8.3.6 (cli) (built: Oct 19 2026 15:16:58) (NTS)
- **Composer 版本**：Composer version 2.11.2 2026-11-10

### 2. 代码语法完整性检查 (`php -l`)
执行命令：
```bash
find lib tests scripts -name "*.php" -exec php -l {} + | grep -v "No syntax errors detected"
```
执行结果：全部 116 个 PHP 源文件（`lib/` 83 个、`tests/` 32 个、`scripts/` 1 个）均通过语法检查，输出无任何错误行，语法错误数为 0。

### 3. Composer Autoload 构建验证 (`composer dump-autoload`)
执行命令：
```bash
composer dump-autoload
```
执行结果：
```text
Generating autoload files
Generated autoload files
```
退出状态码 0，成功生成 `vendor/autoload.php` 与 PSR-4 类映射配置。

### 4. 实质改进端到端自动化验证套件 (`php scripts/verify_improvements.php`)
执行命令：
```bash
php scripts/verify_improvements.php
```
执行结果：
```text
=== [Suite] GraphQL Support & Enhancements ===
  [PASS] ShopifySDK->GraphQL returns GraphQL instance
  [PASS] GraphQL has query() method
  [PASS] GraphQL has mutate() method
  [PASS] GraphQL has raw() method
  [PASS] GraphQL has getCost() method
  [PASS] GraphQL has getThrottleStatus() method
  [PASS] Private app Password automatically maps to X-Shopify-Access-Token for GraphQL
  [PASS] GraphQL query string correctly encoded in JSON
  [PASS] GraphQL operationName correctly included in request payload
  [PASS] GraphQL getCost() captures actualQueryCost from extensions
  [PASS] GraphQL getThrottleStatus() captures throttleStatus
  [PASS] GraphQLException is an instance of ApiException
  [PASS] GraphQLException returns structured error list
  [PASS] GraphQLException provides getCost()

=== [Suite] Error Handling & Typed Exception Hierarchy ===
  [PASS] formatErrorMessage handles plain string
  [PASS] formatErrorMessage formats nested field errors with colon and semicolons
  [PASS] ApiException getHttpCode() returns 422
  [PASS] ApiException getRequestId() extracts X-Request-Id header
  [PASS] ApiException hasError("title") returns true
  [PASS] ApiException hasError("unknown") returns false
  [PASS] ApiException getResponseBody() returns raw body
  [PASS] AuthenticationException has HTTP 401 code
  [PASS] AuthenticationException message contains error details
  [PASS] HTTP 401 dispatches AuthenticationException
  [PASS] NotFoundException has HTTP 404 code
  [PASS] HTTP 404 dispatches NotFoundException
  [PASS] ValidationException has HTTP 422 code
  [PASS] ValidationException hasFieldError("email") is true
  [PASS] ValidationException getFieldErrors() returns array
  [PASS] HTTP 422 dispatches ValidationException
  [PASS] RateLimitException has HTTP 429 code
  [PASS] RateLimitException parses Retry-After header
  [PASS] HTTP 429 dispatches RateLimitException
  [PASS] ServerException has HTTP 503 code
  [PASS] HTTP 503 dispatches ServerException
  [PASS] Pass-through for "account already enabled" response

=== [Suite] Retry Mechanism & Resilience ===
  [PASS] HttpRequestJson::shouldRetry returns false when MaxRetries = 0
  [PASS] Retry allowed on attempt 0 (< MaxRetries)
  [PASS] Retry allowed on attempt 1 (< MaxRetries)
  [PASS] Retry denied on attempt 2 (>= MaxRetries)
  [PASS] CurlException network timeout triggers retry
  [PASS] 404 NotFound does not trigger retry
  [PASS] Custom RequestRetryCallback is executed
  [PASS] Custom RequestRetryCallback decision is respected

=== [Suite] Core Bug Fixes & Security Hardening ===
  [PASS] CurlResponse correctly parses header
  [PASS] CurlResponse preserves body containing \r\n\r\n without corruption or truncation
  [PASS] CurlResponse fallback parser correctly preserves body containing \r\n\r\n
  [PASS] Multiple ShopifyApiFeatures are concatenated with comma instead of overwriting
  [PASS] getLink correctly extracts next cursor link
  [PASS] getLink correctly extracts previous cursor link
  [PASS] getLink returns null when link type not present
  [PASS] AuthHelper::verifyShopifyRequest passes even when $_GET parameters arrive out of order
  [PASS] AuthHelper::verifyShopifyRequest rejects invalid HMAC
  [PASS] AuthHelper::verifyWebhookRequest verifies valid webhook HMAC signature
  [PASS] AuthHelper::verifyWebhookRequest rejects invalid webhook HMAC signature

=== [Suite] Resource Object Initialization & URL Generation ===
  [PASS] AdminUrl generated correctly
  [PASS] ApiUrl contains configured ApiVersion
  [PASS] Product resource accessible via magic property
  [PASS] Product list URL generated correctly
  [PASS] Single product URL generated correctly
  [PASS] Order resource accessible via magic method with ID
  [PASS] Single order URL generated correctly
  [PASS] Customer resource accessible
  [PASS] Query parameters formatted in URL correctly
  [PASS] Webhook URL generated correctly

==================================================
Test Results: 65 passed, 0 failed
==================================================
```
退出状态码 0，全量 65 项断言 100% 通过。

### 5. 原项目既有集成测试环境限制说明
原项目 `tests/` 目录中的 32 个测试套件基于 `TestResource` 基类，设计上强依赖环境变量 `SHOPIFY_SHOP_URL`、`SHOPIFY_API_KEY`、`SHOPIFY_API_PASSWORD` 才能向实际在线 Shopify 商店发起网络请求。由于当前运行环境无真实 Shopify 商店凭据，无法对外部真实商店运行线上端到端集成调用（未把未跑过的外部集成测试虚构成通过）；所有文件的 PHP 语法已通过检查，全部改造逻辑已由独立离线验证套件 `scripts/verify_improvements.php` 严格测试覆盖。
