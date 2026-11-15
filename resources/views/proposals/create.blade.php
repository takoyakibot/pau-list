@extends('layouts.app')

@push('vite')
    @vite('resources/js/propose.js')
@endpush

@section('title', $channel->title.' への提案')
@section('main_width', 'max-w-[1200px]')

@section('content')
    <div class="flex flex-col gap-5">
        @include('partials.steps', ['current' => 1])

        <header class="card flex flex-wrap items-center gap-3.5 px-5 py-4">
            @if ($channel->thumbnail_url)
                <img src="{{ $channel->thumbnail_url }}" alt="" width="48" height="48" class="size-12 flex-none rounded-full">
            @else
                <span class="flex size-12 flex-none items-center justify-center rounded-full bg-accent text-lg font-bold text-on-accent" aria-hidden="true">{{ mb_substr($channel->title, 0, 1) }}</span>
            @endif
            <div class="flex min-w-0 flex-col gap-0.5">
                <span class="hint">提案先のチャンネル</span>
                <h1 class="text-base font-bold break-words">{{ $channel->title }}</h1>
                <p class="hint">一覧の最終取り込み: {{ $channel->last_synced_at->timezone('Asia/Tokyo')->format('n月j日 G時i分') }}@if ($staleness) <strong>{{ $staleness }}</strong>@endif ・ データ提供: YouTube</p>
                <p class="hint">この一覧は {{ $channel->cached_since->timezone('Asia/Tokyo')->format('n月j日') }}に取り込み、{{ $channel->expires_at->timezone('Asia/Tokyo')->format('n月j日') }}までに削除されます（削除後に開くと全件を取り込み直します）</p>
            </div>
            <a class="ml-auto text-[13px] underline" href="https://www.youtube.com/channel/{{ $channel->channel_id }}" rel="noopener noreferrer" target="_blank">YouTube でチャンネルを開く</a>
        </header>

        @if ($reimported || $channel->truncated)
            <div class="space-y-1 text-[13px] text-ink-2">
                @if ($reimported)
                    <p>前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています</p>
                @endif
                @if ($channel->truncated)
                    <p>これより古い動画は動画 URL で追加してください</p>
                @endif
            </div>
        @endif

        <noscript>
            <p class="notice-danger text-sm">この画面の操作には JavaScript が必要です</p>
        </noscript>

        <div id="proposer" x-data="proposer" data-catalog="{{ json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" data-old="{{ json_encode(['title' => old('title', ''), 'description' => old('description', ''), 'comment' => old('comment', ''), 'proposerName' => old('proposer_name', ''), 'videoIds' => array_values(array_filter((array) old('video_ids', []), 'is_string'))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="flex flex-wrap items-start gap-5">
            <section aria-labelledby="pick-heading" class="card min-w-0 flex-[999_1_560px] overflow-hidden">
                <div class="flex flex-col gap-2.5 border-b border-line-subtle px-4 py-4 sm:px-5">
                    <h2 id="pick-heading" class="text-base font-bold">動画を選ぶ</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex min-h-11 min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-line-strong bg-raised px-3 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent">
                            <svg class="flex-none text-ink-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                            <span class="sr-only">タイトルで絞り込み（スペース区切りですべて含むもの）</span>
                            <input type="search" x-model="query" x-ref="filter" placeholder="タイトルで絞り込み（スペース区切り）" class="min-w-0 flex-1 bg-transparent py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none" autocomplete="off">
                        </label>
                        {{-- Disabled until a filter matches a video not selected yet (propose.js); the no-JS state is disabled. --}}
                        <button type="button" @click="selectAllFiltered" :disabled="isSelectFilteredDisabled" x-text="selectFilteredLabel" class="btn-sm min-h-11 border border-accent bg-accent-soft font-bold text-accent-hover" disabled>絞り込み結果をすべて選択</button>
                        <p class="select-filtered-status hint basis-full" role="status" aria-live="polite" x-text="selectFilteredStatus"></p>
                    </div>
                    <p class="hint" aria-live="polite">表示 <span x-text="shownCount"></span> 件</p>
                </div>

                <ul class="grid max-h-[40rem] grid-cols-[repeat(auto-fill,minmax(min(100%,17rem),1fr))] gap-2 overflow-y-auto p-2">
                    <template x-for="video in filtered" :key="video.id">
                        <li class="flex flex-col items-end rounded-lg border border-line-subtle bg-raised pr-2 pb-1.5 has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                            <label class="flex min-w-0 cursor-pointer items-center gap-2.5 self-stretch p-2">
                                <input type="checkbox" :checked="isSelected" @change="toggle" :disabled="isCheckDisabled" class="size-[18px] flex-none">
                                <template x-if="video.thumbnail_url">
                                    <img :src="video.thumbnail_url" loading="lazy" alt="" width="112" height="63" class="h-[63px] w-28 flex-none rounded-md object-cover">
                                </template>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="text-[13px] font-medium break-words text-ink-2" x-text="video.title"></span>
                                    <span class="hint" x-text="publishedLabel"></span>
                                </span>
                            </label>
                            <a class="shrink-0 text-xs underline" :href="watchHref" x-show="watchHref" rel="noopener noreferrer" target="_blank">YouTube で見る</a>
                        </li>
                    </template>
                </ul>
                <p class="hint border-t border-line-subtle px-5 py-2">データ提供: YouTube</p>
            </section>

            <aside class="flex min-w-0 flex-[1_1_320px] flex-col gap-4">
                <section aria-labelledby="selected-heading" class="card flex flex-col gap-3 px-4 py-4 sm:px-5">
                    <div class="flex items-baseline gap-2" aria-live="polite">
                        <h2 id="selected-heading" class="text-base font-bold">選択中</h2>
                        <span class="text-[22px] font-bold text-accent" x-text="selectedCount"></span>
                        <span class="text-[13px] text-ink-muted">/ <span x-text="maxItems"></span> 本</span>
                        <button type="button" class="ml-auto min-h-10 px-2 text-[13px] text-ink-muted underline" @click="clearAll">全解除</button>
                    </div>
                    <progress class="h-1.5 w-full appearance-none overflow-hidden rounded-full bg-muted [&::-moz-progress-bar]:bg-accent [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-accent" aria-hidden="true" max="1" value="0" :max="maxItems" :value="selectedCount"></progress>
                    <p class="text-sm text-danger" role="alert" x-show="hasLimitMessage" x-cloak x-text="limitMessage"></p>
                    <p class="text-sm text-danger" role="alert" x-show="maxItemsMissing" x-cloak>最大本数を読み込めなかったため、動画を選べません。ページを再読み込みしてください</p>
                    <p class="hint" x-show="!hasSelection" x-cloak>まだ動画が選ばれていません</p>
                    <p class="notice-warn text-xs" x-show="hasPlaceholders" x-cloak>一部の動画はタイトルを表示できませんが、選択は保たれています（保存時に YouTube から取得し直します）</p>
                    <ol class="counter-list space-y-1.5 text-[13px]">
                        <template x-for="item in selected" :key="item.id">
                            <li class="counter-item flex items-start gap-2 before:pt-2.5">
                                {{-- Submitted with the proposal form below (form attribute). --}}
                                <input type="hidden" name="video_ids[]" form="proposal-form" :value="item.id">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <template x-if="item.thumbnail_url">
                                            <img :src="item.thumbnail_url" loading="lazy" alt="" width="64" height="36" class="h-9 w-16 flex-none rounded object-cover">
                                        </template>
                                        <span class="min-w-0 flex-1 break-words text-ink-2" x-text="item.title"></span>
                                        <span class="flex flex-none">
                                            <button type="button" @click="moveUp" class="flex size-10 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="上へ"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg></button>
                                            <button type="button" @click="moveDown" class="flex size-10 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="下へ"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
                                            <button type="button" @click="remove" class="flex size-10 items-center justify-center rounded-md text-ink-muted hover:bg-muted" aria-label="削除"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
                                        </span>
                                    </div>
                                    <span class="pill mt-1 bg-muted text-ink-muted" x-show="item.outside">ほかのチャンネル</span>
                                    <p class="mt-1 text-xs text-danger" x-show="item.unavailable">この動画は削除または非公開になっています。外して保存してください</p>
                                </div>
                            </li>
                        </template>
                    </ol>

                    <section aria-labelledby="url-heading" class="flex flex-col gap-1.5 border-t border-line-subtle pt-3">
                        <h3 id="url-heading" class="hint">動画 URL で追加</h3>
                        <div class="flex gap-1.5">
                            <input type="url" x-model="urlInput" @keydown.enter.prevent="addByUrl" placeholder="https://www.youtube.com/watch?v=..." class="field min-h-10 min-w-0 flex-1 text-[13px]" aria-label="動画の URL" autocomplete="off">
                            <button type="button" @click="addByUrl" :disabled="urlBusy" class="btn-sm btn-secondary">追加</button>
                        </div>
                        <p class="text-sm text-danger" role="alert" x-show="hasUrlMessage" x-cloak x-text="urlMessage"></p>
                        <p class="hint">最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください</p>
                    </section>
                </section>

                <form id="proposal-form" method="POST" action="{{ route('proposals.store', $channel->channel_id) }}" @submit="onSubmit" class="card flex flex-col gap-3 px-4 py-4 sm:px-5">
                    @csrf
                    <h2 class="text-base font-bold">提案の内容</h2>

                    @if ($errors->any())
                        <ul class="notice-danger text-sm" role="alert">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- No maxlength: browsers count UTF-16 units (an emoji is 2). The counters count characters like the server. --}}
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">再生リスト名（必須）</span>
                        <input type="text" name="title" x-model="title" required class="field mt-1 min-h-10">
                        <span class="block text-xs" :class="titleCounterClass" x-text="titleCounter"></span>
                        <span class="block text-xs text-danger" x-show="titleOver" x-cloak>再生リスト名は 150 文字以内で入力してください</span>
                    </label>
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">再生リストの説明（任意）</span>
                        <textarea name="description" x-model="description" rows="2" class="field mt-1"></textarea>
                        <span class="block text-xs" :class="descriptionCounterClass" x-text="descriptionCounter"></span>
                        <span class="block text-xs text-danger" x-show="descriptionOver" x-cloak>再生リストの説明は 1000 文字以内で入力してください</span>
                    </label>
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">チャンネル主へのコメント（任意）</span>
                        <textarea name="comment" x-model="comment" rows="3" class="field mt-1"></textarea>
                        <span class="block text-xs" :class="commentCounterClass" x-text="commentCounter"></span>
                        <span class="block text-xs text-danger" x-show="commentOver" x-cloak>チャンネル主へのコメントは 500 文字以内で入力してください</span>
                    </label>
                    <label class="block text-[13px]">
                        <span class="block font-semibold text-ink-2">ニックネーム（任意）</span>
                        <input type="text" name="proposer_name" x-model="proposerName" class="field mt-1 min-h-10">
                        <span class="hint block">実名は書かないでください</span>
                        <span class="block text-xs" :class="proposerNameCounterClass" x-text="proposerNameCounter"></span>
                        <span class="block text-xs text-danger" x-show="proposerNameOver" x-cloak>ニックネームは 30 文字以内で入力してください</span>
                    </label>
                    <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <div class="space-y-2">
                        <p class="hint">保存すると、<a class="underline" href="{{ route('terms') }}" target="_blank" rel="noopener">利用規約</a> と <a class="underline" href="{{ route('privacy') }}" target="_blank" rel="noopener">プライバシーポリシー</a> に同意したものとみなされます</p>
                        <p class="text-sm text-danger" role="alert" x-show="anyOver" x-cloak>文字数の上限を超えている項目があるため保存できません</p>
                        <button type="submit" :disabled="isSubmitDisabled" class="btn btn-primary min-h-12 w-full text-[15px]">提案を保存する</button>
                    </div>
                </form>

                <div class="space-y-1">
                    @include('partials.max-items')
                    @include('partials.remaining-today', ['remaining' => $remaining, 'note' => '作成はチャンネル主が操作した時点の残りで決まります'])
                </div>
            </aside>
        </div>
    </div>
@endsection
