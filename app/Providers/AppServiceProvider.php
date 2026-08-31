<?php

namespace App\Providers;

use App\Support\Docker;
use App\Support\DockerCompose;
use App\Support\KamalProxy;
use App\Support\OverrideFile;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Docker::class);
        $this->app->singleton(KamalProxy::class);
        $this->app->singleton(OverrideFile::class);

        // Compose always operates on the directory the command was run from.
        $this->app->singleton(DockerCompose::class, fn (): DockerCompose => new DockerCompose(
            (string) getcwd(),
        ));
    }
}
