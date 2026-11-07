<?php

namespace ItsJustVita\LaravelZnuny\Support;

/**
 * Writes the YAML of a Znuny web service definition, so the package needs no YAML library.
 * Znuny reads every value as a string, so all scalars are written as quoted strings.
 */
final class Yaml
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function dump(array $data): string
    {
        return "---\n".implode("\n", self::lines($data, 0))."\n";
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private static function lines(array $data, int $indent): array
    {
        if ($data === []) {
            return [str_repeat(' ', $indent).'{}'];
        }

        $pad = str_repeat(' ', $indent);
        $list = array_is_list($data);
        $lines = [];

        foreach ($data as $key => $value) {
            $nested = is_array($value) && $value !== [];

            if ($list) {
                if ($nested) {
                    // The first line of a nested block moves up behind the dash.
                    $block = self::lines($value, $indent + 2);
                    $block[0] = $pad.'- '.substr($block[0], $indent + 2);
                    array_push($lines, ...$block);
                } else {
                    $lines[] = $pad.'- '.self::scalar($value);
                }

                continue;
            }

            $label = $pad.self::key((string) $key).':';

            if ($nested) {
                $lines[] = $label;
                array_push($lines, ...self::lines($value, $indent + 2));
            } else {
                $lines[] = $label.' '.self::scalar($value);
            }
        }

        return $lines;
    }

    private static function key(string $key): string
    {
        // Unquoted keys such as True, Null or 42 would be read as other types.
        $plain = preg_match('/^[A-Za-z][A-Za-z0-9_.\/-]*$/', $key) === 1
            && ! in_array(strtolower($key), ['y', 'n', 'yes', 'no', 'on', 'off', 'true', 'false', 'null'], true);

        return $plain ? $key : self::string($key);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '~',
            $value === [] => '{}',
            is_bool($value) => $value ? "'1'" : "'0'",
            is_scalar($value) => self::string((string) $value),
            default => self::string(get_debug_type($value)),
        };
    }

    private static function string(string $value): string
    {
        // Single quotes would fold line breaks into spaces; double quotes keep them escaped.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
