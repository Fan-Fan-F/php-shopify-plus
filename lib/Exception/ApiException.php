<?php
/**
 * Created by PhpStorm.
 * @author Tareq Mahmood <tareqtms@yahoo.com>
 * Created at 8/19/16 10:28 AM UTC+06:00
 */

namespace PHPShopify\Exception;

class ApiException extends \Exception
{
    /**
     * HTTP Status code
     *
     * @var int|null
     */
    protected $httpCode;

    /**
     * HTTP Response headers
     *
     * @var array
     */
    protected $responseHeaders = [];

    /**
     * Raw HTTP Response body
     *
     * @var string|null
     */
    protected $responseBody;

    /**
     * Structured errors parsed from response
     *
     * @var mixed
     */
    protected $errors;

    /**
     * ApiException constructor.
     *
     * @param string $message
     * @param int $code
     * @param \Exception|null $previous
     * @param int|null $httpCode
     * @param array $responseHeaders
     * @param string|null $responseBody
     * @param mixed $errors
     */
    public function __construct(
        $message = "",
        $code = 0,
        $previous = null,
        $httpCode = null,
        $responseHeaders = [],
        $responseBody = null,
        $errors = null
    ) {
        // If code is passed as HTTP code (backward compatibility)
        if ($httpCode === null && is_int($code) && $code >= 100 && $code < 600) {
            $httpCode = $code;
        }

        $this->httpCode = $httpCode;
        $this->responseHeaders = is_array($responseHeaders) ? $responseHeaders : [];
        $this->responseBody = $responseBody;
        $this->errors = $errors;

        parent::__construct($message, (int)$code, $previous);
    }

    /**
     * Get the HTTP status code
     *
     * @return int|null
     */
    public function getHttpCode()
    {
        return $this->httpCode;
    }

    /**
     * Get all response headers
     *
     * @return array
     */
    public function getResponseHeaders()
    {
        return $this->responseHeaders;
    }

    /**
     * Get a specific response header (case-insensitive)
     *
     * @param string $header
     * @param mixed $default
     *
     * @return mixed
     */
    public function getResponseHeader($header, $default = null)
    {
        $normalized = strtolower($header);
        foreach ($this->responseHeaders as $key => $value) {
            if (strtolower($key) === $normalized) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Get the raw response body
     *
     * @return string|null
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * Get structured errors
     *
     * @return mixed
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Check if a specific field error exists
     *
     * @param string $field
     *
     * @return bool
     */
    public function hasError($field)
    {
        if (is_array($this->errors)) {
            return array_key_exists($field, $this->errors);
        }
        return false;
    }

    /**
     * Get Shopify Request ID from headers
     *
     * @return string|null
     */
    public function getRequestId()
    {
        return $this->getResponseHeader('x-request-id');
    }
}
