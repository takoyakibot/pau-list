@extends('layouts.app')

@push('vite')
    @vite('resources/js/propose.js')
@endpush

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

    <noscript>
        <p class="mt-6 rounded border border-red-300 bg-red-50 p-3 text-sm">この画面の操作には JavaScript が必要です</p>
    </noscript>

    <div id="proposer" x-data="proposer" data-catalog="{{ json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-old="{{ json_encode(['title' => old('title', ''), 'description' => old('description', ''), 'comment' => old('comment', ''), 'proposerName' => old('proposer_name', ''), 'videoIds' => array_values(array_filter((array) old('video_ids', []), 'is_string'))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="mt-8 space-y-8">
        <section aria-labelledby="pick-heading" class="space-y-3">
            <h2 id="pick-heading" class="text-lg font-semibold">動画を選ぶ</h2>
            <label class="block text-sm">
                <span class="block">タイトルで絞り込み（スペース区切りですべて含むもの）</span>
                <input type="search" x-model="query" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" autocomplete="off">
            </label>
            <p class="text-sm" aria-live="polite">
                表示 <span x-text="shownCount"></span> 件／選択 <span x-text="selectedCount"></span> / <span x-text="maxItems"></span>
                <button type="button" class="ml-3 underline" @click="clearAll">全解除</button>
            </p>
            <p class="text-sm text-red-700" role="alert" x-show="hasLimitMessage" x-text="limitMessage"></p>

            <ul class="max-h-[32rem] divide-y divide-gray-200 overflow-y-auto rounded border border-gray-200">
                <template x-for="video in filtered" :key="video.id">
                    <li>
                        <label class="flex cursor-pointer items-center gap-3 p-2 text-sm">
                            <input type="checkbox" :checked="isSelected" @change="toggle" :disabled="isCheckDisabled">
                            <template x-if="video.thumbnail_url">
                                <img :src="video.thumbnail_url" loading="lazy" alt="" width="96" height="54" class="h-[54px] w-24 flex-none rounded object-cover">
                            </template>
                            <span class="min-w-0 flex-1">
                                <span class="block break-words" x-text="video.title"></span>
                                <span class="block text-xs text-gray-600" x-text="publishedLabel"></span>
                            </span>
                        </label>
                    </li>
                </template>
            </ul>
        </section>

        <section aria-labelledby="url-heading" class="space-y-2">
            <h2 id="url-heading" class="text-lg font-semibold">動画 URL で追加</h2>
            <div class="flex gap-2">
                <input type="url" x-model="urlInput" @keydown.enter.prevent="addByUrl" placeholder="https://www.youtube.com/watch?v=..." class="min-w-0 flex-1 rounded border border-gray-300 px-3 py-2 text-sm" aria-label="動画の URL" autocomplete="off">
                <button type="button" @click="addByUrl" :disabled="urlBusy" class="rounded border border-gray-400 px-3 py-2 text-sm">追加</button>
            </div>
            <p class="text-sm text-red-700" role="alert" x-show="hasUrlMessage" x-text="urlMessage"></p>
        </section>

        <form method="POST" action="{{ route('proposals.store', $channel->channel_id) }}" @submit="onSubmit" class="space-y-6">
            @csrf
            <section aria-labelledby="selected-heading" class="space-y-2">
                <h2 id="selected-heading" class="text-lg font-semibold">選択した動画（<span x-text="selectedCount"></span> / <span x-text="maxItems"></span>）</h2>
                <p class="text-sm text-gray-600" x-show="!hasSelection">まだ動画が選ばれていません</p>
                <ol class="space-y-2">
                    <template x-for="item in selected" :key="item.id">
                        <li class="rounded border border-gray-200 p-2 text-sm">
                            <input type="hidden" name="video_ids[]" :value="item.id">
                            <div class="flex items-center gap-2">
                                <template x-if="item.thumbnail_url">
                                    <img :src="item.thumbnail_url" loading="lazy" alt="" width="64" height="36" class="h-9 w-16 flex-none rounded object-cover">
                                </template>
                                <span class="min-w-0 flex-1 break-words" x-text="item.title"></span>
                                <button type="button" @click="moveUp" class="rounded border border-gray-300 px-2" aria-label="上へ">↑</button>
                                <button type="button" @click="moveDown" class="rounded border border-gray-300 px-2" aria-label="下へ">↓</button>
                                <button type="button" @click="remove" class="rounded border border-gray-300 px-2">削除</button>
                            </div>
                            <p class="mt-1 text-xs text-amber-800" x-show="item.outside">対象チャンネル外</p>
                            <p class="mt-1 text-xs text-red-700" x-show="item.unavailable">この動画は削除または非公開になっています。外して保存してください</p>
                        </li>
                    </template>
                </ol>
            </section>

            @if ($errors->any())
                <ul class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800" role="alert">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif

            <section class="space-y-4">
                <label class="block text-sm">
                    <span class="block font-semibold">タイトル（必須）</span>
                    <input type="text" name="title" x-model="title" maxlength="150" required class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                    <span class="block text-xs text-gray-600" x-text="titleCounter"></span>
                </label>
                <label class="block text-sm">
                    <span class="block font-semibold">説明（任意）</span>
                    <textarea name="description" x-model="description" maxlength="1000" rows="3" class="mt-1 w-full rounded border border-gray-300 px-3 py-2"></textarea>
                    <span class="block text-xs text-gray-600" x-text="descriptionCounter"></span>
                </label>
                <label class="block text-sm">
                    <span class="block font-semibold">コメント（任意）</span>
                    <textarea name="comment" x-model="comment" maxlength="500" rows="3" class="mt-1 w-full rounded border border-gray-300 px-3 py-2"></textarea>
                    <span class="block text-xs text-gray-600" x-text="commentCounter"></span>
                </label>
                <label class="block text-sm">
                    <span class="block font-semibold">提案者の名前（任意）</span>
                    <input type="text" name="proposer_name" x-model="proposerName" maxlength="30" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                    <span class="block text-xs text-gray-600">実名は書かないでください</span>
                    <span class="block text-xs text-gray-600" x-text="proposerNameCounter"></span>
                </label>
                <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            </section>

            <div class="space-y-2">
                <p class="text-xs text-gray-600">保存すると、<a class="underline" href="{{ route('terms') }}" target="_blank" rel="noopener">利用規約</a> と <a class="underline" href="{{ route('privacy') }}" target="_blank" rel="noopener">プライバシーポリシー</a> に同意したものとみなされます</p>
                <button type="submit" :disabled="submitting" class="rounded bg-gray-900 px-4 py-2 text-white disabled:opacity-50">提案を保存する</button>
            </div>
        </form>
    </div>
@endsection
