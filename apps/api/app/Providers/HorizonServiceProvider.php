<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    /**
     * Horizon exposes queue payloads — destinations, visitor hashes, job
     * arguments — so it is owner-only. Anything less than an explicit role check
     * would make an operational tool a disclosure surface.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            static fn (mixed $user = null): bool => $user instanceof User
                && $user->isOwner()
                && ! $user->isDisabled(),
        );
    }
}
