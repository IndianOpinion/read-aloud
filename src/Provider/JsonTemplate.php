<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Provider;

use IndianOpinion\ReadAloud\Exception\ProviderConfigException;

/**
 * Fills a JSON body template at the value level.
 *
 * The template is parsed as JSON once. A string value that is exactly one
 * placeholder becomes a typed JSON value; placeholders inside a longer
 * string are replaced in a single pass, so text that itself contains
 * "{voice}" is never expanded again. Nothing is built by concatenating JSON.
 */
final class JsonTemplate
{
    public const PLACEHOLDERS = ['text', 'voice', 'model', 'format', 'speed', 'locale'];

    private mixed $tree;

    /**
     * @throws ProviderConfigException
     */
    public function __construct(string $template)
    {
        try {
            $this->tree = json_decode($template, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderConfigException('The body template is not valid JSON: ' . $e->getMessage());
        }
        if (!is_object($this->tree) && !is_array($this->tree)) {
            throw new ProviderConfigException('The body template must be a JSON object or array.');
        }
    }

    /**
     * @param array<string, string|float|int> $values
     */
    public function fill(array $values): string
    {
        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs['{' . $name . '}'] = (string) $value;
        }
        $filled = $this->walk($this->tree, $values, $pairs);

        return (string) json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|float|int> $values
     * @param array<string, string> $pairs
     */
    private function walk(mixed $node, array $values, array $pairs): mixed
    {
        if (is_string($node)) {
            if (preg_match('/^\{([a-z]+)\}$/', $node, $m) === 1 && array_key_exists($m[1], $values)) {
                return $values[$m[1]];
            }
            return strtr($node, $pairs);
        }
        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $value) {
                $out[$key] = $this->walk($value, $values, $pairs);
            }
            return $out;
        }
        if (is_object($node)) {
            $out = new \stdClass();
            foreach (get_object_vars($node) as $key => $value) {
                $out->{$key} = $this->walk($value, $values, $pairs);
            }
            return $out;
        }

        return $node;
    }

    /**
     * Resolve an RFC 6901 JSON pointer.
     */
    public static function pointer(mixed $document, string $pointer): mixed
    {
        if ($pointer === '') {
            return $document;
        }
        if ($pointer[0] !== '/') {
            return null;
        }
        foreach (explode('/', substr($pointer, 1)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (is_array($document) && array_key_exists($token, $document)) {
                $document = $document[$token];
            } elseif (is_array($document) && ctype_digit($token) && array_key_exists((int) $token, $document)) {
                $document = $document[(int) $token];
            } elseif (is_object($document) && property_exists($document, $token)) {
                $document = $document->{$token};
            } else {
                return null;
            }
        }

        return $document;
    }
}
