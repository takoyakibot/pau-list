<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsNoInlineCode;
use Tests\TestCase;

/** Spec 9.1 (S7): the privacy policy and the terms of use, with the statements the review relies on. */
class LegalPagesTest extends TestCase
{
    use AssertsNoInlineCode;

    private const YOUTUBE_TERMS = 'https://www.youtube.com/t/terms';

    private const GOOGLE_PRIVACY = 'https://policies.google.com/privacy';

    private const PERMISSIONS = 'https://myaccount.google.com/permissions';

    private const USER_DATA_POLICY = 'https://developers.google.com/terms/api-services-user-data-policy';

    public function test_privacy_contains_required_statements_and_links(): void
    {
        $res = $this->get('/privacy')->assertOk();
        foreach ([
            '本サービスは YouTube API Services を使用しています',
            'YouTube API Services による情報の取り扱いには Google プライバシーポリシーが適用されます',
            'Google ログインで許可いただく YouTube の権限（youtube スコープ）は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います',
            'メールアドレス・氏名・リフレッシュトークンは取得しません',
            '編集画面で動画 URL から動画を追加するときは、その動画が追加できるもの（公開の動画か、自分の限定公開の動画）かを確かめるためにも使います（確かめた結果は画面に返すだけで、保存しません）',
            '限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します',
            'IP アドレスはデータベースに保存しません',
            'IP アドレスを推測しにくい値（鍵付きハッシュ）に変えたものと回数を、最長 2 日だけ一時的に保存します',
            'IP アドレスやアクセスした URL（提案の URL を含みます）が記録されることがあります',
            'ホスティング事業者（CORESERVER）',
            '提案と、YouTube から取得した動画の情報は、最長 30 日で削除します',
            'アクセストークンは暗号化したセッションにだけ保存し、ログアウトすると直ちに削除します',
            'トークンは有効期限（約 1 時間）を過ぎると使えなくなり、次にページを開いたときにセッションから削除します',
            '操作のないまま 120 分たったセッションは使えなくなり、その記録（アクセストークンを含みます）は 1 時間ごとの整理の処理で、それから約 1 時間以内にデータベースから消去します',
            '提案作成中の選択と『この端末で作った提案』（共有 URL）はブラウザの localStorage に保存し、サーバーには送りません',
            'チャンネル主の編集中の内容はブラウザの sessionStorage に保存し、タブを閉じると消えます',
            '問い合わせの内容は運営者へのメールとして送られ、本サービスのデータベースには保存しません',
            '返信用のメールアドレスの入力は任意です',
            '受け取ったメールは対応の完了後 90 日以内に削除します',
            'Google アカウントの サードパーティのアクセス（https://myaccount.google.com/permissions）からいつでも承認を取り消せます',
            '提案などの削除は、お問い合わせフォームから種別『削除依頼』でご依頼ください。7 日以内に削除します',
            '最終更新: 2026年11月15日',
            // Limited Use (Google API Services User Data Policy)
            '限定的な使用（Limited Use）の要件を含む Google API サービスのユーザーデータに関するポリシー（Google API Services User Data Policy）に従います',
            '広告の表示や配信には使いません',
            '第三者に販売したり、転送したりしません',
            'AI・機械学習のモデルの開発や学習には使いません',
            '利用者の同意がある場合、セキュリティ上の目的（不正利用の調査など）で必要な場合、または法令に従う必要がある場合を除き、人が読むことはありません',
            // Owner reads beyond the unlisted list, and what the creation records hold
            'チャンネル主の再生リストの一覧と、作成した再生リストに入っている動画を読み取ることがあります',
            '編集画面で読み込んだ限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します',
            '作成したチャンネル主のチャンネル ID',
            '追加する動画の ID・タイトル・サムネイル画像の URL',
            'チャンネル主が選んだ自分の限定公開の動画も、ここに含まれます',
            '作成状況の画面でチャンネル主にだけ表示します',
            'チャンネル主が本サービスを最後に使った時（作成状況の画面を開く・作成を進める・ログアウトするなど）から 7 日以内に削除します',
            '公開の再生リストの ID は誰でも見られる情報のため、提案と一緒に（最長 30 日で）削除します',
            '共有 URL（提案の URL）を知っている人は誰でも、その提案の作成状況（作成中・作成済みなど）を見ることができます',
            '公開設定を「公開」にして作成した再生リストは、そのリンクも表示します',
            '共有 URL を知っている人に、提案の内容と作成状況（公開の再生リストはそのリンク）を表示する場合',
            // Cleanup timing
            'そのブラウザで次に本サービスを開いたときに削除されます',
            '回数制限のカウンターと一時的なキャッシュは、期限切れのものを 1 日 1 回の整理の処理で消去します',
            'ログアウト（承認の取り消し）または本サービスを最後に使った時から 7 日以内に削除します',
            'チャンネルの URL（https://www.youtube.com/channel/UC…）またはチャンネル ID',
            '運営者がチャンネルの公開の連絡先を通じて確認をお願いすることがあります',
            '運営者の連絡先（README で設定）',
            'ご依頼から 7 日以内に削除します',
        ] as $text) {
            $res->assertSeeText($text);
        }
        $main = $this->main($res);
        foreach ([self::GOOGLE_PRIVACY, self::YOUTUBE_TERMS, self::PERMISSIONS, self::USER_DATA_POLICY, route('contact.show')] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $main);
        }
        foreach (['about', 'youtube', 'data', 'retention', 'browser-storage', 'sharing', 'contact-form', 'revoke', 'deletion', 'contact', 'changes'] as $id) {
            $res->assertSee('id="'.$id.'"', false);
        }
        $res->assertSeeInOrder(array_map(fn ($id) => 'id="'.$id.'"', ['about', 'youtube', 'data', 'retention', 'browser-storage', 'sharing', 'contact-form', 'revoke', 'deletion', 'contact', 'changes']), false);
        $this->assertExternalLinksOpenSafely($main);
    }

    public function test_terms_contains_required_statements_and_links(): void
    {
        $res = $this->get('/terms')->assertOk();
        foreach ([
            '本サービスは YouTube API Services を使用しています。本サービスを利用することで、YouTube 利用規約（https://www.youtube.com/t/terms）に同意したものとみなされます',
            '提案の保存、または Google でのログインをもって、この利用規約とプライバシーポリシーに同意したものとみなします',
            '共有 URL を知っている人は誰でも提案を閲覧できます',
            '提案は作成から 30 日で自動的に削除されます',
            '最終更新: 2026年11月15日',
        ] as $text) {
            $res->assertSeeText($text);
        }
        $main = $this->main($res);
        foreach ([self::YOUTUBE_TERMS, self::GOOGLE_PRIVACY, route('privacy'), route('contact.show')] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $main);
        }
        $res->assertSeeInOrder(array_map(fn ($id) => 'id="'.$id.'"', ['about', 'youtube-terms', 'consent', 'proposals', 'retention', 'creation', 'disclaimer', 'changes', 'contact']), false);
        $this->assertExternalLinksOpenSafely($main);
    }

    public function test_legal_pages_stay_indexable_and_have_no_placeholder(): void
    {
        foreach (['/privacy', '/terms'] as $path) {
            $res = $this->get($path)->assertOk()->assertHeaderMissing('X-Robots-Tag');
            $res->assertDontSee('本文は公開前に掲載します');
            $res->assertDontSee('（仮称）');
            // No published email address: contact goes through the form only.
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $this->main($res));
            $this->assertNoInlineCode($res->getContent());
        }
    }

    private function main(TestResponse $res): string
    {
        $html = (string) $res->getContent();
        $this->assertStringContainsString('<main', $html);

        return Str::before(Str::after($html, '<main'), '</main>');
    }

    /** Every external link in <main> has rel="noopener noreferrer" target="_blank". */
    private function assertExternalLinksOpenSafely(string $main): void
    {
        preg_match_all('/<a\b[^>]*href="https?:\/\/(?!'.preg_quote(parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost', '/').')[^"]*"[^>]*>/', $main, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('rel="noopener noreferrer"', $tag);
            $this->assertStringContainsString('target="_blank"', $tag);
        }
    }
}
