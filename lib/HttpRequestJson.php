<?php
/**
 * Created by PhpStorm.
 * @author Tareq Mahmood <tareqtms@yahoo.com>
 * Created at: 8/24/16 9:03 AM UTC+06:00
 */

namespace PHPShopify;

use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\AuthenticationException;
use PHPShopify\Exception\NotFoundException;
use PHPShopify\Exception\ValidationException;
use PHPShopify\Exception\RateLimitException;
use PHPShopify\Exception\ServerException;
use PHPShopify\Exception\CurlException;

/**
 * Class HttpRequestJson
 *
 * Prepare the data / headers for JSON requests, make the call and decode the response
 * Accepts data in array format returns data in array format
 *
 * @uses CurlRequest
 *
 * @package PHPShopify
 */
class HttpRequestJson
{
    /**
     * HTTP request headers
     *
     * @var array
     */
    protected static $httpHeaders;

    /**
     * Prepared JSON string to be posted with request
     *
     * @var string
     */
    protected static $postDataJSON;


    /**
     * Prepare the data and request headers before making the call
     *
     * @param array $httpHeaders
     * @param array $dataArray
     *
     * @return void
     */
    protected static function prepareRequest($httpHeaders = array(), $dataArray = array())
    {

        self::$postDataJSON = json_encode($dataArray);

        self::$httpHeaders = $httpHeaders;

        if (!empty($dataArray)) {
            self::$httpHeaders['Content-type'] = 'application/json';
            self::$httpHeaders['Content-Length'] = strlen(self::$postDataJSON);
        }
    }

    /**
     * Implement a GET request and return json decoded output
     *
     * @param string $url
     * @param array $httpHeaders
     *
     * @return array
     */
    public static function get($url, $httpHeaders = array())
    {
        self::prepareRequest($httpHeaders);

        return self::processRequest('GET', $url);
    }

    /**
     * Implement a POST request and return json decoded output
     *
     * @param string $url
     * @param array $dataArray
     * @param array $httpHeaders
     *
     * @return array
     */
    public static function post($url, $dataArray, $httpHeaders = array())
    {
        self::prepareRequest($httpHeaders, $dataArray);

        return self::processRequest('POST', $url);
    }

    /**
     * Implement a PUT request and return json decoded output
     *
     * @param string $url
     * @param array $dataArray
     * @param array $httpHeaders
     *
     * @return array
     */
    public static function put($url, $dataArray, $httpHeaders = array())
    {
        self::prepareRequest($httpHeaders, $dataArray);

        return self::processRequest('PUT', $url);
    }

    /**
     * Implement a DELETE request and return json decoded output
     *
     * @param string $url
     * @param array $httpHeaders
     *
     * @return array
     */
    public static function delete($url, $httpHeaders = array())
    {
        self::prepareRequest($httpHeaders);

        return self::processRequest('DELETE', $url);
    }

    /**
     * Process a curl request and return decoded JSON response
     *
     * @param string $method Request http method ('GET', 'POST', 'PUT' or 'DELETE')
     * @param string $url Request URL
     *
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public static function processRequest($method, $url) {
        $retry = 0;
        $raw = null;

        while(true) {
            try {
                switch($method) {
                    case 'GET':
                        $raw = CurlRequest::get($url, self::$httpHeaders);
                        break;
                    case 'POST':
                        $raw = CurlRequest::post($url, self::$postDataJSON, self::$httpHeaders);
                        break;
                    case 'PUT':
                        $raw = CurlRequest::put($url, self::$postDataJSON, self::$httpHeaders);
                        break;
                    case 'DELETE':
                        $raw = CurlRequest::delete($url, self::$httpHeaders);
                        break;
                    default:
                        throw new \Exception("unexpected request method '$method'");
                }

                return self::processResponse($raw);
            } catch(\Exception $e) {
                if (!self::shouldRetry($raw, $e, $retry++)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Evaluate whether to retry a request
     *
     * @param string $response Raw request response
     * @param \Exception $error The request error occurred
     * @param integer $retry The current number of retry (0-indexed)
     *
     * @return bool
     */
    public static function shouldRetry($response, $error, $retry) {
        $config = ShopifySDK::$config;

        if (isset($config['RequestRetryCallback'])) {
            return (bool) $config['RequestRetryCallback']($response, $error, $retry);
        }

        $maxRetries = isset($config['MaxRetries']) ? (int)$config['MaxRetries'] : 0;
        if ($retry >= $maxRetries) {
            return false;
        }

        $retryStatusCodes = isset($config['RetryStatusCodes']) && is_array($config['RetryStatusCodes'])
            ? $config['RetryStatusCodes']
            : [429, 500, 502, 503, 504];

        $shouldRetry = false;
        if ($error instanceof CurlException) {
            $shouldRetry = true;
        } elseif ($error instanceof ApiException) {
            $code = $error->getHttpCode();
            if ($code && in_array($code, $retryStatusCodes)) {
                $shouldRetry = true;
            }
        }

        if ($shouldRetry) {
            $delayMs = isset($config['RetryDelay']) ? (int)$config['RetryDelay'] : 500;
            $backoff = isset($config['RetryBackoff']) ? strtolower($config['RetryBackoff']) : 'exponential';

            if ($backoff === 'exponential') {
                $waitMs = $delayMs * pow(2, $retry);
            } else {
                $waitMs = $delayMs;
            }

            // Cap at 10 seconds max wait
            $waitMs = min($waitMs, 10000);
            usleep($waitMs * 1000);
            return true;
        }

        return false;
    }

    /**
     * Helper to format complex error structures into readable string
     *
     * @param mixed $errors
     *
     * @return string
     */
    public static function formatErrorMessage($errors)
    {
        if (is_string($errors)) {
            return $errors;
        }
        if (is_array($errors)) {
            $parts = [];
            foreach ($errors as $field => $messages) {
                if (is_array($messages)) {
                    $messagesStr = implode(', ', array_map('strval', $messages));
                    $parts[] = is_numeric($field) ? $messagesStr : "$field: $messagesStr";
                } elseif (is_scalar($messages)) {
                    $parts[] = is_numeric($field) ? (string)$messages : "$field: $messages";
                }
            }
            if (!empty($parts)) {
                return implode('; ', $parts);
            }
        }
        return '';
    }

    /**
     * Decode JSON response and dispatch typed exceptions if needed
     *
     * @param string $response
     *
     * @throws ApiException
     * @throws AuthenticationException
     * @throws NotFoundException
     * @throws ValidationException
     * @throws RateLimitException
     * @throws ServerException
     *
     * @return array
     */
    protected static function processResponse($response)
    {
        $responseArray = json_decode($response, true);

        // Checking HTTP Codes
        $httpOK = 200; // Request Successful, OK.
        $httpCreated = 201; // Create Successful.
        $httpDeleted = 204; // Delete Successful
        $httpOther = 303; // See other (headers).

        $lastHttpResponseHeaders = CurlRequest::$lastHttpResponseHeaders;
        $httpCode = CurlRequest::$lastHttpCode;

        if ($httpCode == $httpOther && array_key_exists('location', $lastHttpResponseHeaders)) {
            return ['location' => $lastHttpResponseHeaders['location']];
        }

        $hasErrorPayload = false;
        $errorMessage = '';
        $extractedErrors = null;

        if (is_array($responseArray)) {
            if (!empty($responseArray['errors'])) {
                $hasErrorPayload = true;
                $extractedErrors = $responseArray['errors'];
                $errorMessage = self::formatErrorMessage($responseArray['errors']);
            } elseif (!empty($responseArray['error'])) {
                $hasErrorPayload = true;
                $extractedErrors = $responseArray['error'];
                $errorMessage = is_string($responseArray['error']) ? $responseArray['error'] : self::formatErrorMessage($responseArray['error']);
            }
        }

        // Allow 'account already enabled' error response to pass through to resource handler
        if ($extractedErrors === 'account already enabled' || (is_array($extractedErrors) && in_array('account already enabled', $extractedErrors))) {
            return $responseArray;
        }

        $isSuccessHttpCode = in_array($httpCode, [null, $httpOK, $httpCreated, $httpDeleted]);

        if (!$isSuccessHttpCode || $hasErrorPayload) {
            $prefix = "Request failed" . ($httpCode ? " with HTTP Code $httpCode" : "");
            $fullMessage = !empty($errorMessage) ? "$prefix: $errorMessage" : "$prefix.";

            switch ($httpCode) {
                case 401:
                case 403:
                    throw new AuthenticationException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
                case 404:
                    throw new NotFoundException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
                case 422:
                    throw new ValidationException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
                case 429:
                    throw new RateLimitException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
                case 500:
                case 502:
                case 503:
                case 504:
                    throw new ServerException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
                default:
                    throw new ApiException($fullMessage, (int)$httpCode, null, $httpCode, $lastHttpResponseHeaders, $response, $extractedErrors);
            }
        }

        return $responseArray;
    }
}
