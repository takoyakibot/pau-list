@extends('layouts.app')

@push('vite')
    @vite('resources/js/proposal-shared.js')
@endpush

@section('title', '提案を保存しました')

@section('content')
    <div id="proposal-shared"
        data-url="{{ $url }}"
        data-title="{{ $proposal->title }}"
        data-channel-title="{{ $proposal->channel_title }}"
        data-channel-id="{{ $proposal->channel_id }}"
        data-expires-at="{{ $proposal->expires_at->utc()->format('Y-m-d\TH:i:s\Z') }}">
        <h1 class="text-2xl font-bold">提案を保存しました</h1>
        <p class="mt-2 text-sm">{{ $proposal->channel_title }} への提案「{{ $proposal->title }}」（{{ $proposal->item_count }} 本）</p>

        <section class="mt-6 space-y-2">
            <label for="share-url" class="block font-semibold">共有 URL</label>
            <div class="flex gap-2">
                <input id="share-url" type="text" readonly value="{{ $url }}" class="min-w-0 flex-1 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm">
                <button type="button" id="copy-share-url" class="shrink-0 rounded bg-gray-900 px-4 py-2 text-white">コピー</button>
            </div>
            <p id="copy-status" class="text-sm" role="status" aria-live="polite"></p>
        </section>

        <section class="mt-6 space-y-1 text-sm">
            <p class="font-semibold text-red-700">この URL は再表示できません。必ず控えてください</p>
            <p>この URL を知っている人は誰でも閲覧できます</p>
            <p>有効期限: {{ $proposal->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}までに削除されます</p>
            <p>チャンネル主に共有 URL を送って、内容を確認してもらってください</p>
        </section>

        <p class="mt-8"><a class="underline" href="{{ $url }}">プレビューを開く</a></p>
    </div>
@endsection
