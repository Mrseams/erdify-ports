<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Tests;

use Mrseams\Erdify\Cli\Application;
use Mrseams\Erdify\Laravel\LaravelSchemaBuilder;
use Mrseams\Erdify\Model\Schema;
use Mrseams\Erdify\Scan\FileFinder;

/** A throwaway Laravel app on disk, for tests. */
final class TempApp
{
    public readonly string $root;

    /** @param array<string, string> $files relative path => contents */
    public function __construct(array $files = [], bool $artisan = true)
    {
        $this->root = sys_get_temp_dir() . '/erdify-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        if ($artisan) {
            touch($this->root . '/artisan');
        }
        foreach ($files as $path => $contents) {
            $this->write($path, $contents);
        }
    }

    public function write(string $path, string $contents): void
    {
        $full = $this->root . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $contents);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root . '/' . $relative;
    }

    /** A migration file whose up() holds $body. */
    public static function migration(string $body): string
    {
        return <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
            $body
                }

                public function down(): void
                {
                    Schema::dropIfExists('ignored_because_down');
                }
            };
            PHP;
    }

    /** A model class in App\Models. */
    public static function model(string $name, string $body = '', string $extends = 'Model', string $uses = ''): string
    {
        return <<<PHP
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;
            $uses

            class $name extends $extends
            {
            $body
            }
            PHP;
    }

    public function schema(): Schema
    {
        $files = (new FileFinder(Application::LARAVEL_INCLUDE))->find($this->root);
        return (new LaravelSchemaBuilder())->build($files);
    }

    public function __destruct()
    {
        if (!is_dir($this->root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }
}
