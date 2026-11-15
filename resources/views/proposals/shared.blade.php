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
        data-expires-at="{{ $proposal->expires_at->utc()->format('Y-m-d\TH:i:s\Z') }}"
        class="flex flex-col gap-5">
        @include('partials.steps', ['current' => 2])

        <section class="card flex flex-col gap-4 p-5 sm:p-6">
            <div>
                <h1 class="text-[22px] font-bold">提案を保存しました</h1>
                <p class="mt-1 text-[13px] text-ink-2">{{ $proposal->channel_title }} への提案「{{ $proposal->title }}」（{{ $proposal->item_count }} 本）</p>
                <p class="hint">データ提供: YouTube</p>
            </div>

            <div class="flex flex-col gap-2">
                <label for="share-url" class="font-bold">共有 URL</label>
                <div class="flex flex-wrap gap-2">
                    <input id="share-url" type="text" readonly value="{{ $url }}" class="field min-h-11 min-w-0 flex-[1_1_240px] bg-surface">
                    <button type="button" id="copy-share-url" class="btn btn-primary shrink-0">コピー</button>
                </div>
                <p id="copy-status" class="text-sm text-ink-2" role="status" aria-live="polite"></p>
            </div>

            <p class="notice-danger text-sm font-bold">この URL は再表示できません。必ず控えてください</p>
            <ul class="space-y-1 text-[13px] text-ink-2">
                <li>この URL をチャンネル主に送ってください。この URL を知っている人は誰でも閲覧できます</li>
                <li>有効期限: {{ $proposal->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}までに削除されます</li>
            </ul>

            <p><a class="underline" href="{{ $url }}">プレビューを開く</a></p>
        </section>
    </div>
@endsection
