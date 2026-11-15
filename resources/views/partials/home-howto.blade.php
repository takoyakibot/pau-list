{{--
    Home「List Maker のしくみ」: four explainer slides in a CSS scroll-snap track (swipe/scroll works without JS).
    resources/js/home-carousel.js (howtoCarousel) syncs the dots and scrolls on arrow/dot clicks.
    The small UI pictures inside the slides are decorative (aria-hidden, no focusable elements).
    Needs $maxItems and $retentionDays.
--}}
@php
    $slideClass = 'relative flex w-full flex-none snap-start snap-always flex-col gap-6 p-5 pb-10 sm:p-8 sm:pb-10 md:px-10';
    $bulletDot = 'size-2 flex-none rounded-full bg-accent';
    $stepBadge = 'flex size-10 flex-none items-center justify-center rounded-full bg-accent text-lg font-bold text-on-accent md:size-12 md:text-2xl';
    $slideHeading = 'text-2xl leading-snug font-bold md:text-[32px]';
    $mockPanel = 'flex flex-col gap-2.5 rounded-xl border border-line bg-surface p-4';
    $counter = 'absolute right-4 bottom-3 text-xs text-ink-muted';
    $rows = [
        ['【コラボ】はじめての合同配信', true, 'bg-indigo-200'],
        ['【コラボ】夏休み耐久ゲーム実況', true, 'bg-purple-200'],
        ['【コラボ】深夜の雑談リレー', false, 'bg-pink-200'],
        ['【コラボ】クイズ大会 第2回', true, 'bg-green-200'],
        ['【コラボ】料理対決してみた', false, 'bg-sky-200'],
    ];
@endphp
<section aria-roledescription="carousel" aria-label="List Maker のしくみ" x-data="howtoCarousel" class="flex flex-col gap-2">
    <div x-ref="track" tabindex="0"
        class="flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain rounded-xl border border-line bg-raised data-enhanced:[scrollbar-width:none] data-enhanced:[&::-webkit-scrollbar]:hidden">

        {{-- 1. Overview --}}
        <div role="group" aria-roledescription="slide" aria-label="1 / 4" class="{{ $slideClass }}">
            <div class="flex flex-col gap-2">
                <p class="text-xs font-bold tracking-widest text-accent md:text-sm">HOW IT WORKS</p>
                <h2 class="text-2xl leading-snug font-bold md:text-4xl">ファンが選んで、チャンネル主が作る。</h2>
                <p class="text-ink-muted md:text-lg">再生リストの「案」を URL ひとつで届けるツールです。</p>
            </div>
            <ol class="flex flex-1 flex-col gap-1 md:flex-row md:items-center md:justify-between md:gap-2">
                <li class="flex items-center gap-3 md:w-40 md:flex-none md:flex-col md:gap-2 md:text-center">
                    <span class="flex size-12 flex-none items-center justify-center rounded-full bg-accent-soft text-accent md:size-24" aria-hidden="true">
                        <svg class="size-6 md:size-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                    </span>
                    <span class="flex flex-col md:items-center">
                        <span class="text-base font-bold md:text-lg">提案する人</span>
                        <span class="text-xs text-ink-muted md:text-sm">ログイン不要</span>
                    </span>
                </li>
                <li class="flex items-center gap-2 py-1 pl-4 md:flex-1 md:flex-col md:p-0">
                    <svg class="h-5 w-4 flex-none text-ink-muted md:hidden" viewBox="0 0 16 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v15M3 12l5 5 5-5"/></svg>
                    <span class="rounded-full bg-muted px-3 py-1 text-xs font-bold text-ink-2 md:text-sm">提案の URL</span>
                    <svg class="hidden h-5 w-full max-w-36 text-ink-muted md:block" viewBox="0 0 144 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" preserveAspectRatio="none" aria-hidden="true"><path d="M4 10h132M128 3l8 7-8 7"/></svg>
                </li>
                <li class="flex items-center gap-3 md:w-40 md:flex-none md:flex-col md:gap-2 md:text-center">
                    <span class="flex size-12 flex-none items-center justify-center rounded-full bg-members-soft text-members md:size-24" aria-hidden="true">
                        <svg class="size-6 md:size-11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="3"/><path d="M10 10l4 2.5-4 2.5z" fill="currentColor"/><path d="M8 3l4 3 4-3"/></svg>
                    </span>
                    <span class="flex flex-col md:items-center">
                        <span class="text-base font-bold md:text-lg">チャンネル主</span>
                        <span class="text-xs text-ink-muted md:text-sm">Google でログイン</span>
                    </span>
                </li>
                <li class="flex items-center gap-2 py-1 pl-4 md:flex-1 md:flex-col md:p-0">
                    <svg class="h-5 w-4 flex-none text-ink-muted md:hidden" viewBox="0 0 16 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v15M3 12l5 5 5-5"/></svg>
                    <span class="rounded-full bg-muted px-3 py-1 text-xs font-bold text-ink-2 md:text-sm">確認して作成</span>
                    <svg class="hidden h-5 w-full max-w-36 text-ink-muted md:block" viewBox="0 0 144 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" preserveAspectRatio="none" aria-hidden="true"><path d="M4 10h132M128 3l8 7-8 7"/></svg>
                </li>
                <li class="flex items-center gap-3 md:w-40 md:flex-none md:flex-col md:gap-2 md:text-center">
                    <span class="flex size-12 flex-none items-center justify-center rounded-xl bg-accent text-on-accent md:size-24 md:rounded-3xl" aria-hidden="true">
                        <svg class="size-6 md:size-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h11M4 12h11M4 18h7"/><path d="M17 15v6l4-3z" fill="currentColor"/></svg>
                    </span>
                    <span class="flex flex-col md:items-center">
                        <span class="text-base font-bold md:text-lg">再生リスト</span>
                        <span class="text-xs text-ink-muted md:text-sm">チャンネルにできあがり</span>
                    </span>
                </li>
            </ol>
            <span class="{{ $counter }}" aria-hidden="true">1 / 4</span>
        </div>

        {{-- 2. Pick videos --}}
        <div role="group" aria-roledescription="slide" aria-label="2 / 4" class="{{ $slideClass }} md:flex-row md:gap-8">
            <div class="flex min-w-0 flex-1 flex-col gap-3 md:gap-4">
                <span class="{{ $stepBadge }}" aria-hidden="true">1</span>
                <h2 class="{{ $slideHeading }}">動画を選ぶ</h2>
                <p class="text-ink-2 md:text-base md:leading-relaxed">チャンネルの公開動画が一覧で出ます。チェックした動画は、絞り込みを変えても外れません。</p>
                <ul class="flex flex-col gap-2 md:mt-auto md:text-[15px]">
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>ログインは不要</li>
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>ほかのチャンネルの動画も URL で追加</li>
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>最大 {{ $maxItems }} 本まで</li>
                </ul>
            </div>
            <div class="{{ $mockPanel }} md:w-[340px] md:flex-none" aria-hidden="true">
                <div class="flex h-9 items-center gap-2 rounded-md border border-line-strong bg-raised px-3 text-[13px]">
                    <svg class="size-4 flex-none text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>コラボ
                </div>
                @foreach ($rows as $i => [$title, $on, $thumb])
                    <div @class(['items-center gap-2.5 rounded-lg border bg-raised p-1.5', 'flex' => $i < 3, 'hidden md:flex' => $i >= 3, 'border-accent' => $on, 'border-line-subtle' => ! $on])>
                        <span @class(['flex size-5 flex-none items-center justify-center rounded border-2', 'border-accent bg-accent text-on-accent' => $on, 'border-line-strong bg-raised' => ! $on])>
                            @if ($on)
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>
                            @endif
                        </span>
                        <span class="h-8 w-14 flex-none rounded-md {{ $thumb }}"></span>
                        <span class="min-w-0 truncate text-xs text-ink-2">{{ $title }}</span>
                    </div>
                @endforeach
                <div class="mt-auto flex items-center justify-between text-[13px]">
                    <span class="text-ink-muted">選択中</span>
                    <span class="text-xl font-bold text-accent">3 <span class="text-xs font-normal text-ink-muted">/ {{ $maxItems }} 本</span></span>
                </div>
            </div>
            <span class="{{ $counter }}" aria-hidden="true">2 / 4</span>
        </div>

        {{-- 3. Send the URL --}}
        <div role="group" aria-roledescription="slide" aria-label="3 / 4" class="{{ $slideClass }} md:flex-row md:gap-8">
            <div class="flex min-w-0 flex-1 flex-col gap-3 md:gap-4">
                <span class="{{ $stepBadge }}" aria-hidden="true">2</span>
                <h2 class="{{ $slideHeading }}">URL を送る</h2>
                <p class="text-ink-2 md:text-base md:leading-relaxed">名前とひとことを付けて保存すると、提案の URL ができます。DM やチャットでチャンネル主に送りましょう。</p>
                <ul class="flex flex-col gap-2 md:mt-auto md:text-[15px]">
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>URL を知っている人だけが開けます</li>
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>作成から {{ $retentionDays }} 日で自動削除</li>
                </ul>
            </div>
            <div class="flex flex-col justify-center gap-3 md:w-[340px] md:flex-none" aria-hidden="true">
                <div class="{{ $mockPanel }}">
                    <span class="text-xs text-ink-muted">提案の URL</span>
                    <div class="flex gap-2">
                        <span class="flex h-9 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-line-strong bg-raised px-2.5 text-xs whitespace-nowrap text-ink-2">{{ request()->getHost() }}/p/••••••</span>
                        <span class="flex h-9 flex-none items-center rounded-md bg-accent px-3 text-[13px] font-bold text-on-accent">コピー</span>
                    </div>
                </div>
                <svg class="mx-auto hidden h-10 w-6 text-ink-muted md:block" viewBox="0 0 24 40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v30M5 27l7 7 7-7"/></svg>
                <p class="max-w-[280px] self-end rounded-2xl rounded-br-sm bg-accent-soft px-4 py-3 text-[13px] leading-relaxed text-ink-2">コラボ回をまとめた再生リストの案を作りました！ よかったら見てください。<br><span class="text-accent underline">{{ request()->getHost() }}/p/••••</span></p>
            </div>
            <span class="{{ $counter }}" aria-hidden="true">3 / 4</span>
        </div>

        {{-- 4. The owner creates it --}}
        <div role="group" aria-roledescription="slide" aria-label="4 / 4" class="{{ $slideClass }} md:flex-row md:gap-8">
            <div class="flex min-w-0 flex-1 flex-col gap-3 md:gap-4">
                <span class="{{ $stepBadge }}" aria-hidden="true">3</span>
                <h2 class="{{ $slideHeading }}">チャンネル主が作る</h2>
                <p class="text-ink-2 md:text-base md:leading-relaxed">URL を開いて Google でログイン。動画の入れ替えや名前の修正をして、公開範囲を選んで作成します。</p>
                <ul class="flex flex-col gap-2 md:mt-auto md:text-[15px]">
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>自分の限定公開動画も追加できます</li>
                    <li class="flex items-center gap-2"><span class="{{ $bulletDot }}" aria-hidden="true"></span>作られるのはチャンネル主のチャンネル</li>
                </ul>
            </div>
            <div class="flex flex-col justify-center gap-3 md:w-[340px] md:flex-none" aria-hidden="true">
                <div class="{{ $mockPanel }}">
                    <span class="text-xs text-ink-muted">公開範囲</span>
                    <div class="flex gap-2 text-[13px]">
                        <span class="flex h-9 flex-1 items-center justify-center rounded-lg border border-line-strong bg-raised">非公開</span>
                        <span class="flex h-9 flex-1 items-center justify-center rounded-lg border-2 border-accent bg-accent-soft font-bold text-accent">限定公開</span>
                        <span class="flex h-9 flex-1 items-center justify-center rounded-lg border border-line-strong bg-raised">公開</span>
                    </div>
                    <span class="flex h-10 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent">再生リストを作成</span>
                </div>
                <div class="flex flex-col gap-2 rounded-xl border border-line bg-raised px-4 py-3">
                    <div class="flex items-baseline gap-1.5"><span class="text-2xl font-bold text-accent">20</span><span class="text-[13px] text-ink-muted">/ 20 本 追加しました</span></div>
                    <div class="h-2 rounded-full bg-accent"></div>
                    <span class="text-xs text-accent underline">YouTube で再生リストを開く</span>
                </div>
            </div>
            <span class="{{ $counter }}" aria-hidden="true">4 / 4</span>
        </div>
    </div>

    {{-- Hidden until Alpine starts: without JS the slides are still swipeable/scrollable. --}}
    <div class="min-h-11">
        <div x-cloak class="flex items-center justify-center gap-1">
            <button type="button" @click="prev" aria-label="前へ" class="flex size-11 items-center justify-center rounded-full text-ink-2 hover:bg-muted">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
            </button>
            <div x-ref="dots" class="flex items-center">
                @foreach (range(1, 4) as $n)
                    <button type="button" @click="goTo" data-index="{{ $n - 1 }}" aria-label="スライド {{ $n }}" @if ($n === 1) aria-current="true" @endif class="group flex size-11 items-center justify-center">
                        <span class="size-2.5 rounded-full bg-line-strong transition-[width,background-color] group-hover:bg-ink-muted group-aria-[current=true]:w-6 group-aria-[current=true]:bg-accent motion-reduce:transition-none" aria-hidden="true"></span>
                    </button>
                @endforeach
            </div>
            <button type="button" @click="next" aria-label="次へ" class="flex size-11 items-center justify-center rounded-full text-ink-2 hover:bg-muted">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </button>
        </div>
    </div>
</section>
