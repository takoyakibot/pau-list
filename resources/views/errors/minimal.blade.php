@extends('layouts.app')

@section('title')@yield('error_title', 'エラー'){{ ' - List Maker' }}@endsection

@section('content')
    <div class="flex items-center">
        @if (trim($__env->yieldContent('code')) !== '')
            <h1 class="border-r border-gray-400 pr-4 text-lg">@yield('code')</h1>
            <p class="pl-4 text-lg">@yield('message')</p>
        @else
            <h1 class="text-lg">@yield('message')</h1>
        @endif
    </div>
    <p class="mt-8"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
@endsection
