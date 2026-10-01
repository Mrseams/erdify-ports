<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests\Cli;

use Mrseams\Erdify\Cli\Application;
use Mrseams\Erdify\Tests\TempApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The behaviour pinned down by spec/cli.md. */
final class ApplicationTest extends TestCase
{
    private TempApp $app;
    private string $stdout = '';
    private string $stderr = '';

    protected function setUp(): void
    {
        $this->app = new TempApp([
            'database/migrations/2024_01_01_000000_create_users.php' => TempApp::migration(
                "Schema::create('users', function (Blueprint \$t) { \$t->id(); \$t->string('email'); });"
            ),
            'README.md' => "# App\n\n<!-- erdify:start -->\n<!-- erdify:end -->\n",
        ]);
    }

    /** @param list<string> $argv */
    private function cli(array $argv): int
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new Application($out, $err))->run($argv);
        rewind($out);
        rewind($err);
        $this->stdout = (string) stream_get_contents($out);
        $this->stderr = (string) stream_get_contents($err);
        return $code;
    }

    public function testStdoutGetsOneTrailingNewlineButFilesNone(): void
    {
        self::assertSame(0, $this->cli([$this->app->path(), '--format', 'mermaid']));
        self::assertStringStartsWith("erDiagram\n", $this->stdout);
        self::assertStringEndsWith("}\n", $this->stdout);
        $printed = $this->stdout;

        self::assertSame(0, $this->cli([$this->app->path(), '-o', $this->app->path('docs/erd.txt'), '--format', 'mermaid']));
        self::assertSame(substr($printed, 0, -1), file_get_contents($this->app->path('docs/erd.mmd')));
        self::assertSame('', $this->stdout);
        self::assertStringContainsString('Found 1 entities and 0 enums', $this->stderr);
    }

    public function testCheck(): void
    {
        $output = $this->app->path('erd');

        self::assertSame(1, $this->cli([$this->app->path(), '-o', $output, '--check']));
        self::assertStringContainsString("Error: generated plantuml ERD is missing at $output.puml. Re-run erdify to update it.", $this->stderr);

        $this->cli([$this->app->path(), '-o', $output]);
        self::assertSame(0, $this->cli([$this->app->path(), '-o', $output, '--check']));
        self::assertStringContainsString("plantuml ERD is up to date: $output.puml", $this->stderr);

        // CRLF checkouts still count as up to date, as upstream reads with universal newlines.
        file_put_contents("$output.puml", str_replace("\n", "\r\n", (string) file_get_contents("$output.puml")));
        self::assertSame(0, $this->cli([$this->app->path(), '-o', $output, '--check']));

        file_put_contents("$output.puml", 'stale');
        self::assertSame(1, $this->cli([$this->app->path(), '-o', $output, '--check']));
        self::assertStringContainsString('differs from', $this->stderr);
    }

    public function testInject(): void
    {
        $readme = $this->app->path('README.md');

        self::assertSame(0, $this->cli([$this->app->path(), '--inject', $readme]));
        self::assertStringContainsString("<!-- erdify:start -->\n```mermaid\nerDiagram\n", (string) file_get_contents($readme));
        self::assertSame(0, $this->cli([$this->app->path(), '--inject', $readme, '--check']));
        self::assertStringContainsString("Injected ERD is up to date: $readme", $this->stderr);

        file_put_contents($readme, 'no markers');
        self::assertSame(1, $this->cli([$this->app->path(), '--inject', $readme]));
        self::assertStringContainsString("Error: no '<!-- erdify:start -->' marker found in $readme", $this->stderr);
    }

    /** @return iterable<string, array{list<string>, int, string}> */
    public static function usageErrors(): iterable
    {
        yield 'check without output' => [['--check'], 2, "Error: --check requires --output or --inject (or 'output'/'inject' in composer.json extra.erdify)"];
        yield 'formats without output' => [['--format', 'plantuml', 'mermaid'], 2, 'Error: multiple --format values require --output'];
        yield 'inject several formats' => [['--inject', 'README.md', '--format', 'plantuml', 'mermaid', '-o', 'x'], 2, 'Error: --inject needs a single --format (got multiple)'];
        yield 'inject html' => [['--inject', 'README.md', '--format', 'html'], 2, 'Error: --inject cannot embed the html format'];
        yield 'unknown option' => [['--sources', 'django'], 2, 'error: unrecognized arguments: --sources'];
        yield 'missing value' => [['--format'], 2, 'error: argument --format: expected at least one argument'];
    }

    /** @param list<string> $argv */
    #[DataProvider('usageErrors')]
    public function testUsageErrors(array $argv, int $code, string $message): void
    {
        self::assertSame($code, $this->cli([$this->app->path(), ...$argv]));
        self::assertStringContainsString($message, $this->stderr);
    }

    public function testUnknownFormatIsIgnoredWithAWarning(): void
    {
        self::assertSame(0, $this->cli([$this->app->path(), '--format', 'svg']));
        self::assertStringContainsString("Warning: unknown format 'svg', ignoring", $this->stderr);
        self::assertStringStartsWith('@startuml', $this->stdout);
    }

    public function testNoTables(): void
    {
        $empty = new TempApp(['app/Services/Foo.php' => "<?php class Foo {}\n"]);

        self::assertSame(1, $this->cli([$empty->path()]));
        self::assertStringContainsString('Error: No tables found in', $this->stderr);

        self::assertSame(0, $this->cli([$empty->path(), '--allow-empty']));
        self::assertStringContainsString('Warning: No tables found in', $this->stderr);
    }

    public function testComposerConfigAndPrecedence(): void
    {
        $this->app->write('composer.json', (string) json_encode(['extra' => ['erdify' => [
            'title' => 'From config',
            'output' => 'docs/erd',
            'format' => ['plantuml', 'mermaid'],
            'exclude' => ['password_*'],
            'colour' => 'blue',
        ]]]));

        self::assertSame(0, $this->cli([$this->app->path()]));
        self::assertStringContainsString("Warning: unknown erdify config key 'colour'", $this->stderr);
        self::assertFileExists($this->app->path('docs/erd.mmd'), 'config paths resolve from composer.json');
        self::assertStringStartsWith('@startuml From config', (string) file_get_contents($this->app->path('docs/erd.puml')));

        // An explicit flag wins over the config.
        self::assertSame(0, $this->cli([$this->app->path(), '--title', 'From CLI', '--format', 'plantuml']));
        self::assertStringStartsWith('@startuml From CLI', (string) file_get_contents($this->app->path('docs/erd.puml')));
    }

    public function testInvalidConfigValue(): void
    {
        $this->app->write('composer.json', '{"extra": {"erdify": {"title": 3}}}');

        self::assertSame(1, $this->cli([$this->app->path()]));
        self::assertStringContainsString("Error: extra.erdify 'title' must be a string", $this->stderr);
    }

    /** @return iterable<array{string, string}> */
    public static function suffixes(): iterable
    {
        yield ['docs/erd', 'docs/erd.puml'];
        yield ['docs/erd.puml', 'docs/erd.puml'];
        yield ['docs/erd.txt', 'docs/erd.puml'];
        yield ['docs/erd.v2.txt', 'docs/erd.v2.puml'];
        yield ['docs/.hidden', 'docs/.hidden.puml'];
        yield ['C:\\out\\erd.txt', 'C:\\out\\erd.puml'];
    }

    #[DataProvider('suffixes')]
    public function testWithSuffixMatchesPythonPathWithSuffix(string $path, string $expected): void
    {
        self::assertSame($expected, Application::withSuffix($path, '.puml'));
    }
}
