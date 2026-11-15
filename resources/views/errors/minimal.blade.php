@extends('layouts.app')

@section('title')@yield('error_title', 'エラー'){{ ' - List Maker' }}@endsection

@section('content')
    <section class="card p-5 sm:p-6">
        @if (trim($__env->yieldContent('code')) !== '')
            <div class="flex flex-wrap items-start gap-4">
                <h1 class="text-[28px] leading-none font-bold text-ink-muted">@yield('code')</h1>
                <p class="min-w-0 flex-1 text-base text-ink-2">@yield('message')</p>
            </div>
        @else
            <h1 class="text-lg font-bold">@yield('message')</h1>
        @endif
        <p class="mt-6"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
    </section>
@endsection
