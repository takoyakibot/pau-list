{{-- Login flow and the unverified-app warning (spec 9.2; shown until Google's verification is completed). Text only.
     $scopeInDetails (optional): put the permission scope note inside the <details> too (S4's top card, D48). --}}
@php($scopeInDetails = $scopeInDetails ?? false)
<details class="mt-3 rounded-md border border-line-subtle bg-surface px-3 py-2">
    <summary id="login-flow-heading" class="min-h-6 font-semibold text-ink-2">{{ $scopeInDetails ? 'ログインの流れと権限（確認されていないアプリの警告について）' : 'ログインの流れ（確認されていないアプリの警告について）' }}</summary>
    <ol class="mt-2 list-decimal space-y-1 pl-6 text-ink-2">
        <li>Google のアカウント選択画面で、再生リストを作りたいチャンネルを選びます</li>
        <li>『このアプリは Google で確認されていません』と表示されたら、『詳細』を押し、『（アプリ名またはドメイン）（安全ではないページ）に移動』のようなリンクを選びます。Google の審査が完了するまでは、この警告が表示されます</li>
        <li>YouTube の権限の確認画面で内容を確認し、『続行』を押します。権限の確認画面にチェックボックスが表示された場合は、YouTube の権限にチェックを入れてください（入れないと再生リストを作成できません）</li>
    </ol>
    @if ($scopeInDetails)
        @include('partials.scope-note')
    @endif
</details>
@unless ($scopeInDetails)
    @include('partials.scope-note')
@endunless
