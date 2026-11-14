@extends('layouts.app')

@section('title', $proposal->title.' — 再生リストの作成')

@section('content')
    <h1 class="text-2xl font-bold break-words">{{ $proposal->title }}</h1>
    <p class="mt-2 text-sm">ログイン中: {{ $ownerChannelTitle }}</p>
@endsection
