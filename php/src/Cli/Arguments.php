<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Cli;

/**
 * Command-line options, parsed like upstream's argparse: multi-value options
 * (`--format plantuml mermaid`) take every following token up to the next
 * option. Unset options stay null so the config file can fill them in.
 */
final class Arguments
{
    public ?string $input = null;
    public ?string $output = null;
    public ?string $title = null;
    /** @var list<string>|null */
    public ?array $format = null;
    /** @var list<string>|null */
    public ?array $exclude = null;
    /** @var list<string>|null */
    public ?array $excludePaths = null;
    /** @var list<string>|null */
    public ?array $include = null;
    public ?string $inject = null;
    public bool $noEnums = false;
    public bool $noRelationships = false;
    public bool $noDefaultExcludes = false;
    public bool $allowEmpty = false;
    public bool $check = false;
    public bool $help = false;
    public bool $version = false;

    private const FLAGS = [
        '--no-enums' => 'noEnums',
        '--no-relationships' => 'noRelationships',
        '--no-default-excludes' => 'noDefaultExcludes',
        '--allow-empty' => 'allowEmpty',
        '--check' => 'check',
        '-h' => 'help',
        '--help' => 'help',
        '-v' => 'version',
        '--version' => 'version',
    ];

    private const SINGLE = ['-o' => 'output', '--output' => 'output', '--title' => 'title', '--inject' => 'inject'];

    /** name => [property, at least one value required] */
    private const MULTI = [
        '--format' => ['format', true],
        '--include' => ['include', true],
        '--exclude' => ['exclude', false],
        '--exclude-paths' => ['excludePaths', false],
    ];

    /**
     * @param list<string> $argv without the program name
     * @throws UsageException
     */
    public static function parse(array $argv): self
    {
        $args = new self();
        $positional = [];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $token = $argv[$i];
            $inline = null;
            if (str_starts_with($token, '--') && str_contains($token, '=')) {
                [$token, $inline] = explode('=', $token, 2);
            }

            if (isset(self::FLAGS[$token])) {
                $args->{self::FLAGS[$token]} = true;
            } elseif (isset(self::SINGLE[$token])) {
                $value = $inline ?? ($argv[++$i] ?? null);
                if ($value === null) {
                    throw new UsageException("argument $token: expected one argument");
                }
                $args->{self::SINGLE[$token]} = $value;
            } elseif (isset(self::MULTI[$token])) {
                [$property, $required] = self::MULTI[$token];
                $values = $inline !== null ? [$inline] : [];
                while ($inline === null && $i + 1 < $count && !self::isOption($argv[$i + 1])) {
                    $values[] = $argv[++$i];
                }
                if ($required && $values === []) {
                    throw new UsageException("argument $token: expected at least one argument");
                }
                $args->{$property} = $values;
            } elseif (self::isOption($token)) {
                throw new UsageException("unrecognized arguments: $token");
            } else {
                $positional[] = $token;
            }
        }

        if (count($positional) > 1) {
            throw new UsageException('unrecognized arguments: ' . implode(' ', array_slice($positional, 1)));
        }
        $args->input = $positional[0] ?? null;
        return $args;
    }

    private static function isOption(string $token): bool
    {
        return strlen($token) > 1 && $token[0] === '-';
    }
}
