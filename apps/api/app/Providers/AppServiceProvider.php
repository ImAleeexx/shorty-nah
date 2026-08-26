<?php

namespace App\Providers;

use App\Listeners\VerifyDependencies;
use App\Support\TrustedProxies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(DiagnosingHealth::class, VerifyDependencies::class);

        // Every model must declare what request input may fill. Outside
        // production a discarded attribute raises, so the mistake surfaces
        // during development; in production it stays silent, because throwing on
        // attacker-supplied fields turns unknown input into an error response.
        // The security property is the same either way: the attribute is never
        // written.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Forwarding headers are believed only from the edge's network. Trusting
        // every peer would let any client set its own apparent address, which
        // defeats redirect rate limiting and forges every geographic figure.
        TrustProxies::at(TrustedProxies::configured());
    }
}
