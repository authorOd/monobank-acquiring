<?php

namespace Vladchornyi\Mono\Tests\Support;

use RuntimeException;

/**
 * Read-only view of the Monobank acquiring OpenAPI document stored in
 * tests/fixtures/monobank. The fixture is the contract; tests derive request
 * shapes and example responses from it instead of from SDK assumptions.
 */
final class OpenApiSpec
{
    private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/D';

    /** @var array<string, mixed> */
    private array $spec;

    public static function load(): self
    {
        $path = dirname(__DIR__) . '/fixtures/monobank/openapi-v2412.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAPI fixture is not an object.');
        }

        return new self($decoded);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    public function version(): string
    {
        return (string) ($this->spec['info']['version'] ?? 'unknown');
    }

    /**
     * @return array<string, string[]> path => methods, with query templates stripped from path keys
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->spec['paths'] as $rawPath => $methods) {
            $path = self::normalizePath((string) $rawPath);

            foreach ($methods as $method => $operation) {
                if (is_array($operation) && isset($operation['responses'])) {
                    $operations[$path][] = strtoupper((string) $method);
                }
            }
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    public function operation(string $method, string $path): array
    {
        foreach ($this->spec['paths'] as $rawPath => $methods) {
            if (self::normalizePath((string) $rawPath) !== $path) {
                continue;
            }

            $operation = $methods[strtolower($method)] ?? null;

            if (is_array($operation)) {
                return $operation;
            }
        }

        throw new RuntimeException("Operation {$method} {$path} is not in the Monobank specification.");
    }

    /**
     * @return array<string, mixed>|null resolved request body schema
     */
    public function requestSchema(string $method, string $path): ?array
    {
        $schema = $this->operation($method, $path)['requestBody']['content']['application/json']['schema'] ?? null;

        return is_array($schema) ? $this->resolve($schema) : null;
    }

    /**
     * @return array<string, mixed>|null resolved 200 response schema
     */
    public function responseSchema(string $method, string $path): ?array
    {
        $schema = $this->operation($method, $path)['responses']['200']['content']['application/json']['schema'] ?? null;

        return is_array($schema) ? $this->resolve($schema) : null;
    }

    /**
     * @return array<string, array{required: bool}> query parameter name => info
     */
    public function queryParameters(string $method, string $path): array
    {
        $parameters = [];

        foreach ($this->operation($method, $path)['parameters'] ?? [] as $parameter) {
            if (($parameter['in'] ?? null) === 'query') {
                $parameters[(string) $parameter['name']] = ['required' => (bool) ($parameter['required'] ?? false)];
            }
        }

        return $parameters;
    }

    /**
     * Follows $ref recursively for the schema node itself (not for nested properties).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $schema = $this->spec['components']['schemas'][$name]
                ?? throw new RuntimeException("Unknown schema reference {$name}.");
        }

        return $schema;
    }

    /**
     * Builds an example document from a schema using the `example` values the
     * specification provides, falling back to type defaults.
     *
     * @param array<string, mixed> $schema
     * @return mixed
     */
    public function example(array $schema, int $depth = 0)
    {
        $schema = $this->resolve($schema);

        if (array_key_exists('example', $schema) && $depth > 0) {
            return $schema['example'];
        }

        switch ($schema['type'] ?? 'object') {
            case 'object':
                $example = [];
                foreach ($schema['properties'] ?? [] as $name => $property) {
                    $example[$name] = $this->example(is_array($property) ? $property : [], $depth + 1);
                }

                return $example;
            case 'array':
                $items = $schema['items'] ?? null;

                return is_array($items) ? [$this->example($items, $depth + 1)] : [];
            case 'integer':
                return 1;
            case 'number':
                return 1.5;
            case 'boolean':
                return true;
            default:
                if (isset($schema['enum'][0])) {
                    return $schema['enum'][0];
                }

                return ($schema['format'] ?? null) === 'date-time' ? '2026-07-01T10:00:00.123456789Z' : 'string';
        }
    }

    /**
     * Validates an encoded JSON document against a schema and returns the
     * problems found (empty when it conforms). The JSON string is decoded with
     * objects preserved, so `{}` and `[]` stay distinguishable. Checks: required
     * properties, undocumented properties, type, enum, minimum/maximum,
     * multipleOf, maxLength, maxItems, uniqueItems, date-time format, and nested objects/array items.
     *
     * @param array<string, mixed> $schema
     * @return string[]
     */
    public function jsonProblems(string $json, array $schema): array
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $problems = [];
        $this->validate($decoded, $schema, '', $problems);

        return $problems;
    }

    /**
     * Convenience for already-decoded associative bodies (JSON objects as
     * arrays). Lists and objects are told apart by their keys, so an empty
     * array is treated as an empty object.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $schema
     * @return string[]
     */
    public function bodyProblems(array $body, array $schema): array
    {
        $json = json_encode(self::toJsonValue($body), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return $this->jsonProblems($json, $schema);
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     * @param string[] $problems
     */
    private function validate($value, array $schema, string $path, array &$problems): void
    {
        $schema = $this->resolve($schema);
        $label = $path === '' ? 'body' : $path;
        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $problems[] = "{$label} must be one of " . implode('|', array_map('strval', $schema['enum']));
        }

        switch ($type) {
            case 'object':
                if (!$value instanceof \stdClass) {
                    $problems[] = "{$label} must be a JSON object";

                    return;
                }

                $properties = $schema['properties'] ?? [];

                foreach ($schema['required'] ?? [] as $required) {
                    if (!property_exists($value, (string) $required)) {
                        $problems[] = "missing required {$label}.{$required}";
                    }
                }

                foreach (get_object_vars($value) as $name => $item) {
                    if (!isset($properties[$name])) {
                        if (($schema['additionalProperties'] ?? null) === false || $properties !== []) {
                            $problems[] = "undocumented field {$label}.{$name}";
                        }

                        continue;
                    }

                    $this->validate($item, (array) $properties[$name], $label . '.' . $name, $problems);
                }

                return;
            case 'array':
                if (!is_array($value)) {
                    $problems[] = "{$label} must be a JSON list";

                    return;
                }

                if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                    $problems[] = "{$label} exceeds maxItems {$schema['maxItems']}";
                }

                if (($schema['uniqueItems'] ?? false) === true) {
                    $seen = [];
                    foreach ($value as $item) {
                        $key = json_encode(self::canonicalValue($item), JSON_THROW_ON_ERROR);
                        if (isset($seen[$key])) {
                            $problems[] = "{$label} must contain unique items";
                            break;
                        }
                        $seen[$key] = true;
                    }
                }

                if (isset($schema['items'])) {
                    foreach ($value as $index => $item) {
                        $this->validate($item, (array) $schema['items'], "{$label}[{$index}]", $problems);
                    }
                }

                return;
            case 'integer':
                if (!is_int($value)) {
                    $problems[] = "{$label} must be an integer";

                    return;
                }
                break;
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    $problems[] = "{$label} must be a number";

                    return;
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $problems[] = "{$label} must be a string";

                    return;
                }

                if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                    $problems[] = "{$label} exceeds maxLength {$schema['maxLength']}";
                }

                if (
                    ($schema['format'] ?? null) === 'date-time'
                    && preg_match(self::DATE_TIME_PATTERN, $value) !== 1
                ) {
                    $problems[] = "{$label} must be an RFC3339 date-time";
                }

                return;
            case 'boolean':
                if (!is_bool($value)) {
                    $problems[] = "{$label} must be a boolean";
                }

                return;
            default:
                return; // untyped: anything goes
        }

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $problems[] = "{$label} is below minimum {$schema['minimum']}";
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $problems[] = "{$label} is above maximum {$schema['maximum']}";
        }

        if (isset($schema['multipleOf']) && $schema['multipleOf'] > 0) {
            $quotient = $value / $schema['multipleOf'];
            $tolerance = 4 * PHP_FLOAT_EPSILON * max(1.0, abs($quotient));
            if (abs($quotient - round($quotient)) > $tolerance) {
                $problems[] = "{$label} must be a multiple of {$schema['multipleOf']}";
            }
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalValue($value)
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map([self::class, 'canonicalValue'], $properties);
        }
        if (is_array($value)) {
            return array_map([self::class, 'canonicalValue'], $value);
        }

        return is_float($value) && $value == 0.0 ? 0 : $value;
    }

    /**
     * Converts a PHP array tree into a JSON-encodable tree where associative
     * arrays (and empty arrays) become objects and lists stay lists.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function toJsonValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
            return array_map([self::class, 'toJsonValue'], $value);
        }

        $object = new \stdClass();

        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::toJsonValue($item);
        }

        return $object;
    }

    private static function normalizePath(string $path): string
    {
        $query = strpos($path, '?');

        return $query === false ? $path : substr($path, 0, $query);
    }
}
