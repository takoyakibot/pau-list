@extends('layouts.app')

@section('title', 'ログイン中のチャンネルはこの提案の対象ではありません')

@section('content')
    <section class="card p-5 sm:p-6">
        <h1 class="text-xl font-bold">ログイン中のチャンネルはこの提案の対象ではありません</h1>
        <p class="mt-3 break-words text-ink-2">この提案は「{{ $proposal->channel_title }}」向けです。ログイン中のチャンネル: 「{{ $ownerChannelTitle }}」</p>
        <p class="mt-2 font-semibold">このチャンネルには作成できません</p>

        <div class="mt-5 space-y-2 border-t border-line-subtle pt-4 text-sm">
            <p><a class="btn btn-primary" href="{{ route('owner.login', ['p' => $key]) }}">別のチャンネルでログインし直す</a></p>
            <p class="hint">ブランドアカウントのチャンネルは、Google のアカウント選択画面で対象のチャンネルを選んでください</p>
            <p class="hint">別のチャンネルに作りたい場合は、そのチャンネルを対象に提案を作り直してもらってください</p>
        </div>

        <p class="mt-5 text-sm"><a class="underline" href="{{ route('proposals.show', $key) }}">提案のプレビューへ戻る</a></p>
        @include('partials.owner-logout')
    </section>
@endsection
