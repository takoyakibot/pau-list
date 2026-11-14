@extends('layouts.app')

@section('title', $heading)

@section('content')
    <h1 class="text-2xl font-bold">{{ $heading }}</h1>
    @if ($hadExpired)
        <p class="mt-4">前回の一覧は 30 日の保存期限で削除されました</p>
    @endif
    <p class="mt-6"><a class="underline" href="{{ route('home') }}">トップに戻る</a></p>
@endsection
