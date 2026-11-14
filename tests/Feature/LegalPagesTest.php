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

    public function test_privacy_contains_required_statements_and_links(): void
    {
        $res = $this->get('/privacy')->assertOk();
        foreach ([
            '本サービスは YouTube API Services を使用しています',
            'YouTube API Services による情報の取り扱いには Google プライバシーポリシーが適用されます',
            'Google ログインで許可いただく YouTube の権限（youtube スコープ）は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います',
            'メールアドレス・氏名・リフレッシュトークンは取得しません',
            '限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します',
            'IP アドレスはデータベースに保存しません',
            '提案と、YouTube から取得した動画の情報は、最長 30 日で削除します',
            'アクセストークンは暗号化したセッションにだけ保存し、ログアウトまたは最長 120 分で削除します',
            '提案作成中の選択と『この端末で作った提案』（共有 URL）はブラウザの localStorage に保存し、サーバーには送りません',
            'チャンネル主の編集中の内容はブラウザの sessionStorage に保存し、タブを閉じると消えます',
            '問い合わせの内容は運営者へのメールとして送られ、本サービスのデータベースには保存しません',
            '返信用のメールアドレスの入力は任意です',
            '受け取ったメールは対応の完了後 90 日以内に削除します',
            'Google アカウントの サードパーティのアクセス（https://myaccount.google.com/permissions）からいつでも承認を取り消せます',
            '提案などの削除は、お問い合わせフォームから種別『削除依頼』でご依頼ください。7 日以内に削除します',
            '最終更新: 2026年11月15日',
        ] as $text) {
            $res->assertSeeText($text);
        }
        $main = $this->main($res);
        foreach ([self::GOOGLE_PRIVACY, self::YOUTUBE_TERMS, self::PERMISSIONS, route('contact.show')] as $href) {
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
