@extends('layouts.app')

@section('title', 'この提案を削除しますか？')

@section('content')
    {{-- Links are relative to /p/{key}/delete so the raw key is not written into the page. --}}
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">この提案を削除しますか？</h1>
        <p class="mt-3 break-words text-ink-2">「{{ $proposal->title }}」（{{ $proposal->item_count }} 本）を削除します。</p>

        @if ($blocked)
            {{-- Y19 (U14): not while the creation is running; S6's 「残りを中止」 completes it first. --}}
            <p class="notice-warn mt-3 text-sm" role="alert">{{ \App\Http\Controllers\OwnerDeleteController::IN_PROGRESS }}</p>
            <p class="mt-5 text-sm"><a class="font-semibold underline" href="creation">作成状況を見る</a></p>
        @else
            <p class="mt-2 font-semibold">削除すると元に戻せません。共有された URL でも表示できなくなります</p>
            @if ($hasCreation)
                <p class="mt-2 text-ink-2">作成済みの再生リストは YouTube に残ります（この提案と作成の記録だけが削除されます）</p>
            @endif

            <form method="post" action="deletion" class="mt-5">
                @csrf
                <button type="submit" class="btn btn-danger">削除する</button>
            </form>

            @if ($hasCreation)
                <p class="mt-5 text-sm"><a class="underline" href="creation">削除せずに作成状況の画面へ戻る</a></p>
            @else
                <p class="mt-5 text-sm"><a class="underline" href="edit">削除せずに編集画面へ戻る</a></p>
            @endif
        @endif
    </section>
@endsection
