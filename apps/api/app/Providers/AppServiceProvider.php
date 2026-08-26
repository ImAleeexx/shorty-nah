<?php

namespace App\Providers;

use App\Analytics\AnalyticsReader;
use App\Clicks\ClickQueue;
use App\Clicks\ClickToken;
use App\Clicks\ClickWriter;
use App\Clicks\GeoLookup;
use App\Clicks\GeoResolver;
use App\Clicks\RedisClickQueue;
use App\Clicks\VisitorHash;
use App\Domains\DnsResolver;
use App\Domains\SystemDnsResolver;
use App\Links\DatabaseSlugAvailability;
use App\Links\SlugAvailability;
use App\Listeners\VerifyDependencies;
use App\Settings\SettingsStore;
use App\Support\ConfigValue;
use App\Support\TrustedProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A singleton is correct here: the store holds a connection, a cache and
        // an encrypter, never request state. It deliberately memoises nothing,
        // so a value written by another worker is visible immediately.
        // Resolution is swapped for a fake in tests; DNS in a suite is neither
        // deterministic nor fast.
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(SlugAvailability::class, DatabaseSlugAvailability::class);

        $this->app->bind(ClickQueue::class, RedisClickQueue::class);

        $this->app->singleton(GeoResolver::class, fn (Application $app): GeoResolver => new GeoLookup(
            databasePath: ConfigValue::string(config('shortynah.geoip_path'), 'GEOIP_PATH'),
        ));

        $this->app->singleton(VisitorHash::class, fn (Application $app): VisitorHash => new VisitorHash(
            cache: $app->make('cache.store'),
            applicationKey: ConfigValue::string(config('app.key'), 'APP_KEY'),
        ));

        $this->app->singleton(ClickWriter::class, fn (Application $app): ClickWriter => new ClickWriter(
            connection: $app->make(ClickHouseServiceProvider::WRITER),
            cache: $app->make('cache.store'),
            logger: $app->make('log'),
        ));

        // Reads through the read-only ClickHouse identity: a reporting query must
        // not be able to mutate the event store.
        $this->app->singleton(AnalyticsReader::class, fn (Application $app): AnalyticsReader => new AnalyticsReader(
            connection: $app->make(ClickHouseServiceProvider::READER),
            settings: $app->make(SettingsStore::class),
        ));

        // Signed with the application key: a beacon token must be unmintable by
        // anyone who does not already hold the instance's secret.
        $this->app->singleton(ClickToken::class, fn (Application $app): ClickToken => new ClickToken(
            cache: $app->make('cache.store'),
            key: ConfigValue::string(config('app.key'), 'APP_KEY'),
        ));

        $this->app->singleton(SettingsStore::class, fn (Application $app): SettingsStore => new SettingsStore(
            database: $app->make('db.connection'),
            cache: $app->make('cache.store'),
            encrypter: $app->make('encrypter'),
        ));
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

        // Generous, because the edge legitimately asks on every first request to
        // an unseen hostname, but bounded so the endpoint cannot be used to
        // enumerate which domains an instance serves.
        RateLimiter::for('tls-authorize', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->ip()));

        // Generous enough that a real audience clicking a shared link is never
        // refused, tight enough that walking the slug space is not free. Keyed on
        // the address the trusted-proxy contract produced, never on a header a
        // client controls.
        // One beacon per interstitial view, so this only needs to allow a
        // visitor's own pages. A token is single-use anyway; the limit is here so
        // the endpoint cannot be hammered.
        RateLimiter::for('beacon', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->ip()));

        RateLimiter::for('redirect', static fn (Request $request): Limit => Limit::perMinute(240)
            ->by((string) $request->ip())
            ->response(static fn (): Response => new Response('Too many requests', 429, [
                'Cache-Control' => 'no-store',
                'Retry-After' => '60',
            ])));
    }
}
