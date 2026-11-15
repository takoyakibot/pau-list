@extends('layouts.app')

@section('title', $heading)

@section('content')
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">{{ $heading }}</h1>
        @if ($hadExpired)
            <p class="mt-3 text-ink-2">前回の一覧は 30 日の保存期限で削除されました</p>
        @endif
        <p class="mt-5"><a class="underline" href="{{ route('home') }}">トップに戻る</a></p>
    </section>
@endsection
