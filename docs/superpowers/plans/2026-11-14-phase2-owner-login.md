# Phase 2 チャンネル主ログイン（List Maker）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** チャンネル主が共有 URL のプレビュー（S4）から Google でログイン（認可コード＋PKCE、youtube スコープのみ）し、提案の対象チャンネルと一致したときだけ編集画面（S5、書込なし）に入れ、ログアウトで Google 側の承認も取り消せるようにする。spec 6.7 の安全対策チェックリスト全項目を満たす。

**Architecture:** OAuth は外部ライブラリを使わず `GoogleOAuth`（Laravel HTTP クライアント、エンドポイントは https 固定の定数）が認可 URL 生成・トークン交換・取り消しの 3 つだけを行う。state・PKCE verifier・戻り先キー・アクセストークンは暗号化 DB セッション（既存の `PrivacyDatabaseSessionHandler`）にだけ置き、その読み書きは `OwnerSession` 1 か所に集める。ログイン状態とチャンネル一致の判定は `RequireOwnerSession` ミドルウェアにまとめ、不一致画面もここで返す。S5 は Blade＋`owner-edit.js`（`@alpinejs/csp`）で、編集内容は sessionStorage のみに置き、ロジックは `resources/js/lib/*.js` の純関数にする。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit（SQLite in-memory、`Http::fake()`）/ Vite 8 + Tailwind 4 / `@alpinejs/csp` 3.17 / Node 22（`node --test`）

**Spec:** `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（1.4・2.3・2.4・2.5 S4/S5・4.1・5.1・5.5・6 章全体（特に 6.7）・8.1・8.2・8.3・8.5・8.6・8.8・9.1・10.5・11 章 Phase 2・12.2 D2/D4/D5/D23）

**Branch:** `phase2-owner-login`（`main` から切る。全タスクを 1 PR にまとめる）

## Global Constraints

- Phase 0/1 の制約はすべて継続: Blade は `{{ }}` のみ、インライン `<script>`／`<style>`／`style=`／`on*=` を書かない（`AssertsNoInlineCode`）、Alpine は `@alpinejs/csp` のみで Blade の式は「プロパティ参照」「引数なしメソッド名」に限る、JS は `textContent`／`x-text` のみ、サーバー→JS は `data-*` 属性の JSON、新しい JS エントリは `vite.config.js` の `input` に追加（`ViteEntriesTest`）。
- 外部 OAuth/Google ライブラリを追加しない（Socialite・league/oauth2-client・google/apiclient 不可。spec 6.1・Y8）。`composer.json` を変更しない。
- エンドポイントは定数のみ: 認可 `https://accounts.google.com/o/oauth2/v2/auth`、トークン `https://oauth2.googleapis.com/token`、取り消し `https://oauth2.googleapis.com/revoke`、YouTube `YouTubeReader::BASE_URL`（`https://www.googleapis.com/youtube/v3/`）。設定・リクエストから差し替えない（H2）。
- スコープは `https://www.googleapis.com/auth/youtube` のみ。認可パラメータは `response_type=code`, `scope`, `access_type=online`, `include_granted_scopes=false`, `prompt=select_account`, `state`, `code_challenge`, `code_challenge_method=S256`, `client_id`, `redirect_uri`。`http_build_query(..., PHP_QUERY_RFC3986)` で組み立てる（J1・J3）。email/profile/openid・`login_hint`・`nonce` は送らない。
- `client_id`／`client_secret`／`redirect_uri` は `config('services.google.*')` のみ（既存）。redirect_uri はリクエストの Host から組み立てない。production で `https://` 以外なら未設定扱い（C1・C2）。
- すべての Google/YouTube 呼び出しに `connectTimeout(5)->timeout(15)->withoutRedirecting()`。`withoutVerifying()`・`'verify' => false` を書かない（H1・H3・H4）。トークン交換・取り消しは `asForm()` の POST 本文で送り、URL クエリに秘密を載せない（E1・H5）。トークン交換は自動再試行しない（D2）。
- 秘密値（アクセストークン・認可コード・state・code_verifier・client_secret・API キー）を受け取る PHP 引数には `#[\SensitiveParameter]` を付ける（例外トレースに出さない。I1）。ログは「何が・HTTP ステータス・`error`／`reason` コード（`/^[A-Za-z0-9_.-]{1,64}$/` に合うときだけ）」のみ。応答本文・URL・例外メッセージを記録しない（F2・8.8）。
- セッション: state・verifier・戻り先キー・トークンは `OwnerSession` 経由でのみ読み書きする。キー名 `OwnerSession::PENDING_KEY = 'oauth_pending'`、`OwnerSession::OWNER_KEY = 'owner'`。アプリのテーブル・キャッシュ・ファイル・ログ・HTML・JS に書かない（6.5・G3）。refresh_token・id_token はどこにも保持しない（E4）。
- 時間の値: state の有効期限 600 秒（A5）、`expires_in` の上限 3600 秒・安全余裕 60 秒で `expires_at = now + min(expires_in, 3600) − 60`（E2・E5）、`now >= expires_at` を期限切れとする。
- 台帳: `channels.list mine=true` は `QuotaLedger::OWNER_READ` に 1 ユニット記録（上限判定なし。5.1・5.4）。
- レート制限: OAuth 開始・コールバック共通で `oauth` リミッタ 10 回/分/IP（8.1・J2）。コールバックは GET のみ。
- 公開範囲の値は `private`／`unlisted`／`public` のみ、既定 `private`（8.2・D10）。名前 1〜150 文字・説明 ≤1,000 文字（`StoreProposalRequest` と同じ数え方。8.2）。
- 画面文言（spec そのまま。数値は導出値）:
  - S4: 「チャンネル主の方: Google でログインして作成へ」、同意文「利用規約・プライバシーポリシーに同意のうえログインしてください」（各ページへのリンク付き）、「確認されていないアプリの警告が表示されます」
  - S5: 「この内容に必要な枠: 再生リスト 1 つ＋動画 {n} 本」、「本日の残り: 再生リスト 1 つ＋動画あと {N} 本まで作成できます」／「本日はこれ以上作成できません。次にリセットされるのは 日本時間 {n月j日 G時} です」、不足時「あと {n − N} 本減らすか、リセット後に作成してください」＋「次にリセットされるのは 日本時間 {n月j日 G時} です」、作成ボタン「あなたのチャンネルに再生リストを作成します」、「1 つの再生リストに入れられるのは最大 {N} 本」
  - 取り消し案内: 「Google アカウントの サードパーティのアクセス（`https://myaccount.google.com/permissions`）からいつでも承認を取り消せます」
- 新規ルート名（固定。Phase 3 が参照）: `owner.login`（GET `/auth/google`）、`owner.callback`（GET `/auth/google/callback`）、`owner.logout`（POST `/logout`）、`owner.logged-out`（GET `/logged-out`）、`proposals.edit`（GET `/p/{key}/edit`、`{key}` は `ProposalKey::ROUTE_PATTERN`）。ルートを足すタスクは `PagesTest::test_only_spec_routes_are_registered` の期待一覧も更新する。
- テストは実 Google/YouTube を呼ばない（`Http::preventStrayRequests()` は `Tests\TestCase` で既に有効）。時刻は `$this->travelTo()`。
- コミット前に毎回 `./vendor/bin/pint` と `php artisan test`（JS を触ったタスクは `npm run test:js` も）を通す。

## Review Focus

1. **2 つのタブでログインを始めた／戻るボタンでコールバック URL を再訪した**: 後から始めた方が pending を上書きし、先の方（または再訪）は state 不一致になる。500 や生の 400 ではなく「もう一度ログインしてください」とプレビューへのリンクを出すべき。→ Task 4 `test_superseded_or_replayed_callback_shows_retry_page`。
2. **編集中にトークンが期限切れ（約 1 時間）になり再読み込み**: 編集画面からプレビューへ戻され「もう一度ログインしてください」と出て、再ログイン後に編集内容（sessionStorage）が戻るべき。→ Task 3 `test_expired_owner_is_sent_back_to_preview_at_boundary`、Task 7 `draft` の復元テスト。
3. **同意画面で YouTube の権限のチェックを外した（部分同意）**: `scope` に youtube が無い応答が返る。トークンを取り消し、理由を固定文言で案内し、ログイン状態にしないべき。→ Task 5 `test_insufficient_scope_revokes_and_explains`。
4. **`.env` の Google 設定が空（`""`）または本番で http の redirect URI**: ログイン開始が 500 にならず 503 の案内になるべき（`env()` は空文字を返す。Issue #18 の注意）。→ Task 2 `test_is_configured_rejects_blank_and_http_in_production`、Task 4 `test_login_start_is_503_when_not_configured`。
5. **YouTube チャンネルの無い Google アカウント／ブランドアカウントの選び間違い**: 前者は「YouTube チャンネルがありません」（トークンは取り消して保持しない）、後者は不一致画面で `select_account` の再ログインを案内すべき。→ Task 5 `test_account_without_channel_is_revoked_and_explained`、Task 3 `test_mismatched_channel_gets_403_mismatch_page`。

---

## 6.7 チェックリスト対応表（実装レビューの観点・完了条件）

spec 6.7 の全項目と、それを満たすタスク・自動テスト（または対象外・静的確認の理由）の対応。Task 8 で spec 6.7 末尾に「判定記録（Phase 2）」として転記し、最終レビュー担当が参照ライブラリのソースを確認して「満たす／対象外（理由）」を記入する。満たさない項目があれば Phase 2 は完了としない（10.5）。

| # | 担当 | 自動テスト（テスト欄が空の項目は静的確認・理由） |
|---|---|---|
| A1 | T1 | `OwnerSessionTest::test_begin_generates_fresh_43_char_state` |
| A2 | T1/T4 | 静的: state はセッションのみ。`OwnerAuthStartTest::test_start_stores_state_only_in_session`（Cookie・本文に出ない）も追加 |
| A3 | T4 | `OwnerCallbackValidationTest::test_bad_state_is_400_without_token_request`（欠落／空／不一致／配列） |
| A4 | T1/T4 | `OwnerSessionTest::test_pull_pending_is_one_time`、`OwnerCallbackValidationTest::test_replayed_callback_is_400` |
| A5 | T4 | `OwnerCallbackValidationTest::test_state_older_than_10_minutes_is_400` |
| A6 | 全体 | 静的: state 省略モード・設定を作らない（`grep -rn stateless app` 0 件） |
| B1 | T1 | `PkceTest::test_verifier_is_43_unreserved_chars_and_random` |
| B2 | T1 | `PkceTest::test_challenge_matches_rfc7636_appendix_b`、`GoogleOAuthTest::test_authorization_url_parameters`（S256） |
| B3 | T1/T5 | `OwnerSessionTest::test_pull_pending_is_one_time`、`OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit`（交換後 pending 無し） |
| C1 | T4 | `OwnerAuthStartTest::test_redirect_uri_ignores_forged_host` |
| C2 | T2/T5 | `GoogleOAuthTest::test_is_configured_rejects_blank_and_http_in_production`、`OwnerCallbackLoginTest::test_redirect_uri_is_identical_in_both_requests` |
| C3 | T4/T5 | `OwnerAuthStartTest::test_external_return_url_is_ignored`、`OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit` |
| D1 | T4 | `OwnerCallbackValidationTest::test_replayed_callback_is_400`（2 回目にトークン要求が無い）、`GoogleOAuthTest::test_invalid_grant_is_not_retried` |
| D2 | T2 | `GoogleOAuthTest::test_timeout_is_not_retried` |
| D3 | T5 | `OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit`（302・本文に code 無し・Referrer-Policy） |
| D4 | T4 | `OwnerCallbackValidationTest::test_malformed_code_or_error_with_code_is_400` |
| D5 | T4 | `OwnerCallbackValidationTest::test_wrong_iss_is_400_and_correct_iss_passes`（Google が返すかは人手確認） |
| E1 | T2 | `GoogleOAuthTest::test_exchange_posts_form_body` |
| E2 | T2 | `GoogleOAuthTest::test_exchange_rejects_invalid_token_responses`（データプロバイダ） |
| E3 | T5 | `OwnerCallbackLoginTest::test_insufficient_scope_revokes_and_explains` |
| E4 | T2/T5/T8 | `GoogleOAuthTest::test_refresh_token_is_dropped`、`OwnerCallbackLoginTest::test_session_holds_only_owner_fields`、`OwnerSecurityTest::test_no_token_in_any_table_cache_or_log`。id_token・nonce・署名検証は OIDC 不使用のため対象外 |
| E5 | T2/T1/T3 | `GoogleOAuthTest::test_expires_at_has_60s_margin_and_3600_cap`、`OwnerSessionTest::test_current_is_null_at_expiry_boundary`、`OwnerEditAccessTest::test_expired_owner_is_sent_back_to_preview_at_boundary` |
| F1 | T4 | `OwnerCallbackValidationTest::test_error_description_is_never_shown` |
| F2 | T2 | `GoogleOAuthTest::test_token_error_logs_only_status_and_code` |
| F3 | T8 | `OwnerLogoutTest::test_revoke_failure_still_logs_out` |
| G1 | T5 | `OwnerCallbackLoginTest::test_session_id_changes_on_login` |
| G2 | T8 | `OwnerLogoutTest::test_logout_revokes_and_invalidates_session` |
| G3 | T8 | `OwnerSecurityTest::test_no_token_in_any_table_cache_or_log` |
| H1 | T8 | `OwnerSecurityTest::test_tls_verification_is_never_disabled`（ソース検査） |
| H2 | T2 | 静的: URL は `GoogleOAuth`／`YouTubeReader` の `const`。`GoogleOAuthTest::test_endpoints_are_https_constants` |
| H3 | T2/T3 | `GoogleOAuthTest::test_timeout_is_not_retried`、`YouTubeOwnerClientTest::test_connection_error_is_transient` |
| H4 | T2 | `GoogleOAuthTest::test_redirect_response_is_failure`（トークン・取り消しとも） |
| H5 | T2 | `GoogleOAuthTest::test_revoke_posts_token_in_body` |
| I1 | T2/T8 | `OwnerSecurityTest::test_secrets_never_reach_logs`、`OwnerSecurityTest::test_secret_parameters_are_sensitive` |
| I2 | T8 | `OwnerSecurityTest::test_debug_in_production_logs_warning` |
| I3 | T8 | 対象外（記録のみ）: アクセスログのコールバック URL はホスティング側管理。A4・D1 で再利用不可。spec 6.7 判定記録とプライバシー対応（Phase 4）に記載 |
| I4 | T8 | `OwnerSecurityTest::test_client_secret_never_reaches_html_or_frontend` |
| J1 | T2 | `GoogleOAuthTest::test_authorization_url_parameters` |
| J2 | T4 | `OwnerAuthStartTest::test_oauth_routes_are_rate_limited_and_callback_is_get_only` |
| J3 | T2 | `GoogleOAuthTest::test_authorization_url_parameters`（`parse_str` で全パラメータ一致・RFC 3986） |

---

## File Structure

| ファイル | 責務 |
|---|---|
| `app/Support/Pkce.php` | code_verifier 生成と S256 challenge（純関数） |
| `app/Support/OwnerSession.php`, `PendingLogin.php`, `OwnerLogin.php` | セッション内の OAuth 状態（pending：state・verifier・戻り先キー・発行時刻）とログイン情報（トークン・期限・チャンネル）の唯一の読み書き口、チャンネル一致判定 |
| `app/Services/YouTube/GoogleOAuth.php`, `OAuthToken.php`, `OAuthFailed.php` | 認可 URL・トークン交換・取り消し（素の HTTP） |
| `app/Services/YouTube/YouTubeOwnerClient.php`, `OwnerChannel.php` | OAuth での YouTube 呼び出し（Phase 2 は `channels.list mine=true` のみ。Phase 3 で書込を足す） |
| `app/Http/Middleware/RequireOwnerSession.php` | 提案の存在・ログイン・期限・チャンネル一致。不一致画面を返す |
| `app/Http/Controllers/OwnerAuthController.php` | ログイン開始・コールバック・ログアウト・ログアウト後画面 |
| `app/Http/Controllers/OwnerEditController.php` | S5（表示のみ。書込なし） |
| `app/Models/Proposal.php` | `findLiveOrAbort()` を追加（ProposalController と middleware で共用） |
| `resources/views/owner/{mismatch,callback-invalid,edit,logged-out}.blade.php`, `partials/owner-login.blade.php` | 画面 |
| `resources/js/owner-edit.js`, `resources/js/lib/{owner-edit,draft}.js`, `lib/safe-storage.js` | S5 の操作と純関数 |
| `tests/Support/{CreatesProposals,FakesGoogle}.php` | テスト補助 |

---

### Task 1: Pkce と OwnerSession（セッション内の OAuth 状態）

**Files:**
- Create: `app/Support/Pkce.php`, `app/Support/OwnerSession.php`, `app/Support/PendingLogin.php`, `app/Support/OwnerLogin.php`
- Modify: `config/session.php`（Issue #18 の 1 項目: `'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production')`。トークンを載せるセッションの Cookie を、APP_ENV 未設定時も Secure にする）
- Test: `tests/Unit/PkceTest.php`, `tests/Feature/OwnerSessionTest.php`

**Interfaces:**
- Produces:
  - `Pkce::verifier(): string`（`random_bytes(32)` の base64url、パディング無し 43 文字）、`Pkce::challenge(#[\SensitiveParameter] string $verifier): string`（`BASE64URL(SHA-256(verifier))`、パディング無し）
  - `final readonly class PendingLogin(string $state, string $verifier, string $proposalKey, int $issuedAt)`、`isExpired(): bool`（`now()->getTimestamp() - issuedAt > 600`）。`state`・`verifier` の引数に `#[\SensitiveParameter]`
  - `final readonly class OwnerLogin(string $accessToken, CarbonImmutable $expiresAt, string $channelId, string $channelTitle)`、`isExpired(): bool`（`now() >= expiresAt`）。`accessToken` に `#[\SensitiveParameter]`
  - `OwnerSession`（コンテナ解決。`Illuminate\Contracts\Session\Session` を注入）: `PENDING_KEY = 'oauth_pending'`, `OWNER_KEY = 'owner'`, `STATE_TTL_SECONDS = 600`
    - `begin(string $proposalKey): PendingLogin`（state＝`random_bytes(32)` の base64url 43 文字、verifier＝`Pkce::verifier()`。既存の pending を上書き）
    - `pullPending(): ?PendingLogin`（`session->pull()`。形が不正なら null。呼ぶと必ず消える）
    - `login(OwnerLogin $login): void`、`current(): ?OwnerLogin`（無い・形が不正・期限切れなら `OWNER_KEY` を消して null）、`tokenForRevoke(): ?string`（期限切れでもトークンを返す。ログアウト用）、`forget(): void`、`matches(string $channelId): bool`（`current()?->channelId === $channelId`）
    - `static serialize(OwnerLogin $l): array`（`['access_token','expires_at'(unix 秒),'channel_id','channel_title']`。テストの `withSession` でも使う）

- [ ] **Step 1: ブランチを作る**

Run: `git switch main && git pull && git switch -c phase2-owner-login`

- [ ] **Step 2: 失敗するテストを書く**

```php
// PkceTest
public function test_verifier_is_43_unreserved_chars_and_random(): void
{
    $a = Pkce::verifier(); $b = Pkce::verifier();
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a);
    $this->assertNotSame($a, $b);
}
public function test_challenge_matches_rfc7636_appendix_b(): void
{
    $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
}

// OwnerSessionTest（Feature。app(OwnerSession::class) を使う）
public function test_begin_generates_fresh_43_char_state(): void
{
    $s = app(OwnerSession::class);
    $a = $s->begin(str_repeat('k', 43)); $b = $s->begin(str_repeat('k', 43));
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a->state);
    $this->assertNotSame($a->state, $b->state);
    $this->assertNotSame($a->verifier, $b->verifier);
    $this->assertSame($b->state, session(OwnerSession::PENDING_KEY)['state']); // 後勝ちで上書き
}
public function test_pull_pending_is_one_time(): void
{
    $s = app(OwnerSession::class);
    $p = $s->begin(str_repeat('k', 43));
    $this->assertEquals($p, $s->pullPending());
    $this->assertNull($s->pullPending());
    $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
}
public function test_pending_expires_after_600_seconds(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $p = app(OwnerSession::class)->begin(str_repeat('k', 43));
    $this->travel(600)->seconds();
    $this->assertFalse($p->isExpired());
    $this->travel(1)->seconds();
    $this->assertTrue($p->isExpired());
}
public function test_malformed_session_values_are_ignored(): void
{
    session([OwnerSession::PENDING_KEY => ['state' => ['x']], OwnerSession::OWNER_KEY => 'junk']);
    $s = app(OwnerSession::class);
    $this->assertNull($s->pullPending());
    $this->assertNull($s->current());
    $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
}
public function test_current_is_null_at_expiry_boundary(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $s = app(OwnerSession::class);
    $s->login(new OwnerLogin('tok', now()->toImmutable()->addSeconds(10), 'UC'.str_repeat('a', 22), 'A'));
    $this->travel(9)->seconds();
    $this->assertNotNull($s->current());
    $this->travel(1)->seconds();               // now == expires_at
    $this->assertNull($s->current());
    $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
}
public function test_matches_compares_channel_id(): void
{
    $s = app(OwnerSession::class);
    $this->assertFalse($s->matches('UC'.str_repeat('a', 22)));
    $s->login(new OwnerLogin('tok', now()->toImmutable()->addHour(), 'UC'.str_repeat('a', 22), 'A'));
    $this->assertTrue($s->matches('UC'.str_repeat('a', 22)));
    $this->assertFalse($s->matches('UC'.str_repeat('b', 22)));
}
public function test_session_cookie_secure_default_assumes_production(): void
{
    $this->assertStringContainsString("env('APP_ENV', 'production') === 'production'", file_get_contents(config_path('session.php')));
}
```

- [ ] **Step 3: テストが失敗することを確認**

Run: `php artisan test --filter='PkceTest|OwnerSessionTest'`
Expected: FAIL（`Class "App\Support\Pkce" not found`）

- [ ] **Step 4: 上記シグネチャで実装する**

`OwnerSession` はセッション値の型をすべて検証してから DTO にする（`state`・`verifier`・`key` は所定の正規表現、`issued_at`／`expires_at` は int、`channel_id` は `ChannelInput::ID_PATTERN`）。`config/session.php` の `secure` を変更。

- [ ] **Step 5: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（既存含む全件）

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add app/Support config/session.php tests/Unit/PkceTest.php tests/Feature/OwnerSessionTest.php
git commit -m "feat: PKCE と OwnerSession（state・verifier・トークンのセッション内保持と期限判定）を追加"
```

---

### Task 2: GoogleOAuth（認可 URL・トークン交換・取り消し）

**Files:**
- Create: `app/Services/YouTube/GoogleOAuth.php`, `OAuthToken.php`, `OAuthFailed.php`, `tests/Support/FakesGoogle.php`
- Test: `tests/Feature/GoogleOAuthTest.php`

**Interfaces:**
- Consumes: `Pkce::challenge()`（テストのみ）
- Produces:
  - `GoogleOAuth` 定数 `AUTHORIZE_URL`, `TOKEN_URL`, `REVOKE_URL`, `SCOPE = 'https://www.googleapis.com/auth/youtube'`, `ISSUER = 'https://accounts.google.com'`, `MAX_EXPIRES_IN = 3600`, `EXPIRY_MARGIN = 60`
  - `isConfigured(): bool`（client_id・secret・redirect がすべて `filled()`、production では redirect が `https://` 始まり）
  - `authorizationUrl(#[\SensitiveParameter] string $state, string $codeChallenge): string`
  - `exchangeCode(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier): OAuthToken`（失敗はすべて `OAuthFailed`）
  - `revoke(#[\SensitiveParameter] string $token): bool`（200 で true。それ以外・接続失敗は false＋`Log::warning('google oauth revoke failed', ['status' => ?int])`。例外を投げない）
  - `final readonly class OAuthToken(string $accessToken, CarbonImmutable $expiresAt, list<string> $scopes)`、`hasScope(string $scope): bool`。refresh_token・id_token のフィールドは持たない
  - `OAuthFailed extends RuntimeException`、`public readonly string $reason`（`connection`／`redirect`／`http_error`／`malformed`）。メッセージは `Google OAuth token request failed: {reason}` のみ
  - テスト補助 `FakesGoogle`: `fakeToken(array $override = [], int $status = 200)`（既定本文 `access_token=ya29.test-access-token`, `expires_in=3599`, `token_type=Bearer`, `scope=GoogleOAuth::SCOPE`）、`fakeRevoke(int $status = 200)`、`fakeMyChannel(?string $channelId, string $title = 'My Channel')`、`googleRequests(string $urlPrefix): Collection`（`Http::recorded()` の絞り込み）。`FakesYouTube` と同じく URL 前方一致のコールバックで重ねる

- [ ] **Step 1: 失敗するテストを書く**

```php
// GoogleOAuthTest（FakesGoogle）。setUp で config(['services.google' => ['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'test-client-secret', 'redirect' => 'http://localhost:8000/auth/google/callback']])
public function test_authorization_url_parameters(): void
{
    $url = app(GoogleOAuth::class)->authorizationUrl('STATE_x', 'CHALLENGE_y');
    $this->assertStringStartsWith(GoogleOAuth::AUTHORIZE_URL.'?', $url);
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $this->assertSame([
        'client_id' => 'cid.apps.googleusercontent.com', 'redirect_uri' => 'http://localhost:8000/auth/google/callback',
        'response_type' => 'code', 'scope' => GoogleOAuth::SCOPE, 'access_type' => 'online',
        'include_granted_scopes' => 'false', 'prompt' => 'select_account', 'state' => 'STATE_x',
        'code_challenge' => 'CHALLENGE_y', 'code_challenge_method' => 'S256',
    ], $q);   // 順不同で比較してよい。余分なキーが無いこと
    $this->assertStringNotContainsString('test-client-secret', $url);
    $this->assertStringContainsString('%3A%2F%2F', $url); // RFC 3986 エンコード
}
public function test_exchange_posts_form_body(): void
{
    $this->fakeToken();
    $token = app(GoogleOAuth::class)->exchangeCode('4/0Acode', 'VERIFIER');
    $req = $this->googleRequests(GoogleOAuth::TOKEN_URL)->sole();
    $this->assertSame('POST', $req->method());
    $this->assertSame(GoogleOAuth::TOKEN_URL, $req->url());          // クエリ無し
    $this->assertStringContainsString('application/x-www-form-urlencoded', $req->header('Content-Type')[0]);
    $this->assertSame(['code' => '4/0Acode', 'client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => 'test-client-secret',
        'redirect_uri' => 'http://localhost:8000/auth/google/callback', 'grant_type' => 'authorization_code', 'code_verifier' => 'VERIFIER'], $req->data());
    $this->assertSame('ya29.test-access-token', $token->accessToken);
}
public static function badTokenResponses(): array
{
    return [
        'no access_token' => [['access_token' => null]], 'empty access_token' => [['access_token' => '']],
        'array access_token' => [['access_token' => ['x']]], 'token_type mac' => [['token_type' => 'mac']],
        'no token_type' => [['token_type' => null]], 'expires_in zero' => [['expires_in' => 0]],
        'expires_in string' => [['expires_in' => 'abc']], 'expires_in missing' => [['expires_in' => null]],
    ];
}
#[DataProvider('badTokenResponses')]
public function test_exchange_rejects_invalid_token_responses(array $override): void
{
    $this->fakeToken($override);   // null の値はキーごと削除する
    $this->expectException(OAuthFailed::class);
    app(GoogleOAuth::class)->exchangeCode('c', 'v');
}
public function test_exchange_rejects_non_json_and_non_200(): void { /* 200 で本文 'oops' → malformed、400 invalid_grant → http_error。それぞれ OAuthFailed */ }
public function test_token_type_is_case_insensitive(): void { /* token_type 'bearer' で成功 */ }
public function test_expires_at_has_60s_margin_and_3600_cap(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $this->fakeToken(['expires_in' => 3599]);
    $this->assertEquals(now()->addSeconds(3539), app(GoogleOAuth::class)->exchangeCode('c', 'v')->expiresAt);
    $this->fakeToken(['expires_in' => 999999]);   // 別テストに分けてもよい
    $this->assertEquals(now()->addSeconds(3540), app(GoogleOAuth::class)->exchangeCode('c', 'v')->expiresAt);
}
public function test_refresh_token_is_dropped(): void
{
    $this->fakeToken(['refresh_token' => '1//refresh-secret', 'id_token' => 'eyJ.x.y']);
    $token = app(GoogleOAuth::class)->exchangeCode('c', 'v');
    $this->assertStringNotContainsString('refresh-secret', serialize($token));
    $this->assertSame(['accessToken', 'expiresAt', 'scopes'], array_keys(get_object_vars($token)));
}
public function test_timeout_is_not_retried(): void
{
    Http::fake([GoogleOAuth::TOKEN_URL => fn () => throw new ConnectionException('timed out')]);
    try { app(GoogleOAuth::class)->exchangeCode('c', 'v'); $this->fail(); }
    catch (OAuthFailed $e) { $this->assertSame('connection', $e->reason); }
    Http::assertSentCount(1);
}
public function test_invalid_grant_is_not_retried(): void { /* 400 {error: invalid_grant} → OAuthFailed('http_error')、送信 1 回 */ }
public function test_redirect_response_is_failure(): void
{
    Http::fake([GoogleOAuth::TOKEN_URL => Http::response('', 302, ['Location' => 'https://evil.example/']),
        GoogleOAuth::REVOKE_URL => Http::response('', 302, ['Location' => 'https://evil.example/'])]);
    try { app(GoogleOAuth::class)->exchangeCode('c', 'v'); $this->fail(); }
    catch (OAuthFailed $e) { $this->assertSame('redirect', $e->reason); }
    $this->assertFalse(app(GoogleOAuth::class)->revoke('tok'));
    Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://evil.example'));
}
public function test_token_error_logs_only_status_and_code(): void
{
    $logged = []; Event::listen(MessageLogged::class, function ($e) use (&$logged) { $logged[] = $e->message.' '.json_encode($e->context); });
    $this->fakeToken(['error' => 'invalid_grant', 'error_description' => 'SECRET-DESCRIPTION'], 400);
    try { app(GoogleOAuth::class)->exchangeCode('CODE-123', 'VERIFIER-456'); } catch (OAuthFailed) {}
    $all = implode("\n", $logged);
    $this->assertStringContainsString('invalid_grant', $all);
    $this->assertStringContainsString('400', $all);
    foreach (['SECRET-DESCRIPTION', 'CODE-123', 'VERIFIER-456', 'test-client-secret'] as $secret) { $this->assertStringNotContainsString($secret, $all); }
}
public function test_revoke_posts_token_in_body(): void
{
    $this->fakeRevoke();
    $this->assertTrue(app(GoogleOAuth::class)->revoke('ya29.revoke-me'));
    $req = $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole();
    $this->assertSame(GoogleOAuth::REVOKE_URL, $req->url());
    $this->assertSame(['token' => 'ya29.revoke-me'], $req->data());
}
public function test_revoke_failure_returns_false_and_logs_status_only(): void { /* 400 と ConnectionException で false、ログに 'ya29' が無い */ }
public function test_is_configured_rejects_blank_and_http_in_production(): void
{
    $this->assertTrue(app(GoogleOAuth::class)->isConfigured());
    config(['services.google.client_secret' => '']);
    $this->assertFalse(app(GoogleOAuth::class)->isConfigured());
    config(['services.google.client_secret' => 's']);
    $this->app['env'] = 'production';
    $this->assertFalse(app(GoogleOAuth::class)->isConfigured());   // http:// の redirect
    config(['services.google.redirect' => 'https://list-maker.alpacasandbag.jp/auth/google/callback']);
    $this->assertTrue(app(GoogleOAuth::class)->isConfigured());
}
public function test_endpoints_are_https_constants(): void
{
    foreach ([GoogleOAuth::AUTHORIZE_URL, GoogleOAuth::TOKEN_URL, GoogleOAuth::REVOKE_URL] as $u) { $this->assertStringStartsWith('https://', $u); }
    $this->assertStringNotContainsString("config('services.google.", preg_replace('/client_id|client_secret|redirect/', '', file_get_contents(app_path('Services/YouTube/GoogleOAuth.php'))));
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=GoogleOAuthTest`
Expected: FAIL（`Class "App\Services\YouTube\GoogleOAuth" not found`）

- [ ] **Step 3: 上記シグネチャで実装する**

検証順: 接続例外→`connection`、3xx→`redirect`、非 2xx→`http_error`（ログに status と `error` コード）、JSON でない／必須項目不正→`malformed`。`expires_in` は `is_int` または数字のみの文字列を許し正の整数に限る。`scope` は空白区切りで `scopes` に入れる（欠落時は空配列。判定は Task 5）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/YouTube/GoogleOAuth.php app/Services/YouTube/OAuthToken.php app/Services/YouTube/OAuthFailed.php tests/Support/FakesGoogle.php tests/Feature/GoogleOAuthTest.php
git commit -m "feat: GoogleOAuth（認可 URL・トークン交換・取り消し。再試行なし・リダイレクト非追従・応答検証）を追加"
```

---

### Task 3: YouTubeOwnerClient・RequireOwnerSession・不一致画面

**Files:**
- Create: `app/Services/YouTube/YouTubeOwnerClient.php`, `app/Services/YouTube/OwnerChannel.php`, `app/Http/Middleware/RequireOwnerSession.php`, `app/Http/Controllers/OwnerEditController.php`, `resources/views/owner/mismatch.blade.php`, `resources/views/owner/edit.blade.php`（この時点では見出しと「ログイン中: {channel_title}」のみ。Task 6 で完成）, `tests/Support/CreatesProposals.php`
- Modify: `app/Models/Proposal.php`（`findLiveOrAbort`）, `app/Http/Controllers/ProposalController.php`（private `findLive` を `Proposal::findLiveOrAbort` に置き換え）, `routes/web.php`, `tests/Feature/PagesTest.php`
- Test: `tests/Feature/YouTubeOwnerClientTest.php`, `tests/Feature/OwnerEditAccessTest.php`

**Interfaces:**
- Consumes: `OwnerSession::current()`, `OwnerSession::serialize()`, `OwnerLogin`, `QuotaLedger::OWNER_READ`, `YouTubeReader::BASE_URL`, `YouTubeError::fromResponse()/connection()/malformed()`, `YouTubeApiException`
- Produces:
  - `YouTubeOwnerClient::myChannel(#[\SensitiveParameter] string $accessToken): ?OwnerChannel`（`GET channels?part=snippet&mine=true`、`withToken()`、API キーは送らない。送信前に `record(OWNER_READ, 1)`。チャンネル無し・ID 不正は null、HTTP エラーは `YouTubeApiException`（ログは endpoint・status・reason・kind のみ））
  - `final readonly class OwnerChannel(string $id, string $title)`（title は 200 文字で切る）
  - `Proposal::findLiveOrAbort(string $key): Proposal`（無効キー・期限切れ 404、`QueryException` は sqlstate だけログして 503。既存 `ProposalController::findLive` の挙動そのまま）
  - `RequireOwnerSession`: 成功時 `$request->attributes` に `proposal`（Proposal）と `owner`（OwnerLogin）。未ログイン・期限切れ → `redirect()->route('proposals.show', $key)->with('owner_notice', 'ログインの有効期限が切れたか、ログインしていません。もう一度ログインしてください')`。不一致 → `owner.mismatch` を 403
  - ルート `proposals.edit`（GET `/p/{key}/edit`、`[RequireDocumentNavigation::class, RequireOwnerSession::class]`）→ `OwnerEditController@show`
  - テスト補助 `CreatesProposals`: `protected string $channelId = 'UCaaaaaaaaaaaaaaaaaaaaaa'`（UC＋a×22）、`createProposal(array $attrs = [], ?array $items = null): string`（生キーを返す。`PreviewScreenTest::makeProposal` と同じ既定値。既存テストは書き換えない）、`actAsOwner(string $channelId, ?CarbonImmutable $expiresAt = null, string $title = 'My Channel', string $token = 'ya29.session-token'): static`（`withSession([OwnerSession::OWNER_KEY => OwnerSession::serialize(...)])`）

- [ ] **Step 1: 失敗するテストを書く**

```php
// YouTubeOwnerClientTest（FakesGoogle）
public function test_my_channel_uses_bearer_and_records_owner_read(): void
{
    $this->fakeMyChannel('UC'.str_repeat('a', 22), '<b>私のチャンネル</b>');
    $ch = app(YouTubeOwnerClient::class)->myChannel('ya29.tok');
    $this->assertSame('UC'.str_repeat('a', 22), $ch->id);
    $req = Http::recorded()->sole()[0];
    $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
    $this->assertFalse($req->hasHeader('X-Goog-Api-Key'));
    parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
    $this->assertSame(['part' => 'snippet', 'mine' => 'true'], $q);
    $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
}
public function test_my_channel_returns_null_without_channel(): void { /* items [] → null */ }
public function test_my_channel_401_is_auth_error_without_token_in_log(): void { /* 401 → YouTubeApiException kind Auth、ログに 'ya29' 無し */ }
public function test_connection_error_is_transient(): void { /* ConnectionException → kind Transient */ }
public function test_read_budget_exhaustion_does_not_block_owner_read(): void { /* read_budget 0 でも成功 */ }

// OwnerEditAccessTest（CreatesProposals, RefreshDatabase）
public function test_edit_without_login_redirects_to_preview_with_notice(): void
{
    $key = $this->createProposal();
    $this->get("/p/$key/edit")->assertRedirect(route('proposals.show', $key))->assertSessionHas('owner_notice');
}
public function test_expired_owner_is_sent_back_to_preview_at_boundary(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $key = $this->createProposal();
    $this->actAsOwner($this->channelId, now()->toImmutable()->addSeconds(5));
    $this->get("/p/$key/edit")->assertOk();
    $this->travel(5)->seconds();
    $this->get("/p/$key/edit")->assertRedirect(route('proposals.show', $key));
}
public function test_matching_owner_sees_edit_page(): void
{
    $key = $this->createProposal();
    $this->actAsOwner($this->channelId, title: '<script>x</script>')->get("/p/$key/edit")
        ->assertOk()->assertSee('ログイン中: &lt;script&gt;x&lt;/script&gt;', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('ya29.session-token');
}
public function test_mismatched_channel_gets_403_mismatch_page(): void
{
    $key = $this->createProposal(['channel_title' => '対象チャンネル']);
    $res = $this->actAsOwner('UC'.str_repeat('z', 22), title: '別チャンネル')->get("/p/$key/edit")->assertForbidden();
    $res->assertSee('対象チャンネル')->assertSee('別チャンネル')
        ->assertSee('/auth/google?p='.$key, false); // Task 4 で route('owner.login', ['p' => $key]) の href 検査に置き換える
    $this->assertNoInlineCode($res->getContent());
}
public function test_unknown_or_expired_proposal_is_404_even_when_logged_in(): void { /* 不明キー・expires_at 経過で 404 */ }
public function test_edit_rejects_non_navigation_fetch(): void { /* Sec-Fetch-Mode: cors → 400 */ }
```

不一致画面の文言: 見出し「ログイン中のチャンネルはこの提案の対象ではありません」、「この提案は「{proposal.channel_title}」向けです。ログイン中のチャンネル: 「{owner.channelTitle}」」、「このチャンネルには作成できません」、再ログインのリンク「別のチャンネルでログインし直す」（`/auth/google?p={key}`。`prompt=select_account` で選び直せる旨と「ブランドアカウントのチャンネルは、Google のアカウント選択画面で対象のチャンネルを選んでください」）、「別のチャンネルに作りたい場合は、そのチャンネルを対象に提案を作り直してもらってください」。ログアウトのフォームは Task 8 で足す。

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='YouTubeOwnerClientTest|OwnerEditAccessTest|PagesTest|PreviewScreenTest'`
Expected: FAIL（クラス・ルート未定義）

- [ ] **Step 3: 上記シグネチャで実装する**

`PagesTest` の期待一覧に `p/{key}/edit` を追加。`/auth/google` はまだ無いので不一致画面のリンクは `url('/auth/google').'?'.http_build_query(['p' => $key])` で組み、Task 4 で `route('owner.login', ['p' => $key])` に置き換える。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（`PreviewScreenTest::test_database_error_shows_japanese_page_without_logging_key_hash` も通ること）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views/owner tests
git commit -m "feat: チャンネル主のチャンネル取得（owner_read）と RequireOwnerSession・不一致画面・編集画面の入口を追加"
```

---

### Task 4: ログイン開始・S4 のログイン欄・コールバックの入力検証

**Files:**
- Create: `app/Http/Controllers/OwnerAuthController.php`（`redirect`・`callback` の前半）, `resources/views/owner/callback-invalid.blade.php`, `resources/views/partials/owner-login.blade.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php`（`oauth` リミッタ）, `app/Http/Controllers/ProposalController.php::show`（`key`・ログイン状態をビューへ）, `resources/views/proposals/show.blade.php`（「準備中」を `@include('partials.owner-login')` に置換）, `resources/views/owner/mismatch.blade.php`（リンクを `route('owner.login', ['p' => $key])` に）, `tests/Feature/PagesTest.php`
- Test: `tests/Feature/OwnerAuthStartTest.php`, `tests/Feature/OwnerCallbackValidationTest.php`, `tests/Feature/PreviewScreenTest.php`（ログイン欄のケースを追加）

**Interfaces:**
- Consumes: `OwnerSession::begin()/pullPending()/matches()/current()`, `PendingLogin::isExpired()`, `GoogleOAuth::isConfigured()/authorizationUrl()/ISSUER`, `Pkce::challenge()`, `Proposal::findLiveOrAbort()`
- Produces:
  - ルート `owner.login`（GET `/auth/google`、`[RequireDocumentNavigation::class, 'throttle:oauth']`）、`owner.callback`（GET `/auth/google/callback`、同じミドルウェア）
  - `RateLimiter::for('oauth', fn (Request $r) => Limit::perMinute(10)->by('oauth:'.$r->ip()))`
  - `OwnerAuthController::redirect(Request): RedirectResponse`: `p` が文字列・`ProposalKey::isWellFormed`・生きた提案でなければ 404、`! isConfigured()` は `abort(503)`、`begin($key)` → `redirect()->away(authorizationUrl(state, Pkce::challenge(verifier)))`。`p` 以外のクエリ（`return` 等）は読まない
  - `OwnerAuthController::callback(Request)` の前半（この順で。すべて `pullPending()` の後）:
    1. `state`・`code`・`error`・`iss` のうち存在するものが文字列でなければ 400
    2. `error` と `code` が両方ある → 400（D4）
    3. `iss` があり `GoogleOAuth::ISSUER` と異なる → 400（D5）
    4. pending が無い・`isExpired()`・`state` が空・`! hash_equals(pending->state, state)` → 400（A3・A5）
    5. 提案が生きていなければ 404
    6. `error` あり → `redirect()->route('proposals.show', key)->with('owner_error', 固定文言)`（`access_denied` は「ログインがキャンセルされました。作成するには、もう一度ログインして YouTube の管理を許可してください」、それ以外は「ログインできませんでした。もう一度お試しください」。`error_description` は読まない）
    7. `code` が `/^[A-Za-z0-9\/._~+-]{1,512}$/D` に合わない → 400（D4。上限・文字種は人手確認で見直す）
    8. ここから先（トークン交換）は Task 5。Task 4 の時点では 7 の後で `abort(501)` にしておく
  - 400 はすべて `response()->view('owner.callback-invalid', ['retryKey' => 生きた提案の pending キー or null], 400)`。本文「ログインの手続きを確認できませんでした（時間が経った、別のタブでログインを始めた、または同じ画面を再読み込みした可能性があります）。もう一度ログインしてください」、`retryKey` があればプレビューへのリンク、無ければトップへのリンク
  - `ProposalController::show` がビューへ渡す追加値: `key`（string）、`ownerMatches`（bool）、`ownerChannelTitle`（?string）
  - `partials/owner-login`: flash `owner_error`／`owner_notice` を `role="alert"` で表示。`ownerMatches` なら「ログイン中: {title}」と「編集画面へ進む」（`route('proposals.edit', $key)`）。それ以外はログインリンク「チャンネル主の方: Google でログインして作成へ」（`route('owner.login', ['p' => $key])`。他チャンネルでログイン中なら文言を「別のチャンネルでログインし直す」に）、同意文（`route('terms')`・`route('privacy')` へのリンク）、「確認されていないアプリの警告が表示されます。「詳細」→「List Maker（安全ではないページ）に移動」で続行できます」、「YouTube の管理権限を求めますが、使うのは再生リストの作成と動画の追加だけです」

- [ ] **Step 1: 失敗するテストを書く**

```php
// OwnerAuthStartTest（CreatesProposals, FakesGoogle, RefreshDatabase）。setUp で Task 2 と同じ services.google 設定
public function test_start_redirects_to_google_with_pkce(): void
{
    $key = $this->createProposal();
    $loc = $this->get(route('owner.login', ['p' => $key]))->assertRedirect()->headers->get('Location');
    parse_str(parse_url($loc, PHP_URL_QUERY), $q);
    $pending = session(OwnerSession::PENDING_KEY);
    $this->assertSame($pending['state'], $q['state']);
    $this->assertSame(Pkce::challenge($pending['verifier']), $q['code_challenge']);
    $this->assertSame($key, $pending['key']);
    $this->assertStringNotContainsString($pending['verifier'], $loc);
}
public function test_start_stores_state_only_in_session(): void { /* 応答の Set-Cookie と本文に state・verifier が無い */ }
public function test_redirect_uri_ignores_forged_host(): void
{
    $key = $this->createProposal();
    $loc = $this->withHeader('Host', 'evil.example')->get('/auth/google?p='.$key)->headers->get('Location');
    parse_str(parse_url($loc, PHP_URL_QUERY), $q);
    $this->assertSame('http://localhost:8000/auth/google/callback', $q['redirect_uri']);
}
public function test_external_return_url_is_ignored(): void
{
    $key = $this->createProposal();
    $this->get('/auth/google?p='.$key.'&return=https://evil.example/&redirect=//evil.example');
    $this->assertSame(['state', 'verifier', 'key', 'issued_at'], array_keys(session(OwnerSession::PENDING_KEY)));
}
public function test_unknown_expired_or_malformed_key_is_404(): void { /* p 無し・p[]=x・不明キー・期限切れで 404、Google への遷移なし */ }
public function test_login_start_is_503_when_not_configured(): void { /* client_id '' → 503、セッションに pending なし */ }
public function test_oauth_routes_are_rate_limited_and_callback_is_get_only(): void
{
    $key = $this->createProposal();
    for ($i = 0; $i < 10; $i++) { $this->get('/auth/google?p='.$key)->assertRedirect(); }
    $this->get('/auth/google/callback?state=x&code=y')->assertStatus(429); // 開始とコールバックで共通の 10 回/分
    $this->post('/auth/google/callback')->assertStatus(405);  // 別テストに分けてよい（CSRF より前に 405 になること）
}

// OwnerCallbackValidationTest（CreatesProposals, FakesGoogle, RefreshDatabase）
// 補助: private function begin(string $key): string — GET /auth/google?p=$key を実行し state を返す
public static function badStates(): array
{
    return ['missing' => [null], 'empty' => [''], 'mismatch' => ['WRONG'], 'array' => [['x']]];
}
#[DataProvider('badStates')]
public function test_bad_state_is_400_without_token_request(mixed $state): void
{
    $key = $this->createProposal(); $this->begin($key); $this->fakeToken();
    $query = ['code' => '4/0Acode'] + ($state === null ? [] : ['state' => $state]);
    $this->get('/auth/google/callback?'.http_build_query($query))->assertStatus(400);
    $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    $this->assertCount(0, $this->googleRequests(GoogleOAuth::TOKEN_URL));
}
public function test_replayed_callback_is_400(): void
{
    $key = $this->createProposal(); $state = $this->begin($key); $this->fakeToken(); $this->fakeMyChannel($this->channelId);
    $url = '/auth/google/callback?'.http_build_query(['state' => $state, 'code' => '4/0Acode']);
    $this->get($url);                       // Task 4 時点では 501、Task 5 以降は 302
    $this->get($url)->assertStatus(400);
    $this->assertLessThanOrEqual(1, $this->googleRequests(GoogleOAuth::TOKEN_URL)->count());
}
public function test_state_older_than_10_minutes_is_400(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $key = $this->createProposal(); $state = $this->begin($key);
    $this->travel(601)->seconds();
    $this->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']))->assertStatus(400);
}
public function test_malformed_code_or_error_with_code_is_400(): void { /* code に空白・'<'・513 文字・配列、error と code の同時指定で 400、トークン要求なし */ }
public function test_wrong_iss_is_400_and_correct_iss_passes(): void { /* iss=https://evil.example → 400、iss=https://accounts.google.com → 400 以外 */ }
public function test_access_denied_returns_to_preview_with_fixed_message(): void
{
    $key = $this->createProposal(); $state = $this->begin($key);
    $this->get('/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))
        ->assertRedirect(route('proposals.show', $key));
    $this->get(route('proposals.show', $key))->assertSee('ログインがキャンセルされました');
}
public function test_error_description_is_never_shown(): void
{
    $key = $this->createProposal(); $state = $this->begin($key);
    $this->followingRedirects()->get('/auth/google/callback?'.http_build_query(['state' => $state, 'error' => 'server_error', 'error_description' => '<b>FAKE-MESSAGE</b> call 0120']))
        ->assertDontSee('FAKE-MESSAGE')->assertSee('ログインできませんでした');
}
public function test_superseded_or_replayed_callback_shows_retry_page(): void
{
    $key = $this->createProposal();
    $first = $this->begin($key); $this->begin($key);   // 2 つ目のタブが上書き
    $res = $this->get('/auth/google/callback?'.http_build_query(['state' => $first, 'code' => 'c']))->assertStatus(400);
    $res->assertSee('もう一度ログインしてください')->assertSee('href="'.route('proposals.show', $key).'"', false);
    $this->assertNoInlineCode($res->getContent());
}

// PreviewScreenTest に追加
public function test_preview_shows_login_link_consent_and_warning(): void
{
    $key = $this->makeProposal();
    $this->get(route('proposals.show', $key))->assertDontSee('準備中')
        ->assertSee('href="'.e(route('owner.login', ['p' => $key])).'"', false)
        ->assertSee('チャンネル主の方: Google でログインして作成へ')
        ->assertSee('確認されていないアプリの警告が表示されます')
        ->assertSee('href="'.route('terms').'"', false)->assertSee('href="'.route('privacy').'"', false);
}
public function test_preview_links_to_edit_for_matching_owner_only(): void { /* 一致: proposals.edit リンクあり。他チャンネル: 無く「別のチャンネルでログインし直す」 */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='OwnerAuthStartTest|OwnerCallbackValidationTest|PreviewScreenTest|PagesTest'`
Expected: FAIL（ルート未定義）

- [ ] **Step 3: 実装する**

上記の順序どおりに `callback` 前半を書く（`pullPending()` を最初の文にする）。`PagesTest` に `auth/google`, `auth/google/callback` を追加。`mismatch.blade.php` のリンクを `route()` に置き換え、Task 3 のテストの期待値も `route('owner.login', ['p' => $key])` に戻す。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views tests
git commit -m "feat: Google ログインの開始とコールバックの検証（state 一回限り・10 分期限・iss・code 形式・固定文言のエラー）、S4 のログイン欄を追加"
```

---

### Task 5: コールバックのトークン交換とログイン完了

**Files:**
- Modify: `app/Http/Controllers/OwnerAuthController.php`（`callback` 後半）
- Test: `tests/Feature/OwnerCallbackLoginTest.php`

**Interfaces:**
- Consumes: `GoogleOAuth::exchangeCode()/revoke()/SCOPE`, `OAuthToken::hasScope()`, `OAuthFailed`, `YouTubeOwnerClient::myChannel()`, `YouTubeApiException`, `OwnerSession::login()`, `OwnerLogin`
- Produces: `callback` 後半（Task 4 の `abort(501)` を置き換え。この順で）:
  1. `exchangeCode($code, $pending->verifier)`。`OAuthFailed` → プレビューへ `owner_error`「Google との通信に失敗しました。もう一度ログインしてください」
  2. `! hasScope(GoogleOAuth::SCOPE)` → `revoke()` → `owner_error`「YouTube の管理が許可されなかったため作成できません。もう一度ログインし、YouTube の管理を許可してください」
  3. `myChannel()`。`YouTubeApiException` → `revoke()` → `owner_error`「YouTube のチャンネルを確認できませんでした。時間をおいてもう一度ログインしてください」。null → `revoke()` → `owner_error`「このアカウントには YouTube チャンネルがありません。チャンネルのあるアカウントでログインしてください」
  4. `session()->regenerate()` → `login(new OwnerLogin(token, expiresAt, channel.id, channel.title))` → `redirect()->route('proposals.edit', $pending->proposalKey)`（チャンネル不一致の判定・画面は `RequireOwnerSession` に任せる。作成済みなら creation へ送る分岐は Phase 3）
  - 既存の owner がセッションにあれば 4 で上書きする（古いトークンの取り消しはしない。同じ承認の取り消しで新しいトークンまで無効になりうるため）

- [ ] **Step 1: 失敗するテストを書く**

```php
// OwnerCallbackLoginTest（CreatesProposals, FakesGoogle, RefreshDatabase）
// 補助: private function callback(string $key, array $extra = []): TestResponse — begin して state 付きでコールバックを叩く
public function test_success_logs_in_and_redirects_to_edit(): void
{
    $key = $this->createProposal(); $this->fakeToken(); $this->fakeMyChannel($this->channelId, 'チャンネル A');
    $res = $this->callback($key, ['code' => '4/0Acode-xyz']);
    $res->assertStatus(302)->assertRedirect(route('proposals.edit', $key))->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertStringNotContainsString('4/0Acode-xyz', $res->getContent());
    $this->assertFalse(session()->has(OwnerSession::PENDING_KEY));
    $this->assertSame($this->channelId, app(OwnerSession::class)->current()->channelId);
    $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    $this->get(route('proposals.edit', $key))->assertOk();
}
public function test_mismatched_login_lands_on_mismatch_page(): void { /* 別 UC で成功 → edit へ 302 → 403 不一致画面 */ }
public function test_redirect_uri_is_identical_in_both_requests(): void
{
    // 開始の Location の redirect_uri と、トークン要求本文の redirect_uri と、config 値の 3 つが完全一致
}
public function test_session_id_changes_on_login(): void
{
    $key = $this->createProposal(); $this->fakeToken(); $this->fakeMyChannel($this->channelId);
    $state = $this->begin($key); $before = session()->getId();
    $this->get('/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'c']));
    $this->assertNotSame($before, session()->getId());
}
public function test_session_holds_only_owner_fields(): void
{
    $key = $this->createProposal(); $this->fakeToken(['refresh_token' => '1//refresh-secret']); $this->fakeMyChannel($this->channelId);
    $this->callback($key);
    $this->assertSame(['access_token', 'channel_id', 'channel_title', 'expires_at'], collect(session(OwnerSession::OWNER_KEY))->keys()->sort()->values()->all());
    $this->assertStringNotContainsString('refresh-secret', json_encode(session()->all()));
}
public function test_insufficient_scope_revokes_and_explains(): void
{
    $key = $this->createProposal(); $this->fakeToken(['scope' => 'https://www.googleapis.com/auth/youtube.readonly']); $this->fakeRevoke();
    $this->callback($key)->assertRedirect(route('proposals.show', $key));
    $this->assertSame(['token' => 'ya29.test-access-token'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
    $this->assertNull(app(OwnerSession::class)->current());
    $this->get(route('proposals.show', $key))->assertSee('YouTube の管理が許可されなかったため');
}
public function test_missing_scope_field_is_treated_as_insufficient(): void { /* scope キー無し → 同上 */ }
public function test_account_without_channel_is_revoked_and_explained(): void { /* fakeMyChannel(null) → revoke 1 回、owner なし、「YouTube チャンネルがありません」 */ }
public function test_channel_lookup_failure_revokes(): void { /* channels 500 → revoke、owner なし、固定文言 */ }
public function test_token_failure_returns_to_preview_without_retry(): void { /* token 500 → 送信 1 回、owner_error「Google との通信に失敗しました」、channels 呼ばれない */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=OwnerCallbackLoginTest`
Expected: FAIL（501）

- [ ] **Step 3: 上記の順序で実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（Task 4 の `test_replayed_callback_is_400` も 1 回目が 302 になって通る）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Http/Controllers/OwnerAuthController.php tests/Feature/OwnerCallbackLoginTest.php
git commit -m "feat: コールバックでのトークン交換・スコープ確認・チャンネル取得・セッション再生成とログイン完了"
```

---

### Task 6: 編集画面 S5（サーバー側）

**Files:**
- Modify: `app/Http/Controllers/OwnerEditController.php`, `resources/views/owner/edit.blade.php`
- Test: `tests/Feature/OwnerEditScreenTest.php`

**Interfaces:**
- Consumes: request attributes `proposal`・`owner`（Task 3）, `QuotaLedger::remainingToday()`, `QuotaPolicy::maxItemsPerPlaylist()`, `ChannelVideoCatalog::load()`, `CatalogResult`, `route('channels.video', $channelId)`, partials `max-items`・`remaining-today`
- Produces:
  - `OwnerEditController::show(Request $request, ChannelVideoCatalog $catalog, QuotaLedger $ledger): Response`
  - 一覧（対象チャンネルから追加する候補）は `$catalog->load($proposal->channel_id)` を使う（キャッシュ使い回し・差分取り込みは Phase 1 のまま。`ChannelFetchThrottle` は掛けない）。`channel` が null なら一覧を出さず「チャンネルの動画一覧を読み込めませんでした。動画 URL で追加してください」を表示（ページ自体は 200）
  - `#owner-editor` 要素の `data-editor` JSON（キー固定。Task 7 が読む）: `maxItems`（int）、`remainingItems`（int）、`resetLabel`（`n月j日 G時` の JST 文字列）、`lookupUrl`、`draftId`（`substr($proposal->key_hash, 0, 16)`）、`items`（提案の並び順で `[{id, title, thumbnail_url, outside}]`）、`videos`（一覧。`ProposalController::create` の payload と同じ形）、`title`、`description`、`privacy`（`'private'`）。トークン・生キー・`key_hash` 全体は入れない
  - サーバー描画の初期状態（JS 無しでも正しい）: 「ログイン中: {channel_title}」、編集リスト（`n / max`）、名前・説明の入力、公開範囲 `<select name="privacy">`（`private` 非公開＝selected、`unlisted` 限定公開、`public` 公開）、`@include('partials.max-items')`、「この内容に必要な枠: 再生リスト 1 つ＋動画 {n} 本」、`@include('partials.remaining-today', ['remaining' => $remaining])`、n > N のとき「あと {n − N} 本減らすか、リセット後に作成してください」＋リセット時刻、作成ボタン `<button type="button" id="create-playlist">あなたのチャンネルに再生リストを作成します</button>`（n > N または n = 0 なら `disabled`）と注記「押すとあなたの YouTube チャンネルに再生リストが作成されます（書き込み操作）。作成機能は準備中で、現在は押しても作成されません」、「提案の内容に戻す」ボタン、「データ提供: YouTube」
  - Phase 2 では `<form>` を置かない（書込エンドポイントは Phase 3 の `POST /p/{key}/creation`）

- [ ] **Step 1: 失敗するテストを書く**

```php
// OwnerEditScreenTest（CreatesProposals, FakesYouTube, RefreshDatabase, AssertsNoInlineCode）。一覧は ChannelCache を直接作って Reused にする
public function test_shows_needed_and_remaining_and_enabled_button(): void
{
    $key = $this->createProposal();   // 2 本
    $res = $this->actAsOwner($this->channelId, title: 'チャンネル A')->get(route('proposals.edit', $key))->assertOk();
    $res->assertSee('ログイン中: チャンネル A')
        ->assertSee('1 つの再生リストに入れられるのは最大 99 本')
        ->assertSee('この内容に必要な枠: 再生リスト 1 つ＋動画 2 本')
        ->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます')
        ->assertSee('あなたのチャンネルに再生リストを作成します')
        ->assertDontSee('本減らすか');
    $this->assertDoesNotMatchRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
    $this->assertNoInlineCode($res->getContent());
}
public function test_shortage_disables_button_and_shows_reset_time(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));          // 次のリセットは 11月15日 17時 JST
    app(QuotaLedger::class)->record(QuotaLedger::WRITE, 4900);               // 空き 100 → N = 1
    $key = $this->createProposal();                                          // n = 2
    $res = $this->actAsOwner($this->channelId)->get(route('proposals.edit', $key));
    $res->assertSee('あと 1 本減らすか、リセット後に作成してください')->assertSee('日本時間 11月15日 17時');
    $this->assertMatchesRegularExpression('/<button[^>]*id="create-playlist"[^>]*disabled/', $res->getContent());
}
public function test_exhausted_quota_shows_reset_message(): void { /* WRITE 5000 → 「本日はこれ以上作成できません」＋リセット時刻、ボタン disabled */ }
public function test_privacy_defaults_to_private_and_offers_three_values(): void
{
    $html = $this->actAsOwner($this->channelId)->get(route('proposals.edit', $this->createProposal()))->getContent();
    $this->assertMatchesRegularExpression('/<option value="private"[^>]*selected/', $html);
    foreach (['unlisted', 'public'] as $v) { $this->assertStringContainsString('<option value="'.$v.'"', $html); }
}
public function test_editor_payload_has_fixed_keys_and_no_secrets(): void
{
    // data-editor を DOM から取り出して json_decode。キー集合が上記 11 個と一致、items が position 順・outside 反映、
    // privacy === 'private'、HTML 全体に 'ya29.session-token'・生キー・key_hash 全体が無い
}
public function test_catalog_unavailable_still_renders_page(): void { /* キャッシュ無し＋read_budget 0 → 200、「動画 URL で追加してください」 */ }
public function test_writes_nothing(): void { /* 表示前後で proposals・proposal_items の行と quota_usage(write) が変わらない */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=OwnerEditScreenTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

画面の要素は `proposals/create.blade.php` の書き方（`x-data="ownerEditor"`、`data-*` JSON、`x-cloak`）に合わせる。Alpine バインディング（`x-text`・`:disabled="isCreateDisabled"` 等）はこのタスクで書いてよいが、JS 本体は Task 7。サーバー描画の `disabled` 属性は Alpine が上書きする前提で両方書く。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Http/Controllers/OwnerEditController.php resources/views/owner/edit.blade.php tests/Feature/OwnerEditScreenTest.php
git commit -m "feat: 編集画面 S5（必要な枠と本日の残り・不足時の作成ボタン無効とリセット時刻・公開範囲の既定は非公開）"
```

---

### Task 7: 編集画面の操作（owner-edit.js と純関数）

**Files:**
- Create: `resources/js/lib/owner-edit.js`, `resources/js/lib/draft.js`, `resources/js/owner-edit.js`, `tests/js/owner-edit.test.js`, `tests/js/draft.test.js`
- Modify: `resources/js/lib/safe-storage.js`（`safeSessionStorage()` を追加）, `vite.config.js`（`input` に `resources/js/owner-edit.js`）, `resources/views/owner/edit.blade.php`（`@push('vite') @vite('resources/js/owner-edit.js') @endpush`）
- Test: 上記 JS テスト、`tests/Feature/ViteEntriesTest.php`（既存。追加漏れを検出）

**Interfaces:**
- Consumes: `data-editor`（Task 6 のキー）、`lib/selection.js` の `addVideo`・`removeVideo`・`moveVideo`・`restoreSelection`・`maxItemsFrom`、`lib/text.js` の `countChars`・`isOverLimit`、`lib/filter.js` の `filterVideos`、`lib/format.js`、`channels.video` の JSON 応答（`{video: {id, title, thumbnail_url, outside, ...}}`／`{message}`）
- Produces:
  - `lib/owner-edit.js`:
    - `PRIVACY_VALUES = ['private', 'unlisted', 'public']`、`normalizePrivacy(v): string`（不正値は `'private'`）
    - `remainingFrom(editor): number`（`remainingItems` が 0 以上の整数でなければ 0。JS で枠の式を持たない）
    - `creationQuota(count, remainingItems): {neededText, shortBy, canCreate}`（`neededText` = `この内容に必要な枠: 再生リスト 1 つ＋動画 ${count} 本`、`shortBy = max(0, count − remainingItems)`、`canCreate = count >= 1 && shortBy === 0`）
    - `shortageText(shortBy, resetLabel): string`（`あと ${shortBy} 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 ${resetLabel} です）`。`shortBy < 1` は `''`）
    - `isCreateDisabled({count, maxItems, remainingItems, title, description}): boolean`（count 0、maxItems 0、枠不足、名前が空（空白のみ含む）・150 超、説明 1,000 超のいずれかで true）
  - `lib/draft.js`（sessionStorage のみ。キー `listmaker:edit:${draftId}`。保存形 `{items: [{id, outside}], title, description, privacy}`。タイトル・サムネは保存しない）: `loadDraft(storage, draftId): object|null`（壊れた JSON・形違いは null）、`saveDraft(storage, draftId, state): boolean`、`clearDraft(storage, draftId): void`。すべて例外を握りつぶす
  - `owner-edit.js`: `Alpine.data('ownerEditor', …)`。初期化で `loadDraft` があれば `restoreSelection(draft.items, 提案項目＋一覧の Map, maxItems)` で復元し、無ければ `items` を使う。操作（追加・削除・上下移動・URL 追加・名前・説明・公開範囲）のたびに `saveDraft`。「提案の内容に戻す」は `clearDraft` して初期値へ。作成ボタンのクリックは「作成機能は準備中です」を表示するだけ（送信しない）

- [ ] **Step 1: 失敗するテストを書く**

```js
// tests/js/owner-edit.test.js
test('creationQuota: within remaining', () => {
  assert.deepEqual(creationQuota(2, 99), { neededText: 'この内容に必要な枠: 再生リスト 1 つ＋動画 2 本', shortBy: 0, canCreate: true });
});
test('creationQuota: shortage and zero', () => {
  assert.equal(creationQuota(5, 3).shortBy, 2);
  assert.equal(creationQuota(5, 3).canCreate, false);
  assert.equal(creationQuota(0, 99).canCreate, false);
});
test('shortageText', () => {
  assert.equal(shortageText(2, '11月15日 17時'), 'あと 2 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 11月15日 17時 です）');
  assert.equal(shortageText(0, 'x'), '');
});
test('remainingFrom rejects missing or malformed', () => {
  for (const v of [undefined, null, -1, 1.5, '3']) assert.equal(remainingFrom({ remainingItems: v }), 0);
  assert.equal(remainingFrom({ remainingItems: 7 }), 7);
});
test('isCreateDisabled', () => {
  const ok = { count: 2, maxItems: 99, remainingItems: 99, title: 'a', description: '' };
  assert.equal(isCreateDisabled(ok), false);
  assert.equal(isCreateDisabled({ ...ok, title: ' 　' }), true);
  assert.equal(isCreateDisabled({ ...ok, title: 'あ'.repeat(151) }), true);
  assert.equal(isCreateDisabled({ ...ok, description: 'x'.repeat(1001) }), true);
  assert.equal(isCreateDisabled({ ...ok, remainingItems: 1 }), true);
  assert.equal(isCreateDisabled({ ...ok, maxItems: 0 }), true);
});
test('normalizePrivacy defaults to private', () => {
  assert.equal(normalizePrivacy('public'), 'public');
  for (const v of [undefined, '', 'PUBLIC', 'friends']) assert.equal(normalizePrivacy(v), 'private');
});

// tests/js/draft.test.js（Map ベースの偽 storage と、getItem/setItem が throw する storage）
test('save and load round trip keeps only ids and outside', () => { /* items に title を渡しても保存値に title が無い */ });
test('broken json or wrong shape returns null', () => { /* '{', '[]', {items: 'x'} → null */ });
test('throwing or null storage never throws', () => { /* load は null、save は false、clear は何もしない */ });
test('drafts are per draftId', () => { /* 'aaa' と 'bbb' が混ざらない */ });
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `npm run test:js`
Expected: FAIL（`Cannot find module .../lib/owner-edit.js`）

- [ ] **Step 3: 実装する**

`owner-edit.js` は `propose.js` と同じ構成（`readJson(el, 'editor')`、getter で派生値、行ごとの helper は `this.item`／`this.video`）。URL 追加は `propose.js::addByUrl` と同じ fetch（`X-Requested-With: XMLHttpRequest`）で、応答の `outside` をそのままバッジに使う。

- [ ] **Step 4: テストが通ることを確認**

Run: `npm run test:js && npm run build && php artisan test --filter='ViteEntriesTest|OwnerEditScreenTest' && ! grep -l "new Function" public/build/assets/*.js`
Expected: PASS（`grep` は 0 件）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add resources/js vite.config.js resources/views/owner/edit.blade.php tests/js
git commit -m "feat: 編集画面の操作（追加・削除・並べ替え・名前と公開範囲、sessionStorage の下書き、枠不足時の作成ボタン無効）"
```

---

### Task 8: ログアウトと取り消し・安全対策の総点検・判定記録

**Files:**
- Create: `resources/views/owner/logged-out.blade.php`, `tests/Feature/OwnerLogoutTest.php`, `tests/Feature/OwnerSecurityTest.php`
- Modify: `app/Http/Controllers/OwnerAuthController.php`（`logout`・`loggedOut`）, `routes/web.php`, `app/Providers/AppServiceProvider.php`（I2）, `resources/views/owner/{edit,mismatch}.blade.php`・`partials/owner-login.blade.php`（ログアウトフォーム）, `tests/Feature/PagesTest.php`, `README.md`（Phase 2 の運用メモ）, `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（6.7 末尾に判定記録表）

**Interfaces:**
- Consumes: `OwnerSession::tokenForRevoke()`, `GoogleOAuth::revoke()`
- Produces:
  - ルート `owner.logout`（POST `/logout`。CSRF は標準）、`owner.logged-out`（GET `/logged-out`）
  - `OwnerAuthController::logout(Request): RedirectResponse`: `tokenForRevoke()` があれば `revoke()`（失敗は `GoogleOAuth` 側でステータスのみログ）→ `session()->invalidate()` → `session()->regenerateToken()` → `redirect()->route('owner.logged-out')->with('revoke_failed', bool)`（トークンが無ければ revoke を呼ばず `revoke_failed=false`）
  - `logged-out` 画面: 「ログアウトしました」、`revoke_failed` が true なら「Google 側の承認の取り消しに失敗しました。下のリンクから取り消してください」、常に取り消し案内（`https://myaccount.google.com/permissions`）とトップへのリンク
  - ログアウトフォーム（`<form method="post" action="{{ route('owner.logout') }}">@csrf<button>ログアウト（Google 側の承認も取り消します）</button></form>`）を S5・不一致画面・S4（ログイン中のとき）に置く
  - `AppServiceProvider::boot()`: `app()->isProduction() && config('app.debug')` なら `Log::warning('APP_DEBUG is enabled in production')`

- [ ] **Step 1: 失敗するテストを書く**

```php
// OwnerLogoutTest（CreatesProposals, FakesGoogle, RefreshDatabase）
public function test_logout_revokes_and_invalidates_session(): void
{
    $key = $this->createProposal(); $this->fakeRevoke();
    $this->actAsOwner($this->channelId, token: 'ya29.logout-me');
    $this->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
    $this->assertSame(['token' => 'ya29.logout-me'], $this->googleRequests(GoogleOAuth::REVOKE_URL)->sole()->data());
    $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    $this->get(route('proposals.edit', $key))->assertRedirect(route('proposals.show', $key));
    $this->get(route('owner.logged-out'))->assertSee('ログアウトしました')->assertSee('href="https://myaccount.google.com/permissions"', false);
}
public function test_revoke_failure_still_logs_out(): void
{
    $this->fakeRevoke(503);
    $this->actAsOwner($this->channelId)->post(route('owner.logout'))->assertRedirect(route('owner.logged-out'));
    $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
    $this->get(route('owner.logged-out'))->assertSee('取り消しに失敗しました');
}
public function test_expired_token_is_still_revoked(): void { /* expiresAt 過去でも revoke 1 回 */ }
public function test_logout_without_login_skips_revoke(): void { /* revoke 0 回、logged-out へ */ }
public function test_logout_requires_post(): void { /* GET /logout は 405（CSRF はテスト実行時に Laravel が検証を外すため、標準の web グループに載っていることを route のミドルウェア一覧で確認） */ }
public function test_csrf_token_changes_on_logout(): void { /* 前後で csrf_token() が変わる */ }

// OwnerSecurityTest（CreatesProposals, FakesGoogle, RefreshDatabase）
public function test_no_token_in_any_table_cache_or_log(): void
{
    config(['session.driver' => 'database', 'session.encrypt' => true, 'cache.default' => 'database']);
    app('session')->forgetDrivers();
    $logged = []; Event::listen(MessageLogged::class, function ($e) use (&$logged) { $logged[] = $e->message.' '.json_encode($e->context); });
    $key = $this->createProposal();
    $this->fakeToken(['access_token' => 'ya29.MUST-NOT-PERSIST', 'refresh_token' => '1//REFRESH-NOT-PERSIST']); $this->fakeMyChannel($this->channelId);
    // 開始 → コールバック → 編集画面 を実際に通す（Cookie を引き継ぐ）
    foreach (DB::connection()->getSchemaBuilder()->getTableListing() as $table) {
        $dump = json_encode(DB::table($table)->get());
        $this->assertStringNotContainsString('MUST-NOT-PERSIST', $dump, $table);
        $this->assertStringNotContainsString('REFRESH-NOT-PERSIST', $dump, $table);
    }
    $this->assertSame(1, DB::table('sessions')->count());   // セッションにはある（暗号化されている）ことの確認
    $this->assertStringNotContainsString('MUST-NOT-PERSIST', implode("\n", $logged));
}
public function test_secrets_never_reach_logs(): void
{
    // client_secret・API キー・code・state・verifier・トークンを固有文字列にし、
    // トークン 500（本文に秘密を含める）、トークン接続例外、channels 401、revoke 400 の各経路を通して
    // MessageLogged の message＋context に固有文字列と 'Bearer' が 1 つも無いこと
}
public function test_secret_parameters_are_sensitive(): void
{
    $cases = [[GoogleOAuth::class, 'exchangeCode', ['code', 'verifier']], [GoogleOAuth::class, 'revoke', ['token']],
        [GoogleOAuth::class, 'authorizationUrl', ['state']], [YouTubeOwnerClient::class, 'myChannel', ['accessToken']],
        [OwnerLogin::class, '__construct', ['accessToken']], [OAuthToken::class, '__construct', ['accessToken']], [Pkce::class, 'challenge', ['verifier']]];
    foreach ($cases as [$class, $method, $params]) {
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
            if (in_array($p->getName(), $params, true)) { $this->assertNotEmpty($p->getAttributes(SensitiveParameter::class), "$class::$method \$".$p->getName()); }
        }
    }
}
public function test_tls_verification_is_never_disabled(): void
{
    foreach (File::allFiles(app_path()) as $f) {
        $src = $f->getContents();
        $this->assertDoesNotMatchRegularExpression('/withoutVerifying|[\'"]verify[\'"]\s*=>\s*false/', $src, $f->getRelativePathname());
    }
}
public function test_client_secret_never_reaches_html_or_frontend(): void
{
    config(['services.google.client_secret' => 'CLIENT-SECRET-SENTINEL']);
    // S4（未ログイン・ログイン中）、S5、不一致画面、callback-invalid、logged-out の HTML に 'CLIENT-SECRET-SENTINEL' が無い。
    // resources/js 以下と .env.example に 'GOOGLE_CLIENT_SECRET' を読む VITE_ 変数が無い（/VITE_[A-Z_]*GOOGLE/ が 0 件）
}
public function test_debug_in_production_logs_warning(): void { /* env production・app.debug true で boot → warning、false では出ない */ }
public function test_no_stateless_mode_exists(): void { /* app 以下に 'stateless' の文字列が無い（A6） */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='OwnerLogoutTest|OwnerSecurityTest|PagesTest'`
Expected: FAIL（ルート未定義）

- [ ] **Step 3: 実装する**

`PagesTest` に `logged-out`, `logout` を追加。README の運用メモに追記: 「ログイン中のトークンは暗号化 DB セッションのみ（最長 `SESSION_LIFETIME`=120 分、ログアウトで即削除と Google 側の取り消し）」「コールバック URL（認可コード）は Web サーバーのアクセスログに残りうるが一回限り・短命で再利用できない（I3）」「`GOOGLE_REDIRECT_URI` は GCP に登録した URI と完全一致させる」。

- [ ] **Step 4: spec 6.7 に判定記録を追記する**

`docs/superpowers/specs/2026-11-14-playlist-proposal-design.md` 6.7 の末尾に「判定記録（Phase 2）」表を追加: 列は `# / 判定（満たす・対象外） / 根拠（テスト名またはファイル・理由） / 参照ライブラリでの裏取り（レビュー担当が記入）`。本計画の「6.7 チェックリスト対応表」のテスト名をそのまま根拠欄に転記し、判定は実装者が仮記入、裏取り欄は空のままにする（最終レビュー担当が Socialite・league/oauth2-client・google/auth のソースを確認して記入。U13）。ライブラリと違う判断（state の 10 分期限、交換の再試行なし、トークン/取り消しのリダイレクト非追従、ログアウト時に古いトークンを取り消さない再ログイン上書き、`scope` 欠落を不足扱い）は理由を書く。

- [ ] **Step 5: テストが通ることを確認**

Run: `php artisan test && npm run test:js && ./vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views tests README.md docs/superpowers/specs/2026-11-14-playlist-proposal-design.md
git commit -m "feat: ログアウトと Google 側の承認取り消し、OAuth 安全対策の総点検テストと 6.7 判定記録を追加"
```

---

## 最終確認（PR 前）

- [ ] `php artisan test`、`./vendor/bin/pint --test`、`npm run test:js`、`npm run build`、`! grep -l "new Function" public/build/assets/*.js` がすべて成功。
- [ ] `grep -rn '{!!' resources/views`・`grep -rn 'innerHTML\|x-html' resources/js` が 0 件。`grep -rn 'withoutVerifying\|stateless\|refresh_token' app` で、`refresh_token` は「保存しない」コメント以外に出ない。`grep -rn "Log::" app/Services/YouTube app/Http/Controllers/OwnerAuthController.php` の各呼び出しの context にトークン・コード・本文が無いことを目視。
- [ ] 最終レビュー担当（`superpowers:requesting-code-review`）に、本計画の「6.7 チェックリスト対応表」と spec 6.7 の判定記録表を渡し、全項目の「満たす／対象外（理由）」と参照ライブラリの裏取りを記入してもらう。未記入・「満たさない」が残れば PR をマージしない（11 章 Phase 2 の完了条件）。
- [ ] `git push -u origin phase2-owner-login` → PR 作成（本文末尾に指定の署名行）。

## 人手で行う確認（実在 Google アカウント・実 API。11 章 Phase 2 の完了条件）

1. ローカル `.env` に本ツール専用 GCP プロジェクトの `GOOGLE_CLIENT_ID`／`GOOGLE_CLIENT_SECRET`／`GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback`（GCP 登録値と完全一致）を設定する。
2. 自分のチャンネル向けの提案を作り、S4 から「Google でログインして作成へ」→ 未確認アプリ警告を「詳細→移動」で越える → 同意 → S5 に入れること。S5 に「ログイン中: {自分のチャンネル名}」、必要な枠・本日の残りが出ること。
3. 別チャンネル（ブランドアカウント）を選んでログインし、不一致画面が出ること。「別のチャンネルでログインし直す」でアカウント選択画面が出て（`prompt=select_account`）、正しいチャンネルで S5 に入れること。
4. ログアウト後、`https://myaccount.google.com/permissions` から List Maker の承認が消えていること（取り消しの確認）。
5. 同意画面で「キャンセル」→ プレビューに「ログインがキャンセルされました」。同意画面で YouTube の権限のチェックを外せる場合は外して、スコープ不足の案内が出てアプリが承認一覧に残らないこと。
6. spec U8・6.2・6.7 の［要確認］を実測して spec に追記: Google のウェブクライアントで PKCE（S256）が受け付けられること、コールバックに `iss` が付くか（付かなければ D5 は「返る場合のみ」のまま）、トークン応答の `scope` の形式と `expires_in`（3599 か）、`access_type=online` で `refresh_token` が返らないこと、認可コードの長さ・文字種（`/^[A-Za-z0-9\/._~+-]{1,512}$/` で足りるか）。
7. YouTube チャンネルの無い Google アカウントで「YouTube チャンネルがありません」が出て、承認一覧に残らないこと。
8. 本番（`https://list-maker.alpacasandbag.jp`、redirect URI を本番値で GCP に登録）にデプロイし、2〜4 を本番でも確認。ブラウザでセッション Cookie に `Secure`・`HttpOnly`・`SameSite=Lax` が付くこと、`APP_DEBUG=false` であること、各画面でコンソールに CSP 違反が出ないことを確認。サーバーの Web アクセスログにコールバック URL が残るかを確認し、spec 6.7 I3 の記録に反映する。
9. **S5 の画面操作（owner-edit.js。Alpine 部分は自動テストが無いためリリース前にブラウザで一巡する）**: チェックでの追加・外す、99 本の上限表示、↑/↓/削除（スクリーンリーダーで「「動画名」を上へ移動」等と読まれること）、絞り込み、URL での追加（重複・上限・対象チャンネル外）、再生リスト名を空にすると「再生リスト名を入力してください」が出て作成ボタンが無効になること、文字数カウンタ、公開範囲、「提案の内容に戻す」。再読み込みで下書きが戻り「前回の編集内容を復元しました（…）」が出ること。「限定公開の動画も表示する」で自分の限定公開動画が「限定公開」付きで出ること（300 本を超えるチャンネルでは「新しい順に300本まで」の案内）、ログイン期限切れ（または Google 側で承認を取り消した後）に押すと「もう一度ログインする」が出ること。ログアウト・削除後の画面でそのタブの下書きが消えること。コンソールに CSP 違反・JS エラーが出ないこと。

**今回は対応しない（記録のみ。Phase 2 最終レビュー）**: 限定公開の読み込みのレート制限がセッションのキャッシュ命中も 1 回と数える点、IPv6 の /64 単位でのまとめ、リバースプロキシ配下での送信元 IP（#7）、owner-edit.js の Alpine コンポーネントの自動テスト（上の 9 の手動確認で代替）。
