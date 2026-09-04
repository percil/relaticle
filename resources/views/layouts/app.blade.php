{{--
    Minimal classic-Blade layout for packages that use @extends()/@yield()
    rather than component slots. Currently only relaticle/ink's blog pages
    (vendor/relaticle/ink/resources/views/pages/*.blade.php) extend this,
    via config('ink.layout') falling back to its own default of
    'layouts.app'. The blog is off by default (relaticle.features.blog).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('ink.feed.title') ?? config('app.name') }}</title>

    <script>
        document.documentElement.classList.toggle(
            'dark',
            localStorage.getItem('theme') === 'dark' || ((!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)
        );
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-gray-700 antialiased dark:bg-gray-950 dark:text-gray-300">
    <header class="border-b border-gray-200/70 dark:border-white/[0.06]">
        <div class="mx-auto flex h-14 w-full max-w-[90rem] items-center gap-3 px-4 sm:px-6">
            <a href="{{ url()->getAppUrl() }}" class="flex shrink-0 items-center gap-2.5">
                <x-brand.logo-lockup size="sm" />
            </a>
            <div class="ml-auto flex items-center gap-2">
                <x-theme-switcher />
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>
</body>
</html>
