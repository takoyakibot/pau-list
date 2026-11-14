@extends('layouts.app')

@section('title', $creation->title.' — 再生リストの作成')

@push('vite')
    @vite('resources/js/creation-runner.js')
@endpush

@php
    $processed = $progress['done'] + $progress['failed'] + $progress['skipped'];
    $completed = $progress['status'] === \App\Models\PlaylistCreation::COMPLETED;
@endphp

@section('content')
    <p class="text-sm">ログイン中: {{ $ownerChannelTitle }}</p>
    @include('partials.owner-logout')
    <h1 class="mt-2 text-2xl font-bold">再生リストの作成</h1>
    @if (session()->has('creation_notice'))
        <p class="rounded bg-amber-50 p-2 text-sm text-amber-900" role="alert">{{ session('creation_notice') }}</p>
    @endif
    <p class="mt-1 text-lg break-words">{{ $creation->title }}</p>

    <noscript>
        <p class="mt-6 rounded border border-red-300 bg-red-50 p-3 text-sm">作成を進める・止めるには JavaScript が必要です</p>
    </noscript>

    {{-- The server renders the current state (readable without JS); creation-runner.js keeps it up to date.
         Every URL in this page is relative to /p/{key}/creation: the raw key is never written here. --}}
    <div id="creation-runner" x-data="creationRunner" data-runner="{{ json_encode($runner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" class="mt-6 space-y-6">
        <section class="space-y-2" aria-label="進捗">
            <progress class="w-full" aria-label="作成の進み具合" max="{{ $progress['total'] }}" value="{{ $processed }}" :max="totalCount" :value="processedCount"></progress>
            <p class="text-sm" x-text="progressLabel">{{ \App\Services\Creation\CreationProgress::progressText($progress) }}</p>
            <p class="rounded bg-gray-50 p-2 text-sm font-semibold" role="status" x-show="hasStatusText" x-text="statusText" @if (($progress['message'] ?? null) === null) x-cloak @endif>{{ $progress['message'] }}</p>
            <p class="text-sm" x-show="needsRelogin" x-cloak><a class="font-semibold underline" :href="reloginHref">もう一度ログインする</a></p>
            <p class="text-sm" x-show="hasPlaylist" @if ($progress['playlist_url'] === null) x-cloak @endif>
                <a class="underline" :href="playlistUrl" @if ($progress['playlist_url'] !== null) href="{{ $progress['playlist_url'] }}" @endif rel="noopener noreferrer" target="_blank">作成した再生リストを YouTube で開く</a>
            </p>
        </section>

        <section class="space-y-2" x-cloak>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded border border-gray-400 px-3 py-1 text-sm" x-show="showPause" :disabled="busy" @click="onPause">一時停止</button>
                <button type="button" class="rounded bg-gray-900 px-3 py-1 text-sm text-white disabled:opacity-50" x-show="showResume" :disabled="busy" @click="onResume">続きから</button>
                {{-- Two clicks (UX m6): the first arms it and shows the warning, the second sends it. --}}
                <button type="button" class="rounded border border-red-700 px-3 py-1 text-sm text-red-700" x-show="showAbort" :disabled="busy" @click="onAbort" x-text="abortLabel">残りを中止</button>
                <button type="button" class="rounded border border-gray-400 px-3 py-1 text-sm" x-show="showAbortWarning" :disabled="busy" @click="onAbortCancel">中止しない</button>
                <button type="button" class="rounded bg-gray-900 px-3 py-1 text-sm text-white disabled:opacity-50" x-show="showRetry" :disabled="busy" @click="onRetry" x-text="retryLabel">失敗分を再試行（必要な枠: 動画 {{ $progress['failed'] }} 本）</button>
                <button type="button" class="rounded bg-gray-900 px-3 py-1 text-sm text-white disabled:opacity-50" x-show="showRecreate" :disabled="busy" @click="onRecreate">見つからなかったので作り直す</button>
            </div>
            <p class="text-sm text-red-700" role="alert" x-show="showAbortWarning">{{ \App\Services\Creation\CreationProgress::ABORT_WARNING }}</p>
            <p class="text-sm text-red-700" role="alert" x-show="hasActionMessage" x-text="actionMessage"></p>
        </section>

        <p class="text-sm" x-text="remainingToday">{{ $progress['remaining_today'] }}</p>

        <section aria-labelledby="creation-items-heading" class="space-y-2">
            <h2 id="creation-items-heading" class="text-lg font-semibold">動画（{{ $progress['total'] }} 本）</h2>
            {{-- Initial list (also the no-JS view); the live list below replaces it once the script runs. --}}
            <ol class="space-y-1" x-show="showInitialList">
                @foreach ($progress['items'] as $item)
                    <li class="rounded border border-gray-200 p-2 text-sm">
                        <span class="text-gray-600">{{ $item['position'] + 1 }}.</span>
                        <span class="break-words">{{ $titles[$item['position']] ?? '' }}</span>
                        <span class="ml-2 text-xs font-semibold">{{ \App\Services\Creation\CreationProgress::ITEM_LABELS[$item['status']] ?? '' }}</span>
                        @if ($item['error'] !== null)
                            <span class="block text-xs text-red-700">{{ $item['error'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
            <ol class="space-y-1" x-show="showLiveList" x-cloak>
                <template x-for="row in rows" :key="row.position">
                    <li class="rounded border border-gray-200 p-2 text-sm">
                        <span class="text-gray-600" x-text="row.number"></span><span class="text-gray-600">.</span>
                        <span class="break-words" x-text="row.title"></span>
                        <span class="ml-2 text-xs font-semibold" x-text="row.label"></span>
                        <span class="block text-xs text-red-700" x-show="row.error" x-text="row.error"></span>
                    </li>
                </template>
            </ol>
        </section>

        {{-- Deleting the proposal is offered once the creation is completed (U14). Relative: /p/{key}/delete. --}}
        <section class="border-t border-gray-200 pt-4 text-sm" x-show="isCompleted" @unless ($completed) x-cloak @endunless>
            <p><a class="text-red-700 underline" href="delete">この提案を削除</a></p>
            <p class="mt-1 text-xs text-gray-600">提案と作成の記録を削除します。YouTube に作成した再生リストは残ります</p>
            {{-- Not created (or unknown): this proposal cannot be created again; deleting it is the way to start over (UX m5). --}}
            <p class="mt-2" x-show="showRemakeHint" @unless ($completed && ! $progress['playlist_created']) x-cloak @endunless>{{ \App\Services\Creation\CreationProgress::REMAKE_HINT }}</p>
        </section>
    </div>

    <section class="mt-8 space-y-2 text-xs text-gray-600">
        <p>Google アカウントの <a class="underline" href="https://myaccount.google.com/permissions" rel="noopener noreferrer">サードパーティのアクセス（https://myaccount.google.com/permissions）</a>からいつでも承認を取り消せます</p>
        <p>データ提供: YouTube</p>
    </section>
@endsection
