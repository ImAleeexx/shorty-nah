<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Redirecting</title>

    {{-- The scripting-free path. A visitor without JavaScript still arrives;
         only the client-side signals are lost. --}}
    <noscript>
        <meta http-equiv="refresh" content="0;url={{ $destination }}">
    </noscript>

    {{-- Deliberately unbranded and unstyled beyond a blank canvas. The mode
         exists so a visitor perceives an ordinary redirect, and anything drawn
         here would be seen as a flash of somebody else's page. --}}
    <style nonce="{{ $nonce }}">
        html, body { margin: 0; background: #fff; }
        @media (prefers-color-scheme: dark) { html, body { background: #000; } }
    </style>
</head>
<body>
<script nonce="{{ $nonce }}">
    (function () {
        'use strict';

        var destination = {!! json_encode($destination) !!};
        var beacon = {!! json_encode($beaconUrl) !!};
        var token = {!! json_encode($token) !!};
        var startedAt = Date.now();

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

        // No hold. The signals are read and reported, then the visitor goes —
        // which is the entire difference from the branded interstitial.
        report();
        window.location.replace(destination);
    })();
</script>
</body>
</html>
