@extends('layouts.app')

@section('title', '削除しました')

{{-- Clears this tab's edit draft of the deleted proposal (it may hold the owner's unlisted video ids). --}}
@push('vite')
    @vite('resources/js/owner-deleted.js')
@endpush

@section('content')
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">削除しました</h1>
        <p class="mt-3 text-ink-2">提案を削除しました。共有された URL でも表示できなくなりました</p>
        @if ($draftId !== null)
            <div id="deleted-proposal" data-draft-id="{{ $draftId }}" hidden></div>
        @endif
        <p class="mt-5"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
    </section>
@endsection
