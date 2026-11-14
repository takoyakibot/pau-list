@extends('layouts.app')

@section('title', 'お問い合わせ - List Maker')

@section('content')
    {{-- Values come from $input (never old()): the contact form's input is not kept in the session. --}}
    @php($kind = $input['kind'] ?? 'general')
    <h1 class="text-2xl font-bold">お問い合わせ</h1>

    @if (session('contact_sent'))
        <p class="mt-4 rounded bg-green-50 p-3 text-green-900" role="status">{{ \App\Http\Controllers\ContactController::SENT }}</p>
    @endif
    @isset($notice)
        <p class="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $notice }}</p>
    @endisset
    @if ($errors->any())
        <ul class="mt-4 list-disc space-y-1 rounded border border-red-300 bg-red-50 p-3 pl-8 text-sm text-red-800" role="alert">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4 text-sm">入力内容は運営者へのメールとして送られ、本サービスのデータベースには保存されません。返信が必要な場合のみメールアドレスを入力してください</p>
    <p class="mt-2 text-sm">削除依頼の場合は、本文に提案の URL（https://list-maker.alpacasandbag.jp/p/…）を書いてください。7 日以内に削除します</p>

    <form method="post" action="{{ route('contact.store') }}" class="mt-6 space-y-5">
        @csrf
        <fieldset>
            <legend class="text-sm font-semibold">種別（必須）</legend>
            <div class="mt-1 flex flex-wrap gap-4 text-sm">
                @foreach (\App\Http\Requests\ContactRequest::KINDS as $value => $label)
                    <label class="inline-flex items-center gap-1">
                        <input type="radio" name="kind" value="{{ $value }}" @checked($kind === $value) required>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <label class="block text-sm">
            <span class="block font-semibold">本文（必須・2,000 文字以内）</span>
            <textarea name="body" maxlength="2000" rows="8" required class="mt-1 w-full rounded border border-gray-300 px-3 py-2">{{ $input['body'] ?? '' }}</textarea>
        </label>
        <label class="block text-sm">
            <span class="block font-semibold">返信用メールアドレス（任意）</span>
            <input type="email" name="reply_address" maxlength="254" value="{{ $input['reply_address'] ?? '' }}" autocomplete="email" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
            <span class="block text-xs text-gray-600">返信が必要な場合のみ入力してください</span>
        </label>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white">送信する</button>
    </form>
@endsection
