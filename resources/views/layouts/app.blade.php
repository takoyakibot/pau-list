<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'List Maker')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('vite')
</head>
<body class="flex min-h-screen flex-col bg-surface text-sm leading-normal text-ink antialiased">
    <header class="border-b border-line bg-raised">
        <div class="mx-auto flex max-w-[1200px] flex-wrap items-center gap-3 px-4 py-2.5 sm:px-8">
            <a href="{{ route('home') }}" class="flex min-h-11 items-center gap-3 text-ink no-underline hover:text-ink">
                <span class="flex size-8 flex-none items-center justify-center rounded-lg bg-accent text-on-accent">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h11M4 12h11M4 18h7"/><path d="M17 15v6l4-3z" fill="currentColor"/></svg>
                </span>
                <span class="text-lg font-bold tracking-wide">List Maker</span>
            </a>
            <span class="hidden text-xs text-ink-muted sm:inline">再生リストの提案ツール</span>
        </div>
    </header>
    <main class="mx-auto w-full flex-1 px-4 pt-6 pb-12 sm:px-8 @yield('main_width', 'max-w-3xl')">
        @yield('content')
    </main>
    @include('partials.footer')
</body>
</html>
