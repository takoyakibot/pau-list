@extends('layouts.app')

@section('title', 'もう一度ログインしてください')

@section('content')
    <h1 class="text-xl font-bold">もう一度ログインしてください</h1>
    <p class="mt-4" role="alert">ログインの手続きを確認できませんでした（時間が経った、別のタブでログインを始めた、または同じ画面を再読み込みした可能性があります）。もう一度ログインしてください</p>
    @if ($retryKey !== null)
        <p class="mt-6"><a class="underline" href="{{ route('proposals.show', $retryKey) }}">提案のプレビューへ戻ってログインし直す</a></p>
    @else
        <p class="mt-6"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
    @endif
@endsection
