@extends('layouts.app')

@section('title', 'お問い合わせ - List Maker')

@section('content')
    {{-- Values come from $input (never old()): the contact form's input is not kept in the session. --}}
    @php($kind = $input['kind'] ?? 'general')
    <section class="card p-5 sm:p-6">
        <h1 class="text-2xl font-bold">お問い合わせ</h1>

        @if (session('contact_sent'))
            <p class="mt-4 rounded-md bg-success-soft px-3 py-2 text-success" role="status">{{ \App\Http\Controllers\ContactController::SENT }}</p>
        @endif
        @isset($notice)
            <p class="notice-danger mt-4 text-sm" role="alert">{{ $notice }}</p>
        @endisset
        @if ($errors->any())
            <ul class="notice-danger mt-4 list-disc space-y-1 pl-8 text-sm" role="alert">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-4 space-y-2 text-[13px] text-ink-2">
            <p>入力内容は運営者へのメールとして送られ、本サービスのデータベースには保存されません。返信が必要な場合のみメールアドレスを入力してください</p>
            <p>入力された情報の取り扱いは<a class="underline" href="{{ route('privacy') }}#contact-form">プライバシーポリシー</a>をご覧ください</p>
            <details class="rounded-md border border-line-subtle bg-surface px-3 py-2">
                <summary class="font-semibold">削除依頼について</summary>
                <div class="mt-2 space-y-2">
                    <p>削除依頼の場合は、本文に提案の URL（https://list-maker.alpacasandbag.jp/p/…）を書いてください。7 日以内に削除します</p>
                    <p>チャンネル単位でまとめて削除したい場合は、チャンネルの URL（https://www.youtube.com/channel/UC…）またはチャンネル ID を書いてください（そのチャンネルの持ち主からの依頼か、運営者がチャンネルの公開の連絡先を通じて確認することがあります）</p>
                    <p>このフォームが使えない場合（1 日の受付数の上限に達した場合など）は、@include('partials.operator-contact')からも削除をご依頼いただけます</p>
                </div>
            </details>
        </div>

        <form method="post" action="{{ route('contact.store') }}" class="mt-6 space-y-5">
            @csrf
            <fieldset>
                <legend class="text-sm font-semibold">種別（必須）</legend>
                <div class="mt-1 flex flex-wrap gap-2 text-sm">
                    @foreach (\App\Http\Requests\ContactRequest::KINDS as $value => $label)
                        <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-line-strong bg-raised px-3 has-[:checked]:border-accent has-[:checked]:bg-accent-soft">
                            <input type="radio" name="kind" value="{{ $value }}" @checked($kind === $value) required>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <label class="block text-sm">
                <span class="block font-semibold">本文（必須・2,000 文字以内）</span>
                <textarea name="body" maxlength="2000" rows="8" required class="field mt-1">{{ $input['body'] ?? '' }}</textarea>
            </label>
            <label class="block text-sm">
                <span class="block font-semibold">返信用メールアドレス（任意）</span>
                <input type="email" name="reply_address" maxlength="254" value="{{ $input['reply_address'] ?? '' }}" autocomplete="email" class="field mt-1 min-h-10">
            </label>
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="btn btn-primary">送信する</button>
        </form>
    </section>
@endsection
