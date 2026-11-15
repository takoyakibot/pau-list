{{-- S4: the channel owner's login area. Needs $key, $ownerMatches, $ownerChannelTitle, $creationStatus. --}}
<section class="card px-4 py-4 text-sm sm:px-5" aria-labelledby="owner-heading">
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
        @if ($ownerChannelTitle !== null)
            <p class="mt-2">ログイン中: {{ $ownerChannelTitle }}（この提案の対象チャンネルではありません）</p>
            @include('partials.owner-logout')
        @endif
        <p class="mt-3">
            <a class="btn btn-primary" href="{{ route('owner.login', ['p' => $key]) }}">{{ $ownerChannelTitle !== null ? '別のチャンネルでログインし直す' : 'チャンネル主の方: Google でログインして作成へ' }}</a>
        </p>
        <p class="hint mt-2"><a class="underline" href="{{ route('terms') }}">利用規約</a>・<a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a>に同意のうえログインしてください</p>
        @include('partials.unverified-app')
    @endif
</section>
