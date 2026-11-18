<?php

namespace PHPShopify\Exception;

/**
 * Thrown when Shopify returns 422 Unprocessable Entity
 */
class ValidationException extends ApiException
{
    /**
     * Get field-level validation errors
     *
     * @return array
     */
    public function getFieldErrors()
    {
        return is_array($this->errors) ? $this->errors : [];
    }

    /**
     * Check if a field has validation errors
     *
     * @param string $field
     *
     * @return bool
     */
    public function hasFieldError($field)
    {
        return $this->hasError($field);
    }
}
