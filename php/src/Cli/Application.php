<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Cli;

use Mrseams\Erdify\Config\ComposerConfig;
use Mrseams\Erdify\Config\ConfigException;
use Mrseams\Erdify\Inject\Injector;
use Mrseams\Erdify\Inject\MarkerException;
use Mrseams\Erdify\Laravel\LaravelSchemaBuilder;
use Mrseams\Erdify\Render\Formats;
use Mrseams\Erdify\Render\RenderOptions;
use Mrseams\Erdify\Scan\FileFinder;

/**
 * The erdify command, with the behaviour defined in spec/cli.md: which setting
 * wins, output naming, --check, --inject, exit codes and messages.
 */
final class Application
{
    public const VERSION = '0.1.0';

    /** Default includes when the input is a Laravel app (it has an `artisan` file). */
    public const LARAVEL_INCLUDE = ['app/**/*.php', 'database/migrations/*.php'];

    /** Default includes for any other directory. */
    public const GENERIC_INCLUDE = ['*.php'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param string $command how users run this tool, used in "Re-run ..." hints
     */
    public function __construct($stdout = null, $stderr = null, private readonly string $command = 'erdify')
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /** @param list<string> $argv without the program name */
    public function run(array $argv): int
    {
        try {
            $args = Arguments::parse($argv);
        } catch (UsageException $e) {
            $this->err("usage: {$this->command} [options] [input]");
            $this->err("{$this->command}: error: {$e->getMessage()}");
            return 2;
        }
        if ($args->help) {
            fwrite($this->stdout, $this->help());
            return 0;
        }
        if ($args->version) {
            fwrite($this->stdout, "erdify-php " . self::VERSION . "\n");
            return 0;
        }

        $input = $args->input ?? '.';
        if (!file_exists($input)) {
            $this->err("Error: Input path does not exist: $input");
            return 1;
        }

        // Settings: explicit CLI flag > composer.json extra.erdify > default.
        try {
            $config = ComposerConfig::find($input);
        } catch (ConfigException $e) {
            $this->err($e->getMessage());
            return 1;
        }
        foreach ($config->warnings as $warning) {
            $this->err($warning);
        }

        $isLaravel = is_dir($input) && is_file($input . DIRECTORY_SEPARATOR . 'artisan');
        $title = $args->title ?? $config->get('title', 'Database ERD');
        $exclude = $args->exclude ?? $config->list('exclude') ?? [];
        $excludePaths = $args->excludePaths ?? $config->list('exclude_paths') ?? [];
        $include = $args->include ?? $config->list('include') ?? ($isLaravel ? self::LARAVEL_INCLUDE : self::GENERIC_INCLUDE);
        // Boolean flags merge by OR.
        $noEnums = $args->noEnums || $config->bool('no_enums');
        $noRelationships = $args->noRelationships || $config->bool('no_relationships');
        $noDefaultExcludes = $args->noDefaultExcludes || $config->bool('no_default_excludes');
        $allowEmpty = $args->allowEmpty || $config->bool('allow_empty');

        // CLI paths resolve from the current directory, config paths from the config's directory.
        $output = $args->output ?? $this->configPath($config, 'output');
        $inject = $args->inject ?? $this->configPath($config, 'inject');

        $defaultFormat = $inject !== null ? 'mermaid' : 'plantuml';
        $formats = [];
        foreach ($args->format ?? $config->list('format') ?? [$defaultFormat] as $format) {
            if (Formats::exists($format)) {
                $formats[] = $format;
            } else {
                $this->err("Warning: unknown format '$format', ignoring");
            }
        }
        $formats = $formats ?: [$defaultFormat];

        if ($args->check && $output === null && $inject === null) {
            $this->err("Error: --check requires --output or --inject (or 'output'/'inject' in composer.json extra.erdify)");
            return 2;
        }
        if ($output === null && count($formats) > 1) {
            $this->err('Error: multiple --format values require --output');
            return 2;
        }
        if ($inject !== null && count($formats) > 1) {
            $this->err('Error: --inject needs a single --format (got multiple)');
            return 2;
        }
        if ($inject !== null && $formats[0] === 'html') {
            $this->err('Error: --inject cannot embed the html format');
            return 2;
        }

        $finder = new FileFinder($include, $excludePaths, !$noDefaultExcludes);
        try {
            $files = $finder->find($input);
            $builder = new LaravelSchemaBuilder();
            $schema = $builder->build($files, $exclude, $title);
        } catch (\Throwable $e) {
            $this->err("Error parsing models: {$e->getMessage()}");
            return 1;
        }
        $base = is_dir($input) ? rtrim(str_replace('\\', '/', (string) realpath($input)), '/') . '/' : '';
        foreach ($builder->warnings() as $warning) {
            $this->err('Warning: ' . ($base !== '' ? str_replace($base, '', $warning) : $warning));
        }

        if ($schema->entities === []) {
            $level = $allowEmpty ? 'Warning' : 'Error';
            $patterns = implode(' ', array_map(static fn ($p) => "'$p'", $include));
            $this->err("$level: No tables found in $input");
            $this->err("  Scanned {$finder->candidateFiles} .php/.sql file(s); " . count($files) . " matched --include $patterns.");
            $this->err($files === []
                ? '  Point erdify at your Laravel app root (the folder with `artisan`), or name your files with --include.'
                : '  Files matched but held no migrations or Eloquent models. Check --include and --exclude.');
            if (!$allowEmpty) {
                return 1;
            }
        }

        $options = new RenderOptions(includeEnums: !$noEnums, includeRelationships: !$noRelationships);
        $stale = false;

        foreach ($formats as $format) {
            $rendered = Formats::renderer($format)->render($schema, $options);
            $target = $output !== null ? self::withSuffix($output, Formats::EXTENSIONS[$format]) : null;

            if ($args->check && $target !== null) {
                $existing = is_file($target) ? self::readText($target) : null;
                if ($existing === $rendered) {
                    $this->err("$format ERD is up to date: $target");
                } else {
                    $reason = $existing !== null ? 'differs from' : 'is missing at';
                    $this->err("Error: generated $format ERD $reason $target. Re-run {$this->command} to update it.");
                    $stale = true;
                }
                continue;
            }

            if ($target !== null) {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                file_put_contents($target, $rendered);
                $this->err("Generated $format ERD: $target");
            } elseif ($inject === null) {
                fwrite($this->stdout, $rendered . "\n");
            }
        }

        if ($inject !== null) {
            $format = $formats[0];
            $region = Injector::region(Formats::renderer($format)->render($schema, $options), $format);
            if (!is_file($inject)) {
                $this->err("Error: --inject target not found: $inject");
                return 1;
            }
            $text = (string) file_get_contents($inject);
            try {
                if ($args->check) {
                    if (Injector::currentRegion($text) === $region) {
                        $this->err("Injected ERD is up to date: $inject");
                    } else {
                        $this->err("Error: injected ERD in $inject is stale. Re-run {$this->command} to update it.");
                        $stale = true;
                    }
                } else {
                    file_put_contents($inject, Injector::inject($text, $region));
                    $this->err("Injected $format ERD into $inject");
                }
            } catch (MarkerException $e) {
                $this->err("Error: {$e->getMessage()} in $inject");
                return 1;
            }
        }

        if ($args->check) {
            return $stale ? 1 : 0;
        }
        if ($output !== null) {
            $this->err('  Found ' . count($schema->entities) . ' entities and ' . count($schema->enums) . ' enums');
        }
        return 0;
    }

    /**
     * Python's Path.with_suffix: replace the last extension of the file name,
     * or add one. "docs/erd.txt" -> "docs/erd.puml", "docs/erd" -> "docs/erd.puml".
     */
    public static function withSuffix(string $path, string $suffix): string
    {
        $forward = strrpos($path, '/');
        $back = strrpos($path, '\\');
        $slash = max($forward === false ? -1 : $forward, $back === false ? -1 : $back);
        $name = substr($path, $slash + 1);
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0 && $dot < strlen($name) - 1) {
            $name = substr($name, 0, $dot);
        }
        return substr($path, 0, $slash + 1) . $name . $suffix;
    }

    /** Reads like Python's read_text(): "\r\n" and "\r" become "\n". */
    private static function readText(string $file): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));
    }

    private function configPath(ComposerConfig $config, string $key): ?string
    {
        $path = $config->get($key);
        if (!is_string($path)) {
            return null;
        }
        $absolute = preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1;
        return $absolute || $config->baseDir === null ? $path : $config->baseDir . DIRECTORY_SEPARATOR . $path;
    }

    private function err(string $line): void
    {
        fwrite($this->stderr, $line . "\n");
    }

    private function help(): string
    {
        $c = $this->command;
        return <<<HELP
            usage: $c [options] [input]

            Generate PlantUML and Mermaid ERDs from Laravel migrations and Eloquent models.

            positional arguments:
              input                   Laravel app root, directory or file (default: .)

            options:
              -h, --help              show this help message and exit
              -o, --output OUTPUT     output file; the extension is set per format (default: stdout)
              --title TITLE           diagram title (default: 'Database ERD')
              --format FMT [FMT ...]  plantuml (.puml), mermaid (.mmd), json (.json), html (.html).
                                      Default: plantuml (mermaid with --inject). Several need -o
              --exclude [PATTERN ...] globs on entity or table name to leave out, e.g. 'telescope_*'
              --exclude-paths [PATTERN ...]
                                      globs on file paths to skip before parsing
              --include PATTERN [PATTERN ...]
                                      files to scan. Default in a Laravel app:
                                      'app/**/*.php' 'database/migrations/*.php'; elsewhere '*.php'
              --no-default-excludes   also scan vendor/, node_modules/, storage/, ...
              --no-enums              leave enums out of the diagram
              --no-relationships      leave relationships out of the JSON output
              --inject FILE           write the diagram into FILE between
                                      '<!-- erdify:start -->' and '<!-- erdify:end -->'
              --allow-empty           'no tables found' is a warning, not an error
              --check                 write nothing; exit 1 if an output is missing or stale
              -v, --version           show the version and exit

            Settings can also go in composer.json under "extra": {"erdify": {...}}.
            Example: $c . -o docs/erd --format plantuml mermaid

            HELP;
    }
}
