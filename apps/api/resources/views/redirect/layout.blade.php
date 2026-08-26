<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    @yield('head')
    {{-- Inlined so the page is a single response: these are the most
         latency-sensitive pages a visitor ever sees, and branding replaces this
         in a later phase. --}}
    <style>
        :root {
            color-scheme: light dark;
            --canvas: #fbfbfa;
            --surface: #ffffff;
            --border: #eaeaea;
            --ink: #111111;
            --muted: #787774;
            --accent: #2f5d8f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #141414;
                --surface: #1b1b1b;
                --border: #2a2a2a;
                --ink: #f2f2f0;
                --muted: #9b9b97;
                --accent: #8ab4e8;
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
            max-width: 26rem;
            padding: 32px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        h1 { margin: 0 0 8px; font-size: 1.25rem; font-weight: 600; letter-spacing: -0.01em; }
        p { margin: 0 0 20px; color: var(--muted); }
        label { display: block; margin-bottom: 8px; font-size: 0.875rem; font-weight: 500; }
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            font: inherit;
            color: var(--ink);
            background: var(--canvas);
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        input[type="password"]:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
        button {
            margin-top: 16px;
            width: 100%;
            padding: 10px 16px;
            font: inherit;
            font-weight: 500;
            color: var(--surface);
            background: var(--ink);
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 160ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        button:active { transform: scale(0.98); }
        @media (prefers-reduced-motion: reduce) { button { transition: none; } }
        .notice { margin: 0 0 16px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem; }
        .muted-link { color: var(--accent); }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
