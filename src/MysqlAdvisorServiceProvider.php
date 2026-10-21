<?php

declare(strict_types=1);

namespace AmjadIqbal\MysqlAdvisor;

use AmjadIqbal\MysqlAdvisor\Console\ReportCommand;
use AmjadIqbal\MysqlAdvisor\Http\Authorize;
use AmjadIqbal\MysqlAdvisor\Http\DashboardController;
use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class MysqlAdvisorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mysql-advisor.php', 'mysql-advisor');
        $this->app->singleton(MysqlAdvisor::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mysql-advisor');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/mysql-advisor.php' => config_path('mysql-advisor.php')], 'mysql-advisor-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/mysql-advisor')], 'mysql-advisor-views');
            $this->commands([ReportCommand::class]);
        }

        // Local only until the app defines its own gate (app providers boot after this one, so theirs wins).
        if (! Gate::has('viewMysqlAdvisor')) {
            Gate::define('viewMysqlAdvisor', fn ($user = null): bool => $this->app->environment('local'));
        }

        if (config('mysql-advisor.dashboard.enabled')) {
            $this->registerRoutes();
        }

        AboutCommand::add('MySQL Advisor', fn (): array => [
            'Version' => self::version(),
            'Dashboard' => config('mysql-advisor.dashboard.enabled') ? '/'.trim((string) config('mysql-advisor.dashboard.path'), '/') : 'off',
            'Support' => 'https://amjad.com.pk/contact?utm_source=artisan&utm_medium=about&utm_campaign=laravel-mysql-advisor',
        ]);
    }

    private function registerRoutes(): void
    {
        $middleware = array_values(array_filter((array) config('mysql-advisor.dashboard.middleware', ['web']), 'is_string'));

        Route::group([
            'prefix' => trim((string) config('mysql-advisor.dashboard.path', 'mysql-advisor'), '/'),
            'domain' => config('mysql-advisor.dashboard.domain'),
            'middleware' => [...$middleware, Authorize::class],
        ], function (): void {
            Route::get('/', DashboardController::class)->name('mysql-advisor.dashboard');
        });
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('amjadiqbal/laravel-mysql-advisor') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
