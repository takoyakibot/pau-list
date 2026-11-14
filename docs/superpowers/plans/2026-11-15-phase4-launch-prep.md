# Phase 4 公開準備（List Maker）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 公開と OAuth 審査に必要なコード・文書をそろえる。問い合わせフォーム S8（メール送信のみ）、運営用 `app:delete-proposal`、プライバシーポリシー・利用規約の本文、利用前の同意文・出典表示・未確認アプリ警告の案内、8.8 準拠のログ整理、レート制限キーの見直し（IP のハッシュ化・IPv6 は /64 単位）と `TRUSTED_PROXIES`、Phase 0 の積み残し（#18）、README の最終版。

**Architecture:** 既存部品に足す形で作る。問い合わせは `ContactRequest`（検証・ハニーポット）→ `ContactLimits`（送信試行だけ数える。`ProposalStoreLimits` と同じ形）→ `ContactMessage`（Mailable。本文は生テキストで組み、Blade を通さない）。入力はセッションにも載せない（検証エラー・送信失敗はリダイレクトせず 422／503 でフォームを直接描画）。運営削除はチャンネル主削除のトランザクションを `ProposalDeleter` に切り出して共用する。レート制限のキーはすべて `ClientKey::for()`（app.key の HMAC。IPv6 は /64）に統一し、`TrustProxies` は `.env` の `TRUSTED_PROXIES` があるときだけ有効にする。ログは `bootstrap/app.php` の report コールバックで SQL バインド・URL を落とし、8.8 の「記録する」項目を足す。

**Tech Stack:** PHP 8.4 / Laravel 13.42 / PHPUnit 12（SQLite in-memory）/ `Mail::fake()`・array メーラー / Vite 8 + Tailwind 4 / `@alpinejs/csp` 3.17 / Node 22（`node --test`）

**Spec:** `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（1.4・2.5 S1〜S8・4.3・6.6・8.1・8.2・8.4・8.6・8.7・8.8・9.1・9.2・9.3・10.2・10.4・10.5（問い合わせ・削除）・11 章 Phase 4・12.1 Y10/Y13・12.2 D18・付録 A.6）。Issue #20（IPv6 /64、TrustProxies）、#18（Phase 0 積み残し）、#7（サーバー確認。本計画では設定の口だけ用意）。

**Branch:** `phase4-launch-prep`（`main` から切る。全タスクを 1 PR にまとめる）

## Global Constraints

- Phase 0〜3 の制約はすべて継続: Blade は `{{ }}` のみ（`{!! !!}` 禁止）、インライン `<script>`／`<style>`／`style=`／`on*=` 禁止（`AssertsNoInlineCode`）、Alpine は `@alpinejs/csp` のみで Blade の式は「プロパティ参照」「引数なしメソッド名」だけ、JS は `textContent`／`x-text` のみ、新しい JS エントリを作らない（作る場合は `vite.config.js` の `input` に追加）、`composer.json`／`package.json` の依存を変更しない。
- アプリ名は「List Maker」（Y10。「（仮称）」を全ページ・README から消す）。ページは日本語のみ（Y13）。YouTube のロゴは使わない。
- 外部リンク（固定 URL）: YouTube 利用規約 `https://www.youtube.com/t/terms`、Google プライバシーポリシー `https://policies.google.com/privacy`、承認の取り消し `https://myaccount.google.com/permissions`。外部リンクには `rel="noopener noreferrer" target="_blank"`。
- 保持期間（文書に書く値）: 提案・取り込んだ動画情報・作成の記録 30 日（`DATA_RETENTION_DAYS`）、ログイン情報はセッションのみ（`SESSION_LIFETIME` 120 分、トークン自体は約 1 時間）、限定公開動画の一覧はセッションに 10 分、アプリログ 14 日、問い合わせメールは対応後 90 日以内に削除、削除依頼は 7 日以内。
- youtube スコープの用途の文言（spec 9.1、既存ホームと同一）: 「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」。
- 問い合わせ（8.2・8.7）: 種別は固定 3 値 `general`＝一般／`deletion`＝削除依頼／`bug`＝不具合。本文 1〜2,000 文字。返信用メールアドレスは任意・RFC 形式・≤254 文字・改行不可。件名は固定 `[List Maker] お問い合わせ（{種別ラベル}）`。Reply-To を付けない。利用者入力をヘッダに入れない。IP・User-Agent を本文に入れない。DB・セッション・ログに本文とアドレスを書かない。宛先は `CONTACT_TO_ADDRESS`（`filled()` で判定。空文字は未設定扱い。#18）。
- レート制限（8.1）: 値は変えない（下の Task 1 の表で固定）。キーに生の IP を使わない（`ClientKey::for()` だけ）。
- ログ（8.8）: アクセストークン・API キー・認可コード・公開キー（必要なら `key_hash` の先頭 8 文字）・IP・問い合わせの本文とアドレス・SQL のバインド値・リクエスト URL のクエリを出さない。本番の `LOG_LEVEL` は `info`（理由は Task 6）。
- テストは実 API・実 SMTP を使わない（`Http::preventStrayRequests()`、メールは `Mail::fake()` か array メーラー／テスト用の失敗トランスポート）。
- コミット前に毎回 `./vendor/bin/pint` と `php artisan test`（JS を触ったタスクは `npm run test:js` も）。

## Review Focus

1. **SMTP が応答しない・認証失敗**: 同期送信なので、利用者が 10 秒程度で「送信できませんでした。時間をおいて再度お試しください」を見て、入力した本文が画面に残り、ログには例外クラスだけが残ること。→ Task 2 `test_mail_failure_shows_message_keeps_input_and_logs_class_only`・`test_smtp_timeout_is_bounded`。
2. **返信用アドレス・本文へのヘッダインジェクション**（`a@example.com\r\nBcc: x@example.com`、本文中の `\r\nBcc:`）: アドレスは 422 で拒否、本文は本文のまま送られ、実際の送信メッセージに Bcc・Reply-To が無いこと。→ Task 2 `test_reply_address_with_newline_is_rejected`・`test_sent_message_headers_have_no_user_input`。
3. **IPv6 の利用者がアドレスを替えて制限を回避／同じ /64 の別端末**: 同じ /64 は同じ制限を共有し、IPv4 射影アドレスは IPv4 と同じキー、`REMOTE_ADDR` が空・不正でも 500 にならないこと。→ Task 1 `test_ipv6_addresses_in_one_64_share_the_global_limit`・`ClientKeyTest`。
4. **運営が作成中の提案を削除した直後に、チャンネル主のブラウザが次のステップを送る**: 500 にならず 404 JSON で止まり、予約が解放されていること。→ Task 3 `test_running_creation_is_deleted_and_next_step_is_404`。
5. **問い合わせ送信後の再読み込み・戻る**: 再送されず（PRG）、完了表示は 1 回だけ出ること。→ Task 2 `test_success_redirects_and_reload_does_not_resend`。

---

## File Structure

| ファイル | 責務 |
|---|---|
| `app/Support/ClientKey.php` | レート制限キー（IP の HMAC、IPv6 は /64） |
| `app/Providers/AppServiceProvider.php` | 全 IP キーを `ClientKey` に、`TRUSTED_PROXIES`、`contact` の値は `ContactLimits` |
| `app/Support/{ProposalStoreLimits,ChannelFetchThrottle,ContactLimits,RateLimitLog}.php` | 成功回数の制限・チャンネル取得の制限・問い合わせの制限・超過ログ |
| `config/listmaker.php`, `config/mail.php`, `.env.example` | `trusted_proxies`、SMTP タイムアウト 10 秒、`TRUSTED_PROXIES=` |
| `app/Http/Requests/ContactRequest.php`, `app/Mail/ContactMessage.php`, `app/Http/Controllers/ContactController.php`, `resources/views/contact/show.blade.php` | S8 |
| `app/Services/ProposalDeleter.php`, `app/Console/Commands/DeleteProposal.php`, `app/Support/ProposalKey.php` | 運営削除とチャンネル主削除の共通処理、URL/キーの解析 |
| `resources/views/static/{privacy,terms}.blade.php` | S7 本文 |
| `resources/views/home.blade.php`, `partials/owner-login.blade.php`, `partials/unverified-app.blade.php`, `proposals/{create,shared}.blade.php`, `owner/edit.blade.php`, `resources/js/lib/format.js`, `resources/js/{propose,owner-edit}.js` | 同意文・出典・未確認アプリ案内 |
| `bootstrap/app.php`, `app/Services/YouTube/YouTubeReader.php`, `app/Services/Creation/{CreationRunner,CreationStarter,CreationLifecycle}.php`, `app/Http/Controllers/{ChannelController,ProposalController}.php` | ログ整理 |
| `resources/views/errors/*.blade.php`, `app/Http/Middleware/SecurityHeaders.php`, `deploy.sh`, `.exclude-list` | #18 |
| `README.md`, `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md` | 最終版と仕様への追記 |

---

### Task 1: レート制限キーの統一（IP の HMAC・IPv6 /64）と TRUSTED_PROXIES

**Files:**
- Create: `app/Support/ClientKey.php`, `tests/Unit/ClientKeyTest.php`, `tests/Feature/TrustedProxiesTest.php`, `tests/Feature/RateLimitValuesTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Support/ProposalStoreLimits.php`, `app/Support/ChannelFetchThrottle.php`, `config/listmaker.php`, `.env.example`, `tests/Feature/RateLimitTest.php`（`test_channel_fetch_throttle_lock_timeout_returns_false` のロック名）

**Interfaces:**
- Produces:
  - `App\Support\ClientKey::network(?string $ip): string` — 純関数。IPv4 はそのまま、IPv4 射影 IPv6（`::ffff:a.b.c.d`）は IPv4 に、IPv6 は先頭 64 ビットを `inet_ntop` した文字列＋`/64`（例 `2001:db8:1:2::/64`）、`null`・空・不正は `'unknown'`。
  - `App\Support\ClientKey::for(Request $request): string` — `hash_hmac('sha256', self::network($request->ip()), (string) config('app.key'))`（64 桁の hex）。以後、IP を使う制限キーはすべてこれ（`->ip()` を直接使ってよいのは `ClientKey` だけ）。
  - `App\Providers\AppServiceProvider::trustProxiesFrom(mixed $value): void`（public static）— `filled($value)` でなければ何もしない。`'*'` はそのまま、それ以外はカンマ区切りを trim して配列で `Illuminate\Http\Middleware\TrustProxies::at(...)`。ヘッダは既定のまま。`boot()` から `config('listmaker.trusted_proxies')` で呼ぶ。
  - `config('listmaker.trusted_proxies')` = `env('TRUSTED_PROXIES')`。

- [ ] **Step 1: ブランチを作る**

Run: `git switch main && git pull && git switch -c phase4-launch-prep`

- [ ] **Step 2: 失敗するテストを書く**

```php
// tests/Unit/ClientKeyTest.php
public function test_ipv4_is_kept_and_mapped_ipv6_equals_ipv4(): void
{
    $this->assertSame('203.0.113.5', ClientKey::network('203.0.113.5'));
    $this->assertSame('203.0.113.5', ClientKey::network('::ffff:203.0.113.5'));
}
public function test_ipv6_is_reduced_to_its_64(): void
{
    $this->assertSame('2001:db8:1:2::/64', ClientKey::network('2001:db8:1:2:aaaa::1'));
    $this->assertSame(ClientKey::network('2001:db8:1:2:aaaa::1'), ClientKey::network('2001:DB8:1:2:ffff:ffff:ffff:ffff'));
    $this->assertNotSame(ClientKey::network('2001:db8:1:2::1'), ClientKey::network('2001:db8:1:3::1'));
}
public function test_missing_or_invalid_ip_is_unknown(): void
{
    foreach ([null, '', 'not-an-ip', '999.1.1.1'] as $ip) { $this->assertSame('unknown', ClientKey::network($ip)); }
}
// tests/Feature/... の中で（app.key が要るので Feature 側に置いてよい）
public function test_key_is_hmac_hex_without_the_ip(): void
{
    $key = ClientKey::for(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']));
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    $this->assertNotSame(hash('sha256', '203.0.113.5'), $key);   // 素の SHA-256 ではない（総当たりで戻せない）
}
```

```php
// tests/Feature/RateLimitTest.php に追加
public function test_ipv6_addresses_in_one_64_share_the_global_limit(): void
{
    for ($i = 0; $i < 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i + 1)])->get('/')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::1'])->get('/')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->get('/')->assertOk();
}
public function test_only_client_key_reads_the_ip(): void
{
    // app/ 以下で "->ip()" を含むファイルは app/Support/ClientKey.php だけ
}
public function test_proposal_store_limits_use_client_key(): void
{
    // ProposalStoreLimits::hit($req) 後に RateLimiter::attempts('proposal-store:m:'.ClientKey::for($req)) === 1、
    // RateLimiter::attempts('proposal-store:m:203.0.113.5') === 0
}
```

```php
// tests/Feature/RateLimitValuesTest.php — 8.1 の値の固定（見直しの結果: 値は変えない）
public static function limiters(): array
{
    return [
        // 名前, maxAttempts, decaySeconds
        ['global', 120, 60], ['video-lookup', 30, 60], ['oauth', 10, 60], ['owner-unlisted', 5, 600],
        ['creation-start', 5, 3600], ['creation-step', 2, 1], ['creation-operation', 30, 60], ['proposal-post', 20, 600],
    ];
}
#[DataProvider('limiters')]
public function test_named_limiter_values(string $name, int $max, int $decay): void
{
    // $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']); $request->setLaravelSession(app('session.store'));
    // $limit = RateLimiter::limiter($name)($request); 配列なら先頭。
    // assertSame($max, $limit->maxAttempts); assertSame($decay, $limit->decaySeconds);
    // IP キーの制限（global, video-lookup, oauth, proposal-post）は $limit->key に '203.0.113.5' を含まない
}
```

```php
// tests/Feature/TrustedProxiesTest.php（tearDown で TrustProxies::flushState()）
// setUp: Route::get('/_ip', fn (Request $r) => $r->ip());
public function test_proxies_are_not_trusted_by_default(): void
{
    AppServiceProvider::trustProxiesFrom(null);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->withHeader('X-Forwarded-For', '203.0.113.7')
        ->get('/_ip')->assertSeeText('10.0.0.1');
}
public function test_empty_string_is_not_trusted(): void { /* trustProxiesFrom('') → 10.0.0.1 */ }
public function test_listed_proxy_is_trusted(): void { /* trustProxiesFrom('192.168.0.1, 10.0.0.0/8') → 203.0.113.7 */ }
public function test_unlisted_sender_is_not_trusted(): void { /* trustProxiesFrom('10.0.0.2') で REMOTE_ADDR 10.0.0.1 → 10.0.0.1 */ }
```

- [ ] **Step 3: テストが失敗することを確認する**

Run: `php artisan test --filter='ClientKeyTest|TrustedProxiesTest|RateLimitValuesTest|RateLimitTest'`
Expected: FAIL（`ClientKey` 未定義、`trustProxiesFrom` 未定義、IPv6 の 121 回目が 200）

- [ ] **Step 4: 実装する**

- `ClientKey`（上記 Interfaces）。IPv6 の /64 は `inet_pton` の先頭 8 バイト＋8 バイトのゼロを `inet_ntop`。
- `AppServiceProvider::configureRateLimiting()` の `$request->ip()` をすべて `ClientKey::for($request)` に置き換える（`oauth:`・`owner-unlisted:ip:`・`creation-start:ip:`・`proposal-post:` の接頭辞は残す）。`boot()` に `self::trustProxiesFrom(config('listmaker.trusted_proxies'))`。
- `ProposalStoreLimits::limits()`・`ChannelFetchThrottle`（`hash('sha256', ip)` を `ClientKey::for()` に）。`RateLimitTest::test_channel_fetch_throttle_lock_timeout_returns_false` のロック名も `'channel-fetch-guard:'.ClientKey::for($req)` に合わせる。
- `config/listmaker.php` に `'trusted_proxies' => env('TRUSTED_PROXIES')`。`.env.example` の `CONTACT_TO_ADDRESS=` の後に:

```
# 信頼するリバースプロキシ（カンマ区切りの IP/CIDR、または *）。空なら信頼しない（#7 の確認後に設定）
TRUSTED_PROXIES=
```

- [ ] **Step 5: テストが通ることを確認する**

Run: `php artisan test`
Expected: PASS（既存の制限・作成・提案のテストも含めて全件）

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add app/Support/ClientKey.php app/Providers/AppServiceProvider.php app/Support/ProposalStoreLimits.php app/Support/ChannelFetchThrottle.php config/listmaker.php .env.example tests/Unit/ClientKeyTest.php tests/Feature/TrustedProxiesTest.php tests/Feature/RateLimitValuesTest.php tests/Feature/RateLimitTest.php
git commit -m "feat: レート制限のキーを IP の HMAC（IPv6 は /64 単位）に統一し、TRUSTED_PROXIES で信頼するプロキシを設定できるようにする"
```

---

### Task 2: 問い合わせフォーム S8（メール送信のみ）

**Files:**
- Create: `app/Http/Requests/ContactRequest.php`, `app/Mail/ContactMessage.php`, `app/Support/ContactLimits.php`, `tests/Feature/ContactFormTest.php`
- Modify: `app/Http/Controllers/ContactController.php`, `resources/views/contact/show.blade.php`, `routes/web.php`, `config/mail.php`（smtp の `timeout`）, `tests/Feature/PagesTest.php`（ルート一覧に `contact` をもう 1 つ）, `tests/Feature/RateLimitValuesTest.php`

**Interfaces:**
- Consumes: `ClientKey::for(Request): string`（Task 1）
- Produces:
  - ルート `POST /contact` → `ContactController@store`、名前 `contact.store`。
  - `ContactRequest::KINDS = ['general' => '一般', 'deletion' => '削除依頼', 'bug' => '不具合']`。入力名 `kind`／`body`／`reply_address`／`website`（ハニーポット）。`isHoneypotHit(): bool`。
  - `ContactMessage extends Mailable`: `__construct(public readonly string $kind, public readonly string $body, public readonly ?string $replyAddress, public readonly CarbonImmutable $sentAt)`、`envelope(): Envelope`（件名のみ）、`plainBody(): string`、`protected function buildView(): array` は `['raw' => $this->plainBody()]`（Blade を通さないので `&` などがエスケープされず、`{!! !!}` も不要）。
  - `ContactLimits`: `retryAfter(Request): ?int`、`hit(Request): void`。キーと値は `['contact:m:'.ClientKey, 3, 600]`、`['contact:d:'.ClientKey, 10, 86400]`、`['contact:all', 50, 86400]`。数えるのは「メール送信を試みた回（成功・失敗とも）」と「ハニーポット」だけ（検証エラーは数えない）。
  - 画面文言（固定）: 完了「お問い合わせを送信しました」、失敗「送信できませんでした。時間をおいて再度お試しください」、制限「送信が集中しています。しばらく待ってから再度お試しください」、フォームの注意（8.7 そのまま）「入力内容は運営者へのメールとして送られ、本サービスのデータベースには保存されません。返信が必要な場合のみメールアドレスを入力してください」、削除依頼の案内「削除依頼の場合は、本文に提案の URL（https://list-maker.alpacasandbag.jp/p/…）を書いてください。7 日以内に削除します」。

本文（`plainBody()` の形式。返信用アドレスの行は入力があるときだけ）:

```
種別: 削除依頼
送信日時: 2026年11月15日 12:34（日本時間）

{本文}

返信用メールアドレス: someone@example.com
```

- [ ] **Step 1: 失敗するテストを書く**

`ContactFormTest`（`RefreshDatabase`。`setUp` で `config(['listmaker.contact_to_address' => 'ops@example.com'])`）:

```php
public function test_form_shows_fields_notice_and_honeypot(): void
{ /* GET /contact: name="kind" の 3 つの value、textarea name="body" maxlength="2000"、input name="reply_address" maxlength="254"、
     name="website"、注意文と削除依頼の案内、action が route('contact.store')、assertNoInlineCode */ }

public function test_sends_text_mail_with_fixed_subject_and_reply_address_in_body(): void
{
    Mail::fake();
    $this->post('/contact', ['kind' => 'deletion', 'body' => "消してください\nhttps://list-maker.alpacasandbag.jp/p/xxx", 'reply_address' => 'me@example.com'])
        ->assertRedirect(route('contact.show'));
    Mail::assertSent(ContactMessage::class, fn (ContactMessage $m) => $m->hasTo('ops@example.com')
        && $m->hasSubject('[List Maker] お問い合わせ（削除依頼）')
        && str_contains($m->plainBody(), "種別: 削除依頼")
        && str_contains($m->plainBody(), '返信用メールアドレス: me@example.com')
        && $m->replyTo === []);
}
public function test_reply_address_line_is_absent_when_not_given(): void { /* plainBody に '返信用メールアドレス' が無い */ }
public function test_sent_message_headers_have_no_user_input(): void
{
    // Mail::fake() を使わず array メーラー（phpunit.xml の MAIL_MAILER=array）で実際に組み立てる。
    // body に "x\r\nBcc: evil@example.com" と '&<b>' を含めて送信。
    // $email = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
    // getSubject() === '[List Maker] お問い合わせ（一般）'、getReplyTo() === []、getBcc() === []、getHtmlBody() === null、
    // getTextBody() が '&<b>' をそのまま含む、ヘッダ全体（$email->getHeaders()->toString()）に 'evil@' が無い
}
public function test_no_database_rows_or_session_input_are_left(): void
{
    // 全テーブル（Schema::getTableListing()）の件数を送信前後で比較して同じ。
    // 検証エラー（body 空）の応答後も session('_old_input') が null（入力をセッションに置かない）
}
public function test_reply_address_with_newline_is_rejected(): void
{ /* reply_address = "a@example.com\r\nBcc: x@example.com" → 422、フォームが本文を保ったまま表示、Mail::assertNothingSent */ }
public function test_validation_limits(): void
{ /* kind 不正値・body 空・body 2001 文字・reply_address 255 文字・'not-an-email' がそれぞれ 422 で未送信。body 2000 文字は送信される */ }
public function test_honeypot_shows_success_without_sending(): void
{ /* website='x' → contact.show へリダイレクトして「お問い合わせを送信しました」、Mail::assertNothingSent。body 空＋website でも同じ */ }
public function test_rate_limit_three_per_ten_minutes_keeps_input(): void
{ /* 3 回送信後、4 回目は 429 でフォームに制限文言と入力した本文、未送信。travel(601) 秒で再び送れる。
     検証エラーの 5 回は数えない（その後も 3 回送れる） */ }
public function test_daily_and_global_limits(): void
{ /* 別の IP（REMOTE_ADDR）から送り分けて、同一 IP は 10 回/日、全体は 50 件/日で止まる（travel で 10 分枠を進める） */ }
public function test_mail_failure_shows_message_keeps_input_and_logs_class_only(): void
{
    // Mail::extend('failing', fn () => new class extends AbstractTransport { protected function doSend(SentMessage $m): void { throw new TransportException('smtp down '.'me@example.com'); } public function __toString(): string { return 'failing'; } });
    // config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    // 503、失敗文言、textarea に本文。MessageLogged を集めて: 'Contact mail failed' の context が ['exception' => TransportException::class] だけで、
    // どのログにも本文・'me@example.com' が無い
}
public function test_unconfigured_recipient_is_a_failure(): void
{ /* contact_to_address を ''（空文字）にして 503・失敗文言・未送信・'Contact mail not configured' のログ */ }
public function test_success_redirects_and_reload_does_not_resend(): void
{ /* 送信 → followRedirects で完了文言 → もう一度 GET /contact では完了文言が出ない。Mail::assertSentCount(1) */ }
public function test_success_is_logged_with_kind_only(): void { /* 'Contact sent' の context === ['kind' => 'bug'] */ }
public function test_smtp_timeout_is_bounded(): void { $this->assertSame(10, config('mail.mailers.smtp.timeout')); }
public function test_csrf_is_required(): void { /* EnforcesCsrf: トークン無しは 419、未送信 */ }
```

`RateLimitValuesTest` に「`ContactLimits` の 3 つの (キー接頭辞, 回数, 秒) が `contact:m:`/3/600・`contact:d:`/10/86400・`contact:all`/50/86400」を追加。`PagesTest::test_only_spec_routes_are_registered` の期待配列に `'contact'` をもう 1 つ（GET と POST）。

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter='ContactFormTest|PagesTest|RateLimitValuesTest'`
Expected: FAIL（`POST /contact` が 405、クラス未定義）

- [ ] **Step 3: 実装する**

- `ContactRequest`: `prepareForValidation` で `body` だけ `TextInput::multiLine`、`reply_address` は前後の空白だけ除く（改行は消さずに検証で弾く）。ルール: `kind` `required|string|in:general,deletion,bug`、`body` `required|string|max:2000`、`reply_address` `nullable|string|max:254|not_regex:/[\r\n]/|email:rfc`、`website` `nullable|string`。日本語メッセージ（`StoreProposalRequest` の書き方に合わせる。属性名: 種別・本文・返信用メールアドレス）。`failedValidation`: ハニーポットなら `app(ContactLimits::class)->hit($this)` して `contact.show` へ `contact_sent` を flash してリダイレクト、そうでなければ **リダイレクトせず** `response()->view('contact.show', ['input' => <website 以外の入力>, 'errors' => (new ViewErrorBag)->put('default', $validator->errors())], 422)` を `HttpResponseException` で返す（入力をセッションに flash しないため）。
- `ContactController::store(ContactRequest $request, ContactLimits $limits)`: 順に ①ハニーポット → hit・成功と同じリダイレクト ②`retryAfter() !== null` → `RateLimitLog::exceeded('contact')`（Task 6 で作る。Task 2 の時点では `Log::info('Rate limit exceeded', ['limiter' => 'contact'])` と書き、Task 6 で置き換える）・制限文言で 429 描画（`Retry-After` ヘッダ付き） ③`! filled(config('listmaker.contact_to_address'))` → `Log::error('Contact mail not configured')`・失敗文言で 503 描画 ④`Mail::to($to)->send(new ContactMessage(...))`、`catch (\Throwable $e)` → `hit`・`Log::error('Contact mail failed', ['exception' => $e::class])`・失敗文言で 503 描画 ⑤`hit`・`Log::info('Contact sent', ['kind' => $kind])`・`redirect()->route('contact.show')->with('contact_sent', true)`。描画はすべて `contact.show` に `input`（本文・種別・アドレス）とメッセージを渡す。
- `show()`: `contact.show` に `input = []`。ビューは `old()` を使わず `$input` から値を入れる。`session('contact_sent')` があれば完了文言を出す（フォームも下に出してよい）。
- `config/mail.php` の smtp `'timeout' => 10`。

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Http/Requests/ContactRequest.php app/Mail/ContactMessage.php app/Support/ContactLimits.php app/Http/Controllers/ContactController.php resources/views/contact/show.blade.php routes/web.php config/mail.php tests/Feature/ContactFormTest.php tests/Feature/PagesTest.php tests/Feature/RateLimitValuesTest.php
git commit -m "feat: 問い合わせフォーム（S8）を追加する（メール送信のみ・DB とセッションに入力を残さない・件名固定・Reply-To なし・ハニーポット・送信回数の制限）"
```

---

### Task 3: 運営の削除コマンド `app:delete-proposal` と削除処理の共通化

**Files:**
- Create: `app/Services/ProposalDeleter.php`, `app/Console/Commands/DeleteProposal.php`, `tests/Feature/DeleteProposalCommandTest.php`
- Modify: `app/Http/Controllers/OwnerDeleteController.php`（トランザクションを `ProposalDeleter` に移す。振る舞いは変えない）, `app/Support/ProposalKey.php`, `tests/Unit/ProposalKeyTest.php`

**Interfaces:**
- Produces:
  - `App\Services\ProposalDeleter::delete(Proposal $proposal, bool $evenIfRunning = false): bool` — `OwnerDeleteController::destroy` にある処理（`ensureWriteRow` → 作成行を主キーでロック → `withWriteLock` → 予約の解放 → `quota_reservations` → `playlist_creation_items` → `playlist_creations` → `proposal_items` → `proposals` の順に明示削除、`DB::transaction(..., 3)`）。作成が `completed` 以外なら、`$evenIfRunning` が false のとき何もせず false、true のときは同じ順序で削除する（予約の未使用分は `WriteLock::release()` で戻す）。`QueryException` はそのまま投げる（呼び出し側がログ）。
  - `App\Support\ProposalKey::fromInput(string $input): ?string` — 前後空白を除き、43 文字のキーそのもの、または `/p/{key}` を含む URL（後ろに `/edit`・`/creation`・`/shared`・`?…`・`#…` が付いてもよい）からキーを取り出す。どちらでもなければ null。
  - コマンド `app:delete-proposal {target : 提案の URL またはキー} {--force : 確認せずに削除する}`。期限切れで未削除の提案も対象（`expires_at` を見ない）。表示するのは チャンネル ID の先頭 8 文字・提案名・本数・作成日・作成の状態（無ければ「なし」）だけで、キー・key_hash は出さない。確認文「この提案を削除しますか？（作成中の場合は作成も止めて削除します。YouTube の再生リストは残ります）」（既定 no）。成功で `deleted`、`Log::info('Proposal deleted by operator', ['key_hash_prefix' => 先頭 8 文字, 'creation' => 状態 or null])`。見つからない・形式不正は同じ `proposal not found`（FAILURE）。確認で no・`--no-interaction` で `--force` なしは `cancelled`（FAILURE、何も消さない）。DB 失敗は `Log::error('Proposal delete failed (database)', ['sqlstate' => …])` と `failed`（FAILURE）。

- [ ] **Step 1: 失敗するテストを書く**

```php
// tests/Unit/ProposalKeyTest.php に追加
public function test_from_input_accepts_key_and_urls(): void
{
    $k = str_repeat('A', 40).'b-_';
    $this->assertSame($k, ProposalKey::fromInput("  $k "));
    $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k"));
    $this->assertSame($k, ProposalKey::fromInput("https://list-maker.alpacasandbag.jp/p/$k/edit?x=1"));
    $this->assertSame($k, ProposalKey::fromInput("http://localhost:8000/p/$k#top"));
    $this->assertNull(ProposalKey::fromInput(substr($k, 1)));            // 42 文字
    $this->assertNull(ProposalKey::fromInput("https://example.com/p/{$k}x")); // 44 文字目が続く
    $this->assertNull(ProposalKey::fromInput(''));
}
```

`DeleteProposalCommandTest`（`RefreshDatabase`, `CreatesProposals`。ログは `Event::listen(MessageLogged::class, …)` で集める）:

```php
public function test_deletes_by_key_with_force(): void { /* proposals・proposal_items が 0、出力 'deleted'、出力にキーが無い */ }
public function test_deletes_by_url_after_confirmation(): void
{ /* artisan('app:delete-proposal', ['target' => url("/p/$key/edit")])->expectsConfirmation('この提案を削除しますか？（作成中の場合は作成も止めて削除します。YouTube の再生リストは残ります）', 'yes') */ }
public function test_declining_or_non_interactive_deletes_nothing(): void { /* 'no' と --no-interaction の両方で FAILURE・'cancelled'・件数そのまま */ }
public function test_expired_but_not_yet_pruned_proposal_is_deleted(): void { /* expires_at = 過去 */ }
public function test_unknown_or_malformed_target_is_not_found(): void { /* 存在しないキー・'abc' → FAILURE 'proposal not found'、他の提案は残る */ }
public function test_running_creation_is_deleted_and_next_step_is_404(): void
{
    // makeCreation(3, ['status' => 'adding'], reserve: 200) → --force で削除
    // quota_reservations・playlist_creation_items・playlist_creations が 0、当日の writeFreeUnits が予約前と同じ
    // actAsOwner($channelId) のまま postJson("/p/$key/creation/step")（RequireSameOriginFetch の要件を満たすヘッダ付き）→ 404
}
public function test_completed_creation_is_deleted_too(): void { /* status=completed も削除 */ }
public function test_log_has_hash_prefix_only(): void
{ /* 'Proposal deleted by operator' の context が ['key_hash_prefix' => substr(hash,0,8), 'creation' => null]、どのログにもキーと完全な hash が無い */ }
```

`OwnerDeleteProposalTest` は変更せずに通ること（共通化の回帰確認）。

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter='DeleteProposalCommandTest|ProposalKeyTest'`
Expected: FAIL（コマンド未登録、`fromInput` 未定義）

- [ ] **Step 3: 実装する**

`ProposalDeleter` に `OwnerDeleteController::destroy` のトランザクション本体を移し、コントローラは `$deleted = $deleter->delete($proposal)` を呼ぶだけにする（`QueryException` の捕捉・ログ・リダイレクトはコントローラに残す）。コマンドは `Proposal::where('key_hash', ProposalKey::hash($key))->first()` で引く。

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test`
Expected: PASS（`OwnerDeleteProposalTest`・`PruneExpiredDataTest` を含む）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/ProposalDeleter.php app/Console/Commands/DeleteProposal.php app/Http/Controllers/OwnerDeleteController.php app/Support/ProposalKey.php tests/Feature/DeleteProposalCommandTest.php tests/Unit/ProposalKeyTest.php
git commit -m "feat: 削除依頼に対応する app:delete-proposal（URL またはキー、確認つき、作成中でも作成記録ごと削除）を追加し、削除処理を ProposalDeleter に共通化する"
```

---

### Task 4: プライバシーポリシーと利用規約の本文（S7）

**Files:**
- Modify: `resources/views/static/privacy.blade.php`, `resources/views/static/terms.blade.php`
- Create: `tests/Feature/LegalPagesTest.php`

**Interfaces:**
- Consumes: `route('contact.show')`（既存）、Task 2 の問い合わせの扱い、Task 3 のコマンドによる 7 日以内の削除。
- Produces: 見出しの `id`（他ページからのリンク先）: `/privacy#youtube`・`#data`・`#browser-storage`・`#revoke`・`#deletion`・`#contact`、`/terms#youtube-terms`。

本文は下の見出しと**必須文（テストで固定）**を含めて、自然な日本語の段落・箇条書きで書く。必須文以外の言い回しは実装者に任せる。最終更新日は「最終更新: 2026年11月15日」。

プライバシーポリシー（`<h2 id>` の順）:
1. `id="about"` 本サービスについて — 運営者が個人で運営する「List Maker」（`https://list-maker.alpacasandbag.jp`）であること。YouTube・Google とは提携していないこと。
2. `id="youtube"` YouTube API Services の利用 — 必須文「本サービスは YouTube API Services を使用しています」。Google プライバシーポリシーへのリンク（必須文「YouTube API Services による情報の取り扱いには Google プライバシーポリシーが適用されます」）と YouTube 利用規約へのリンク。
3. `id="data"` 取得する情報と利用目的 — 次の 6 項目を箇条書き:
   - 提案の内容（再生リスト名・説明・コメント・ニックネーム・選んだ動画）: 共有 URL での表示とチャンネル主の作成のため。
   - YouTube の公開情報（動画のタイトル・サムネイル画像の URL・再生時間・公開日、チャンネル名・アイコンの URL）: 一覧と提案の表示のため。サムネイル画像は保存せず YouTube の URL を表示するだけ。
   - チャンネル主のログイン情報: 必須文「Google ログインで許可いただく YouTube の権限（youtube スコープ）は、チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います」。取得するのはチャンネル ID・チャンネル名・アクセストークンだけで、メールアドレス・氏名・リフレッシュトークンは取得しない。
   - チャンネル主が読み込んだ自分の限定公開動画の一覧: 必須文「限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します」。他の利用者には表示しない。
   - 作成の記録（作成した内容・作成した再生リストの ID・進み具合）。
   - 問い合わせ（項目 7 へ）。
   - 加えて必須文「IP アドレスはデータベースに保存しません」。回数制限のため IP アドレスから計算した復元できない値を最長 1 日だけ一時保存すること、Web サーバーのアクセスログはホスティング事業者が管理することも書く。
4. `id="retention"` 保存場所と期間 — 必須文「提案と、YouTube から取得した動画の情報は、最長 30 日で削除します」「アクセストークンは暗号化したセッションにだけ保存し、ログアウトまたは最長 120 分で削除します」。アプリのログは 14 日（IP・本文は含まない）。作成した再生リストはチャンネル主の YouTube に残り、本サービスは保持しないこと。
5. `id="browser-storage"` Cookie とブラウザへの保存 — Cookie はセッション用の 1 つだけ（アクセス解析・広告のタグは使わない）。必須文「提案作成中の選択と『この端末で作った提案』（共有 URL）はブラウザの localStorage に保存し、サーバーには送りません」「チャンネル主の編集中の内容はブラウザの sessionStorage に保存し、タブを閉じると消えます」。
6. `id="sharing"` 第三者への提供 — 提供しない。ただしチャンネル主の操作による YouTube への送信（再生リストの作成・動画の追加）と、YouTube API Services の利用に伴う Google への送信を除く。
7. `id="contact-form"` 問い合わせの取り扱い — 必須文「問い合わせの内容は運営者へのメールとして送られ、本サービスのデータベースには保存しません」「返信用のメールアドレスの入力は任意です」「受け取ったメールは対応の完了後 90 日以内に削除します」。
8. `id="revoke"` 承認の取り消し — ログアウトすると Google 側の承認も取り消すこと。必須文「Google アカウントの サードパーティのアクセス（https://myaccount.google.com/permissions）からいつでも承認を取り消せます」（リンク付き）。取り消し後は本サービスにトークンが残らないこと。
9. `id="deletion"` 削除の依頼 — 必須文「提案などの削除は、お問い合わせフォームから種別『削除依頼』でご依頼ください。7 日以内に削除します」。チャンネル主は自分のチャンネル宛ての提案を編集画面から削除できること。
10. `id="contact"` お問い合わせ先 — 問い合わせフォームへのリンク（プライバシーに関する問い合わせもここで受けること）。
11. `id="changes"` 改定 — 変更時はこのページで告知。最終更新日。

利用規約（`<h2 id>` の順）:
1. `id="about"` 本サービス — YouTube の再生リストを提案・作成するための非公式ツールで、YouTube・Google とは提携していない。
2. `id="youtube-terms"` YouTube 利用規約 — 必須文「本サービスは YouTube API Services を使用しています。本サービスを利用することで、YouTube 利用規約（https://www.youtube.com/t/terms）に同意したものとみなされます」（リンク付き）。Google プライバシーポリシーとプライバシーポリシー（`route('privacy')`）へのリンク。
3. `id="consent"` 同意 — 必須文「提案の保存、または Google でのログインをもって、この利用規約とプライバシーポリシーに同意したものとみなします」。
4. `id="proposals"` 提案の内容 — 提案者が内容に責任を持つ。他人の権利の侵害・誹謗中傷・宣伝・個人情報（実名など）を書かない。必須文「共有 URL を知っている人は誰でも提案を閲覧できます」。運営者は不適切な提案を予告なく削除できる。
5. `id="retention"` 保存期間 — 必須文「提案は作成から 30 日で自動的に削除されます」。
6. `id="creation"` チャンネル主の操作 — 再生リストの作成はチャンネル主が「作成」を押したときだけ行う書き込み操作であること。作成した再生リストの管理はチャンネル主の責任。
7. `id="disclaimer"` 免責 — 無保証・中断や変更・YouTube 側の仕様変更や上限による失敗・損害の免責。
8. `id="changes"` 変更 — 告知方法。最終更新日。
9. `id="contact"` お問い合わせ — フォームへのリンク。

- [ ] **Step 1: 失敗するテストを書く**

```php
// tests/Feature/LegalPagesTest.php
public function test_privacy_contains_required_statements_and_links(): void
{
    $res = $this->get('/privacy')->assertOk();
    foreach (['本サービスは YouTube API Services を使用しています', 'チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加にのみ使います',
        '限定公開の動画の一覧は、ログイン中のセッションに 10 分間だけ保存します', 'IP アドレスはデータベースに保存しません',
        '最長 30 日で削除します', '最長 120 分で削除します', 'localStorage', 'sessionStorage',
        '本サービスのデータベースには保存しません', '返信用のメールアドレスの入力は任意です', '対応の完了後 90 日以内に削除します',
        '7 日以内に削除します', '最終更新: 2026年11月15日'] as $text) {
        $res->assertSeeText($text);
    }
    foreach (['https://policies.google.com/privacy', 'https://www.youtube.com/t/terms', 'https://myaccount.google.com/permissions', route('contact.show')] as $href) {
        // フッター以外（<main> 内）にもあること: <main> の部分文字列に href="..." がある
    }
    foreach (['youtube', 'data', 'browser-storage', 'revoke', 'deletion', 'contact'] as $id) { $res->assertSee('id="'.$id.'"', false); }
}
public function test_terms_contains_required_statements_and_links(): void
{ /* 'YouTube 利用規約（https://www.youtube.com/t/terms）に同意したものとみなされます'、'Google でのログインをもって'、
     '共有 URL を知っている人は誰でも提案を閲覧できます'、'作成から 30 日で自動的に削除されます'、<main> 内に youtube terms・google privacy・route('privacy')・route('contact.show') のリンク、id="youtube-terms" */ }
public function test_legal_pages_stay_indexable_and_have_no_placeholder(): void
{ /* X-Robots-Tag ヘッダが無い、'本文は公開前に掲載します'・'（仮称）' が無い、assertNoInlineCode */ }
```

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter=LegalPagesTest`
Expected: FAIL（「本文は公開前に掲載します」のまま）

- [ ] **Step 3: 2 つのビューに本文を書く**（上の構成。Blade は `{{ }}` とリンクだけ、`{!! !!}` 禁止）

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add resources/views/static/privacy.blade.php resources/views/static/terms.blade.php tests/Feature/LegalPagesTest.php
git commit -m "feat: プライバシーポリシーと利用規約の本文を掲載する（YouTube API Services・Google プライバシーポリシー・取得データと用途・保存期間・ブラウザ保存・問い合わせの扱い・取り消し・削除依頼）"
```

---

### Task 5: 利用前の同意文・出典表示・未確認アプリ警告の案内・ホーム

**Files:**
- Create: `resources/views/partials/unverified-app.blade.php`
- Modify: `resources/views/home.blade.php`, `resources/views/partials/owner-login.blade.php`, `resources/views/proposals/create.blade.php`, `resources/views/proposals/shared.blade.php`, `resources/views/owner/edit.blade.php`, `resources/js/lib/format.js`, `resources/js/propose.js`, `resources/js/owner-edit.js`, `tests/js/format.test.js`, `tests/Feature/PagesTest.php`, `tests/Feature/PreviewScreenTest.php`, `tests/Feature/ProposeScreenTest.php`, `tests/Feature/OwnerEditScreenTest.php`, `tests/Feature/SharedScreenTest.php`

**Interfaces:**
- Consumes: Task 4 の見出し id（`/privacy#data`）。
- Produces:
  - `export function watchUrl(id)`（`resources/js/lib/format.js`）: `/^[A-Za-z0-9_-]{11}$/` に合えば `https://www.youtube.com/watch?v=${id}`、合わなければ `''`。
  - Alpine の行ヘルパ `watchHref()`（`proposer` と `ownerEditor` の両方。`return watchUrl(this.video.id)`）。
  - `partials/unverified-app.blade.php`（引数なし）: 見出し「ログインの流れ（確認されていないアプリの警告について）」と次の順序付きリスト（固定文言）:
    1. 「Google のアカウント選択画面で、再生リストを作りたいチャンネルを選びます」
    2. 「『このアプリは Google で確認されていません』と表示されたら、『詳細』を押し、『List Maker（安全ではないページ）に移動』を選びます。本サービスは Google の審査を申請する前のため、この警告が表示されます」
    3. 「YouTube の権限の確認画面で内容を確認し、『続行』を押します」
    続けて「使うのはチャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加だけです（<a href="/privacy#data">詳しくはプライバシーポリシー</a>）」。

- [ ] **Step 1: 失敗するテストを書く**

```js
// tests/js/format.test.js に追加
test('watchUrl builds a watch URL only for an 11-char id', () => {
  assert.equal(watchUrl('aaaaaaaaaaa'), 'https://www.youtube.com/watch?v=aaaaaaaaaaa');
  assert.equal(watchUrl('bad id'), '');
  assert.equal(watchUrl(undefined), '');
});
```

```php
// PagesTest
public function test_home_has_final_name_api_services_note_and_login_flow(): void
{ /* <title>List Maker</title>、'（仮称）' が無い、'本サービスは YouTube API Services を使用しています'、
     unverified-app の 2 番目の文、route('privacy') と route('terms') へのリンク（<main> 内） */ }
// PreviewScreenTest（未ログイン）
public function test_login_area_has_consent_flow_and_scope(): void
{ /* '利用規約・プライバシーポリシーに同意のうえログインしてください' がログインリンクより後・同じ section 内、
     unverified-app の 3 つの文、'/privacy#data' へのリンク */ }
// ProposeScreenTest
public function test_cards_link_to_youtube_and_consent_is_near_save(): void
{ /* カードのテンプレートに :href="watchHref" と 'YouTube で見る'、rel="noopener noreferrer"、'データ提供: YouTube'、
     保存ボタンの直後に '保存すると、' から始まる同意文（既存）*/ }
// OwnerEditScreenTest
public function test_cards_link_to_youtube(): void { /* :href="watchHref" と 'YouTube で見る'、'データ提供: YouTube' */ }
// SharedScreenTest
public function test_shared_page_has_attribution(): void { /* 'データ提供: YouTube' */ }
```

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter='PagesTest|PreviewScreenTest|ProposeScreenTest|OwnerEditScreenTest|SharedScreenTest' && npm run test:js`
Expected: FAIL

- [ ] **Step 3: 実装する**

- `home.blade.php`: タイトルと見出しを「List Maker」に。スコープ説明の段落の後に「本サービスは YouTube API Services を使用しています。利用には <a>YouTube 利用規約</a> と <a>Google プライバシーポリシー</a> が適用されます」と、`<section aria-labelledby>` で `@include('partials.unverified-app')`（「チャンネル主の方へ」の小見出しの下）。
- `owner-login.blade.php`: 既存の警告の 1 行とスコープの 1 行を `@include('partials.unverified-app')` に置き換える（同意文とログインリンクは残す）。
- S2/S5 のカード: `<label>` の外（`<li>` 内の右端）に `<a class="shrink-0 text-xs underline" :href="watchHref" rel="noopener noreferrer" target="_blank">YouTube で見る</a>`（チェックの切り替えと分けるため label に入れない）。`propose.js`・`owner-edit.js` の行ヘルパに `watchHref()` を足し、`watchUrl` を import。
- `shared.blade.php`: チャンネル名の段落の下に `<p class="text-xs text-gray-600">データ提供: YouTube</p>`。

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test && npm run test:js && npm run build`
Expected: PASS（ビルド成功）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add resources/views resources/js tests/js/format.test.js tests/Feature/PagesTest.php tests/Feature/PreviewScreenTest.php tests/Feature/ProposeScreenTest.php tests/Feature/OwnerEditScreenTest.php tests/Feature/SharedScreenTest.php
git commit -m "feat: ホームのアプリ名を確定し、YouTube API Services の表示・ログインの流れ（未確認アプリの警告）の案内・動画カードから YouTube へのリンク・共有画面の出典表示を追加する"
```

---

### Task 6: ログ整理（8.8）

**Files:**
- Create: `app/Support/RateLimitLog.php`, `tests/Feature/LogPolicyTest.php`
- Modify: `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`（`owner-unlisted` の応答コールバック）, `app/Http/Controllers/{ChannelController,ProposalController,ContactController}.php`, `app/Services/YouTube/YouTubeReader.php`, `app/Services/Creation/{CreationRunner,CreationStarter,CreationLifecycle}.php`

**Interfaces:**
- Consumes: `ContactController`（Task 2 の仮の `Log::info('Rate limit exceeded', …)` を置き換える）
- Produces:
  - `App\Support\RateLimitLog::exceeded(string $limiter): void` — `Log::info('Rate limit exceeded', ['limiter' => $limiter])`。IP・キー・パスは出さない。呼び出し箇所と `limiter` 値: `ThrottleRequestsException` の render（`'route:'.($request->route()?->getName() ?? 'none')`）、`owner-unlisted` の `->response()` 内（`'owner-unlisted'`）、`ChannelController`・`ProposalController::create` の `abort(429)` 直前（`'channel-fetch'`）、`ProposalController::store` の成功回数超過（`'proposal-store'`）、`ContactController`（`'contact'`）。
  - `bootstrap/app.php` の `withExceptions` に report コールバック 2 つ（どちらも既定のログを止める `return false`）:
    - `QueryException` → `Log::error('Database error', ['sqlstate' => (string) $e->getCode()])`
    - `RequestException|ConnectionException`（`Illuminate\Http\Client`）→ `Log::error('HTTP client error', ['exception' => $e::class, 'status' => $e instanceof RequestException ? $e->response->status() : null])`
  - 追加するログ（8.8「クオータ超過・予約不足」）:
    - `YouTubeReader` の読取予算切れ（`ReadBudgetExceeded` を投げる直前）: `Log::warning('Read budget exhausted')`。同じ PT 日に 1 回だけ（`Cache::add('log-once:read-budget:'.QuotaClock::todayPt(), true, 86400)` が true のときだけ出す）。
    - 作成開始・失敗分再試行の予約不足（`CreationStarter::insufficient()`、`CreationLifecycle` の `InsufficientWriteQuota` を投げる箇所）: `Log::info('Write quota insufficient', ['items' => $n, 'free_units' => <空き>])`。
    - `CreationRunner::pause()` で理由が `quota_exceeded`／`quota_budget` のとき: `Log::warning('Creation paused', ['creation_id' => $c->id, 'reason' => $reason])`。
  - 本番の `LOG_LEVEL` は `info`（8.8 で記録すると決めた作成の開始・完了、運用者の解放、問い合わせの成否、制限超過が info のため。`warning` では出ない）。README と spec 10.2 を Task 8 で直す。

- [ ] **Step 1: 失敗するテストを書く**

`LogPolicyTest`（ログは `Event::listen(MessageLogged::class, fn ($e) => $this->logs[] = $e)` で集める）:

```php
public function test_unhandled_query_exception_logs_sqlstate_only(): void
{
    // Route::get('/_boom', fn () => throw new QueryException('sqlite', 'select * from proposals where key_hash = ?', ['SECRETBINDING'], new \PDOException('x')));
    // config(['app.debug' => false]); get('/_boom') → 500
    // ログに 'Database error' があり、どのログの message と json_encode(context) にも 'SECRETBINDING'・'key_hash = ?' が無い
}
public function test_unhandled_http_client_exception_logs_without_url(): void
{
    // Http::fake(['*' => Http::response('', 500)]); ルートで Http::get('https://example.test/x?key=SECRETKEY')->throw()
    // 'HTTP client error' の context が ['exception' => RequestException::class, 'status' => 500]、どのログにも 'SECRETKEY' が無い
}
public function test_throttled_request_logs_limiter_without_ip(): void
{ /* global を 121 回で超過 → 'Rate limit exceeded' の context === ['limiter' => 'route:home']、ログに '127.0.0.1' が無い */ }
public function test_channel_fetch_refusal_is_logged(): void { /* ChannelFetchThrottle を 5 回使い切ってから GET /channels?q=@x（キャッシュ無し）→ limiter 'channel-fetch' */ }
public function test_read_budget_exhaustion_is_logged_once_per_day(): void
{ /* QUOTA_READ_BUDGET を使い切った状態でチャンネル取得を 2 回 → 'Read budget exhausted' は 1 件。travelTo で翌 PT 日 → もう 1 件 */ }
public function test_quota_pause_is_logged(): void
{ /* 既存 CreationRunnerTest と同じ準備で playlistItems.insert が 403 quotaExceeded → 'Creation paused' の context === ['creation_id' => id, 'reason' => 'quota_exceeded'] */ }
public function test_insufficient_write_quota_is_logged(): void { /* 空き不足で開始 → 'Write quota insufficient'（items と free_units のみ） */ }
public function test_no_log_call_passes_secrets(): void
{
    // app/ 以下の全 .php から /Log::\w+\((.*?)\);/s を抜き出し、どの呼び出しにも次が無い:
    // '->ip()', 'access_token', 'accessToken', 'reply_address', 'replyAddress', '$body', "'key_hash' =>", '->getQuery', 'url('
}
```

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter=LogPolicyTest`
Expected: FAIL

- [ ] **Step 3: 実装する**（Interfaces のとおり。`ThrottleRequestsException` の render コールバックの先頭で `RateLimitLog::exceeded(...)` を呼んでから既存の分岐へ）

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Support/RateLimitLog.php bootstrap/app.php app/Providers/AppServiceProvider.php app/Http/Controllers app/Services tests/Feature/LogPolicyTest.php
git commit -m "feat: ログを 8.8 に合わせる（未処理の DB・HTTP 例外はバインドと URL を出さない、制限超過・読取予算切れ・予約不足・枠切れの一時停止を記録）"
```

---

### Task 7: Phase 0 の積み残し（Issue #18）

#18 の各項目を main で確認した結果と、このタスクでの扱い:

| #18 の項目 | 確認結果 | 扱い |
|---|---|---|
| session の secure 既定値 | `config/session.php:172` 対応済み | なし |
| README に `curl -sI https://…/` が 200 の確認 | 未対応（`.env`・`composer.json`・http の 3 行のみ） | Task 8 |
| メンテナンス・hot のテストが実パスに書く | 未対応（`public_path('hot')`、file ドライバ） | 本タスク |
| エラーページのインライン検査が 404 だけ | 未対応 | 本タスク |
| `errors/minimal` のタイトルに「 - List Maker」 | 未対応 | 本タスク |
| `.env.example` のセキュリティ値をテスト | キーの有無のみ（値は未検査） | 本タスク |
| deploy.sh: `--delete-after`・REMOTE_PATH の形式チェック・rsync 後の失敗メッセージ | 未対応 | 本タスク |
| `.exclude-list` の docs/tests/README.md をルート基準に | 未対応 | 本タスク |
| GitHub Actions を SHA 固定 | 未対応。条件（デプロイ・秘密を使うジョブの追加前）に当たらない | 対象外（監督者判断。README に注記しない） |
| SecurityHeaders の no-store 上書き | キャッシュを使う画面が無い | 対象外 |
| `.env` の空文字に `filled()` | Task 1（TRUSTED_PROXIES）・Task 2（CONTACT_TO_ADDRESS）で対応 | 済 |

**Files:**
- Modify: `resources/views/errors/{401,403,404,405,413,419,429,4xx,500,503,5xx}.blade.php`（`@section('title', …)` → `@section('error_title', …)`）, `resources/views/errors/minimal.blade.php`, `app/Http/Middleware/SecurityHeaders.php`, `tests/Feature/SecurityHeadersTest.php`, `tests/Feature/PagesTest.php`, `tests/Feature/ConfigurationTest.php`, `deploy.sh`, `.exclude-list`, `tests/Feature/DeployScriptTest.php`, `tests/Feature/DeployExcludeListTest.php`

**Interfaces:**
- Produces: `errors/minimal.blade.php` が `@section('title')@yield('error_title', 'エラー') - List Maker@endsection` を定義（子は `error_title` だけを持つ）。`SecurityHeaders::csp()` は `public_path('hot')` ではなく `Vite::hotFile()` を読む。deploy.sh の `REMOTE_PATH` は `^/[A-Za-z0-9._/-]+$` に合い、`/` そのものではないこと。

- [ ] **Step 1: 失敗するテストを書く**

```php
// PagesTest
public static function errorCodes(): array { return [[401], [403], [404], [405], [413], [419], [429], [500], [503]]; }
#[DataProvider('errorCodes')]
public function test_error_views_have_suffixed_title_and_no_inline_code(int $code): void
{
    $html = view("errors.$code", ['exception' => new HttpException($code)])->render();
    $this->assertMatchesRegularExpression('#<title>[^<]+ - List Maker</title>#', $html);
    $this->assertNoInlineCode($html);
}
// SecurityHeadersTest: 既存の hot の 2 テストを Vite::useHotFile($tmp)（sys_get_temp_dir() の一時ファイル、tearDown で削除）に変え、
// public_path('hot') に触れない。メンテナンスのテストは activate の前に
// config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']) とし、storage/framework/down を作らない
public function test_tests_do_not_touch_real_hot_or_down_files(): void
{ /* テスト前後で file_exists(public_path('hot')) と file_exists(storage_path('framework/down')) が変わらない */ }
// ConfigurationTest
public function test_env_example_has_safe_values(): void
{
    // .env.example に次の行が（コメントなしで）ある: SESSION_DRIVER=database, SESSION_ENCRYPT=true, SESSION_SAME_SITE=lax,
    // CACHE_STORE=database, QUEUE_CONNECTION=sync, LOG_CHANNEL=daily, LOG_DAILY_DAYS=14, CAPTCHA_ENABLED=false, TRUSTED_PROXIES=
}
// DeployScriptTest
public function test_rsync_uses_delete_after(): void { /* deploy.sh に '--delete-after' があり、' --delete ' 単独が無い */ }
public function test_remote_path_must_be_an_absolute_safe_path(): void
{ /* deploy.env を一時ディレクトリにコピーした deploy.sh と一緒に置き、REMOTE_PATH='relative/x'・'/'・'/a b' で実行すると
     終了コード 1 と 'REMOTE_PATH' を含むメッセージで、git・rsync に到達しない（PATH に失敗するダミーの git を置いて検出）*/ }
public function test_failure_after_rsync_says_files_were_sent(): void
{ /* deploy.sh に RSYNC_DONE フラグがあり、on_exit が RSYNC_DONE=1 のとき「ファイルは本番に転送済みです。サーバー側の手順（migrate など）が終わっていません」を出す */ }
// DeployExcludeListTest: MUST_TRANSFER に 'vendor/example/pkg/docs/guide.md', 'vendor/example/pkg/tests/Fixture.php', 'vendor/example/pkg/README.md' を追加
```

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter='PagesTest|SecurityHeadersTest|ConfigurationTest|DeployScriptTest|DeployExcludeListTest'`
Expected: FAIL

- [ ] **Step 3: 実装する**

- エラービュー 11 個の `title` を `error_title` に改名、`minimal.blade.php` に上記の `title` セクション。
- `SecurityHeaders::csp()`: `@file_get_contents(Vite::hotFile())`。
- deploy.sh: 必須変数の確認の直後に REMOTE_PATH の形式チェック（不正なら「❌ REMOTE_PATH は / で始まる絶対パス（英数字と . _ / - のみ、/ だけは不可）にしてください」で exit 1）。`--delete` → `--delete-after`。rsync 成功直後に `RSYNC_DONE=1`、`on_exit` で `RSYNC_DONE=1` なら「本番への反映は完了していません」の代わりに上記の文言を出す。
- `.exclude-list`: `docs`→`/docs`、`tests`→`/tests`、`README.md`→`/README.md`、`phpunit.xml`→`/phpunit.xml`。

- [ ] **Step 4: テストが通ることを確認する**

Run: `php artisan test && bash -n deploy.sh`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add resources/views/errors app/Http/Middleware/SecurityHeaders.php tests/Feature deploy.sh .exclude-list
git commit -m "fix: Phase 0 の積み残し（#18）を片付ける（エラーページのタイトルとインライン検査、テストが実ファイルに書かない、.env.example の値の検査、deploy.sh の --delete-after と REMOTE_PATH の検査、.exclude-list をルート基準に）"
```

---

### Task 8: README の最終版・仕様への追記・最終確認

**Files:**
- Modify: `README.md`, `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`, `tests/Feature/ConfigurationTest.php`（README の検査を追加）

**Interfaces:**
- Consumes: すべての前タスク（コマンド名・env 名・文言）

- [ ] **Step 1: 失敗するテストを書く**

```php
// ConfigurationTest
public function test_readme_documents_operations(): void
{
    $readme = file_get_contents(base_path('README.md'));
    foreach (['# List Maker', 'LOG_LEVEL=info', 'TRUSTED_PROXIES=', 'CONTACT_TO_ADDRESS=', 'php artisan app:delete-proposal',
        'php artisan app:release-creation-reservation', 'curl -sI https://list-maker.alpacasandbag.jp/ | head -1',
        'https://myaccount.google.com/permissions', '公開前の人手作業'] as $text) {
        $this->assertStringContainsString($text, $readme, $text);
    }
    $this->assertStringNotContainsString('仮称', $readme);
}
```

- [ ] **Step 2: テストが失敗することを確認する**

Run: `php artisan test --filter=test_readme_documents_operations`
Expected: FAIL

- [ ] **Step 3: README を最終版にする**

- 見出しを `# List Maker`、「（仮称）」をすべて削除（Google Cloud セットアップの 3 も）。
- 本番 `.env` の例: `LOG_LEVEL=info`、`MAIL_*` に CORESERVER の値を入れる旨、`CONTACT_TO_ADDRESS=`、`TRUSTED_PROXIES=`（「#7 で REMOTE_ADDR が全員同じプロキシ IP だと分かった場合だけ、そのプロキシの IP/CIDR を書く。空なら信頼しない」）。
- 初回手順 5 の確認に `curl -sI https://list-maker.alpacasandbag.jp/ | head -1    # 200 であること（.htaccess の Require が使えないと全体が 500 になる）` を追加。
- 「運用」節を追加・整理:
  - 削除依頼: 問い合わせメールを受けたら 7 日以内に `php artisan app:delete-proposal <提案の URL またはキー>`（サーバーでは `/usr/local/php84/bin/php artisan …`）。作成中でも作成記録ごと削除され、YouTube の再生リストは残ること。対応後、メールは 90 日以内に削除。
  - 放置された予約: `php artisan app:release-creation-reservation <creation_id>`（ログの `Creation started` の `channel` と `creation_id` で特定）。
  - レート制限の値と変更箇所（8.1 の表。`AppServiceProvider`・`ProposalStoreLimits`・`ChannelFetchThrottle`・`ContactLimits`）。キーは IP の HMAC（IPv6 は /64）。
  - ログに出るもの・出ないもの（8.8 の要約）と主なメッセージ名（`Creation started`・`Creation completed`・`Creation paused`・`Write quota insufficient`・`Read budget exhausted`・`Rate limit exceeded`・`Contact sent`・`Contact mail failed`・`Proposal deleted by operator`）。
  - 問い合わせの送信テスト手順（本番でフォームから 1 通送り、`CONTACT_TO_ADDRESS` に届くこと、`storage/logs` に `Contact sent` が出ること、DB のテーブル件数が増えないこと）。
- 末尾に「## 公開前の人手作業」として下の「人手作業」の一覧を転記（チェックボックス）。

- [ ] **Step 4: 仕様に追記する**（本文の書き換えはせず、該当節の末尾に追記）

- 8.1: 「レート制限のキーは `ClientKey`（app.key による IP の HMAC。IPv6 は /64 単位）。`TRUSTED_PROXIES`（空なら信頼しない）で前段のプロキシを信頼できる。値は Phase 4 で見直し、変更なし」。
- 8.7: 「検証エラー・送信失敗・制限超過はリダイレクトせずフォームを直接描画する（入力をセッションにも置かない）。制限は送信を試みた回（成功・失敗）とハニーポットだけ数える。SMTP のタイムアウトは 10 秒」。
- 8.8: 追加したログの一覧。10.2: `LOG_LEVEL=info`（8.8 の記録項目が info のため）と `TRUSTED_PROXIES=` を追加。
- 4.3: 「`app:delete-proposal` は作成の状態にかかわらず提案と作成記録を削除する（予約は解放）」。
- 12.2 に D34（ClientKey と TRUSTED_PROXIES）、D35（問い合わせの入力をセッションに置かない・送信試行で数える）、D36（運営削除は作成中でも削除）、D37（本番 LOG_LEVEL=info）を追加。

- [ ] **Step 5: 全体を確認する**

Run: `./vendor/bin/pint --test && php artisan test && npm run test:js && npm run build`
Expected: すべて PASS、ビルド成功

- [ ] **Step 6: コミット**

```bash
git add README.md docs/superpowers/specs/2026-11-14-playlist-proposal-design.md tests/Feature/ConfigurationTest.php
git commit -m "docs: README を公開用の最終版にし（運用・削除依頼・予約の解放・ログ・人手作業）、Phase 4 の判断を仕様に記録する"
```

---

## 人手作業（コード外。PR の説明と README の「公開前の人手作業」に載せる）

1. #7: 本番で `REMOTE_ADDR` が利用者ごとの IP かを確認し、全員同じなら `.env` に `TRUSTED_PROXIES` を設定（未設定のままなら信頼しない）。送信元 IP が固定なら API キーを IP 制限に。
2. SMTP 疎通: CORESERVER の SMTP 値（ホスト・ポート・`MAIL_SCHEME`・認証）を本番 `.env` に入れ、送信元ドメインの SPF/DKIM を確認し、本番フォームから 1 通送って `CONTACT_TO_ADDRESS` に届くこと・DB に残らないことを確認（Phase 4 の完了条件）。
3. 本番 `.env` を `LOG_LEVEL=info` に変更し、デプロイ後に `curl -sI https://list-maker.alpacasandbag.jp/` が 200 であることを確認。
4. OAuth 同意画面: アプリ名「List Maker」、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL、承認済みドメイン `alpacasandbag.jp`、スコープ `.../auth/youtube`、スコープの用途説明（「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」）を登録し、ブランディングを公開、公開ステータスを「本番環境」に（未審査のまま）。
5. Search Console で `alpacasandbag.jp`（またはサブドメイン）の所有権を確認（プロジェクトのオーナー／編集者のアカウントで）。
6. 第三者アカウント（自分の別アカウント）で、ログイン前の案内どおりに未確認アプリの警告を越え、提案 → ログイン → 編集 → 作成 → ログアウト（承認が消える）まで完走できることを確認（Phase 4 の完了条件）。実際の警告画面の文言が Task 5 の案内（「このアプリは Google で確認されていません」「詳細」「List Maker（安全ではないページ）に移動」「続行」）と一致するかを確かめ、違えば文言を直す。必要なら警告画面のスクリーンショットを撮る（画像付き説明を付けるかは監督者判断）。
7. 英語のデモ動画（Phase 5 の準備）: 同意画面に「List Maker」、アドレスバーに OAuth クライアント ID が見える状態で、ログイン〜限定公開動画の表示〜作成までを英語で説明し、YouTube に限定公開でアップロード。
8. 9.1 のチェックリストを本番で目視確認（全ページのフッター、S2/S4 の同意文、出典表示、S6 の取り消し案内、プライバシーポリシー・規約の本文）。
