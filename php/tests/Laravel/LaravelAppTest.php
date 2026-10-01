<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Laravel;

use Mrseams\Erdify\Cli\Application;
use Mrseams\Erdify\Laravel\LaravelSchemaBuilder;
use Mrseams\Erdify\Model\Schema;
use Mrseams\Erdify\Render\Formats;
use Mrseams\Erdify\Scan\FileFinder;
use Mrseams\Erdify\Tests\Conformance\RenderConformanceTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whole Laravel apps in tests/fixtures/laravel. Each has golden files
 * (expected.json/.puml/.mmd); oracle/crosscheck.py also checks those golden
 * files against upstream erdify's rendering of expected.json.
 */
final class LaravelAppTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/laravel';

    /** @var list<string> */
    private array $warnings = [];

    private function build(string $app, string $title = 'Database ERD'): Schema
    {
        $files = (new FileFinder(Application::LARAVEL_INCLUDE))->find(self::FIXTURES . "/$app");
        $builder = new LaravelSchemaBuilder();
        $schema = $builder->build($files, title: $title);
        $this->warnings = $builder->warnings();
        return $schema;
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function goldenFiles(): iterable
    {
        foreach (['shop' => 'Shop ERD', 'example' => 'Database ERD'] as $app => $title) {
            foreach (['json' => 'json', 'plantuml' => 'puml', 'mermaid' => 'mmd'] as $format => $ext) {
                yield "$app.$ext" => [$app, $title, $format];
            }
        }
    }

    #[DataProvider('goldenFiles')]
    public function testMatchesGoldenFile(string $app, string $title, string $format): void
    {
        $ext = ltrim(Formats::EXTENSIONS[$format], '.');
        $expected = file_get_contents(self::FIXTURES . "/$app/expected.$ext");

        self::assertSame($expected, Formats::renderer($format)->render($this->build($app, $title)));
    }

    public function testSkipsFilesThatDoNotParse(): void
    {
        $this->build('shop');

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('Broken.php', $this->warnings[0]);
    }

    /**
     * The Eloquent version of upstream's "one schema, five frameworks" example
     * draws the same diagram as the SQLModel one. Only the column types differ
     * (Laravel's bigint/string instead of Python's int/str).
     */
    public function testExampleMatchesUpstreamExampleApartFromTypes(): void
    {
        $upstream = (string) file_get_contents(RenderConformanceTest::SPEC . '/fixtures/example_sqlmodel/default.mmd');
        $laravel = Formats::renderer('mermaid')->render($this->build('example'));

        self::assertSame(self::withoutTypes($upstream), self::withoutTypes($laravel));
        self::assertNotSame($upstream, $laravel, 'types are expected to differ');
    }

    /** Replaces the type token of every Mermaid attribute line. */
    private static function withoutTypes(string $mermaid): string
    {
        return (string) preg_replace('/^( {8})\S+ /m', '$1<type> ', $mermaid);
    }
}
