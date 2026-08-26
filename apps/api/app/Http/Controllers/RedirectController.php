<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RedirectMode;
use App\Links\ClickCounter;
use App\Links\RedirectResolver;
use App\Links\ResolvedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The redirect path.
 *
 * Nothing here may disclose why a link is unavailable. Expired, disabled,
 * limit-reached and never-existed all produce the same response to a visitor, so
 * a stranger cannot use the redirect path to learn which slugs are real.
 */
final class RedirectController
{
    private const PASSWORD_MAX_ATTEMPTS = 8;

    private const PASSWORD_DECAY_SECONDS = 300;

    private const DEFAULT_REFERRER_POLICY = 'strict-origin-when-cross-origin';

    public function __invoke(Request $request, string $slug, RedirectResolver $resolver, ClickCounter $clicks): SymfonyResponse
    {
        $link = $resolver->resolve($request->getHost(), $slug);

        if ($link === null) {
            return $this->unavailable();
        }

        if ($link->disabled || $link->isExpired()) {
            return $this->unavailable();
        }

        if ($link->hasReachedLimit($clicks->current($link->id))) {
            return $this->unavailable();
        }

        if ($link->requiresPassword) {
            return $this->passwordPrompt($slug);
        }

        return $this->send($request, $link, $clicks);
    }

    /**
     * A submitted password. Success performs the link's configured redirect
     * directly, so no grant has to be stored anywhere.
     */
    public function unlock(Request $request, string $slug, RedirectResolver $resolver, ClickCounter $clicks): SymfonyResponse
    {
        $link = $resolver->resolve($request->getHost(), $slug);

        if ($link === null || $link->disabled || $link->isExpired()) {
            return $this->unavailable();
        }

        if (! $link->requiresPassword) {
            return $this->send($request, $link, $clicks);
        }

        $limiterKey = 'link-password:'.$link->publicId.':'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, self::PASSWORD_MAX_ATTEMPTS)) {
            return $this->passwordPrompt($slug, tooManyAttempts: true, retryAfter: RateLimiter::availableIn($limiterKey));
        }

        $submitted = $request->input('password');
        $hash = $resolver->passwordHashFor($link->id);

        if (! is_string($submitted) || $hash === null || ! Hash::check($submitted, $hash)) {
            RateLimiter::hit($limiterKey, self::PASSWORD_DECAY_SECONDS);

            return $this->passwordPrompt($slug, incorrect: true);
        }

        RateLimiter::clear($limiterKey);

        if ($link->hasReachedLimit($clicks->current($link->id))) {
            return $this->unavailable();
        }

        return $this->send($request, $link, $clicks);
    }

    private function send(Request $request, ResolvedLink $link, ClickCounter $clicks): SymfonyResponse
    {
        // Counted before responding so a limit cannot be exceeded by a burst that
        // all reads the pre-increment value.
        $clicks->increment($link->id);

        return $link->mode === RedirectMode::Interstitial
            ? $this->interstitial($link)
            : $this->direct($link);
    }

    private function direct(ResolvedLink $link): RedirectResponse
    {
        $response = new RedirectResponse($link->destination, 302);

        // No body worth caching and nothing to track: the whole point of this
        // mode is that it is a plain redirect.
        return $this->withNoStore($response, $link);
    }

    private function interstitial(ResolvedLink $link): Response
    {
        // The branded hold page and its beacon arrive in the next phase. Until
        // then this mode still reaches the destination, using the same
        // scripting-free fallback the final page keeps for visitors without
        // JavaScript.
        $response = new Response(view('redirect.interstitial', [
            'destination' => $link->destination,
        ])->render());

        return $this->withNoStore($response, $link);
    }

    private function passwordPrompt(
        string $slug,
        bool $incorrect = false,
        bool $tooManyAttempts = false,
        int $retryAfter = 0,
    ): Response {
        // Deliberately carries no destination, no link identifier and no reason
        // beyond the prompt itself.
        $view = view('redirect.password', [
            'slug' => $slug,
            'incorrect' => $incorrect,
            'tooManyAttempts' => $tooManyAttempts,
            'retryAfter' => $retryAfter,
        ]);

        return $this->withNoStore(new Response($view->render(), $tooManyAttempts ? 429 : 401));
    }

    private function unavailable(): Response
    {
        // One response for every unavailable reason. A visitor learns only that
        // there is nothing here.
        return $this->withNoStore(new Response(view('redirect.unavailable')->render(), 404));
    }

    /**
     * @template TResponse of SymfonyResponse
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    private function withNoStore(SymfonyResponse $response, ?ResolvedLink $link = null): SymfonyResponse
    {
        $policy = $link === null
            ? self::DEFAULT_REFERRER_POLICY
            : ($link->referrerPolicy ?? self::DEFAULT_REFERRER_POLICY);

        // A cached redirect would keep sending visitors to an old destination
        // after an edit, and would hide clicks from analytics entirely.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', $policy);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
