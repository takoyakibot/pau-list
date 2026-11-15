@extends('layouts.app')

@section('title', $creation->title.' — 再生リストの作成')

@push('vite')
    @vite('resources/js/creation-runner.js')
@endpush

@php
    $processed = $progress['done'] + $progress['failed'] + $progress['skipped'];
    $completed = $progress['status'] === \App\Models\PlaylistCreation::COMPLETED;
    $privacyLabel = ['private' => '非公開', 'unlisted' => '限定公開'][$creation->privacy_status] ?? null;
    // Item status pills: the colour follows data-status (server-rendered here, bound to row.status by Alpine).
    $statusPill = 'pill shrink-0 bg-muted text-ink-muted data-[status=done]:bg-success-soft data-[status=done]:text-success data-[status=in_flight]:bg-accent-soft data-[status=in_flight]:text-accent-hover data-[status=failed]:bg-danger-soft data-[status=failed]:text-danger';
@endphp

@section('content')
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-[13px] text-ink-muted">
            <p>ログイン中: {{ $ownerChannelTitle }}</p>
            @include('partials.owner-logout')
        </div>

        @if (session()->has('creation_notice'))
            <p class="notice-warn text-sm" role="alert">{{ session('creation_notice') }}</p>
        @endif

        <noscript>
            <p class="notice-danger text-sm">作成を進める・止めるには JavaScript が必要です</p>
        </noscript>

        {{-- The server renders the current state (readable without JS); creation-runner.js keeps it up to date.
             Every URL in this page is relative to /p/{key}/creation: the raw key is never written here. --}}
        <div id="creation-runner" x-data="creationRunner" data-runner="{{ json_encode($runner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="flex flex-col gap-4">
            <section class="card flex flex-col gap-4 p-5 sm:p-6" aria-label="進捗">
                <div>
                    <h1 class="text-xs font-normal text-ink-muted">再生リストの作成</h1>
                    <div class="mt-1 flex flex-wrap items-center gap-2.5">
                        <p class="text-[22px] font-bold break-words">{{ $creation->title }}</p>
                        @if (in_array($creation->privacy_status, ['private', 'unlisted', 'public'], true))
                        <span class="pill bg-muted text-ink-2">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                @if ($creation->privacy_status === 'private')
                                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                                @elseif ($creation->privacy_status === 'unlisted')
                                    <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>
                                @else
                                    <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>
                                @endif
                            </svg>{{ $privacyLabel ?? '公開' }}
                        </span>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="flex items-baseline gap-2" aria-hidden="true">
                        <span class="text-[28px] font-bold text-accent" x-text="processedCount">{{ $processed }}</span>
                        <span class="text-[15px] text-ink-muted">/ <span x-text="totalCount">{{ $progress['total'] }}</span> 本</span>
                    </p>
                    <progress class="h-2.5 w-full appearance-none overflow-hidden rounded-full bg-muted [&::-moz-progress-bar]:bg-accent [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-accent" aria-label="作成の進み具合" max="{{ $progress['total'] }}" value="{{ $processed }}" :max="totalCount" :value="processedCount"></progress>
                    <p class="text-[13px] text-ink-muted" x-text="progressLabel">{{ \App\Services\Creation\CreationProgress::progressText($progress) }}</p>
                </div>

                <p class="rounded-md bg-surface px-3 py-2 text-sm font-semibold" role="status" x-show="hasStatusText" x-text="statusText" @if (($progress['message'] ?? null) === null) x-cloak @endif>{{ $progress['message'] }}</p>
                <p class="text-sm" x-show="needsRelogin" x-cloak><a class="font-semibold underline" :href="reloginHref">もう一度ログインする</a></p>
                <p class="text-sm" x-show="hasPlaylist" @if ($progress['playlist_url'] === null) x-cloak @endif>
                    <a class="font-semibold underline" :href="playlistUrl" @if ($progress['playlist_url'] !== null) href="{{ $progress['playlist_url'] }}" @endif rel="noopener noreferrer" target="_blank">作成した再生リストを YouTube で開く</a>
                </p>

                <div class="flex flex-col gap-2" x-cloak>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary" x-show="showPause" :disabled="busy" @click="onPause">一時停止</button>
                        <button type="button" class="btn btn-primary" x-show="showResume" :disabled="busy" @click="onResume">続きから</button>
                        {{-- Two clicks (UX m6): the first arms it and shows the warning, the second sends it. --}}
                        <button type="button" class="btn border border-danger bg-raised text-danger" x-show="showAbort" :disabled="busy" @click="onAbort" x-text="abortLabel">残りを中止</button>
                        <button type="button" class="btn btn-secondary" x-show="showAbortWarning" :disabled="busy" @click="onAbortCancel">中止しない</button>
                        <button type="button" class="btn btn-primary" x-show="showRetry" :disabled="busy" @click="onRetry" x-text="retryLabel">失敗分を再試行（必要な枠: 動画 {{ $progress['failed'] }} 本）</button>
                        <button type="button" class="btn btn-primary" x-show="showRecreate" :disabled="busy" @click="onRecreate">見つからなかったので作り直す</button>
                    </div>
                    <p class="text-sm text-danger" role="alert" x-show="showAbortWarning">{{ \App\Services\Creation\CreationProgress::ABORT_WARNING }}</p>
                    <p class="text-sm text-danger" role="alert" x-show="hasActionMessage" x-text="actionMessage"></p>
                </div>

                <p class="hint" x-text="remainingToday">{{ $progress['remaining_today'] }}</p>
            </section>

            <section aria-labelledby="creation-items-heading" class="card overflow-hidden">
                <h2 id="creation-items-heading" class="border-b border-line-subtle px-4 py-3 text-base font-bold sm:px-5">動画（{{ $progress['total'] }} 本）</h2>
                {{-- Initial list (also the no-JS view); the live list below replaces it once the script runs. --}}
                <ol x-show="showInitialList">
                    @foreach ($progress['items'] as $item)
                        <li class="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-b border-line-subtle px-4 py-2.5 text-[13px] last:border-b-0 sm:px-5">
                            <span class="w-6 shrink-0 text-right text-ink-muted">{{ $item['position'] + 1 }}.</span>
                            <span class="min-w-0 flex-1 break-words text-ink-2">{{ $titles[$item['position']] ?? '' }}</span>
                            <span class="{{ $statusPill }}" data-status="{{ $item['status'] }}">{{ \App\Services\Creation\CreationProgress::ITEM_LABELS[$item['status']] ?? '' }}</span>
                            @if ($item['error'] !== null)
                                <span class="basis-full pl-8 text-xs text-danger">{{ $item['error'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <ol x-show="showLiveList" x-cloak>
                    <template x-for="row in rows" :key="row.position">
                        <li class="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-b border-line-subtle px-4 py-2.5 text-[13px] last:border-b-0 sm:px-5">
                            <span class="w-6 shrink-0 text-right text-ink-muted"><span x-text="row.number"></span>.</span>
                            <span class="min-w-0 flex-1 break-words text-ink-2" x-text="row.title"></span>
                            <span class="{{ $statusPill }}" :data-status="row.status" x-text="row.label"></span>
                            <span class="basis-full pl-8 text-xs text-danger" x-show="row.error" x-text="row.error"></span>
                        </li>
                    </template>
                </ol>
            </section>

            <section class="flex items-start gap-2.5 rounded-lg border border-dashed border-line-strong bg-raised px-4 py-3.5 text-[13px] text-ink-2">
                <svg class="mt-0.5 flex-none text-ink-muted" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>
                <div class="space-y-1">
                    {{-- Always shown (static): reads right before and after the playlist exists. Nothing for public. --}}
                    @if ($privacyLabel !== null)
                        <p>この再生リストは「{{ $privacyLabel }}」で作成されます。公開範囲は、作成後に YouTube Studio の「再生リスト」で変更できます。</p>
                    @endif
                    <p>Google アカウントの <a class="underline" href="https://myaccount.google.com/permissions" rel="noopener noreferrer">サードパーティのアクセス（https://myaccount.google.com/permissions）</a>からいつでも承認を取り消せます</p>
                    <p class="hint">データ提供: YouTube</p>
                </div>
            </section>

            {{-- Deleting the proposal is offered once the creation is completed (U14). Relative: /p/{key}/delete. --}}
            <section class="text-sm" x-show="isCompleted" @unless ($completed) x-cloak @endunless>
                <p><a class="text-danger underline" href="delete">この提案を削除</a> <span class="hint">（YouTube に作成した再生リストは残ります）</span></p>
                {{-- Not created (or unknown): this proposal cannot be created again; deleting it is the way to start over (UX m5). --}}
                <p class="mt-2" x-show="showRemakeHint" @unless ($completed && ! $progress['playlist_created']) x-cloak @endunless>{{ \App\Services\Creation\CreationProgress::REMAKE_HINT }}</p>
            </section>
        </div>
    </div>
@endsection
