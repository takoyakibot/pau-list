<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use App\Support\ClientKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\EnforcesCsrf;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use AssertsNoInlineCode, EnforcesCsrf, RefreshDatabase;

    private const SENT = 'お問い合わせを送信しました';

    private const FAILED = '送信できませんでした。時間をおいて再度お試しください';

    private const LIMITED = '送信が集中しています。しばらく待ってから再度お試しください';

    /** @var list<MessageLogged> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['listmaker.contact_to_address' => 'ops@example.com']);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = $e;
        });
    }

    /** @param array<string, mixed> $overrides */
    private function send(array $overrides = [], string $ip = '203.0.113.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/contact', array_merge(['kind' => 'general', 'body' => 'こんにちは'], $overrides));
    }

    /** @return list<MessageLogged> */
    private function logsWithMessage(string $message): array
    {
        return array_values(array_filter($this->logs, fn (MessageLogged $e) => $e->message === $message));
    }

    private function assertLogsDoNotContain(string $needle): void
    {
        foreach ($this->logs as $log) {
            $this->assertStringNotContainsString($needle, $log->message.json_encode($log->context, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_form_shows_fields_notice_and_honeypot(): void
    {
        $res = $this->get('/contact')->assertOk();
        foreach (['general', 'deletion', 'bug'] as $kind) {
            $res->assertSee('name="kind" value="'.$kind.'"', false);
        }
        foreach (['一般', '削除依頼', '不具合'] as $label) {
            $res->assertSee($label);
        }
        $res->assertSee('<textarea', false);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*maxlength="2000"/', $res->getContent());
        $this->assertMatchesRegularExpression('/<input[^>]*name="reply_address"[^>]*maxlength="254"/', $res->getContent());
        $res->assertSee('name="website"', false);
        $res->assertSee('入力内容は運営者へのメールとして送られ、本サービスのデータベースには保存されません。返信が必要な場合のみメールアドレスを入力してください');
        $res->assertSee('削除依頼の場合は、本文に提案の URL（https://list-maker.alpacasandbag.jp/p/…）を書いてください。7 日以内に削除します');
        $res->assertSee('チャンネル単位でまとめて削除したい場合は、チャンネルの URL（https://www.youtube.com/channel/UC…）またはチャンネル ID を書いてください');
        $res->assertSee('このフォームが使えない場合（1 日の受付数の上限に達した場合など）は、「運営者の連絡先（README で設定）」からも削除をご依頼いただけます');
        $res->assertSee('入力された情報の取り扱いは<a class="underline" href="'.route('privacy').'#contact-form">プライバシーポリシー</a>をご覧ください', false);
        $res->assertSee('action="'.route('contact.store').'"', false);
        $res->assertSee('name="_token"', false);
        $res->assertDontSee(self::SENT);
        $this->assertNoInlineCode($res->getContent());
    }

    public function test_operator_contact_is_shown_when_configured(): void
    {
        config(['listmaker.operator_contact' => 'X（旧 Twitter）の @operator_example']);

        $this->get('/contact')->assertOk()
            ->assertSee('「X（旧 Twitter）の @operator_example」からも削除をご依頼いただけます')
            ->assertDontSee('README で設定');
        $this->get('/privacy')->assertOk()->assertSee('X（旧 Twitter）の @operator_example');
    }

    public function test_sends_text_mail_with_fixed_subject_and_reply_address_in_body(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:34:00', 'UTC'));
        $this->send(['kind' => 'deletion', 'body' => "消してください\nhttps://list-maker.alpacasandbag.jp/p/xxx", 'reply_address' => 'me@example.com'])
            ->assertRedirect(route('contact.show'));
        Mail::assertSent(ContactMessage::class, fn (ContactMessage $m) => $m->hasTo('ops@example.com')
            && $m->hasSubject('[List Maker] お問い合わせ（削除依頼）')
            && $m->plainBody() === "種別: 削除依頼\n送信日時: 2026年11月15日 12:34（日本時間）\n\n消してください\nhttps://list-maker.alpacasandbag.jp/p/xxx\n\n返信用メールアドレス: me@example.com\n"
            && $m->replyTo === []);
        Mail::assertSentCount(1);
    }

    public function test_reply_address_line_is_absent_when_not_given(): void
    {
        Mail::fake();
        $this->send(['kind' => 'bug', 'body' => '動きません', 'reply_address' => ''])->assertRedirect(route('contact.show'));
        Mail::assertSent(ContactMessage::class, fn (ContactMessage $m) => str_contains($m->plainBody(), "種別: 不具合\n")
            && str_contains($m->plainBody(), '動きません')
            && ! str_contains($m->plainBody(), '返信用メールアドレス'));
    }

    public function test_sent_message_headers_have_no_user_input(): void
    {
        $this->send(['body' => "x\r\nBcc: evil@example.com\n&<b>", 'reply_address' => 'me@example.com'])
            ->assertRedirect(route('contact.show'));
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('[List Maker] お問い合わせ（一般）', $email->getSubject());
        $this->assertSame([], $email->getReplyTo());
        $this->assertSame([], $email->getBcc());
        $this->assertSame([], $email->getCc());
        $this->assertSame('ops@example.com', $email->getTo()[0]->getAddress());
        $this->assertCount(1, $email->getTo());
        $this->assertNull($email->getHtmlBody());
        $this->assertStringContainsString('&<b>', $email->getTextBody());
        $this->assertStringContainsString("x\nBcc: evil@example.com", $email->getTextBody());
        $headers = $email->getHeaders()->toString();
        $this->assertStringNotContainsString('evil@', $headers);
        $this->assertStringNotContainsString('me@example.com', $headers);
    }

    public function test_no_database_rows_or_session_input_are_left(): void
    {
        Mail::fake();
        $count = fn () => collect(Schema::getTableListing())
            ->mapWithKeys(fn (string $table) => [$table => DB::table(str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)->count()])
            ->all();
        $before = $count();
        $this->send(['body' => '保存しないでください', 'reply_address' => 'me@example.com'])->assertRedirect(route('contact.show'));
        $this->assertSame($before, $count());
        $this->assertNull(session('_old_input'));

        $this->send(['body' => '', 'reply_address' => 'me@example.com'])->assertStatus(422);
        $this->assertNull(session('_old_input'));
        $this->assertNull(session('errors'));
        $this->assertSame($before, $count());
    }

    public function test_reply_address_with_newline_is_rejected(): void
    {
        Mail::fake();
        $res = $this->send(['body' => '本文はそのまま', 'reply_address' => "a@example.com\r\nBcc: x@example.com"])->assertStatus(422);
        $res->assertSee('返信用メールアドレス');
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*>本文はそのまま<\/textarea>/', $res->getContent());
        $this->assertNoInlineCode($res->getContent());
        Mail::assertNothingSent();
    }

    public function test_validation_limits(): void
    {
        Mail::fake();
        $invalid = [
            ['kind' => 'other'],
            ['kind' => ''],
            ['body' => ''],
            ['body' => str_repeat('あ', 2001)],
            ['reply_address' => str_repeat('a', 243).'@example.com'],
            ['reply_address' => 'not-an-email'],
        ];
        foreach ($invalid as $overrides) {
            $this->send($overrides)->assertStatus(422);
        }
        Mail::assertNothingSent();

        $this->send(['body' => str_repeat('あ', 2000)])->assertRedirect(route('contact.show'));
        Mail::assertSentCount(1);
    }

    public function test_validation_error_keeps_the_selected_kind_and_address(): void
    {
        Mail::fake();
        $res = $this->send(['kind' => 'bug', 'body' => '', 'reply_address' => 'me@example.com'])->assertStatus(422);
        $res->assertSee('本文を入力してください');
        $this->assertMatchesRegularExpression('/name="kind" value="bug"[^>]*checked/', $res->getContent());
        $this->assertMatchesRegularExpression('/<input[^>]*name="reply_address"[^>]*value="me@example.com"/', $res->getContent());
    }

    public function test_honeypot_shows_success_without_sending(): void
    {
        Mail::fake();
        $this->send(['website' => 'x'])->assertRedirect(route('contact.show'));
        $this->get('/contact')->assertSee(self::SENT);
        $this->send(['website' => 'x', 'body' => ''])->assertRedirect(route('contact.show'));
        $this->get('/contact')->assertSee(self::SENT);
        Mail::assertNothingSent();
    }

    public function test_rate_limit_three_per_ten_minutes_keeps_input(): void
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->send(['body' => ''])->assertStatus(422);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->send()->assertRedirect(route('contact.show'));
        }
        $res = $this->send(['body' => '4 通目の本文'])->assertStatus(429)->assertHeader('Retry-After');
        $res->assertSee(self::LIMITED);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*>4 通目の本文<\/textarea>/', $res->getContent());
        $this->assertNoInlineCode($res->getContent());
        Mail::assertSentCount(3);
        $this->assertCount(1, $this->logsWithMessage('Rate limit exceeded'));
        $this->assertSame(['limiter' => 'contact'], $this->logsWithMessage('Rate limit exceeded')[0]->context);

        $this->travel(601)->seconds();
        $this->send()->assertRedirect(route('contact.show'));
        Mail::assertSentCount(4);
    }

    public function test_honeypot_hits_count_toward_the_limit(): void
    {
        Mail::fake();
        for ($i = 0; $i < 3; $i++) {
            $this->send(['website' => 'x'])->assertRedirect(route('contact.show'));
        }
        $this->send()->assertStatus(429);
        Mail::assertNothingSent();
    }

    public function test_daily_and_global_limits(): void
    {
        Mail::fake();
        for ($i = 0; $i < 10; $i++) {
            if ($i > 0 && $i % 3 === 0) {
                $this->travel(601)->seconds();
            }
            $this->send()->assertRedirect(route('contact.show'));
        }
        $this->travel(601)->seconds();
        $this->send()->assertStatus(429);
        $this->send(ip: '203.0.113.11')->assertRedirect(route('contact.show'));
        Mail::assertSentCount(11);

        // 39 more from other addresses reach 50 for the day overall.
        for ($i = 0; $i < 39; $i++) {
            $this->send(ip: '198.51.100.'.($i + 1))->assertRedirect(route('contact.show'));
        }
        Mail::assertSentCount(50);
        $this->send(ip: '192.0.2.1')->assertStatus(429)->assertSee(self::LIMITED);
        Mail::assertSentCount(50);

        $this->travel(86400)->seconds();
        $this->send(ip: '192.0.2.1')->assertRedirect(route('contact.show'));
    }

    public function test_mail_failure_shows_message_keeps_input_and_logs_class_only(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('smtp down '.'me@example.com');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $res = $this->send(['body' => '届かない本文', 'reply_address' => 'me@example.com'])->assertStatus(503);
        $res->assertSee(self::FAILED);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*>届かない本文<\/textarea>/', $res->getContent());
        $this->assertNoInlineCode($res->getContent());

        $failed = $this->logsWithMessage('Contact mail failed');
        $this->assertCount(1, $failed);
        $this->assertSame('error', $failed[0]->level);
        $this->assertSame(['exception' => TransportException::class], $failed[0]->context);
        $this->assertSame([], $this->logsWithMessage('Contact sent'));
        $this->assertLogsDoNotContain('届かない本文');
        $this->assertLogsDoNotContain('me@example.com');
        $this->assertNull(session('_old_input'));
    }

    public function test_failed_sends_count_toward_the_limit(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('down');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        for ($i = 0; $i < 3; $i++) {
            $this->send()->assertStatus(503);
        }
        $this->send()->assertStatus(429);
    }

    public function test_unconfigured_recipient_is_a_failure(): void
    {
        Mail::fake();
        foreach (['', null] as $to) {
            config(['listmaker.contact_to_address' => $to]);
            $res = $this->send(['body' => '宛先なし'])->assertStatus(503);
            $res->assertSee(self::FAILED);
            $res->assertSee('宛先なし');
        }
        Mail::assertNothingSent();
        $this->assertCount(2, $this->logsWithMessage('Contact mail not configured'));
        $this->assertSame('error', $this->logsWithMessage('Contact mail not configured')[0]->level);
    }

    public function test_success_redirects_and_reload_does_not_resend(): void
    {
        Mail::fake();
        $this->followingRedirects()->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/contact', ['kind' => 'general', 'body' => '一度だけ'])
            ->assertOk()->assertSee(self::SENT)->assertDontSee('一度だけ');
        $this->get('/contact')->assertOk()->assertDontSee(self::SENT);
        Mail::assertSentCount(1);
    }

    public function test_success_is_logged_with_kind_only(): void
    {
        Mail::fake();
        $this->send(['kind' => 'bug', 'body' => 'ログに出さない', 'reply_address' => 'me@example.com'])->assertRedirect(route('contact.show'));
        $sent = $this->logsWithMessage('Contact sent');
        $this->assertCount(1, $sent);
        $this->assertSame('info', $sent[0]->level);
        $this->assertSame(['kind' => 'bug'], $sent[0]->context);
        $this->assertLogsDoNotContain('ログに出さない');
        $this->assertLogsDoNotContain('me@example.com');
    }

    public function test_smtp_timeout_is_bounded(): void
    {
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
    }

    public function test_csrf_is_required(): void
    {
        Mail::fake();
        $this->enforceCsrf();
        $this->get('/contact');
        $this->send()->assertStatus(419);
        $this->send(['_token' => 'wrong'])->assertStatus(419);
        Mail::assertNothingSent();
        $this->send(['_token' => session()->token()])->assertRedirect(route('contact.show'));
        Mail::assertSentCount(1);
    }

    public function test_honeypot_flood_does_not_close_the_form_for_others(): void
    {
        Mail::fake();
        for ($i = 0; $i < 60; $i++) {
            $this->send(['website' => 'x'], ip: '203.0.113.20')->assertRedirect(route('contact.show'));
        }
        $client = ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.20']));
        $this->assertSame(0, RateLimiter::attempts('contact:all'));
        $this->assertSame(3, RateLimiter::attempts('contact:m:'.$client));
        $this->assertSame(3, RateLimiter::attempts('contact:d:'.$client));

        $this->send(ip: '203.0.113.21')->assertRedirect(route('contact.show'));
        Mail::assertSentCount(1);
        $this->assertSame(1, RateLimiter::attempts('contact:all'));
    }

    public function test_honeypot_with_invalid_input_does_not_count_globally(): void
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->send(['website' => 'x', 'body' => ''])->assertRedirect(route('contact.show'));
        }
        $this->assertSame(0, RateLimiter::attempts('contact:all'));
        $this->assertSame(3, RateLimiter::attempts('contact:m:'.ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.10']))));
    }

    /** @return list<array{string}> */
    public static function nonDeliveringMailers(): array
    {
        return [['log'], ['array']];
    }

    #[DataProvider('nonDeliveringMailers')]
    public function test_non_delivering_mailer_in_production_is_not_configured(string $mailer): void
    {
        Mail::fake();
        $this->app['env'] = 'production';
        // CSRF is only skipped in the testing environment.
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['mail.default' => $mailer]);
        $res = $this->send(['body' => '届かない本文', 'reply_address' => 'me@example.com'])->assertStatus(503);
        $res->assertSee(self::FAILED);
        Mail::assertNothingSent();
        $this->assertCount(1, $this->logsWithMessage('Contact mail not configured'));
        $this->assertSame('error', $this->logsWithMessage('Contact mail not configured')[0]->level);
        $this->assertLogsDoNotContain('届かない本文');
        $this->assertLogsDoNotContain('me@example.com');
    }

    public function test_non_delivering_mailer_outside_production_still_sends(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->send()->assertRedirect(route('contact.show'));
        Mail::assertSentCount(1);
    }
}
