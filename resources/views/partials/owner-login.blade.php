{{-- S4: the channel owner's call to action, placed right under the proposal title.
     Needs $key, $ownerMatches, $ownerChannelTitle, $creationStatus.
     $compact (optional): only the primary action (+ the consent line next to a login button) for a repeat below a long list. --}}
@php($compact = $compact ?? false)
@php($loginLabel = $ownerChannelTitle !== null ? '別のチャンネルでログインし直す' : ($compact ? 'チャンネル主の方は Google でログイン' : 'Google でログインして作成する'))

@if ($compact)
    <div class="text-sm" data-owner-cta="bottom">
        @if ($ownerMatches)
            @if ($creationStatus !== null)
                <a class="btn btn-primary" href="{{ route('proposals.creation', $key) }}">作成状況を見る</a>
            @else
                <a class="btn btn-primary" href="{{ route('proposals.edit', $key) }}">編集画面へ進む</a>
            @endif
        @else
            <a class="btn btn-primary" href="{{ route('owner.login', ['p' => $key]) }}">{{ $loginLabel }}</a>
            <p class="hint mt-2"><a class="underline" href="{{ route('terms') }}">利用規約</a>・<a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a>に同意のうえログインしてください（<a class="underline" href="{{ route('privacy') }}#data">権限について</a>）</p>
        @endif
    </div>
@else
    <section class="rounded-lg border-2 border-accent bg-raised px-4 py-4 text-sm sm:px-5" aria-labelledby="owner-heading" data-owner-cta="top">
        <h2 id="owner-heading" class="text-base font-bold">チャンネル主の方へ</h2>

        @if (session()->has('owner_error'))
            <p class="notice-danger mt-2" role="alert">{{ session('owner_error') }}</p>
        @endif
        @if (session()->has('owner_notice'))
            <p class="notice-warn mt-2" role="alert">{{ session('owner_notice') }}</p>
        @endif

        @if ($ownerMatches)
            <p class="mt-2">ログイン中: {{ $ownerChannelTitle }}</p>
            @if ($creationStatus !== null)
                <p class="mt-3"><a class="btn btn-primary" href="{{ route('proposals.creation', $key) }}">作成状況を見る</a></p>
            @else
                <p class="mt-3"><a class="btn btn-primary" href="{{ route('proposals.edit', $key) }}">編集画面へ進む</a></p>
            @endif
            @include('partials.owner-logout')
        @else
            <p class="mt-1 text-ink-2">あなたのチャンネルに再生リストを作成できます</p>
            @if ($ownerChannelTitle !== null)
                <p class="mt-2">ログイン中: {{ $ownerChannelTitle }}（この提案の対象チャンネルではありません）</p>
                @include('partials.owner-logout')
            @endif
            <p class="mt-3">
                <a class="btn btn-primary" href="{{ route('owner.login', ['p' => $key]) }}">{{ $loginLabel }}</a>
            </p>
            <p class="hint mt-2"><a class="underline" href="{{ route('terms') }}">利用規約</a>・<a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a>に同意のうえログインしてください</p>
            @include('partials.unverified-app', ['scopeInDetails' => true])
        @endif
    </section>
@endif
