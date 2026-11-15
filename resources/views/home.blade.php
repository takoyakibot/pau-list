@extends('layouts.app')

@push('vite')
    @vite('resources/js/home.js')
@endpush

@section('title', 'List Maker')

@section('content')
    <div class="flex flex-col gap-5">
        @if (session()->has('owner_notice'))
            <p class="notice-warn text-sm" role="status">{{ session('owner_notice') }}</p>
        @endif

        <div>
            <h1 class="text-2xl font-bold">List Maker</h1>
            <p class="mt-2 text-ink-2">YouTube の再生リストを、ファンが提案してチャンネル主が作成するためのツールです</p>
        </div>

        @include('partials.steps', ['current' => null])

        <form method="GET" action="{{ route('channels.open') }}" class="card flex flex-col gap-2 p-5">
            <label for="q" class="text-base font-bold">提案したいチャンネル</label>
            <p class="hint">チャンネルの URL・ハンドル（@…）・チャンネル ID（UC…）のどれでも入力できます</p>
            <div class="flex flex-wrap gap-2">
                <input id="q" name="q" type="text" maxlength="200" value="{{ old('q') }}" placeholder="@example" required
                    class="field min-h-11 min-w-0 flex-[1_1_240px]" @error('q') aria-invalid="true" aria-describedby="q-error" @enderror>
                <button type="submit" class="btn btn-primary shrink-0">開く</button>
            </div>
            @error('q')
                <p id="q-error" role="alert" class="text-sm text-danger">{{ $message }}</p>
            @enderror
        </form>

        <section id="my-proposals" hidden aria-labelledby="my-proposals-heading" class="card p-5">
            <h2 id="my-proposals-heading" class="text-base font-bold">この端末で作った提案</h2>
            <p class="hint mt-1">この端末のブラウザにだけ保存されています</p>
            <ul data-list class="mt-2 space-y-1 text-sm"></ul>
        </section>

        <section aria-labelledby="home-owner-heading" class="card p-5 text-sm">
            <h2 id="home-owner-heading" class="text-base font-bold">チャンネル主の方へ</h2>
            <p class="mt-2 text-ink-2">届いた共有 URL を開き、「Google でログインして作成へ」からログインします</p>
            @include('partials.unverified-app')
        </section>

        <div class="space-y-2 text-xs text-ink-muted">
            <p>Google ログインで許可いただく YouTube の権限は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います。</p>
            <p>本サービスは YouTube API Services を使用しています。利用には <a class="underline" href="https://www.youtube.com/t/terms" rel="noopener noreferrer" target="_blank">YouTube 利用規約</a> と <a class="underline" href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">Google プライバシーポリシー</a> が適用されます。本サービスの <a class="underline" href="{{ route('terms') }}">利用規約</a> と <a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a> もご確認ください</p>
        </div>
    </div>
@endsection
