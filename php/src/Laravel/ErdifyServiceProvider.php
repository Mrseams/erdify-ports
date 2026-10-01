<?php

declare(strict_types=1);

namespace Mrseams\Erdify\Laravel;

use Illuminate\Support\ServiceProvider;

/** Registers `php artisan erdify`. Auto-discovered through composer.json extra.laravel. */
final class ErdifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ErdifyCommand::class]);
        }
    }
}
