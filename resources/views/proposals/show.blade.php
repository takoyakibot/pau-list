@extends('layouts.app')

@section('title', $proposal->title.' — 再生リストの提案')

@section('content')
    <p class="text-sm text-gray-600">
        <a class="underline" href="https://www.youtube.com/channel/{{ $proposal->channel_id }}" rel="noopener noreferrer" target="_blank">{{ $proposal->channel_title }}</a> への再生リストの提案
    </p>

    <header class="mt-2">
        <h1 class="text-2xl font-bold break-words">{{ $proposal->title }}</h1>
        @if ($proposal->description !== '')
            <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $proposal->description }}</p>
        @endif
        <p class="mt-2 text-sm">{{ $proposal->item_count }} 本・合計 {{ \App\Support\DurationFormat::total($totalSeconds) }}@if ($hasUnknownDuration)（長さ不明の動画を除く）@endif</p>
    </header>

    <section class="mt-6 space-y-1 text-sm">
        <p>状態: {{ match ($creationStatus) {
            'creating' => '作成中',
            'created' => '作成済み',
            'not_created' => '再生リストは作成されませんでした',
            default => '未作成',
        } }}</p>
        {{-- Only set when it may be shown to this viewer (spec 7.6: unlisted/private only to the channel's owner). --}}
        @if ($playlistUrl !== null)
            <p><a class="font-semibold underline" href="{{ $playlistUrl }}" rel="noopener noreferrer" target="_blank">作成された再生リストを YouTube で見る</a></p>
        @endif
        <p>作成日 {{ $proposal->created_at->timezone('Asia/Tokyo')->format('n月j日') }}／{{ $proposal->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}までに削除されます</p>
    </section>

    <section class="mt-6 rounded border border-gray-200 p-4 text-sm" aria-labelledby="proposer-heading">
        <h2 id="proposer-heading" class="font-semibold">提案者: {{ $proposal->proposer_name ?? '匿名' }}</h2>
        @if (! is_null($proposal->comment) && $proposal->comment !== '')
            <p class="mt-2 whitespace-pre-line break-words">{{ $proposal->comment }}</p>
        @endif
    </section>

    <section class="mt-6" aria-labelledby="videos-heading">
        <h2 id="videos-heading" class="text-lg font-semibold">動画</h2>
        <ol class="mt-2 divide-y divide-gray-200 rounded border border-gray-200">
            @foreach ($items as $item)
                <li class="flex items-start gap-3 p-2 text-sm">
                    <span class="w-8 shrink-0 text-right text-gray-600">{{ $loop->iteration }}</span>
                    @if ($item->thumbnail_url)
                        <img src="{{ $item->thumbnail_url }}" alt="" width="120" height="68" loading="lazy" class="h-[68px] w-[120px] shrink-0 rounded object-cover">
                    @endif
                    <div class="min-w-0 flex-1">
                        <a class="break-words underline" href="https://www.youtube.com/watch?v={{ $item->video_id }}" rel="noopener noreferrer" target="_blank">{{ $item->title }}</a>
                        <p class="text-gray-600">
                            <a class="underline" href="https://www.youtube.com/channel/{{ $item->video_channel_id }}" rel="noopener noreferrer" target="_blank">{{ $item->video_channel_title }}</a>
                            @if ($item->duration_seconds !== null)
                                ・ {{ \App\Support\DurationFormat::clock($item->duration_seconds) }}
                            @endif
                            @if ($item->is_outside_channel)
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">対象チャンネル外</span>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="mt-2 text-xs text-gray-600">データ提供: YouTube</p>
    </section>

    @if ($creationStatus === null)
        <section class="mt-6 text-sm">
            @include('partials.max-items')
            @include('partials.remaining-today', ['remaining' => $remaining, 'itemCount' => $proposal->item_count])
        </section>
    @endif

    @include('partials.owner-login')
@endsection
