@extends('layouts.app')

@section('title', 'プライバシーポリシー - List Maker')

@section('content')
    <h1 class="text-2xl font-bold">プライバシーポリシー</h1>
    <p class="mt-4">本文は公開前に掲載します。</p>
    <ul class="mt-4 list-disc space-y-2 pl-6">
        <li><a class="underline" href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">Google プライバシーポリシー</a></li>
        <li><a class="underline" href="https://myaccount.google.com/permissions" rel="noopener noreferrer" target="_blank">サードパーティのアクセス（Google アカウント）</a></li>
    </ul>
@endsection
