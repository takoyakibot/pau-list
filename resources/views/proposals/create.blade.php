@extends('layouts.app')

@section('title', $channel->title.' への提案')

@section('content')
    <header class="flex items-center gap-4">
        @if ($channel->thumbnail_url)
            <img src="{{ $channel->thumbnail_url }}" alt="" width="64" height="64" class="h-16 w-16 rounded-full">
        @endif
        <div>
            <h1 class="text-2xl font-bold">{{ $channel->title }}</h1>
            <p class="text-sm">
                <a class="underline" href="https://www.youtube.com/channel/{{ $channel->channel_id }}" rel="noopener noreferrer" target="_blank">YouTube でチャンネルを開く</a>
                ・ データ提供: YouTube
            </p>
        </div>
    </header>

    <section class="mt-6 text-sm">
        @include('partials.max-items')
        @include('partials.remaining-today', ['remaining' => $remaining, 'note' => '作成はチャンネル主が操作した時点の残りで決まります'])
    </section>

    <section class="mt-6 space-y-1 text-sm">
        @if ($reimported)
            <p>前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています</p>
        @endif
        <p>この一覧は {{ $channel->cached_since->timezone('Asia/Tokyo')->format('n月j日') }}に取り込み、{{ $channel->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}に削除されます（削除後に開くと全件を取り込み直します）</p>
        <p>一覧の最終取り込み: {{ $channel->last_synced_at->timezone('Asia/Tokyo')->format('n月j日 G時i分') }}@if ($staleness) <strong>{{ $staleness }}</strong>@endif</p>
        <p>最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください</p>
        @if ($channel->truncated)
            <p>これより古い動画は動画 URL で追加してください</p>
        @endif
    </section>

    <div id="proposer" x-data="proposer" data-catalog="{{ json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="mt-8"></div>
@endsection
