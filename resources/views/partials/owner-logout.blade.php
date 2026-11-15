{{-- Logout for a logged-in owner: revokes the Google grant too (spec 6.6). --}}
<form method="post" action="{{ route('owner.logout') }}" class="mt-2">
    @csrf
    <button type="submit" class="btn-sm btn-secondary">ログアウト（Google 側の承認も取り消します）</button>
</form>
