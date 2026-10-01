<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Model;

/**
 * Encodes JSON exactly like Python's `json.dumps(value, indent=2)`, which is
 * what erdify's `--format json` writes. PHP's json_encode differs (4-space
 * indent, escaped "/", raw UTF-8), so `--check` against an upstream file would
 * fail without this.
 *
 * Arrays that are lists encode as JSON arrays, other arrays as objects.
 */
final class PythonJson
{
    public static function encode(mixed $value): string
    {
        return self::encodeValue($value, 0);
    }

    private static function encodeValue(mixed $value, int $depth): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Unsupported JSON value: ' . get_debug_type($value));
        }
        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $depth + 1);
        $closing = str_repeat('  ', $depth);
        $items = [];
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $items[] = $indent . self::encodeValue($item, $depth + 1);
            }
            return "[\n" . implode(",\n", $items) . "\n" . $closing . ']';
        }
        foreach ($value as $key => $item) {
            $items[] = $indent . self::encodeString((string) $key) . ': ' . self::encodeValue($item, $depth + 1);
        }
        return "{\n" . implode(",\n", $items) . "\n" . $closing . '}';
    }

    /** Python's ensure_ascii=True string encoding. */
    private static function encodeString(string $text): string
    {
        $out = '"';
        $codepoints = mb_str_split($text, 1, 'UTF-8');
        foreach ($codepoints as $char) {
            $out .= match ($char) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\x08" => '\\b',
                "\x0C" => '\\f',
                default => self::escapeChar($char),
            };
        }
        return $out . '"';
    }

    private static function escapeChar(string $char): string
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code >= 0x20 && $code < 0x7F) {
            return $char;
        }
        if ($code < 0x10000) {
            return sprintf('\\u%04x', $code);
        }
        // Outside the BMP: a UTF-16 surrogate pair, as Python writes it.
        $code -= 0x10000;
        return sprintf('\\u%04x\\u%04x', 0xD800 | ($code >> 10), 0xDC00 | ($code & 0x3FF));
    }
}
