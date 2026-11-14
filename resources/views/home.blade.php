@extends('layouts.app')

@section('title', 'List Maker（仮称）')

@section('content')
    <h1 class="text-2xl font-bold">List Maker（仮称）</h1>
    <p class="mt-4">YouTube の再生リストを、ファンが提案してチャンネル主が作成するためのツールです</p>

    <ol class="mt-8 list-decimal space-y-2 pl-6">
        <li>ファンが再生リストを提案する</li>
        <li>共有 URL をチャンネル主に送る</li>
        <li>チャンネル主が内容を確認して作成する</li>
    </ol>

    <p class="mt-8">Google ログインで許可いただく権限は、あなたのチャンネルへの再生リストの作成と動画の追加にのみ使います。</p>

    <form method="GET" action="{{ route('channels.open') }}" class="mt-8">
        <label for="q" class="block font-semibold">提案したいチャンネル</label>
        <p class="mt-1 text-sm text-gray-600">チャンネルの URL、ハンドル（@…）、チャンネル ID（UC…）のいずれかを入力してください</p>
        <div class="mt-2 flex gap-2">
            <input id="q" name="q" type="text" maxlength="200" value="{{ old('q') }}" placeholder="@example" required
                class="w-full rounded border border-gray-300 px-3 py-2" @error('q') aria-invalid="true" aria-describedby="q-error" @enderror>
            <button type="submit" class="shrink-0 rounded bg-gray-900 px-4 py-2 text-white">開く</button>
        </div>
        @error('q')
            <p id="q-error" role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </form>
@endsection
