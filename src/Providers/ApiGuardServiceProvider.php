<?php namespace Utkarshx\ApiGuard\Providers;

use Illuminate\Support\ServiceProvider;

class ApiGuardServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->register('EllipseSynergie\ApiResponse\Laravel\ResponseServiceProvider');

        $this->commands([
            'Utkarshx\ApiGuard\Console\Commands\GenerateApiKeyCommand',
            'Utkarshx\ApiGuard\Console\Commands\DeleteApiKeyCommand',
        ]);

        // Publish your migrations
        $this->publishes([
            __DIR__ . '/../../database/migrations/' => base_path('/database/migrations')
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../../config/apiguard.php' => config_path('apiguard.php'),
        ], 'config');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

}
