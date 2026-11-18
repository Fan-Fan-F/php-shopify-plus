<?php

namespace PHPShopify\Exception;

/**
 * Thrown when Shopify returns 429 Too Many Requests
 */
class RateLimitException extends ApiException
{
    /**
     * Seconds to wait before retry
     *
     * @var float|null
     */
    protected $retryAfter;

    /**
     * RateLimitException constructor.
     *
     * @param string $message
     * @param int $code
     * @param \Exception|null $previous
     * @param int|null $httpCode
     * @param array $responseHeaders
     * @param string|null $responseBody
     * @param mixed $errors
     * @param float|null $retryAfter
     */
    public function __construct(
        $message = "",
        $code = 429,
        $previous = null,
        $httpCode = 429,
        $responseHeaders = [],
        $responseBody = null,
        $errors = null,
        $retryAfter = null
    ) {
        $this->retryAfter = $retryAfter;
        if ($this->retryAfter === null && is_array($responseHeaders)) {
            foreach ($responseHeaders as $header => $val) {
                if (strtolower($header) === 'retry-after') {
                    $this->retryAfter = (float)$val;
                    break;
                }
            }
        }
        parent::__construct($message, $code, $previous, $httpCode, $responseHeaders, $responseBody, $errors);
    }

    /**
     * Get the Retry-After seconds
     *
     * @return float|null
     */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }
}
