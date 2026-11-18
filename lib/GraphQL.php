<?php
/**
 * Created by PhpStorm.
 * User: Tareq
 * Date: 5/27/2019
 * Time: 12:36 PM
 *
 * @see https://help.shopify.com/en/api/graphql-admin-api GraphQL Admin API
 */

namespace PHPShopify;


use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\CurlException;
use PHPShopify\Exception\GraphQLException;
use PHPShopify\Exception\SdkException;

class GraphQL extends ShopifyResource
{
    /**
     * Last full response from GraphQL API
     *
     * @var array|null
     */
    protected $lastResponse;

    /**
     * Last extensions received (including cost)
     *
     * @var array|null
     */
    protected $lastExtensions;

    /**
     * Last cost metadata received
     *
     * @var array|null
     */
    protected $lastCost;

    /**
     * @inheritdoc
     */
    protected function getResourcePath()
    {
        return 'graphql';
    }

    /**
     * Execute a GraphQL query
     *
     * @param string $query
     * @param array $variables
     * @param string|null $operationName
     * @param string|null $url
     *
     * @return array
     */
    public function query($query, $variables = [], $operationName = null, $url = null)
    {
        return $this->post($query, $url, false, $variables, $operationName);
    }

    /**
     * Execute a GraphQL mutation
     *
     * @param string $mutation
     * @param array $variables
     * @param string|null $operationName
     * @param string|null $url
     *
     * @return array
     */
    public function mutate($mutation, $variables = [], $operationName = null, $url = null)
    {
        return $this->post($mutation, $url, false, $variables, $operationName);
    }

    /**
     * Execute a GraphQL request and return raw output (including data, errors, extensions)
     *
     * @param string $graphQL
     * @param array $variables
     * @param string|null $operationName
     * @param string|null $url
     *
     * @return array
     */
    public function raw($graphQL, $variables = [], $operationName = null, $url = null)
    {
        if (!$url) $url = $this->generateUrl();

        $response = HttpRequestGraphQL::post($url, $graphQL, $this->httpHeaders, $variables, $operationName);

        $this->recordMetadata($response);

        return $response;
    }

    /**
     * Call POST method for any GraphQL request
     *
     * @param string $graphQL A valid GraphQL String. @see https://help.shopify.com/en/api/graphql-admin-api/graphiql-builder
     * @param string $url
     * @param bool $wrapData
     * @param array|null $variables
     * @param string|null $operationName
     *
     * @uses HttpRequestGraphQL::post() to send the HTTP request
     * @throws GraphQLException if response has GraphQL errors specified
     * @throws ApiException if the response has an error specified
     * @throws CurlException if response received with unexpected HTTP code.
     *
     * @return array
     */
    public function post($graphQL, $url = null, $wrapData = false, $variables = null, $operationName = null)
    {
        if (!$url) $url = $this->generateUrl();

        $response = HttpRequestGraphQL::post($url, $graphQL, $this->httpHeaders, $variables, $operationName);

        $this->recordMetadata($response);

        if (is_array($response) && !empty($response['errors'])) {
            $errorMessage = HttpRequestJson::formatErrorMessage($response['errors']);
            $data = isset($response['data']) ? $response['data'] : null;
            $extensions = isset($response['extensions']) ? $response['extensions'] : null;

            throw new GraphQLException(
                "GraphQL request failed: " . $errorMessage,
                CurlRequest::$lastHttpCode ?: 200,
                null,
                CurlRequest::$lastHttpCode ?: 200,
                CurlRequest::$lastHttpResponseHeaders,
                json_encode($response),
                $response['errors'],
                $data,
                $extensions
            );
        }

        return $this->processResponse($response);
    }

    /**
     * Record metadata from GraphQL response
     *
     * @param array $response
     */
    protected function recordMetadata($response)
    {
        if (is_array($response)) {
            $this->lastResponse = $response;
            $this->lastExtensions = isset($response['extensions']) ? $response['extensions'] : null;
            $this->lastCost = isset($this->lastExtensions['cost']) ? $this->lastExtensions['cost'] : null;
        }
    }

    /**
     * Get last query cost metadata
     *
     * @return array|null
     */
    public function getCost()
    {
        return $this->lastCost;
    }

    /**
     * Get last throttle status metadata
     *
     * @return array|null
     */
    public function getThrottleStatus()
    {
        if (isset($this->lastCost['throttleStatus'])) {
            return $this->lastCost['throttleStatus'];
        }
        return null;
    }

    /**
     * Get last full response
     *
     * @return array|null
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Get last extensions
     *
     * @return array|null
     */
    public function getLastExtensions()
    {
        return $this->lastExtensions;
    }

    /**
     * @inheritdoc
     * @throws SdkException
     */
    public function get($urlParams = array(), $url = null, $dataKey = null)
    {
        throw new SdkException("Only POST method is allowed for GraphQL!");
    }

    /**
     * @inheritdoc
     * @throws SdkException
     */
    public function put($dataArray, $url = null, $wrapData = true)
    {
        throw new SdkException("Only POST method is allowed for GraphQL!");
    }

    /**
     * @inheritdoc
     * @throws SdkException
     */
    public function delete($urlParams = array(), $url = null)
    {
        throw new SdkException("Only POST method is allowed for GraphQL!");
    }
}
