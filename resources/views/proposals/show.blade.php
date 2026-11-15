@extends('layouts.app')

@section('title', $proposal->title.' — 再生リストの提案')
@section('main_width', 'max-w-[1200px]')

@section('content')
    <div class="flex flex-col gap-5">
        @include('partials.steps', ['current' => 3])

        <header class="flex flex-col gap-1">
            <p class="hint">
                <a class="underline" href="https://www.youtube.com/channel/{{ $proposal->channel_id }}" rel="noopener noreferrer" target="_blank">{{ $proposal->channel_title }}</a> への再生リストの提案
            </p>
            <h1 class="text-[22px] font-bold break-words">{{ $proposal->title }}</h1>
            @if ($proposal->description !== '')
                <p class="text-[13px] whitespace-pre-line break-words text-ink-2">{{ $proposal->description }}</p>
            @endif
            @unless ($ownerMatches)
                <p class="hint">提案した方は、この URL をチャンネル主に送ってください</p>
            @endunless
        </header>

        @include('partials.owner-login')

        <div class="flex flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-5">
                <section class="card px-4 py-3 text-sm sm:px-5" aria-labelledby="proposer-heading">
                    <h2 id="proposer-heading" class="font-bold">提案者: {{ $proposal->proposer_name ?? '匿名' }}</h2>
                    @if (! is_null($proposal->comment) && $proposal->comment !== '')
                        <p class="mt-2 border-l-4 border-line-strong pl-3 whitespace-pre-line break-words text-ink-2">{{ $proposal->comment }}</p>
                    @endif
                </section>

                <section class="card overflow-hidden" aria-labelledby="videos-heading">
                    <div class="flex flex-wrap items-center gap-2 border-b border-line-subtle px-4 py-3.5 sm:px-5">
                        <h2 id="videos-heading" class="text-base font-bold">動画</h2>
                        <p class="text-[13px] text-ink-muted">{{ $proposal->item_count }} 本・合計 {{ \App\Support\DurationFormat::total($totalSeconds) }}@if ($hasUnknownDuration)（長さ不明の動画を除く）@endif</p>
                    </div>
                    <ol class="flex flex-col gap-1.5 p-2">
                        @foreach ($items as $item)
                            <li class="flex items-start gap-2.5 rounded-lg border border-line-subtle px-2 py-1.5 text-[13px]">
                                <span class="w-6 shrink-0 pt-1 text-right text-xs text-ink-muted">{{ $loop->iteration }}</span>
                                @if ($item->thumbnail_url)
                                    <img src="{{ $item->thumbnail_url }}" alt="" width="96" height="54" loading="lazy" class="h-[54px] w-24 shrink-0 rounded-md object-cover">
                                @endif
                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <a class="font-medium break-words underline" href="https://www.youtube.com/watch?v={{ $item->video_id }}" rel="noopener noreferrer" target="_blank">{{ $item->title }}</a>
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-ink-muted">
                                        <a class="underline" href="https://www.youtube.com/channel/{{ $item->video_channel_id }}" rel="noopener noreferrer" target="_blank">{{ $item->video_channel_title }}</a>
                                        @if ($item->duration_seconds !== null)
                                            <span>・ {{ \App\Support\DurationFormat::clock($item->duration_seconds) }}</span>
                                        @endif
                                        @if ($item->is_outside_channel)
                                            <span class="pill bg-muted text-ink-muted">ほかのチャンネル</span>
                                        @endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <p class="hint border-t border-line-subtle px-4 py-2 sm:px-5">データ提供: YouTube</p>
                </section>

                {{-- A long list pushes the top call to action out of view: repeat just the primary action below it. --}}
                @if ($proposal->item_count >= 8)
                    @include('partials.owner-login', ['compact' => true])
                @endif
            </div>

            <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                <section class="card space-y-1.5 px-4 py-4 text-[13px] sm:px-5" aria-label="提案の状態">
                    <p class="font-bold">状態: {{ match ($creationStatus) {
                        'creating' => '作成中',
                        'created' => '作成済み',
                        'not_created' => '再生リストは作成されませんでした',
                        'unknown' => '再生リストが作成されたか確認できませんでした',
                        default => '未作成',
                    } }}</p>
                    @if ($ownerMatches && $creationStatus === 'unknown')
                        <p>{{ \App\Services\Creation\CreationProgress::MAYBE_CREATED }}</p>
                    @endif
                    {{-- The owner's way out when nothing (certain) was created: this proposal cannot be created again (UX m5). --}}
                    @if ($ownerMatches && in_array($creationStatus, ['not_created', 'unknown'], true))
                        <p>{{ \App\Services\Creation\CreationProgress::REMAKE_HINT }} <a class="text-danger underline" href="{{ route('proposals.delete', $key) }}">この提案を削除</a></p>
                    @endif
                    {{-- Only set when it may be shown to this viewer (spec 7.6: unlisted/private only to the channel's owner). --}}
                    @if ($playlistUrl !== null)
                        <p><a class="font-semibold underline" href="{{ $playlistUrl }}" rel="noopener noreferrer" target="_blank">作成された再生リストを YouTube で見る</a></p>
                    @endif
                    <p class="hint">作成日 {{ $proposal->created_at->timezone('Asia/Tokyo')->format('n月j日') }}／{{ $proposal->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}までに削除されます</p>
                </section>

                @if ($creationStatus === null)
                    <div class="space-y-1">
                        @include('partials.max-items')
                        @include('partials.remaining-today', ['remaining' => $remaining, 'itemCount' => $proposal->item_count])
                    </div>
                @endif
            </aside>
        </div>
    </div>
@endsection
