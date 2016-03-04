<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('command.goodlinks.import', function() {
            return new \App\Console\Commands\ImportCommand();
        });
        $this->commands('command.goodlinks.import');

        //
    }
}
