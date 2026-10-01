<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Illuminate\Console\Command;
use Mrseams\Erdify\Cli\Application;

/**
 * `php artisan erdify`: the same tool as vendor/bin/erdify, run from the app
 * root. Repeat a multi-value option to pass several values:
 * `php artisan erdify -o docs/erd --format=plantuml --format=mermaid`.
 */
final class ErdifyCommand extends Command
{
    protected $signature = 'erdify
        {input? : Directory or file to read (default: the app root)}
        {--o|output= : Output file; the extension is set per format}
        {--title= : Diagram title}
        {--format=* : plantuml, mermaid, json or html}
        {--include=* : Files to scan}
        {--exclude=* : Entity or table names to leave out (globs)}
        {--exclude-paths=* : File paths to skip (globs)}
        {--inject= : Write the diagram into a Markdown file between erdify markers}
        {--no-enums : Leave enums out}
        {--no-relationships : Leave relationships out of the JSON output}
        {--no-default-excludes : Also scan vendor/, node_modules/, storage/}
        {--allow-empty : "No tables found" is a warning, not an error}
        {--check : Write nothing; fail if an output is missing or stale}';

    protected $description = 'Generate a PlantUML or Mermaid ERD from your migrations and Eloquent models';

    public function handle(): int
    {
        $argv = [(string) ($this->argument('input') ?? $this->laravel->basePath())];

        foreach (['output', 'title', 'inject'] as $name) {
            $value = $this->option($name);
            if (is_string($value) && $value !== '') {
                $argv[] = "--$name";
                $argv[] = $value;
            }
        }
        foreach (['format', 'include', 'exclude', 'exclude-paths'] as $name) {
            $values = (array) $this->option($name);
            if ($values !== []) {
                array_push($argv, "--$name", ...$values);
            }
        }
        foreach (['no-enums', 'no-relationships', 'no-default-excludes', 'allow-empty', 'check'] as $name) {
            if ($this->option($name)) {
                $argv[] = "--$name";
            }
        }

        return (new Application(command: 'php artisan erdify'))->run($argv);
    }
}
