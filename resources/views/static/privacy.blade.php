@extends('layouts.app')

@section('title', 'プライバシーポリシー - List Maker')

@section('content')
    <h1 class="text-2xl font-bold">プライバシーポリシー</h1>
    <p class="mt-2 text-sm text-gray-600">最終更新: 2026年11月15日</p>

    <div class="mt-6 space-y-8 leading-relaxed">
        <section>
            <h2 id="about" class="text-xl font-bold">1. 本サービスについて</h2>
            <p class="mt-3">「List Maker」（https://list-maker.alpacasandbag.jp 、以下「本サービス」）は、YouTube の再生リストを提案し、チャンネル主が自分のチャンネルに作成するためのツールです。運営者が個人で運営しています。本サービスは YouTube および Google とは提携しておらず、YouTube・Google が提供・承認するものではありません。</p>
            <p class="mt-3">このページでは、本サービスが取得する情報と、その利用目的・保存期間・削除の方法を説明します。</p>
        </section>

        <section>
            <h2 id="youtube" class="text-xl font-bold">2. YouTube API Services の利用</h2>
            <p class="mt-3">本サービスは YouTube API Services を使用しています。チャンネルや動画の情報の取得と、チャンネル主の操作による再生リストの作成に使います。</p>
            <p class="mt-3">YouTube API Services による情報の取り扱いには Google プライバシーポリシーが適用されます。</p>
            <ul class="mt-3 list-disc space-y-1 pl-6">
                <li><a class="underline" href="https://policies.google.com/privacy" rel="noopener noreferrer" target="_blank">Google プライバシーポリシー</a></li>
                <li><a class="underline" href="https://www.youtube.com/t/terms" rel="noopener noreferrer" target="_blank">YouTube 利用規約</a></li>
            </ul>
        </section>

        <section>
            <h2 id="data" class="text-xl font-bold">3. 取得する情報と利用目的</h2>
            <p class="mt-3">本サービスが取得・保存する情報は次のとおりです。本サービスには会員登録はなく、提案する人のアカウントは作りません。</p>
            <ul class="mt-3 list-disc space-y-3 pl-6">
                <li><strong>提案の内容</strong>（再生リスト名・説明・コメント・ニックネーム・選んだ動画）: 共有 URL での表示と、チャンネル主が再生リストを作成するために使います。ニックネームとコメントの入力は任意です。</li>
                <li><strong>YouTube の公開情報</strong>（動画のタイトル・サムネイル画像の URL・再生時間・公開日、チャンネル名・アイコンの URL）: 動画の一覧と提案の表示に使います。サムネイル画像やアイコンの画像そのものは保存せず、YouTube の URL を表示するだけです。</li>
                <li><strong>チャンネル主のログイン情報</strong>: Google ログインで許可いただく YouTube の権限（youtube スコープ）は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います。取得するのはチャンネル ID・チャンネル名・アクセストークンだけで、メールアドレス・氏名・リフレッシュトークンは取得しません。</li>
                <li><strong>チャンネル主が読み込んだ自分の限定公開動画の一覧</strong>: 限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します。チャンネル主が提案を編集するときにだけ使い、他の利用者には表示しません。</li>
                <li><strong>作成の記録</strong>（作成する再生リストの名前・説明・公開設定・動画、作成した再生リストの ID、作成の進み具合）: 作成の途中から再開したり、同じ提案から二重に作成されるのを防いだりするために使います。</li>
                <li><strong>問い合わせ</strong>: 「<a class="underline" href="#contact-form">7. 問い合わせの取り扱い</a>」をご覧ください。</li>
            </ul>
            <p class="mt-3">IP アドレスはデータベースに保存しません。短時間に大量の操作が行われるのを防ぐ（回数制限の）ため、IP アドレスから計算した、元の IP アドレスに戻せない値と回数を、最長 1 日だけ一時的に保存します。このほか、YouTube API の利用量の日ごとの合計（利用者の情報を含みません）を 90 日間保存します。</p>
            <p class="mt-3">Web サーバーのアクセスログは、本サービスが利用しているホスティング事業者が管理しています。</p>
        </section>

        <section>
            <h2 id="retention" class="text-xl font-bold">4. 保存場所と期間</h2>
            <p class="mt-3">情報は本サービスのサーバーのデータベースに保存し、期限を過ぎたものは 1 日 1 回の処理で削除します。</p>
            <ul class="mt-3 list-disc space-y-3 pl-6">
                <li>提案と、YouTube から取得した動画の情報は、最長 30 日で削除します。提案は作成から 30 日で、提案に関する作成の記録も提案と一緒に削除します。チャンネルごとに取り込んだ動画の一覧は、取り込みから 30 日でチャンネル単位でまとめて削除します（削除したチャンネルの ID と削除日時だけは、取り込み直しの判定のため、さらに 30 日保存します）。</li>
                <li>アクセストークンは暗号化したセッションにだけ保存し、ログアウトまたは最長 120 分で削除します。セッションの内容はすべて暗号化して保存し、セッションには IP アドレスやブラウザの種類（User-Agent）を記録しません。操作のないまま 120 分たったセッションは使えなくなり、その記録も 1 日 1 回の処理でデータベースから消去します。</li>
                <li>アプリのログは 14 日間保存します。ログには IP アドレス、アクセストークン、提案のキー、問い合わせの本文やメールアドレスを含めません。</li>
            </ul>
            <p class="mt-3">作成した再生リストはチャンネル主の YouTube に残ります。再生リストそのものは本サービスでは保持せず、本サービスの記録を削除しても YouTube 上の再生リストは削除されません。</p>
        </section>

        <section>
            <h2 id="browser-storage" class="text-xl font-bold">5. Cookie とブラウザへの保存</h2>
            <p class="mt-3">本サービスが使う Cookie は、セッション用の Cookie と、フォームの不正な送信を防ぐための Cookie（XSRF-TOKEN）だけです。アクセス解析や広告のためのタグ・Cookie は使いません。</p>
            <p class="mt-3">提案作成中の選択と『この端末で作った提案』（共有 URL）はブラウザの localStorage に保存し、サーバーには送りません。選択中の動画は動画の ID（と、別のチャンネルの動画かどうか）だけを保存し、最後の保存から 30 日たつと削除します。『この端末で作った提案』は、共有 URL・提案名・チャンネル名などを最大 50 件、提案の期限まで保存し、期限を過ぎたものは削除します。</p>
            <p class="mt-3">チャンネル主の編集中の内容はブラウザの sessionStorage に保存し、タブを閉じると消えます。</p>
            <p class="mt-3">これらはブラウザの設定からいつでも削除できます。</p>
        </section>

        <section>
            <h2 id="sharing" class="text-xl font-bold">6. 第三者への提供</h2>
            <p class="mt-3">本サービスは、取得した情報を第三者に提供しません。ただし、次の場合を除きます。</p>
            <ul class="mt-3 list-disc space-y-1 pl-6">
                <li>チャンネル主の操作によって、YouTube に再生リストの作成と動画の追加の内容を送信する場合</li>
                <li>YouTube API Services の利用に伴い、Google に必要な情報を送信する場合</li>
            </ul>
        </section>

        <section>
            <h2 id="contact-form" class="text-xl font-bold">7. 問い合わせの取り扱い</h2>
            <p class="mt-3">問い合わせの内容は運営者へのメールとして送られ、本サービスのデータベースには保存しません。返信用のメールアドレスの入力は任意です。入力された場合は返信のためだけに使います。</p>
            <p class="mt-3">受け取ったメールは対応の完了後 90 日以内に削除します。</p>
        </section>

        <section>
            <h2 id="revoke" class="text-xl font-bold">8. 承認の取り消し</h2>
            <p class="mt-3">チャンネル主が本サービスからログアウトすると、本サービスは Google 側の承認（アクセストークン）も取り消します。取り消しに失敗した場合は画面でお知らせしますので、下の方法で取り消してください。</p>
            <p class="mt-3">また、Google アカウントの サードパーティのアクセス（<a class="underline" href="https://myaccount.google.com/permissions" rel="noopener noreferrer" target="_blank">https://myaccount.google.com/permissions</a>）からいつでも承認を取り消せます。</p>
            <p class="mt-3">本サービスはリフレッシュトークンを取得しないため、取り消した後に本サービスがチャンネルにアクセスできるトークンは残りません。</p>
        </section>

        <section>
            <h2 id="deletion" class="text-xl font-bold">9. 削除の依頼</h2>
            <p class="mt-3">提案などの削除は、お問い合わせフォームから種別『削除依頼』でご依頼ください。7 日以内に削除します。依頼の際は、本文に提案の URL を書いてください。</p>
            <p class="mt-3">チャンネル主は、自分のチャンネル宛ての提案を、編集画面からいつでも削除できます。</p>
        </section>

        <section>
            <h2 id="contact" class="text-xl font-bold">10. お問い合わせ先</h2>
            <p class="mt-3">このプライバシーポリシーや情報の取り扱いに関するお問い合わせは、<a class="underline" href="{{ route('contact.show') }}">お問い合わせフォーム</a>から受け付けます。</p>
        </section>

        <section>
            <h2 id="changes" class="text-xl font-bold">11. 改定</h2>
            <p class="mt-3">このプライバシーポリシーを変更する場合は、このページでお知らせします。</p>
            <p class="mt-3">最終更新: 2026年11月15日</p>
        </section>
    </div>
@endsection
