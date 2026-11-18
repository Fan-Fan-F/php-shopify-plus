<?php

namespace PHPShopify;

class CurlResponse
{
    /** @var array */
    private $headers = [];
    /** @var string */
    private $body;

    public function __construct($response, $headerSize = null)
    {
        $this->parse($response, $headerSize);
    }

    /**
     * @param string $response
     * @param int|null $headerSize
     */
    private function parse($response, $headerSize = null)
    {
        if ($headerSize !== null && $headerSize >= 0) {
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            $this->parseHeaders($headers);
            $this->body = $body !== false ? $body : '';
            return;
        }

        $parts = \explode("\r\n\r\n", $response);
        if (\count($parts) > 1) {
            $headerBlocks = [];
            $bodyParts = [];
            $inHeaders = true;
            for ($i = 0; $i < count($parts); $i++) {
                if ($inHeaders && (strpos($parts[$i], 'HTTP/') === 0 || count($headerBlocks) === 0)) {
                    $headerBlocks[] = $parts[$i];
                } else {
                    $inHeaders = false;
                    $bodyParts[] = $parts[$i];
                }
            }
            $lastHeader = end($headerBlocks);
            $this->parseHeaders($lastHeader);
            $this->body = implode("\r\n\r\n", $bodyParts);
        } else {
            $this->body = $response;
        }
    }

    /**
     * Parse raw headers string into key-value map
     *
     * @param string $headers
     */
    private function parseHeaders($headers)
    {
        foreach (\explode("\r\n", $headers) as $header) {
            $pair = \explode(': ', $header, 2);
            if (isset($pair[1])) {
                $headerKey = strtolower($pair[0]);
                $this->headers[$headerKey] = $pair[1];
            }
        }
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function getHeader($key)
    {
        $key = strtolower($key);
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    public function __toString()
    {
        $body = $this->getBody();
        $body = $body ? : '';

        return $body;
    }
}
