@extends('layouts.app')

@section('title', 'ログイン中のチャンネルはこの提案の対象ではありません')

@section('content')
    <h1 class="text-xl font-bold">ログイン中のチャンネルはこの提案の対象ではありません</h1>
    <p class="mt-4 break-words">この提案は「{{ $proposal->channel_title }}」向けです。ログイン中のチャンネル: 「{{ $ownerChannelTitle }}」</p>
    <p class="mt-2 font-semibold">このチャンネルには作成できません</p>

    <section class="mt-6 rounded border border-gray-200 p-4 text-sm">
        <p><a class="underline" href="{{ url('/auth/google').'?'.http_build_query(['p' => $key]) }}">別のチャンネルでログインし直す</a></p>
        <p class="mt-2">Google のアカウント選択画面が表示されるので、対象のチャンネルを選び直せます。ブランドアカウントのチャンネルは、Google のアカウント選択画面で対象のチャンネルを選んでください</p>
        <p class="mt-2">別のチャンネルに作りたい場合は、そのチャンネルを対象に提案を作り直してもらってください</p>
    </section>

    <p class="mt-6 text-sm"><a class="underline" href="{{ route('proposals.show', $key) }}">提案のプレビューへ戻る</a></p>
@endsection
