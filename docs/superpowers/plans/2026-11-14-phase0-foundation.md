# Phase 0 基盤（List Maker）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** リポジトリ直下に Laravel 13 アプリの土台（設定キー・ページ骨組み・セキュリティヘッダ・空の日次バッチ・デプロイ手段・CI）を作り、後続フェーズがそのまま機能を足せる状態にする。

**Architecture:** Laravel 13 スケルトンを一時ディレクトリで生成してリポジトリへ取り込み、ユーザーテーブル等の不要物を除去する。全レスポンスに `SecurityHeaders` をグローバル適用し、インデックス可能なのはホーム・静的ページのみ（それ以外は既定で noindex＋no-store）。デプロイは channel-calendar の rsync 方式をそのまま踏襲する。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit 12（SQLite in-memory）/ Vite 8 + Tailwind 4（スケルトン既定）/ `@alpinejs/csp` 3.x / Pint / GitHub Actions

**Spec:** `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（3 章・3.1・4.3・8.6・9.1・10 章・11 章 Phase 0）

**Branch:** `phase0-foundation`（全タスクを 1 PR にまとめる）

## Global Constraints

- PHP: `composer.json` の `require.php` は `^8.4`、`config.platform.php` は `8.4.0`（サーバーは `/usr/local/php84/bin/php`）。
- フレームワーク: `laravel/framework` 13 系（スケルトン既定の制約のまま）。
- アプリ名: `APP_NAME="List Maker"`（仮称）。名称に「YouTube」を含めない。YouTube ロゴを使わない。
- 本番 URL: `https://list-maker.alpacasandbag.jp`。OAuth コールバック: `${APP_URL}/auth/google/callback`。
- アンチゴール: ユーザーテーブルを作らない。アクセス解析タグ・外部フォント・外部 CDN を読み込まない。Blade は `{{ }}` のみ（`{!! !!}` 禁止）。
- CSP（8.6、文字列そのまま）: `default-src 'self'; img-src 'self' https://i.ytimg.com https://yt3.ggpht.com; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'self'`
- Alpine は CSP ビルド（`@alpinejs/csp`）のみ。標準ビルド `alpinejs` を入れない。インライン `<script>` / `<style>` / `style="..."` を Blade に書かない。
- 新しい JS エントリは必ず `vite.config.js` の `input` に追加する（3.1）。
- セッション `database`＋`SESSION_ENCRYPT=true`、キャッシュ `database`、キュー `sync`（3 章）。
- 既存の `docs/`、`.gitignore` の既存行、`YouTube再生リスト作成支援ツール 実装指示書.ini` を壊さない・消さない。
- 秘密情報（`.env`、`deploy.env` の値）をコミット・ログ・計画外ファイルに書かない。
- 設定キー名（後続フェーズが参照するため固定）:
  - `config/youtube.php`: `api_key`←`YOUTUBE_API_KEY`／`quota.write_limit`←`QUOTA_WRITE_LIMIT`(5000)／`quota.read_budget`←`QUOTA_READ_BUDGET`(2000)／`quota.google_daily`←`QUOTA_GOOGLE_DAILY`(10000)／`channel.fetch_max_videos`←`CHANNEL_FETCH_MAX_VIDEOS`(1000)／`channel.cache_reuse_minutes`←`CHANNEL_CACHE_REUSE_MINUTES`(360)／`channel.sync_known_run`←`CHANNEL_SYNC_KNOWN_RUN`(3)。すべて `(int)`。動画数上限キーは置かない（`QuotaPolicy` が導出、Phase 1）。
  - `config/services.php` の `google`: `client_id`←`GOOGLE_CLIENT_ID`／`client_secret`←`GOOGLE_CLIENT_SECRET`／`redirect`←`GOOGLE_REDIRECT_URI`。
  - `config/listmaker.php`: `retention_days`←`DATA_RETENTION_DAYS`(30, int)／`captcha_enabled`←`CAPTCHA_ENABLED`(false, bool)／`contact_to_address`←`CONTACT_TO_ADDRESS`(null)。
- ルート名（固定）: `home`（`GET /`）、`privacy`（`GET /privacy`）、`terms`（`GET /terms`）、`contact.show`（`GET /contact`）。Phase 4 の送信は `contact.send`（`POST /contact`）を使う想定。
- 名前空間・クラス（3.1 どおり）: `App\Http\Controllers\HomeController`、`App\Http\Controllers\StaticPageController`、`App\Http\Controllers\ContactController`、`App\Http\Middleware\SecurityHeaders`、`App\Console\Commands\PruneExpiredData`。
- 日次バッチ: `app:prune-expired` を毎日 04:00（`Asia/Tokyo`）。アプリのタイムゾーンはスケルトン既定の `UTC` のまま変えない（PT 計算は Phase 1 の QuotaClock が明示的に行う）。

## Review Focus

1. **未知・将来のルートや 404 のインデックス漏れ**: ホーム・静的ページ以外（存在しない URL、後続フェーズで追加される提案ページ）は何も設定しなくても `X-Robots-Tag: noindex, nofollow` と `Cache-Control: no-store` が付くべき。→ Task 4 で 404 と `/contact` を検査。
2. **CSP で黙って壊れるインライン記述**: Blade や Laravel 既定のエラーページ（`errors::minimal` はインライン `<style>` を含む）にインライン script/style があると本番で表示崩れ・JS 停止が起きる。→ Task 3 で全ページと 404 のHTMLを検査、エラーレイアウトを上書き。
3. **デプロイで機密・不要物が本番へ送られる**: `.env`、`deploy.env`、`docs/`、`*.ini`（実装指示書）、`tests/`、`.github/` が rsync 対象に入ってはいけない。→ Task 6 で rsync のドライランを検査。
4. **ユーザー情報を溜める土台が紛れ込む**: スケルトン既定の `users`・`password_reset_tokens`・ジョブ系テーブルが残るとアンチゴール違反の足場になる。`sessions`・`cache` は必要。→ Task 1 でスキーマを検査。
5. **ビルド済みアセットが無いとテストが落ちる**: deploy.sh はビルド前に `php artisan test` を実行し、CI の PHP ジョブもビルドしない。`public/build` が無いクリーンな状態でもテストが通るべき。→ Task 1 で `withoutVite()` を TestCase に入れ、Task 7 で `public/build` 無しのテスト実行を確認。

---

### Task 1: Laravel 13 スケルトンの取り込みと不要物の除去

**Files:**
- Create: スケルトン一式（`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `artisan`, `composer.json`, `composer.lock`, `package.json`, `phpunit.xml`, `vite.config.js`, `.editorconfig`, `.gitattributes`, `.npmrc`）
- Modify: `.gitignore`（既存行を残してスケルトンの行を追記）、`composer.json`、`tests/TestCase.php`
- Delete: `database/migrations/0001_01_01_000000_create_users_table.php`, `database/migrations/0001_01_01_000002_create_jobs_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `resources/views/welcome.blade.php`, `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`（取り込み時に `README.md`, `CLAUDE.md`, `AGENTS.md`, `.gitignore`, `.env.example` はスケルトン側を持ち込まない）
- Create: `database/migrations/0001_01_01_000000_create_sessions_table.php`, `tests/Unit/.gitkeep`
- Test: `tests/Feature/DatabaseSchemaTest.php`

**Interfaces:**
- Produces: `Tests\TestCase::setUp()` が `$this->withoutVite()` を呼ぶ（全テストで `public/build` 不要）。テーブル `sessions`, `cache`, `cache_locks` のみ（＋`migrations`）。

- [ ] **Step 1: ブランチを作る**

Run: `git switch -c phase0-foundation`

- [ ] **Step 2: 一時ディレクトリでスケルトンを生成し、リポジトリへ取り込む**

```bash
SK=$(mktemp -d)/sk
composer create-project laravel/laravel:^13 "$SK" --no-interaction
rsync -a --exclude .git --exclude vendor --exclude node_modules \
  --exclude .gitignore --exclude .env --exclude .env.example \
  --exclude README.md --exclude CLAUDE.md --exclude AGENTS.md \
  --exclude database/database.sqlite "$SK"/ /Users/aokiyuuta/work/pau-list/
```
Expected: `docs/`, `.gitignore`, `*.ini` が変更されていない（`git status` で `.gitignore` と docs に差分なし）。

- [ ] **Step 3: `.gitignore` にスケルトンの行を追記**

既存 5 行（`.DS_Store`, `.env`, `.env.*`, `!.env.example`, `deploy.env`）は残し、スケルトンの `.gitignore` の残り（`/vendor`, `/node_modules`, `/public/build`, `/public/hot`, `/public/storage`, `/storage/*.key`, `/storage/pail`, `/.phpunit.cache`, `.phpunit.result.cache`, `*.log`, `/.idea`, `/.vscode` 等）を追記。加えて `/database/database.sqlite`。

- [ ] **Step 4: composer.json を PHP 8.4 に固定して依存を入れ直す**

```bash
composer config platform.php 8.4.0
composer require php:^8.4 --no-update
composer update --no-interaction
npm install
```
Expected: `composer.lock` の `platform-overrides` に `"php": "8.4.0"`、エラーなし。

- [ ] **Step 5: 失敗するスキーマテストを書く**

`tests/Feature/DatabaseSchemaTest.php`（`RefreshDatabase` 使用）:
```php
public function test_only_session_and_cache_tables_exist(): void
{
    $this->assertTrue(Schema::hasTable('sessions'));
    $this->assertTrue(Schema::hasTable('cache'));
    $this->assertTrue(Schema::hasTable('cache_locks'));
    foreach (['users', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'] as $t) {
        $this->assertFalse(Schema::hasTable($t), "{$t} must not exist");
    }
}
```

- [ ] **Step 6: テストが失敗することを確認**

Run: `php artisan test --filter=DatabaseSchemaTest`
Expected: FAIL（`users must not exist`）

- [ ] **Step 7: マイグレーションとユーザー関連を整理**

- users／jobs マイグレーションを削除し、`0001_01_01_000000_create_sessions_table.php` を新規作成（スケルトンの users マイグレーション内 `sessions` 定義と同じ列: `id` string primary, `user_id` foreignId nullable index（制約なし）, `ip_address` string(45) nullable, `user_agent` text nullable, `payload` longText, `last_activity` integer index）。
- `app/Models/User.php`, `database/factories/UserFactory.php` を削除し、`database/seeders/DatabaseSeeder.php` の `run()` を空にする（`User` の use も削除）。`config/auth.php` はそのまま（クラス名文字列のみで autoload されない）。
- `welcome.blade.php` と Example テスト 2 本を削除、`tests/Unit/.gitkeep` を置く。
- `tests/TestCase.php` に `protected function setUp(): void { parent::setUp(); $this->withoutVite(); }`。
- `routes/web.php` は一旦空（`/` は Task 3 で定義）。

- [ ] **Step 8: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（1 test）

- [ ] **Step 9: Pint とコミット**

```bash
./vendor/bin/pint
git add -A
git commit -m "chore: Laravel 13 スケルトンを取り込み、ユーザー・ジョブ系テーブルを除去"
```
（`git add -A` の前に `git status` で `.env` と `vendor/`、`node_modules/` が含まれていないことを確認する）

---

### Task 2: 設定ファイルと .env.example（10.2 の項目）

**Files:**
- Create: `config/youtube.php`, `config/listmaker.php`, `.env.example`
- Modify: `config/services.php`（`google` を追加）
- Test: `tests/Feature/ConfigurationTest.php`

**Interfaces:**
- Produces: Global Constraints の「設定キー名」すべて。後続フェーズは `config('youtube.quota.write_limit')` 等で参照する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/ConfigurationTest.php`:
```php
private const SPEC_ENV_KEYS = [
    'APP_NAME','APP_ENV','APP_KEY','APP_DEBUG','APP_URL',
    'LOG_CHANNEL','LOG_LEVEL','LOG_DAILY_DAYS',
    'DB_CONNECTION','DB_HOST','DB_PORT','DB_SOCKET','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
    'SESSION_DRIVER','SESSION_ENCRYPT','SESSION_LIFETIME','SESSION_SECURE_COOKIE','SESSION_SAME_SITE',
    'CACHE_STORE','QUEUE_CONNECTION',
    'YOUTUBE_API_KEY','GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI',
    'QUOTA_WRITE_LIMIT','QUOTA_READ_BUDGET','QUOTA_GOOGLE_DAILY',
    'CHANNEL_FETCH_MAX_VIDEOS','CHANNEL_CACHE_REUSE_MINUTES','CHANNEL_SYNC_KNOWN_RUN',
    'DATA_RETENTION_DAYS','CAPTCHA_ENABLED',
    'MAIL_MAILER','MAIL_HOST','MAIL_PORT','MAIL_USERNAME','MAIL_PASSWORD','MAIL_SCHEME','MAIL_FROM_ADDRESS','MAIL_FROM_NAME',
    'CONTACT_TO_ADDRESS',
];

public function test_env_example_lists_every_spec_key(): void
{
    $env = file_get_contents(base_path('.env.example'));
    foreach (self::SPEC_ENV_KEYS as $key) {
        $this->assertMatchesRegularExpression('/^#?\s*'.$key.'=/m', $env, $key);
    }
    $this->assertDoesNotMatchRegularExpression('/PROPOSAL_MAX_ITEMS/', $env);
    $this->assertStringContainsString('APP_NAME="List Maker"', $env);
    $this->assertStringContainsString('GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"', $env);
}

public function test_config_keys_exist_with_expected_types(): void
{
    foreach (['quota.write_limit','quota.read_budget','quota.google_daily',
              'channel.fetch_max_videos','channel.cache_reuse_minutes','channel.sync_known_run'] as $k) {
        $this->assertIsInt(config("youtube.$k"), "youtube.$k");
    }
    $this->assertTrue(array_key_exists('api_key', config('youtube')));
    $this->assertNull(config('youtube.max_items'));
    $this->assertSame(['client_id','client_secret','redirect'], array_keys(config('services.google')));
    $this->assertIsInt(config('listmaker.retention_days'));
    $this->assertIsBool(config('listmaker.captcha_enabled'));
    $this->assertTrue(array_key_exists('contact_to_address', config('listmaker')));
}
```
3 本目 `test_defaults_match_spec_when_env_is_absent()` は、実 `.env` の値に依存しないよう「`QUOTA_*`・`CHANNEL_*`・`DATA_RETENTION_DAYS`・`CAPTCHA_ENABLED` を `$_ENV`/`$_SERVER`/`putenv` から一時的に外し（`tearDown` で戻す）、`require config_path('youtube.php')` と `require config_path('listmaker.php')` の戻り値が既定値 5000/2000/10000/1000/360/3/30/false になる」ことを assertSame で検査する。

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=ConfigurationTest`
Expected: FAIL（`.env.example` が無い／`config('youtube')` が null）

- [ ] **Step 3: 設定ファイルを作る**

- `config/youtube.php`, `config/listmaker.php`: Global Constraints のキー・既定値どおり。整数は `(int) env(...)`、`captcha_enabled` は `(bool) env('CAPTCHA_ENABLED', false)`。
- `config/services.php` に `'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect' => env('GOOGLE_REDIRECT_URI')]`。
- `.env.example`（ローカル開発用の値。本番値は README に書く）: スケルトンの `.env.example` を土台に、`APP_NAME="List Maker"`, `APP_URL=http://localhost:8000`, `APP_LOCALE=ja`, `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14`, `DB_CONNECTION=sqlite`（`DB_HOST`〜`DB_PASSWORD`, `DB_SOCKET` はコメント行）, `SESSION_DRIVER=database`, `SESSION_ENCRYPT=true`, `SESSION_LIFETIME=120`, `SESSION_SECURE_COOKIE=false`, `SESSION_SAME_SITE=lax`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, 10.2 の YouTube/Google/QUOTA/CHANNEL/DATA_RETENTION/CAPTCHA/CONTACT 行（値は既定値、秘密は空）。Redis/Memcached/AWS 行は削除。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add config/ .env.example tests/Feature/ConfigurationTest.php
git commit -m "feat: youtube/listmaker の設定キーと .env.example を追加"
```

---

### Task 3: フロント基盤とホーム・静的ページの骨組み

**Files:**
- Modify: `vite.config.js`（`fonts`/bunny を削除）, `resources/css/app.css`（Instrument Sans の `@theme` を削除し system-ui）, `resources/js/app.js`, `package.json`, `routes/web.php`
- Create: `app/Http/Controllers/HomeController.php`, `app/Http/Controllers/StaticPageController.php`, `app/Http/Controllers/ContactController.php`
- Create: `resources/views/layouts/app.blade.php`, `resources/views/partials/footer.blade.php`, `resources/views/home.blade.php`, `resources/views/static/privacy.blade.php`, `resources/views/static/terms.blade.php`, `resources/views/contact/show.blade.php`, `resources/views/errors/minimal.blade.php`
- Test: `tests/Feature/PagesTest.php`

**Interfaces:**
- Consumes: Task 1 の `withoutVite()`。
- Produces: レイアウト `layouts.app`（`@section('title')`, `@section('content')`, `<meta name="csrf-token">`, `<html lang="ja">`, `@vite(['resources/css/app.css', 'resources/js/app.js'])`）。フッタ部分テンプレート `partials.footer`。ルート名 `home`, `privacy`, `terms`, `contact.show`。後続ページはこのレイアウトを `@extends` する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/PagesTest.php`:
```php
public static function publicPages(): array
{
    return [['/'], ['/privacy'], ['/terms'], ['/contact']];
}

#[DataProvider('publicPages')]
public function test_page_renders_with_footer_links(string $path): void
{
    $res = $this->get($path)->assertOk();
    $res->assertSee('href="'.route('privacy').'"', false);
    $res->assertSee('href="'.route('terms').'"', false);
    $res->assertSee('href="'.route('contact.show').'"', false);
    $res->assertSee('href="https://www.youtube.com/t/terms"', false);
    $res->assertSee('href="https://policies.google.com/privacy"', false);
    $res->assertSee('<html lang="ja"', false);
}

#[DataProvider('publicPages')]
public function test_page_has_no_inline_script_or_style(string $path): void
{
    $this->assertNoInlineCode($this->get($path)->getContent());
}

public function test_error_page_has_no_inline_script_or_style(): void
{
    $html = $this->get('/no-such-page')->assertNotFound()->getContent();
    $this->assertNoInlineCode($html);
}

public function test_home_explains_tool_and_scope_without_youtube_in_name(): void
{
    $this->get('/')
        ->assertSee('List Maker')
        ->assertSee('再生リストの作成と動画の追加')
        ->assertDontSee('YouTube List Maker');
}

private function assertNoInlineCode(string $html): void
{
    $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
    $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
    $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
    $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=PagesTest`
Expected: FAIL（404 / Laravel 既定エラーページの `<style>`）

- [ ] **Step 3: Alpine CSP ビルドと Vite 設定**

- `npm install -D @alpinejs/csp@^3.17`（`alpinejs` 本体は入れない）。
- `resources/js/app.js`: `import Alpine from '@alpinejs/csp'; window.Alpine = Alpine; Alpine.start();`
- `vite.config.js`: `laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true })` と `tailwindcss()`。`fonts`（bunny）と関連 import を削除（外部フォント禁止）。
- `resources/css/app.css`: `@import 'tailwindcss';` と `@source` 2 行を残し、`@theme` の `--font-sans` は `ui-sans-serif, system-ui, sans-serif` に置換。

- [ ] **Step 4: ルート・コントローラ・ビュー**

- `routes/web.php`: `Route::get('/', HomeController::class)->name('home'); Route::get('/privacy', [StaticPageController::class, 'privacy'])->name('privacy'); Route::get('/terms', [StaticPageController::class, 'terms'])->name('terms'); Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');`
- `HomeController::__invoke(): View`、`StaticPageController::privacy(): View` / `terms(): View`、`ContactController::show(): View`。いずれもビューを返すだけ。
- `home.blade.php`（S1 の骨組み）: 見出し「List Maker（仮称）」、説明「YouTube の再生リストを、ファンが提案してチャンネル主が作成するためのツールです」、提案→共有 URL→チャンネル主が確認して作成、の 3 ステップ、権限の説明「Google ログインで許可いただく権限は、あなたのチャンネルへの再生リストの作成と動画の追加にのみ使います。」、チャンネル入力欄の位置に「準備中」の注記（フォームは Phase 1）。
- `static/privacy.blade.php` / `static/terms.blade.php`: 見出しと「本文は公開前に掲載します」の骨組み。privacy には Google プライバシーポリシーと「サードパーティのアクセス」（`https://myaccount.google.com/permissions`）へのリンク、terms には YouTube 利用規約へのリンクを本文にも置く（本文の詳細は Phase 4）。
- `contact/show.blade.php`: 見出し「お問い合わせ」と「フォームは準備中です」のみ（フォームは Phase 4）。
- `partials/footer.blade.php`: プライバシーポリシー・利用規約・YouTube 利用規約・Google プライバシーポリシー・お問い合わせの 5 リンク（外部リンクは `rel="noopener noreferrer" target="_blank"`）。
- `errors/minimal.blade.php`: `layouts.app` を使い `@yield('code')` と `@yield('message')` を表示する（Laravel の `errors::minimal` を上書きし、全エラーコードのページからインライン `<style>` を消す）。

- [ ] **Step 5: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: ビルドと CSP ビルドの確認**

Run: `npm run build && grep -l "new Function" public/build/assets/*.js; echo "exit=$?"`
Expected: ビルド成功、grep は何も出さず `exit=1`（`new Function` を含まない＝標準 Alpine が混入していない）

- [ ] **Step 7: 目視確認**

Run: `php artisan serve` で `http://localhost:8000/` を開く（`npm run build` 済み）。Tailwind が効いており、ブラウザのコンソールに CSP 違反が出ないこと（ヘッダは Task 4 で付くので、Task 4 後に再確認する）。

- [ ] **Step 8: コミット**

```bash
./vendor/bin/pint
git add routes/web.php app/Http/Controllers resources/ vite.config.js package.json package-lock.json tests/Feature/PagesTest.php
git commit -m "feat: ホーム・静的ページ・問い合わせの骨組みと Alpine CSP ビルドを追加"
```

---

### Task 4: SecurityHeaders ミドルウェア

**Files:**
- Create: `app/Http/Middleware/SecurityHeaders.php`
- Modify: `bootstrap/app.php`（`$middleware->append(SecurityHeaders::class)`）
- Test: `tests/Feature/SecurityHeadersTest.php`

**Interfaces:**
- Consumes: Task 3 のルート名 `home`, `privacy`, `terms`。
- Produces: `SecurityHeaders::handle(Request $request, Closure $next): Response`、公開定数 `SecurityHeaders::CSP`（8.6 の文字列）と `SecurityHeaders::INDEXABLE_ROUTES = ['home', 'privacy', 'terms']`。後続フェーズは何もしなくても新規ルートが noindex＋no-store になる。インデックスさせたいページを増やす場合のみ定数に追加する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/SecurityHeadersTest.php`:
```php
public function test_common_headers_on_home(): void
{
    $this->get('/')
        ->assertHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' https://i.ytimg.com https://yt3.ggpht.com; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'self'")
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeaderMissing('X-Robots-Tag');
}

public function test_static_pages_are_indexable(): void  // /privacy, /terms: X-Robots-Tag 無し、Cache-Control に no-store を含まない

public function test_contact_is_noindex_and_no_store(): void
{
    $res = $this->get('/contact');
    $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
}

public function test_unknown_route_is_noindex_and_has_csp(): void
{
    $res = $this->get('/p/unknown-key')->assertNotFound();
    $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $res->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    $this->assertNotNull($res->headers->get('Content-Security-Policy'));
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=SecurityHeadersTest`
Expected: FAIL（`Content-Security-Policy` ヘッダが無い）

- [ ] **Step 3: `SecurityHeaders` を実装し、グローバルに登録**

- `$next($request)` の後でヘッダを付ける（ルート解決後なので `$request->route()?->getName()` で判定でき、404 でもルートは null → noindex 側）。
- ルート名が `INDEXABLE_ROUTES` に含まれない場合: `X-Robots-Tag: noindex, nofollow`、`Cache-Control: no-store, private`（既存値を置き換え）。
- ローカル開発で `npm run dev` を使う場合のみ、`app()->isLocal() && Vite::isRunningHot()` のとき `script-src`/`style-src`/`connect-src` に `public/hot` の URL のオリジン（`connect-src` には同ホストの `ws://` も）を足す。それ以外の環境では `CSP` 定数そのまま。
- `bootstrap/app.php` の `withMiddleware` で `$middleware->append(SecurityHeaders::class);`（web グループではなくグローバル。404 にも付けるため）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: 実ブラウザで CSP 違反が無いことを確認**

Run: `npm run build && php artisan serve` → `/`, `/privacy`, `/terms`, `/contact`, `/no-such-page` を開き、開発者ツールのコンソールに CSP 違反が 0 件、`Alpine` が `window.Alpine` に存在すること。続けて `npm run dev` を併用して同じページで CSP 違反が出ないこと。

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add app/Http/Middleware/SecurityHeaders.php bootstrap/app.php tests/Feature/SecurityHeadersTest.php
git commit -m "feat: SecurityHeaders ミドルウェア（CSP・no-referrer・既定 noindex）を追加"
```

---

### Task 5: `app:prune-expired` の空実装とスケジュール登録

**Files:**
- Create: `app/Console/Commands/PruneExpiredData.php`
- Modify: `routes/console.php`（`inspire` を削除しスケジュールを登録）
- Test: `tests/Feature/PruneExpiredDataTest.php`

**Interfaces:**
- Produces: コマンド `app:prune-expired`（`PruneExpiredData::handle(): int`、`self::SUCCESS` を返す）。Phase 1 以降が `handle()` 内に削除処理（10.3 の対象）を追加する。スケジュール: 毎日 04:00 `Asia/Tokyo`、`withoutOverlapping()` 付き（ロックは database キャッシュ）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_command_runs_successfully(): void
{
    $this->artisan('app:prune-expired')->assertSuccessful();
}

public function test_scheduled_daily_at_four_jst(): void
{
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'app:prune-expired'));
    $this->assertCount(1, $events);
    $event = $events->first();
    $this->assertSame('0 4 * * *', $event->expression);
    $this->assertSame('Asia/Tokyo', $event->timezone);
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=PruneExpiredDataTest`
Expected: FAIL（`The command "app:prune-expired" does not exist.`）

- [ ] **Step 3: 実装**

- `PruneExpiredData`: `$signature = 'app:prune-expired'`、`$description = '保持期限切れのデータを削除する（4.3）'`。`handle()` は `$this->info('削除対象の処理は未実装です（Phase 1 以降）')` を出して `self::SUCCESS`。
- `routes/console.php`: `Schedule::command('app:prune-expired')->dailyAt('04:00')->timezone('Asia/Tokyo')->withoutOverlapping();`

- [ ] **Step 4: テストとスケジュール一覧を確認**

Run: `php artisan test && php artisan schedule:list`
Expected: PASS。`schedule:list` に `0 4 * * *  php artisan app:prune-expired` が 1 行。

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Console routes/console.php tests/Feature/PruneExpiredDataTest.php
git commit -m "feat: app:prune-expired の空実装と日次スケジュールを追加"
```

---

### Task 6: デプロイスクリプトと README 初版

**Files:**
- Create: `deploy.sh`（実行権限付き）, `deploy.env.example`, `.exclude-list`, `README.md`
- 参照元: `/Users/aokiyuuta/work/channel-calendar/deploy.sh`, `deploy.env.example`, `.exclude-list`, `README.md`（`deploy.env` は読まない・値を転記しない）

**Interfaces:**
- Consumes: Task 5 の `app:prune-expired`（README の cron 説明）、Task 2 の `.env` キー（README のサーバー `.env` 例）。
- Produces: `./deploy.sh`（`deploy.env` の `SERVER`, `SSH_KEY`, `REMOTE_PATH`, `SITE_URL` を読む。`ALLOW_NON_MAIN_DEPLOY=1` で main 一致チェックを迂回）。

- [ ] **Step 1: 期待する除外を確かめる検査を先に用意して失敗させる**

```bash
OUT=$(mktemp -d)
rsync -an --exclude-from=.exclude-list . "$OUT"/ --out-format='%n' \
  | grep -E '^(\.env$|deploy\.env$|docs/|tests/|\.github/|node_modules/|.*\.ini$|storage/logs/|storage/framework/|database/database\.sqlite$|README\.md$|deploy\.sh$)'
echo "exit=$?"
```
Expected（この時点）: `.exclude-list` が無いため rsync がエラー、または該当行が出る。

- [ ] **Step 2: `.exclude-list` と `deploy.env.example` を作る**

- `.exclude-list`: channel-calendar の内容（`.git`〜`database/database.sqlite`）をそのまま写し、加えて `*.ini`、`.npmrc`、`.phpunit.cache`、`.phpunit.result.cache` を追加。`public/uploads` は不要なので削除。
- `deploy.env.example`: channel-calendar と同じ 4 変数・同じプレースホルダ形式で、`REMOTE_PATH` と `SITE_URL` の例を `list-maker.example.com` 形式にする（実ドメインや実ユーザー名は書かない）。

- [ ] **Step 3: 除外検査が通ることを確認**

Run: Step 1 のコマンド
Expected: grep が何も出さず `exit=1`

- [ ] **Step 4: `deploy.sh` を作る**

channel-calendar の `deploy.sh` を処理順・trap・origin/main チェック・`/usr/local/php84/bin/php`・`.env` 存在確認・`APP_KEY` 生成・storage 作成・4 種の clear・`migrate --force`・dev 依存の復元まで同一に写す。変更点はヘッダコメントのアプリ名のみ（10.1）。`chmod +x deploy.sh`。

- [ ] **Step 5: 構文と deploy.env 無しの挙動を確認**

Run: `bash -n deploy.sh && ./deploy.sh; echo "exit=$?"`
Expected: 構文エラーなし。`deploy.env` が無いので「❌ deploy.env がありません」を出して `exit=1`（何も転送しない）。`shellcheck` があれば `shellcheck deploy.sh` も警告 0。

- [ ] **Step 6: README 初版を書く**

章立て（channel-calendar の README の書き方に倣う）:
1. 概要: List Maker（仮称）の 2 行説明と設計書へのリンク。
2. ローカル開発: `composer install` → `npm install` → `cp .env.example .env` → `php artisan key:generate` → `touch database/database.sqlite` → `php artisan migrate` → `npm run build`（または `npm run dev`）→ `php artisan serve` → `php artisan test` → `./vendor/bin/pint`。CSP 下で `npm run dev` を使う場合は `APP_ENV=local` が必要な旨。新しい JS エントリは `vite.config.js` の `input` に追加する旨。
3. 本番デプロイ（初回のみ）: `deploy.env` 作成 → DirectAdmin で MySQL 作成 → サブドメイン `list-maker.alpacasandbag.jp` の SSL 発行 → サーバーの `.env` 作成（10.2 の本番値を列挙: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://list-maker.alpacasandbag.jp`, `LOG_LEVEL=warning`, `DB_CONNECTION=mysql` と `DB_*`, `SESSION_SECURE_COOKIE=true`, `MAIL_MAILER=smtp` と `MAIL_*`, `CONTACT_TO_ADDRESS` など。秘密は空欄）→ `./deploy.sh` → cron 登録（10.3 の 1 行）。2 回目以降は `./deploy.sh` のみ。`ALLOW_NON_MAIN_DEPLOY=1` の説明。
4. Google Cloud セットアップ要点: 10.4 の 1〜6 をそのまま手順化（専用プロジェクト、API キー制限、同意画面、OAuth クライアントのリダイレクト URI 2 つ、Search Console、割り当ての確認場所とリセット時刻）。
5. 運用メモ: 日次バッチ `app:prune-expired`、ログは `storage/logs`（14 日）。

- [ ] **Step 7: README の必須項目を確認**

Run: `grep -c -E 'list-maker.alpacasandbag.jp/auth/google/callback|localhost:8000/auth/google/callback|schedule:run|deploy.env.example|YouTube Data API v3' README.md`
Expected: 5 以上

- [ ] **Step 8: コミット**

```bash
git add deploy.sh deploy.env.example .exclude-list README.md
git commit -m "chore: deploy.sh・.exclude-list・deploy.env.example と README 初版を追加"
```
（`git status` で `deploy.env` が未追跡かつ ignore 済みであることを確認）

---

### Task 7: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Task 1 の `withoutVite()`（PHP ジョブはビルド不要）、Task 3 の `npm run build`。
- Produces: `pull_request` と `main` への push で動く 2 ジョブ `php`（テスト＋Pint）と `frontend`（ビルド）。Phase 1 で `node --test` を足す場合は `frontend` ジョブに `npm run test:js` を追加する。

- [ ] **Step 1: クリーンな状態でテストが通ることをローカルで確認（CI と同条件）**

```bash
BAK=$(mktemp -d)
mv public/build "$BAK"/build 2>/dev/null; mv .env "$BAK"/env 2>/dev/null
cp .env.example .env && php artisan key:generate
php artisan test; echo "exit=$?"
rm .env; mv "$BAK"/env .env 2>/dev/null; mv "$BAK"/build public/build 2>/dev/null
```
Expected: `exit=0`（`public/build` が無くても全テスト PASS）

- [ ] **Step 2: `ci.yml` を書く**

ycs の `.github/workflows/ci.yml` の書式に倣う（`actions/checkout@v4`、`shivammathur/setup-php@v2`、`actions/setup-node@v4`、composer キャッシュ）。
- `on: push: branches: [main]` と `pull_request`。
- ジョブ `php`（名前 `PHP tests (SQLite) / Pint`）: PHP `8.4`、`coverage: none` → composer キャッシュ → `composer install --prefer-dist --no-progress --no-interaction` → `cp .env.example .env && php artisan key:generate`（APP_KEY をワークフローに書かない）→ `php artisan test` → `./vendor/bin/pint --test`。
- ジョブ `frontend`（名前 `Frontend build`）: Node `22`、`cache: npm` → `npm ci` → `npm run build` → Task 3 Step 6 と同じ `new Function` 検査（見つかったら失敗させる: `! grep -l "new Function" public/build/assets/*.js`）。
- MySQL ジョブは置かない（Phase 0 のマイグレーションは sessions/cache のみ。MySQL 固有処理が入る Phase 3 で検討）。

- [ ] **Step 3: 構文確認**

Run: `actionlint .github/workflows/ci.yml`（未導入なら `ruby -ryaml -e 'YAML.load_file(".github/workflows/ci.yml")'` で YAML として読めること）
Expected: エラーなし

- [ ] **Step 4: 全体の最終確認**

Run: `php artisan test && ./vendor/bin/pint --test && npm run build`
Expected: 全 PASS、Pint 差分なし、ビルド成功

- [ ] **Step 5: コミット**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: PHP テスト・Pint・フロントビルドの GitHub Actions を追加"
```

- [ ] **Step 6: PR 作成後に CI が緑になることを確認**

`git push -u origin phase0-foundation` → PR 作成 → 両ジョブ成功を確認（push と PR 作成はユーザーの指示があってから行う）。

---

## Issue 化する人手作業（本計画のコード作業には含めない）

設計書 11 章 Phase 0 の完了条件のうち、リポジトリ外で人が行う作業。各 1 Issue として起票する。

1. **本ツール専用 GCP プロジェクトの作成**: YouTube Data API v3 有効化、API キー作成（API 制限: YouTube Data API v3 のみ）、OAuth 同意画面（外部・アプリ名「List Maker」・本番ステータス）、OAuth クライアント ID（リダイレクト URI 2 つ）。README「Google Cloud セットアップ要点」に沿う（10.4、D16）。
2. **サブドメイン `list-maker.alpacasandbag.jp` の作成と SSL 証明書の発行**（ホスティング管理画面、10.1）。
3. **DirectAdmin で MySQL データベースとユーザーを作成**（3 章）。
4. **サーバーの `.env` 作成**（README の本番値。`APP_KEY` は deploy.sh が生成）と **cron 登録**（10.3）。ローカルの `deploy.env` 作成。
5. **初回の実デプロイ**: `./deploy.sh` を実行し、`https://list-maker.alpacasandbag.jp` でホーム・静的ページが表示され、レスポンスヘッダ（CSP 等）が付くことを確認（11 章 Phase 0 完了条件）。
6. **SMTP 疎通確認**: CORESERVER の SMTP ホスト・ポート・`MAIL_SCHEME`・認証を確定し、サーバーから運営者アドレスへテストメールが届くこと。送信元ドメインの SPF/DKIM を確認（10.2、U7）。
7. **プロキシ配下の IP 確認**: 本番で `REMOTE_ADDR` が利用者ごとの IP になるか、全員同じプロキシ IP かを確認し、必要なら `TrustProxies` の設定方針を決めて設計書に追記（8.1、U6）。あわせてサーバーの送信元 IP が固定かを確認し API キーのアプリケーション制限を決める（10.4-2）。
