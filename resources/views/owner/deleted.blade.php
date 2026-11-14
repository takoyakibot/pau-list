@extends('layouts.app')

@section('title', '削除しました')

{{-- Clears this tab's edit draft of the deleted proposal (it may hold the owner's unlisted video ids). --}}
@push('vite')
    @vite('resources/js/owner-deleted.js')
@endpush

@section('content')
    <h1 class="text-xl font-bold">削除しました</h1>
    <p class="mt-4">提案を削除しました。共有された URL でも表示できなくなりました</p>
    @if ($draftId !== null)
        <div id="deleted-proposal" data-draft-id="{{ $draftId }}" hidden></div>
    @endif
    <p class="mt-6"><a class="underline" href="{{ route('home') }}">トップへ戻る</a></p>
@endsection
