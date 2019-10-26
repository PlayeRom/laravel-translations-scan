<?php

namespace PlayeRom\TranslationsScan;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider class
 */
class TranslScanServiceProvider extends ServiceProvider
{
	/**
	 * Bootstrap any application services.
	 *
	 * @return void
	 */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
		    $this->commands([
		        TranslScanCommand::class,
		    ]);
		}
    }
}
