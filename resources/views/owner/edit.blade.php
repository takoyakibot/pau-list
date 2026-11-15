@extends('layouts.app')

@section('title', $proposal->title.' — 再生リストの作成')
@section('main_width', 'max-w-[1200px]')

@push('vite')
    @vite('resources/js/owner-edit.js')
@endpush

@section('content')
    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[13px] text-ink-muted">
            <p>ログイン中: {{ $ownerChannelTitle }}</p>
            @include('partials.owner-logout')
        </div>

        <header class="flex flex-col gap-1">
            <p class="hint">{{ $proposal->proposer_name !== null ? $proposal->proposer_name.' さんからの提案' : '匿名の提案' }}</p>
            <h1 class="text-[22px] font-bold break-words">{{ $proposal->title }}</h1>
            @if (! is_null($proposal->comment) && $proposal->comment !== '')
                <p class="card mt-1 px-3.5 py-2.5 text-[13px] whitespace-pre-line break-words text-ink-2">{{ $proposal->comment }}</p>
            @endif
        </header>

        <noscript>
            <p class="notice-danger text-sm">この画面の操作には JavaScript が必要です</p>
        </noscript>

        {{-- No <form>: 「作成」 is a JSON POST to the relative `creation` (owner-edit.js). The server renders a correct initial state; owner-edit.js takes over. --}}
        <div id="owner-editor" x-data="ownerEditor" data-editor="{{ json_encode($editor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="flex flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_560px] flex-col gap-4">
                <section aria-labelledby="items-heading" class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-2 border-b border-line-subtle px-4 py-3.5 sm:px-5">
                        <h2 id="items-heading" class="text-base font-bold">再生リストに入れる動画（<span x-text="count">{{ $count }}</span> / <span x-text="maxItems">{{ $editor['maxItems'] }}</span>）</h2>
                        <button type="button" @click="resetToProposal" class="btn-sm btn-secondary ml-auto">提案の内容に戻す</button>
                    </div>

                    <div class="flex flex-col gap-2 p-2">
                        {{-- Initial list (also the no-JS view). owner-edit.js hides it and shows the live list below. --}}
                        <ol id="owner-items-initial" x-show="showInitialList" class="counter-list space-y-1.5 text-[13px]">
                            @foreach ($items as $item)
                                <li class="counter-item flex items-center gap-2.5 rounded-lg border border-line-subtle px-2 py-1.5">
                                    @if ($item->thumbnail_url)
                                        <img src="{{ $item->thumbnail_url }}" loading="lazy" alt="" width="96" height="54" class="h-[54px] w-24 flex-none rounded-md object-cover">
                                    @endif
                                    <div class="flex min-w-0 flex-1 flex-col items-start gap-1">
                                        <span class="font-medium break-words text-ink-2">{{ $item->title }}</span>
                                        @if ($item->is_outside_channel)
                                            <span class="pill bg-muted text-ink-muted">ほかのチャンネル</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                        @if ($count === 0)
                            <p class="hint px-2" x-show="showInitialList">まだ動画が選ばれていません</p>
                        @endif

                        <div x-show="showLiveList" x-cloak class="flex flex-col gap-2">
                            <p class="notice-warn text-sm" role="status" x-show="hasRestoredMessage" x-text="restoredMessage"></p>
                            <p class="hint px-2" x-show="isEmpty">まだ動画が選ばれていません</p>
                            <ol class="counter-list space-y-1.5 text-[13px]">
                                <template x-for="item in items" :key="item.id">
                                    <li class="counter-item flex items-center gap-2.5 rounded-lg border border-line-subtle px-2 py-1.5">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2.5">
                                                <template x-if="item.thumbnail_url">
                                                    <img :src="item.thumbnail_url" loading="lazy" alt="" width="96" height="54" class="h-[54px] w-24 flex-none rounded-md object-cover">
                                                </template>
                                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                                    <span class="font-medium break-words text-ink-2" x-text="item.title"></span>
                                                    <span class="flex flex-wrap gap-1.5">
                                                        <span class="pill bg-members-soft text-members" x-show="item.unlisted">限定公開</span>
                                                        <span class="pill bg-muted text-ink-muted" x-show="item.outside">ほかのチャンネル</span>
                                                    </span>
                                                </div>
                                                {{-- Per-row names for screen readers (owner-edit.js methods; the CSP build allows property refs only). --}}
                                                <span class="flex flex-none">
                                                    <button type="button" @click="moveUp" class="flex size-8 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="上へ" :aria-label="moveUpLabel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg></button>
                                                    <button type="button" @click="moveDown" class="flex size-8 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="下へ" :aria-label="moveDownLabel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
                                                    <button type="button" @click="remove" class="flex size-8 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="削除" :aria-label="removeLabel"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                                                </span>
                                            </div>
                                            <p class="mt-1 text-xs font-semibold text-danger" x-show="item.unavailable">削除または非公開のため作成できません</p>
                                        </div>
                                    </li>
                                </template>
                            </ol>
                        </div>
                        <p class="px-2 text-sm text-danger" role="alert" x-show="hasLimitMessage" x-cloak x-text="limitMessage"></p>
                    </div>
                    <p class="hint border-t border-line-subtle px-4 py-2 sm:px-5">データ提供: YouTube</p>
                </section>

                <details class="card group overflow-hidden">
                    <summary class="flex min-h-12 items-center gap-2 px-4 font-bold sm:px-5">
                        <svg class="flex-none text-ink-muted transition-transform group-open:rotate-45" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <span id="add-heading">動画を追加</span>
                    </summary>
                    <section aria-labelledby="add-heading" class="flex flex-col gap-3 border-t border-line-subtle px-4 py-4 sm:px-5">
                        @if ($catalogUnavailable)
                            <p class="notice-warn text-sm">{{ \App\Http\Controllers\OwnerEditController::CATALOG_UNAVAILABLE }}</p>
                        @endif

                        {{-- Loaded on demand only (owner-edit.js fetches data-editor.unlistedUrl): each load spends owner_read. --}}
                        <div class="flex flex-col items-start gap-1" x-cloak>
                            <button type="button" id="load-unlisted" @click="loadUnlisted" :disabled="unlistedBusy" x-show="canLoadUnlisted" class="btn-sm btn-secondary">限定公開の動画も表示する</button>
                            <p class="hint">{{ \App\Http\Controllers\OwnerEditController::UNLISTED_NOTE }}</p>
                            <p class="text-sm text-warn" role="alert" x-show="hasUnlistedMessage" x-text="unlistedMessage"></p>
                            {{-- 401 (login expired): reloading this page sends the owner to the preview's login link; the draft is kept. --}}
                            <p class="text-sm" x-show="unlistedNeedsRelogin"><a class="font-semibold underline" :href="reloginHref">もう一度ログインする</a></p>
                        </div>

                        <div x-show="hasVideos" x-cloak class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="flex min-h-11 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-line-strong bg-raised px-3 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent">
                                    <svg class="flex-none text-ink-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                                    <span class="sr-only">タイトルで絞り込み（スペース区切りですべて含むもの）</span>
                                    <input type="search" x-model="query" x-ref="filter" placeholder="タイトルで絞り込み（スペース区切り）" class="min-w-0 flex-1 bg-transparent py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none" autocomplete="off">
                                </label>
                                {{-- Disabled until a filter matches a video not selected yet (owner-edit.js); the no-JS state is disabled. --}}
                                <button type="button" @click="selectAllFiltered" :disabled="isSelectFilteredDisabled" x-text="selectFilteredLabel" class="btn-sm min-h-11 border border-accent bg-accent-soft font-bold text-accent-hover" disabled>絞り込み結果をすべて選択</button>
                                <p class="select-filtered-status hint basis-full" role="status" aria-live="polite" x-text="selectFilteredStatus"></p>
                            </div>
                            <ul class="grid max-h-[32rem] grid-cols-[repeat(auto-fill,minmax(min(100%,17rem),1fr))] gap-2 overflow-y-auto">
                                <template x-for="video in filtered" :key="video.id">
                                    <li class="flex flex-col items-end rounded-lg border border-line-subtle bg-raised pr-2 pb-1.5 has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                                        <label class="flex min-w-0 cursor-pointer items-center gap-2.5 self-stretch p-2">
                                            <input type="checkbox" :checked="isSelected" @change="toggle" :disabled="isCheckDisabled" class="size-[18px] flex-none">
                                            <template x-if="video.thumbnail_url">
                                                <img :src="video.thumbnail_url" loading="lazy" alt="" width="112" height="63" class="h-[63px] w-28 flex-none rounded-md object-cover">
                                            </template>
                                            <span class="flex min-w-0 flex-1 flex-col items-start gap-0.5">
                                                <span class="text-[13px] font-medium break-words text-ink-2" x-text="video.title"></span>
                                                <span class="hint" x-text="publishedLabel"></span>
                                                <span class="pill bg-members-soft text-members" x-show="video.unlisted">限定公開</span>
                                            </span>
                                        </label>
                                        <a class="shrink-0 text-xs underline" :href="watchHref" x-show="watchHref" rel="noopener noreferrer" target="_blank">YouTube で見る</a>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div class="flex flex-col gap-1.5 border-t border-line-subtle pt-3" x-cloak>
                            <div class="flex gap-1.5">
                                <input type="url" x-model="urlInput" @keydown.enter.prevent="addByUrl" placeholder="https://www.youtube.com/watch?v=..." class="field min-h-9 min-w-0 flex-1 text-[13px]" aria-label="動画の URL" autocomplete="off">
                                <button type="button" @click="addByUrl" :disabled="urlBusy" class="btn-sm btn-secondary">追加</button>
                            </div>
                            <p class="hint">{{ \App\Http\Controllers\OwnerEditController::URL_UNLISTED_HINT }}</p>
                            <p class="text-sm text-danger" role="alert" x-show="hasUrlMessage" x-cloak x-text="urlMessage"></p>
                            {{-- 401 from the owner lookup (login expired): reloading sends the owner to the preview's login link; the draft is kept. --}}
                            <p class="text-sm" x-show="urlNeedsRelogin" x-cloak><a class="font-semibold underline" :href="reloginHref">もう一度ログインする</a></p>
                        </div>
                    </section>
                </details>
            </div>

            <aside class="flex min-w-0 flex-[1_1_340px] flex-col gap-4">
                <section aria-labelledby="create-heading" class="card flex flex-col gap-3.5 px-4 py-4 sm:px-5">
                    <h2 id="create-heading" class="text-base font-bold">再生リストを作る</h2>
                    {{-- No maxlength: browsers count UTF-16 units (an emoji is 2). The counters count characters like the server. --}}
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">再生リスト名（必須）</span>
                        <input type="text" name="title" x-model="title" value="{{ $proposal->title }}" required class="field mt-1 min-h-10">
                        <span class="block text-xs" :class="titleCounterClass" x-text="titleCounter"></span>
                        <span class="block text-xs text-danger" x-show="titleOver" x-cloak>再生リスト名 は 150 文字以内で入力してください</span>
                        <span class="block text-xs text-danger" x-show="titleEmpty" x-cloak x-text="titleRequiredText">再生リスト名を入力してください</span>
                    </label>
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">再生リストの説明（任意）</span>
                        <textarea name="description" x-model="description" rows="2" class="field mt-1">{{ $proposal->description }}</textarea>
                        <span class="block text-xs" :class="descriptionCounterClass" x-text="descriptionCounter"></span>
                        <span class="block text-xs text-danger" x-show="descriptionOver" x-cloak>再生リストの説明 は 1000 文字以内で入力してください</span>
                    </label>
                    {{-- No default (D46): nothing is checked until the owner picks one; 「作成」 asks for it otherwise. --}}
                    <fieldset class="privacy-choice text-sm" aria-describedby="privacy-error">
                        <legend class="mb-2 text-[13px] font-semibold text-ink-2">公開範囲（必須）</legend>
                        <div class="flex flex-col gap-2">
                            @foreach (\App\Http\Controllers\OwnerEditController::PRIVACY_OPTIONS as $value => [$label, $hint])
                                <label class="privacy-option flex min-h-[52px] cursor-pointer items-center gap-2.5 rounded-lg border border-line-strong bg-raised px-3 py-2 has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                                    <input type="radio" name="privacy" value="{{ $value }}" id="privacy-{{ $value }}" x-model="privacy" autocomplete="off" class="size-[18px] flex-none">
                                    <span class="flex size-8 flex-none items-center justify-center rounded-lg bg-muted text-ink-2">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @if ($value === 'private')
                                                <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                            @elseif ($value === 'unlisted')
                                                <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>
                                            @else
                                                <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>
                                            @endif
                                        </svg>
                                    </span>
                                    <span class="flex flex-col">
                                        <span class="block font-bold">{{ $label }}</span>
                                        <span class="block text-xs text-ink-muted">{{ $hint }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p id="privacy-error" class="mt-1 text-sm text-danger" role="alert" x-show="hasPrivacyMessage" x-cloak x-text="privacyMessage"></p>
                    </fieldset>
                    {{-- Security M1: a public playlist exposes the owner's unlisted videos in it to anyone who opens it. --}}
                    <p class="notice-warn text-sm" role="status" x-show="hasPublicUnlistedNote" x-cloak x-text="publicUnlistedNote"></p>

                    <div class="space-y-1.5 text-[13px]">
                        <p class="font-semibold text-ink-2" x-text="neededText">この内容に必要な枠: 再生リスト 1 つ＋動画 {{ $count }} 本</p>
                        @include('partials.remaining-today', ['remaining' => $remaining])
                        @include('partials.max-items')
                        {{-- When nothing is left today, the remaining-today partial already shows the reset time; reducing would not help. --}}
                        @if ($shortBy > 0 && ! $remaining->exhausted())
                            <p class="text-danger" x-show="showServerShortage">あと {{ $shortBy }} 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 {{ $resetLabel }} です）</p>
                        @endif
                        <p class="text-danger" x-show="hasShortage" x-cloak x-text="shortageText"></p>
                    </div>

                    <div class="flex flex-col gap-2">
                        {{-- The server-side disabled attribute is the no-JS state; owner-edit.js keeps it in sync via :disabled. --}}
                        <button type="button" :disabled="isCreateDisabled" @click="onCreate" class="btn btn-primary min-h-[46px] w-full text-[15px]" id="create-playlist" @disabled($count === 0 || $shortBy > 0)>あなたのチャンネルに再生リストを作成します</button>
                        <p class="hint">押すとあなたの YouTube チャンネルに再生リストが作成されます（書き込み操作）</p>
                        <p class="text-sm text-danger" role="alert" x-show="hasCreateMessage" x-cloak x-text="createMessage"></p>
                        {{-- 401 from the start API (login expired): reloading sends the owner to the preview's login link; the draft is kept. --}}
                        <p class="text-sm" x-show="createNeedsRelogin" x-cloak><a class="font-semibold underline" :href="reloginHref">もう一度ログインする</a></p>
                    </div>

                    {{-- Owner-only deletion (user decision #12): the link opens a confirmation page; only its POST deletes.
                         Relative to /p/{key}/edit so the raw key is not written into this page. --}}
                    <p class="border-t border-line-subtle pt-3 text-center text-xs"><a class="text-ink-muted underline hover:text-danger" href="delete">この提案を削除</a></p>
                </section>
            </aside>
        </div>
    </div>
@endsection
