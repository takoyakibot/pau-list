@extends('layouts.app')

@section('title', 'もう一度ログインしてください')

@section('content')
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">もう一度ログインしてください</h1>
        <p class="mt-3 text-ink-2" role="alert">ログインの手続きを確認できませんでした。もう一度ログインしてください</p>
        <details class="hint mt-2">
            <summary>詳しく</summary>
            <p class="mt-1">時間が経った、別のタブでログインを始めた、または同じ画面を再読み込みした可能性があります</p>
        </details>
        @if ($retryKey !== null)
            <p class="mt-5"><a class="btn btn-primary" href="{{ route('proposals.show', $retryKey) }}">提案のプレビューへ戻ってログインし直す</a></p>
        @else
            <p class="mt-5"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
        @endif
    </section>
@endsection
