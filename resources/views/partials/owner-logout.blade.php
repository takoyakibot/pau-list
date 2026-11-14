{{-- Logout for a logged-in owner: revokes the Google grant too (spec 6.6). --}}
<form method="post" action="{{ route('owner.logout') }}" class="mt-2">
    @csrf
    <button type="submit" class="rounded border border-gray-400 px-3 py-1 text-sm">ログアウト（Google 側の承認も取り消します）</button>
</form>
