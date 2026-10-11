<?php

namespace ItsJustVita\LaravelZnuny\Http;

use ItsJustVita\LaravelZnuny\Exceptions\ZnunyConfigurationException;

final class QueryString
{
    /**
     * Encode parameters the way Znuny's REST transport parses query strings:
     * raw URL encoding, repeated keys for lists, no bracket notation.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function build(array $params, string $operation = ''): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (! is_array($value)) {
                $pairs[] = self::pair((string) $key, $value);

                continue;
            }

            if (! array_is_list($value)) {
                throw self::nested($operation, (string) $key);
            }

            foreach ($value as $item) {
                if (is_array($item)) {
                    throw self::nested($operation, (string) $key);
                }

                if ($item !== null) {
                    $pairs[] = self::pair((string) $key, $item);
                }
            }
        }

        return implode('&', $pairs);
    }

    public static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    private static function pair(string $key, mixed $value): string
    {
        return rawurlencode($key).'='.rawurlencode(self::scalar($value));
    }

    private static function nested(string $operation, string $key): ZnunyConfigurationException
    {
        return new ZnunyConfigurationException(sprintf(
            'Operation [%1$s] uses a GET route, but parameter [%2$s] is a nested structure that Znuny cannot read from a query string. '
            ."Map the operation to a route with a request body, e.g. routes['%1\$s'] = 'POST /%1\$s'.",
            $operation,
            $key,
        ));
    }
}
