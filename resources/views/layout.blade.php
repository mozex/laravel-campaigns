<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>
        @yield('title') | {{ $app }}
    </title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; padding: 1rem; background: #f4f4f5; color: #18181b; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 30rem; margin: 4rem auto; padding: 2rem; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; }
        .app { margin: 0 0 1.5rem; color: #71717a; font-size: .875rem; font-weight: 600; }
        h1 { margin: 0 0 .75rem; font-size: 1.375rem; line-height: 1.3; }
        p { margin: 0 0 1rem; }
        .address { color: #52525b; overflow-wrap: anywhere; }
        .note { margin: 1.5rem 0 0; padding: .75rem 1rem; background: #fef9c3; border-radius: 8px; font-size: .875rem; }
        form { margin: 1.5rem 0 0; }
        button { appearance: none; border: 0; border-radius: 8px; padding: .75rem 1.25rem; background: #18181b; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover, button:focus-visible { background: #3f3f46; }
    </style>
</head>
<body>
    <main>
        <p class="app">
            {{ $app }}
        </p>
        @yield('content')
    </main>
</body>
</html>
