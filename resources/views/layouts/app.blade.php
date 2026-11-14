<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'List Maker')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex flex-col bg-white text-gray-900 antialiased">
    <header class="border-b border-gray-200">
        <div class="mx-auto max-w-3xl px-4 py-4">
            <a href="{{ route('home') }}" class="text-lg font-semibold">List Maker</a>
        </div>
    </header>
    <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8">
        @yield('content')
    </main>
    @include('partials.footer')
</body>
</html>
