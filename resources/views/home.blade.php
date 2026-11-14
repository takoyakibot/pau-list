@extends('layouts.app')

@push('vite')
    @vite('resources/js/home.js')
@endpush

@section('title', 'List Maker')

@section('content')
    @if (session()->has('owner_notice'))
        <p class="mb-4 rounded bg-amber-50 p-2 text-sm text-amber-900" role="status">{{ session('owner_notice') }}</p>
    @endif
    <h1 class="text-2xl font-bold">List Maker</h1>
    <p class="mt-4">YouTube の再生リストを、ファンが提案してチャンネル主が作成するためのツールです</p>

    <ol class="mt-8 list-decimal space-y-2 pl-6">
        <li>ファンが再生リストを提案する</li>
        <li>共有 URL をチャンネル主に送る</li>
        <li>チャンネル主が内容を確認して作成する</li>
    </ol>

    <p class="mt-8">Google ログインで許可いただく YouTube の権限は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います。</p>
    <p class="mt-4 text-sm">本サービスは YouTube API Services を使用しています。利用には <a class="underline" href="https://www.youtube.com/t/terms" rel="noopener noreferrer" target="_blank">YouTube 利用規約</a> と <a class="underline" href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">Google プライバシーポリシー</a> が適用されます</p>
    <p class="mt-2 text-sm">本サービスの <a class="underline" href="{{ route('terms') }}">利用規約</a> と <a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a> もご確認ください</p>

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

    <section aria-labelledby="home-owner-heading" class="mt-10 rounded border border-gray-200 p-4 text-sm">
        <h2 id="home-owner-heading" class="font-semibold">チャンネル主の方へ</h2>
        <p class="mt-2">提案の共有 URL を開き、「チャンネル主の方: Google でログインして作成へ」からログインします</p>
        @include('partials.unverified-app')
    </section>

    <section id="my-proposals" hidden aria-labelledby="my-proposals-heading" class="mt-10">
        <h2 id="my-proposals-heading" class="text-lg font-semibold">この端末で作った提案</h2>
        <p class="mt-1 text-xs text-gray-600">この端末のブラウザにだけ保存されています</p>
        <ul data-list class="mt-2 space-y-1 text-sm"></ul>
    </section>
@endsection
