@extends('layouts.app')

@section('title', 'List Maker（仮称）')

@section('content')
    <h1 class="text-2xl font-bold">List Maker（仮称）</h1>
    <p class="mt-4">YouTube の再生リストを、ファンが提案してチャンネル主が作成するためのツールです</p>

    <ol class="mt-8 list-decimal space-y-2 pl-6">
        <li>ファンが再生リストを提案する</li>
        <li>共有 URL をチャンネル主に送る</li>
        <li>チャンネル主が内容を確認して作成する</li>
    </ol>

    <p class="mt-8">Google ログインで許可いただく権限は、あなたのチャンネルへの再生リストの作成と動画の追加にのみ使います。</p>

    <p class="mt-8 rounded border border-gray-200 p-4 text-gray-600">チャンネルの入力欄は準備中です。</p>
@endsection
