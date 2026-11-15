@extends('layouts.app')

@section('title', 'ログアウトしました')

{{-- Clears this tab's edit drafts (they may hold the owner's unlisted video ids). --}}
@push('vite')
    @vite('resources/js/owner-logged-out.js')
@endpush

@section('content')
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">ログアウトしました</h1>
        @if ($revokeFailed)
            <p class="notice-danger mt-3" role="alert">Google 側の承認の取り消しに失敗しました。下のリンクから取り消してください</p>
        @endif
        <p class="mt-3 text-ink-2">Google アカウントの <a class="underline" href="https://myaccount.google.com/permissions" rel="noopener noreferrer">サードパーティのアクセス（https://myaccount.google.com/permissions）</a>からいつでも承認を取り消せます</p>
        <p class="mt-5"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
    </section>
@endsection
