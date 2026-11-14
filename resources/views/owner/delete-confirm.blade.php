@extends('layouts.app')

@section('title', 'この提案を削除しますか？')

@section('content')
    {{-- Links are relative to /p/{key}/delete so the raw key is not written into the page. --}}
    <h1 class="text-xl font-bold">この提案を削除しますか？</h1>
    <p class="mt-4 break-words">「{{ $proposal->title }}」（{{ $proposal->item_count }} 本）を削除します。</p>
    <p class="mt-2 font-semibold">削除すると元に戻せません。共有された URL でも表示できなくなります</p>

    <form method="post" action="deletion" class="mt-6">
        @csrf
        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white">削除する</button>
    </form>

    <p class="mt-6 text-sm"><a class="underline" href="edit">削除せずに編集画面へ戻る</a></p>
@endsection
