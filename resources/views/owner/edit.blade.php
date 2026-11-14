@extends('layouts.app')

@section('title', $proposal->title.' — 再生リストの作成')

@section('content')
    <p class="text-sm">ログイン中: {{ $ownerChannelTitle }}</p>
    <h1 class="mt-2 text-2xl font-bold break-words">{{ $proposal->title }}</h1>

    <noscript>
        <p class="mt-6 rounded border border-red-300 bg-red-50 p-3 text-sm">この画面の操作には JavaScript が必要です</p>
    </noscript>

    {{-- No <form>: creating is Phase 3 (POST /p/{key}/creation). The server renders a correct initial state; owner-edit.js takes over. --}}
    <div id="owner-editor" x-data="ownerEditor" data-editor="{{ json_encode($editor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="mt-6 space-y-8">
        <section aria-labelledby="items-heading" class="space-y-2">
            <h2 id="items-heading" class="text-lg font-semibold">再生リストに入れる動画（<span x-text="count">{{ $count }}</span> / <span x-text="maxItems">{{ $editor['maxItems'] }}</span>）</h2>

            {{-- Initial list (also the no-JS view). owner-edit.js hides it and shows the live list below. --}}
            <ol id="owner-items-initial" x-show="showInitialList" class="space-y-2">
                @foreach ($items as $item)
                    <li class="rounded border border-gray-200 p-2 text-sm">
                        <div class="flex items-center gap-2">
                            @if ($item->thumbnail_url)
                                <img src="{{ $item->thumbnail_url }}" loading="lazy" alt="" width="64" height="36" class="h-9 w-16 flex-none rounded object-cover">
                            @endif
                            <span class="min-w-0 flex-1 break-words">{{ $item->title }}</span>
                        </div>
                        @if ($item->is_outside_channel)
                            <p class="mt-1 text-xs text-amber-800">対象チャンネル外</p>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if ($count === 0)
                <p class="text-sm text-gray-600" x-show="showInitialList">まだ動画が選ばれていません</p>
            @endif

            <div x-show="showLiveList" x-cloak>
                <p class="text-sm text-gray-600" x-show="isEmpty">まだ動画が選ばれていません</p>
                <ol class="space-y-2">
                    <template x-for="item in items" :key="item.id">
                        <li class="rounded border border-gray-200 p-2 text-sm">
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
                            <p class="mt-1 text-xs text-gray-700" x-show="item.unlisted">限定公開</p>
                        </li>
                    </template>
                </ol>
            </div>
            <p class="text-sm text-red-700" role="alert" x-show="hasLimitMessage" x-cloak x-text="limitMessage"></p>
            <button type="button" @click="resetToProposal" class="rounded border border-gray-400 px-3 py-1 text-sm">提案の内容に戻す</button>
        </section>

        <section aria-labelledby="add-heading" class="space-y-3">
            <h2 id="add-heading" class="text-lg font-semibold">動画を追加</h2>
            @if ($catalogUnavailable)
                <p class="text-sm text-amber-800">{{ \App\Http\Controllers\OwnerEditController::CATALOG_UNAVAILABLE }}</p>
            @endif
            @if ($unlistedShown)
                <p class="text-sm">{{ \App\Http\Controllers\OwnerEditController::UNLISTED_SHOWN }}</p>
            @endif
            @if ($unlistedFailed)
                <p class="text-sm text-amber-800">{{ \App\Http\Controllers\OwnerEditController::UNLISTED_FAILED }}</p>
            @endif

            <div x-show="hasVideos" x-cloak class="space-y-2">
                <label class="block text-sm">
                    <span class="block">タイトルで絞り込み（スペース区切りですべて含むもの）</span>
                    <input type="search" x-model="query" class="mt-1 w-full rounded border border-gray-300 px-3 py-2" autocomplete="off">
                </label>
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
                                    <span class="block text-xs text-gray-700" x-show="video.unlisted">限定公開</span>
                                </span>
                            </label>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="flex gap-2" x-cloak>
                <input type="url" x-model="urlInput" @keydown.enter.prevent="addByUrl" placeholder="https://www.youtube.com/watch?v=..." class="min-w-0 flex-1 rounded border border-gray-300 px-3 py-2 text-sm" aria-label="動画の URL" autocomplete="off">
                <button type="button" @click="addByUrl" :disabled="urlBusy" class="rounded border border-gray-400 px-3 py-2 text-sm">追加</button>
            </div>
            <p class="text-sm text-red-700" role="alert" x-show="hasUrlMessage" x-cloak x-text="urlMessage"></p>
        </section>

        <section class="space-y-4">
            {{-- No maxlength: browsers count UTF-16 units (an emoji is 2). The counters count characters like the server. --}}
            <label class="block text-sm">
                <span class="block font-semibold">再生リスト名（必須）</span>
                <input type="text" name="title" x-model="title" value="{{ $proposal->title }}" required class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                <span class="block text-xs" :class="titleCounterClass" x-text="titleCounter"></span>
                <span class="block text-xs text-red-700" x-show="titleOver" x-cloak>再生リスト名 は 150 文字以内で入力してください</span>
            </label>
            <label class="block text-sm">
                <span class="block font-semibold">再生リストの説明（任意）</span>
                <textarea name="description" x-model="description" rows="3" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">{{ $proposal->description }}</textarea>
                <span class="block text-xs" :class="descriptionCounterClass" x-text="descriptionCounter"></span>
                <span class="block text-xs text-red-700" x-show="descriptionOver" x-cloak>再生リストの説明 は 1000 文字以内で入力してください</span>
            </label>
            <label class="block text-sm">
                <span class="block font-semibold">公開範囲</span>
                <select name="privacy" x-model="privacy" class="mt-1 rounded border border-gray-300 px-3 py-2">
                    <option value="private" selected>非公開</option>
                    <option value="unlisted">限定公開</option>
                    <option value="public">公開</option>
                </select>
            </label>
        </section>

        <section class="space-y-2 text-sm">
            @include('partials.max-items')
            <p x-text="neededText">この内容に必要な枠: 再生リスト 1 つ＋動画 {{ $count }} 本</p>
            @include('partials.remaining-today', ['remaining' => $remaining])
            @if ($shortBy > 0)
                <p class="text-red-700" x-show="showServerShortage">あと {{ $shortBy }} 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 {{ $resetLabel }} です）</p>
            @endif
            <p class="text-red-700" x-show="hasShortage" x-cloak x-text="shortageText"></p>
        </section>

        <div class="space-y-2">
            {{-- The server-side disabled attribute is the no-JS state; owner-edit.js keeps it in sync via :disabled. --}}
            <button type="button" :disabled="isCreateDisabled" @click="onCreate" class="rounded bg-gray-900 px-4 py-2 text-white disabled:opacity-50" id="create-playlist" @disabled($count === 0 || $shortBy > 0)>あなたのチャンネルに再生リストを作成します</button>
            <p class="text-xs text-gray-600">押すとあなたの YouTube チャンネルに再生リストが作成されます（書き込み操作）。作成機能は準備中で、現在は押しても作成されません</p>
            <p class="text-sm" role="status" x-show="hasCreateMessage" x-cloak x-text="createMessage"></p>
        </div>

        <p class="text-xs text-gray-600">データ提供: YouTube</p>
    </div>
@endsection
