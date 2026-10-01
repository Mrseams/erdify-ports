<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Config;

/**
 * Reads settings from `"extra": {"erdify": {...}}` in the nearest composer.json,
 * searching upward from the input. The keys are the same as upstream's
 * `[tool.erdify]` table in pyproject.toml.
 */
final class ComposerConfig
{
    public const KEYS = [
        'title', 'output', 'format', 'exclude', 'exclude_paths', 'include', 'inject',
        'no_enums', 'no_relationships', 'no_default_excludes', 'allow_empty',
    ];

    /**
     * @param array<string, mixed> $values
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly array $values,
        /** Directory holding composer.json; relative paths resolve from here. */
        public readonly ?string $baseDir,
        public readonly array $warnings,
    ) {
    }

    /** @throws ConfigException */
    public static function find(string $input): self
    {
        $dir = is_dir($input) ? $input : dirname($input);
        $dir = realpath($dir) ?: $dir;
        while (true) {
            $file = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($file)) {
                return self::load($file);
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return new self([], null, []);
            }
            $dir = $parent;
        }
    }

    /** @throws ConfigException */
    public static function load(string $file): self
    {
        try {
            $composer = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException("Error: could not read $file: {$e->getMessage()}");
        }
        $table = is_array($composer) ? ($composer['extra']['erdify'] ?? []) : [];
        if (!is_array($table)) {
            throw new ConfigException("Error: \"extra.erdify\" must be an object in $file");
        }

        $values = [];
        $warnings = [];
        foreach ($table as $key => $value) {
            if (in_array($key, self::KEYS, true)) {
                $values[$key] = $value;
            } else {
                $warnings[] = "Warning: unknown erdify config key '$key' in $file";
            }
        }
        foreach (['title', 'output', 'inject'] as $key) {
            if (isset($values[$key]) && !is_string($values[$key])) {
                throw new ConfigException("Error: extra.erdify '$key' must be a string in $file");
            }
        }
        return new self($values, dirname($file), $warnings);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    /** @return list<string>|null */
    public function list(string $key): ?array
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->values[$key];
        return array_values(array_map('strval', is_array($value) ? $value : [$value]));
    }
}
