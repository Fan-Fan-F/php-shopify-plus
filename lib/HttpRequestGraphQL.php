<?php
/**
 * Created by PhpStorm.
 * User: Tareq
 * Date: 5/30/2019
 * Time: 3:25 PM
 */

namespace PHPShopify;


use PHPShopify\Exception\SdkException;

class HttpRequestGraphQL extends HttpRequestJson
{
    /**
     * Prepared GraphQL string to be posted with request
     *
     * @var string
     */
    private static $postDataGraphQL;

    /**
     * Prepare the data and request headers before making the call
     *
     * @param array $httpHeaders
     * @param mixed $data
     * @param array|null $variables
     * @param string|null $operationName
     *
     * @return void
     *
     * @throws SdkException if $data is not a string
     */
    protected static function prepareRequest($httpHeaders = array(), $data = array(), $variables = null, $operationName = null)
    {

        if (is_string($data)) {
            self::$postDataGraphQL = $data;
        } else {
            throw new SdkException("Only GraphQL string is allowed!");
        }

        // Support AccessToken, private app Password, or StorefrontAccessToken
        if (!isset($httpHeaders['X-Shopify-Access-Token']) && !isset($httpHeaders['X-Shopify-Storefront-Access-Token'])) {
            $config = ShopifySDK::$config;
            if (isset($config['AccessToken'])) {
                $httpHeaders['X-Shopify-Access-Token'] = $config['AccessToken'];
            } elseif (isset($config['Password'])) {
                $httpHeaders['X-Shopify-Access-Token'] = $config['Password'];
            } elseif (isset($config['StorefrontAccessToken'])) {
                $httpHeaders['X-Shopify-Storefront-Access-Token'] = $config['StorefrontAccessToken'];
            } else {
                throw new SdkException("The GraphQL Admin API requires an access token (or private app Password) for making authenticated requests!");
            }
        }

        self::$httpHeaders = $httpHeaders;
        self::$httpHeaders['Content-type'] = 'application/json';

        $payload = ['query' => $data];
        if (is_array($variables) && !empty($variables)) {
            $payload['variables'] = $variables;
        }
        if (!empty($operationName)) {
            $payload['operationName'] = $operationName;
        }

        self::$postDataGraphQL = json_encode($payload);
        self::$httpHeaders['Content-Length'] = strlen(self::$postDataGraphQL);
    }

    /**
     * Implement a POST request and return json decoded output
     *
     * @param string $url
     * @param mixed $data
     * @param array $httpHeaders
     * @param array|null $variables
     * @param string|null $operationName
     *
     * @return array
     */
    public static function post($url, $data, $httpHeaders = array(), $variables = null, $operationName = null)
    {
        self::prepareRequest($httpHeaders, $data, $variables, $operationName);

        self::$postDataJSON = self::$postDataGraphQL;

        return self::processRequest('POST', $url);
    }
}
