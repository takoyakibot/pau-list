@extends('layouts.app')

@section('title', '利用規約 - List Maker')

@section('content')
    <h1 class="text-2xl font-bold">利用規約</h1>
    <p class="mt-2 text-sm text-gray-600">最終更新: 2026年11月15日</p>

    <div class="mt-6 space-y-8 leading-relaxed">
        <section>
            <h2 id="about" class="text-xl font-bold">1. 本サービス</h2>
            <p class="mt-3">「List Maker」（以下「本サービス」）は、YouTube の再生リストを提案し、チャンネル主が自分のチャンネルに作成するための非公式のツールです。運営者が個人で運営しています。本サービスは YouTube および Google とは提携しておらず、YouTube・Google が提供・承認するものではありません。</p>
        </section>

        <section>
            <h2 id="youtube-terms" class="text-xl font-bold">2. YouTube 利用規約</h2>
            <p class="mt-3">本サービスは YouTube API Services を使用しています。本サービスを利用することで、YouTube 利用規約（<a class="underline" href="https://www.youtube.com/t/terms" rel="noopener noreferrer" target="_blank">https://www.youtube.com/t/terms</a>）に同意したものとみなされます。</p>
            <p class="mt-3">YouTube API Services による情報の取り扱いには <a class="underline" href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">Google プライバシーポリシー</a> が適用されます。本サービスによる情報の取り扱いは<a class="underline" href="{{ route('privacy') }}">プライバシーポリシー</a>をご覧ください。</p>
        </section>

        <section>
            <h2 id="consent" class="text-xl font-bold">3. 同意</h2>
            <p class="mt-3">提案の保存、または Google でのログインをもって、この利用規約とプライバシーポリシーに同意したものとみなします。</p>
        </section>

        <section>
            <h2 id="proposals" class="text-xl font-bold">4. 提案の内容</h2>
            <p class="mt-3">提案の内容（再生リスト名・説明・コメント・ニックネーム）には、提案した人が責任を持つものとします。次のような内容は書かないでください。</p>
            <ul class="mt-3 list-disc space-y-1 pl-6">
                <li>他人の権利（著作権・肖像権・プライバシーなど）を侵害する内容</li>
                <li>他人を誹謗中傷する内容</li>
                <li>宣伝・勧誘を目的とする内容</li>
                <li>実名・連絡先などの個人情報</li>
            </ul>
            <p class="mt-3">共有 URL を知っている人は誰でも提案を閲覧できます。共有 URL は、見せたい相手にだけ伝えてください。</p>
            <p class="mt-3">運営者は、この規約に反する、または不適切と判断した提案を、予告なく削除することがあります。</p>
        </section>

        <section>
            <h2 id="retention" class="text-xl font-bold">5. 保存期間</h2>
            <p class="mt-3">提案は作成から 30 日で自動的に削除されます。削除された提案は元に戻せません。作成した再生リストは、提案の削除後もチャンネル主の YouTube に残ります。</p>
        </section>

        <section>
            <h2 id="creation" class="text-xl font-bold">6. チャンネル主の操作</h2>
            <p class="mt-3">本サービスが YouTube に書き込む（再生リストを作成し、動画を追加する）のは、ログインしたチャンネル主が編集画面で「あなたのチャンネルに再生リストを作成します」のボタンを押したとき（と、その作成を続ける操作をしたとき）だけです。提案を保存しただけでは、YouTube には何も書き込みません。</p>
            <p class="mt-3">作成した再生リストはチャンネル主の YouTube 上のものであり、その管理（公開設定の変更や削除など）はチャンネル主の責任で YouTube 上で行ってください。</p>
        </section>

        <section>
            <h2 id="disclaimer" class="text-xl font-bold">7. 免責</h2>
            <ul class="mt-3 list-disc space-y-1 pl-6">
                <li>本サービスは現状のまま提供するもので、正確性・完全性・特定の目的への適合性などを保証しません。</li>
                <li>運営者は、予告なく本サービスの内容を変更し、または提供を中断・終了することがあります。</li>
                <li>YouTube 側の仕様変更や、YouTube API の利用上限などにより、動画の取得や再生リストの作成ができない、または途中で止まることがあります。</li>
                <li>本サービスの利用、または利用できなかったことにより生じた損害について、運営者は、運営者の故意または重大な過失による場合を除き、責任を負いません。</li>
            </ul>
        </section>

        <section>
            <h2 id="changes" class="text-xl font-bold">8. 変更</h2>
            <p class="mt-3">この利用規約を変更する場合は、このページでお知らせします。変更後に本サービスを利用した場合は、変更後の利用規約に同意したものとみなします。</p>
            <p class="mt-3">最終更新: 2026年11月15日</p>
        </section>

        <section>
            <h2 id="contact" class="text-xl font-bold">9. お問い合わせ</h2>
            <p class="mt-3">本サービスに関するお問い合わせは、<a class="underline" href="{{ route('contact.show') }}">お問い合わせフォーム</a>から受け付けます。</p>
        </section>
    </div>
@endsection
