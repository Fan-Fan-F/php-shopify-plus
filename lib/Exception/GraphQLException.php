<?php

namespace PHPShopify\Exception;

/**
 * Thrown when Shopify GraphQL API returns error responses
 */
class GraphQLException extends ApiException
{
    /**
     * List of GraphQL error objects
     *
     * @var array
     */
    protected $graphQLErrors = [];

    /**
     * Partial data returned with the response
     *
     * @var mixed
     */
    protected $data;

    /**
     * Extensions returned with the response
     *
     * @var array|null
     */
    protected $extensions;

    /**
     * GraphQLException constructor.
     *
     * @param string $message
     * @param int $code
     * @param \Exception|null $previous
     * @param int|null $httpCode
     * @param array $responseHeaders
     * @param string|null $responseBody
     * @param array $graphQLErrors
     * @param mixed $data
     * @param array|null $extensions
     */
    public function __construct(
        $message = "",
        $code = 0,
        $previous = null,
        $httpCode = null,
        $responseHeaders = [],
        $responseBody = null,
        $graphQLErrors = [],
        $data = null,
        $extensions = null
    ) {
        $this->graphQLErrors = is_array($graphQLErrors) ? $graphQLErrors : [];
        $this->data = $data;
        $this->extensions = $extensions;

        parent::__construct(
            $message,
            $code,
            $previous,
            $httpCode,
            $responseHeaders,
            $responseBody,
            $this->graphQLErrors
        );
    }

    /**
     * Get GraphQL errors
     *
     * @return array
     */
    public function getGraphQLErrors()
    {
        return $this->graphQLErrors;
    }

    /**
     * Get partial data
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Get extensions
     *
     * @return array|null
     */
    public function getExtensions()
    {
        return $this->extensions;
    }

    /**
     * Get query cost information from extensions if available
     *
     * @return array|null
     */
    public function getCost()
    {
        if (isset($this->extensions['cost'])) {
            return $this->extensions['cost'];
        }
        return null;
    }
}
