{{-- S4: the channel owner's login area. Needs $key, $ownerMatches, $ownerChannelTitle, $creationStatus. --}}
<section class="mt-6 rounded border border-gray-200 p-4 text-sm" aria-labelledby="owner-heading">
    <h2 id="owner-heading" class="font-semibold">チャンネル主の方へ</h2>

    @if (session()->has('owner_error'))
        <p class="mt-2 rounded bg-red-50 p-2 text-red-900" role="alert">{{ session('owner_error') }}</p>
    @endif
    @if (session()->has('owner_notice'))
        <p class="mt-2 rounded bg-amber-50 p-2 text-amber-900" role="alert">{{ session('owner_notice') }}</p>
    @endif

    @if ($ownerMatches)
        <p class="mt-2">ログイン中: {{ $ownerChannelTitle }}</p>
        @if ($creationStatus !== null)
            <p class="mt-2"><a class="font-semibold underline" href="{{ route('proposals.creation', $key) }}">作成状況を見る</a></p>
        @else
            <p class="mt-2"><a class="font-semibold underline" href="{{ route('proposals.edit', $key) }}">編集画面へ進む</a></p>
        @endif
        @include('partials.owner-logout')
    @else
        @if ($ownerChannelTitle !== null)
            <p class="mt-2">ログイン中: {{ $ownerChannelTitle }}（この提案の対象チャンネルではありません）</p>
            @include('partials.owner-logout')
        @endif
        <p class="mt-2">
            <a class="font-semibold underline" href="{{ route('owner.login', ['p' => $key]) }}">{{ $ownerChannelTitle !== null ? '別のチャンネルでログインし直す' : 'チャンネル主の方: Google でログインして作成へ' }}</a>
        </p>
        <p class="mt-2"><a class="underline" href="{{ route('terms') }}">利用規約</a>・<a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a>に同意のうえログインしてください</p>
        <p class="mt-2">確認されていないアプリの警告が表示されます。「詳細」→「List Maker（安全ではないページ）に移動」で続行できます</p>
        <p class="mt-2">YouTube の管理権限を求めますが、使うのはチャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加だけです</p>
    @endif
</section>
