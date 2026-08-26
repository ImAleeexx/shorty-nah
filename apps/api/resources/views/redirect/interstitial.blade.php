<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Redirecting — {{ $branding['name'] }}</title>

    {{-- The scripting-free path. A visitor without JavaScript still reaches the
         destination; only the client-side signals are lost. --}}
    <noscript>
        <meta http-equiv="refresh" content="0;url={{ $destination }}">
    </noscript>

    {{-- Inlined so the page is a single response with no additional requests.
         The nonce is what lets the policy forbid inline script and style
         wholesale rather than allowing them everywhere. --}}
    <style nonce="{{ $nonce }}">
        :root {
            color-scheme: light dark;
            --accent: {{ $branding['accent'] }};
            --radius: {{ $branding['radius'] }}px;
            --canvas: #fbfbfa;
            --surface: #ffffff;
            --border: #eaeaea;
            --ink: #111111;
            --muted: #787774;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #141414;
                --surface: #1b1b1b;
                --border: #2a2a2a;
                --ink: #f2f2f0;
                --muted: #9b9b97;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--canvas);
            color: var(--ink);
            font: 400 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        main {
            width: 100%;
            max-width: 24rem;
            padding: 32px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            text-align: center;
        }
        .mark { max-height: 32px; margin-bottom: 20px; }
        .name { margin: 0 0 20px; font-size: 0.8125rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
        h1 { margin: 0 0 8px; font-size: 1.125rem; font-weight: 600; letter-spacing: -0.01em; }
        p { margin: 0; color: var(--muted); font-size: 0.9375rem; }
        .target { margin-top: 16px; font-size: 0.8125rem; word-break: break-all; }
        a { color: var(--accent); }
        .track { margin-top: 24px; height: 2px; background: var(--border); border-radius: 2px; overflow: hidden; }
        .fill { height: 100%; width: 0; background: var(--accent); transition: width {{ $branding['delay_ms'] }}ms linear; }
        @media (prefers-reduced-motion: reduce) {
            .track { display: none; }
        }
    </style>
</head>
<body>
<main>
    @if ($branding['logo'] !== null)
        <img class="mark" src="{{ $branding['logo'] }}" alt="{{ $branding['name'] }}">
    @else
        <p class="name">{{ $branding['name'] }}</p>
    @endif

    <h1>Taking you there</h1>
    <p>You’re being redirected.</p>

    <p class="target"><a href="{{ $destination }}" rel="noopener" id="destination">{{ $destination }}</a></p>

    <div class="track" aria-hidden="true"><div class="fill" id="progress"></div></div>
</main>

<script nonce="{{ $nonce }}">
    (function () {
        'use strict';

        var destination = {!! json_encode($destination) !!};
        var beacon = {!! json_encode($beaconUrl) !!};
        var token = {!! json_encode($token) !!};
        var delay = {{ $branding['delay_ms'] }};
        var startedAt = Date.now();

        var fill = document.getElementById('progress');
        if (fill) {
            requestAnimationFrame(function () { fill.style.width = '100%'; });
        }

        function signals() {
            var connection = navigator.connection || {};

            return {
                token: token,
                viewport_width: window.innerWidth || null,
                viewport_height: window.innerHeight || null,
                screen_width: (window.screen && window.screen.width) || null,
                screen_height: (window.screen && window.screen.height) || null,
                device_pixel_ratio: window.devicePixelRatio || null,
                timezone: (function () {
                    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; }
                    catch (e) { return null; }
                })(),
                language: navigator.language || null,
                color_scheme: window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark' : 'light',
                connection_type: connection.effectiveType || null,
                dwell_ms: Date.now() - startedAt
            };
        }

        function report() {
            var body = JSON.stringify(signals());

            // sendBeacon survives the navigation that follows; fetch would be
            // cancelled by it.
            if (navigator.sendBeacon) {
                navigator.sendBeacon(beacon, new Blob([body], { type: 'application/json' }));
                return;
            }

            try {
                var request = new XMLHttpRequest();
                request.open('POST', beacon, true);
                request.setRequestHeader('Content-Type', 'application/json');
                request.send(body);
            } catch (e) {
                // A failed measurement must never stop the visitor arriving.
            }
        }

        window.setTimeout(function () {
            report();
            window.location.replace(destination);
        }, delay);
    })();
</script>
</body>
</html>
