# Phase 3 作成（List Maker）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** チャンネル主が S5 の「作成」で書込枠を予約し、進捗画面 S6 がブラウザから 1 ステップずつ（1 リクエスト 1 書込）サーバーを呼んで再生リストを作成・動画を `snippet.position` 付きで追加する。一時停止・再開・残りを中止・失敗分再試行・二重作成防止・日付またぎの再予約・401 での再ログインを備え、プレビュー S4 に作成状態とリンク（public のみ匿名表示）を出す。あわせて MySQL の CI ジョブを追加する（Issue #20）。

**Architecture:** 作成内容は提案を書き換えず `playlist_creations`／`playlist_creation_items` にスナップショットとして持つ。書込枠は `QuotaLedger::withWriteLock()` が当日の `quota_usage(write)` 行を `FOR UPDATE` で握った中でだけ `WriteLock`（予約・確定・解放）を操作でき、`writeFreeUnits()` は当日の active 予約の未使用分を差し引く。開始は `CreationStarter`（最終確認 videos.list → 予約と作成をひとつのトランザクションで）、ステップは `CreationRunner`（短いトランザクションで作成行をロックして「何を送るか」を決め 60 秒のリースを付ける → ロックを外して YouTube を 1 回呼ぶ → 再びロックして結果を書く）、一時停止などの操作は `CreationLifecycle`。HTTP 層は `CreationController`（JSON は `RequireSameOriginFetch`＋CSRF＋`RequireOwnerSession` の JSON 経路）と S6（`creation-runner.js`、純関数は `lib/creation-runner.js`）。

**Tech Stack:** PHP 8.4 / Laravel 13.42 / PHPUnit 12（SQLite in-memory、MySQL 8.4 は CI の `mysql` グループ）/ `Http::fake()` / Vite 8 + Tailwind 4 / `@alpinejs/csp` 3.17 / Node 22（`node --test`）/ GitHub Actions

**Spec:** `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（2.3 の 5〜6・2.5 S4/S6・4.1・4.2 の playlist_creations／playlist_creation_items／quota_reservations・4.3・5.1・5.4・5.5・5.7・6.2（作成済みなら S6 へ）・6.4・7 章全体・8.1・8.2・8.5・8.8・10.5・11 章 Phase 3・12.2 D6/D13/D14/D15/D19・12.1 Y11/Y15・12.3 U8/U14）

**Branch:** `phase3-creation`（`main` から切る。全タスクを 1 PR にまとめる）

## Global Constraints

- Phase 0〜2 の制約はすべて継続: Blade は `{{ }}` のみ、インライン `<script>`／`<style>`／`style=`／`on*=` 禁止（`AssertsNoInlineCode`）、Alpine は `@alpinejs/csp` のみで Blade の式は「プロパティ参照」「引数なしメソッド名」だけ、JS は `textContent`／`x-text` のみ、サーバー→JS は `data-*` の JSON、新しい JS エントリは `vite.config.js` の `input` に追加（`ViteEntriesTest`）、`composer.json`／依存を変更しない。
- 提案ページ内のリンク・fetch 先は**相対 URL**で書き、生キーをページに書かない（既存の `edit.blade.php`・`delete-confirm.blade.php` と同じ）。S5（`/p/{key}/edit`）から開始は `creation`、S6（`/p/{key}/creation`）からステップ等は `creation/step`・`creation/pause`・`creation/resume`・`creation/abort`・`creation/retry`・`creation/recreate`、削除は `delete`。開始 API の応答の `redirect` も相対 `creation`。
- YouTube 呼び出しはすべて `connectTimeout(5)->timeout(15)->withoutRedirecting()`、`withToken()`（OAuth）。API キーを書込に使わない。`#[\SensitiveParameter]` をトークン引数に付ける。ログは endpoint・status・reason・kind のみ（既存 `YouTubeOwnerClient::fail()`）。
- 台帳: 書込（`playlists.insert`／`playlistItems.insert`）は**送信直前に** `WriteLock::consume()` が `quota_usage(write)` に 50 を加算する（クライアント側では記録しない）。失敗した書込も 50 消費（D19）。最終確認・照合の読取は `QuotaLedger::OWNER_READ`（上限なし。限定公開の読み込みのガード `ownerReadGuard` は使わない）。
- 単価・本数: `QuotaPolicy::PLAYLIST_UNITS = 50`、`ITEM_UNITS = 50`、見込み `estimated_units = 50 + 50×本数`、本数は 1〜`QuotaPolicy::maxItemsPerPlaylist()`（既定 99）。JS に式を持たない。
- 空き（5.4）: `QUOTA_WRITE_LIMIT − 当日の write 送信済み − Σ(当日 day_pt かつ status=active の reserved_units − consumed_units)`。日付は `QuotaClock::todayPt()`。
- 時間の値: 通常のステップ間隔 `next_delay_ms = 1000`。一時エラーのバックオフ 2, 4, 8, 16, 32 秒（`1000 × 2^attempts` ms、`attempts` は送信回数）。送信は 1 項目につき最大 `CreationRunner::MAX_ATTEMPTS = 6` 回（初回＋再試行 5 回）。リース `CreationRunner::LEASE_SECONDS = 60`（タイムアウト 15 秒より十分長い）。
- 状態値（固定。DB の enum と JSON で同じ文字列）: creation `pending`／`creating_playlist`／`adding`／`paused`／`completed`／`needs_check`、pause_reason `auth_expired`／`quota_exceeded`／`quota_budget`／`user`、item `pending`／`in_flight`／`done`／`failed`／`skipped`、reservation `active`／`released`／`expired`。
- 公開範囲は `private`／`unlisted`／`public` のみ、既定 `private`。名前 1〜150 文字・説明 ≤1,000 文字（`StoreProposalRequest` と同じ整形・数え方）。
- 限定公開動画（Y11）の ID・タイトルは `playlist_creation_items` にだけ置き、`proposal_items`・S4・共有画面に出さない。S6 はログイン中の該当チャンネル主だけが見られる。作成テーブルにトークンを書かない（7.7）。
- レート制限（8.1）: 作成開始・失敗分再試行で共有の `creation-start` = 5 回/時/チャンネル（未ログインは IP）、ステップ `creation-step` = 2 回/秒/セッション。超過は 429 JSON（既存の `ThrottleRequestsException` の JSON 応答）。
- JSON の POST はすべて `X-CSRF-TOKEN`（`<meta name="csrf-token">`）、`Accept: application/json`、`X-Requested-With: XMLHttpRequest` を付け、ルートに `RequireSameOriginFetch` を付ける（制限より前）。419 は JS が固定文言に置き換える。
- 新規ルート名（固定）: `proposals.creation`（GET `/p/{key}/creation`）、`proposals.creation.start`（POST `/p/{key}/creation`）、`proposals.creation.step`／`.pause`／`.resume`／`.abort`／`.retry`／`.recreate`（POST `/p/{key}/creation/{step|pause|resume|abort|retry|recreate}`）。`{key}` は `ProposalKey::ROUTE_PATTERN`。ルートを足すタスクは `PagesTest::test_only_spec_routes_are_registered` を更新する。
- 画面文言（spec そのまま。数値は導出値）: 「本日の残り: 再生リスト 1 つ＋動画あと {N} 本まで作成できます」／「本日はこれ以上作成できません。次にリセットされるのは 日本時間 {n月j日 G時} です」、作成ボタン「あなたのチャンネルに再生リストを作成します」、取り消し案内「Google アカウントの サードパーティのアクセス（`https://myaccount.google.com/permissions`）からいつでも承認を取り消せます」、再生リスト URL `https://www.youtube.com/playlist?list={id}`。
- テストは実 API を呼ばない（`Http::preventStrayRequests()` は `Tests\TestCase` で有効）。時刻は `$this->travelTo()`。MySQL でしか意味のないテストは `DB::getDriverName() !== 'mysql'` で `markTestSkipped`。
- コミット前に毎回 `./vendor/bin/pint` と `php artisan test`（JS を触ったタスクは `npm run test:js` も）。

## Review Focus

1. **S6 を 2 つのタブで開いて両方が進める／ボタン連打**: 片方の送信中（リース 60 秒以内）にもう片方が呼んでも同じ動画を二重に送らず、`snippet.position` もずれないこと。→ Task 6 `test_fresh_lease_makes_concurrent_step_busy_without_sending`。
2. **送信中にタブを閉じた・PHP が途中で落ちた（結果不明）**: 次のステップで再送せず照合（playlistItems.list／playlists.list）し、あれば `done`／採用、無ければ再送すること。→ Task 6 `test_stale_in_flight_is_reconciled_before_resend`・`test_ambiguous_playlist_is_adopted_or_reinserted`。
3. **作成中に太平洋時間 0 時（日本時間 16/17 時）をまたぐ**: 前日の予約は効かず、残り分を当日分として再予約してから続け、足りなければ `paused(quota_budget)` で送らないこと。→ Task 6 `test_day_rollover_rereserves_remaining_or_pauses`、Task 3 `test_old_day_reservation_is_not_counted_and_is_expired_on_reserve`。
4. **作成中にログイン期限（約 1 時間）が切れる／Google 側で承認を取り消す**: ステップが 401 JSON を返して止まり、再ログインのリンクから戻ると S6 に着き（編集画面ではなく）、「続きから」で同じ位置から再開できること。→ Task 7 `test_google_401_pauses_forgets_login_and_returns_401`、Task 9 `test_callback_returns_to_creation_page_when_creation_exists`。
5. **「作成」の二度押し・戻るボタンで S5 から再送信**: 2 回目は新しい作成も予約も作らず S6 へ誘導すること（一意制約が最後の砦）。→ Task 5 `test_second_start_redirects_without_new_reservation`・`test_unique_violation_is_treated_as_existing`。

---

## File Structure

| ファイル | 責務 |
|---|---|
| `.github/workflows/ci.yml` | MySQL サービス付きジョブ `php-mysql`（`--group=mysql`） |
| `tests/Support/FailsQueries.php` | 指定テーブルへの SQL を QueryException にする（DDL を使わず DB 非依存） |
| `tests/Feature/MySql/*.php`, `tests/Support/mysql/reserve-worker.php` | MySQL 専用: 照合順序・行ロック・同時予約 |
| `database/migrations/2026_11_15_000100_create_playlist_creation_tables.php` | 3 テーブル＋MySQL の utf8mb4_bin |
| `app/Models/{PlaylistCreation,PlaylistCreationItem,QuotaReservation}.php` | モデルと状態定数 |
| `app/Services/YouTube/QuotaLedger.php`, `WriteLock.php` | 予約→確定→解放、空きの計算 |
| `app/Services/YouTube/YouTubeOwnerClient.php`, `OwnerPlaylist.php`, `OwnerPlaylistsPage.php` | 書込・照合・オーナートークンの videos.list |
| `app/Support/TextInput.php` | 名前・説明の整形（`StoreProposalRequest` から移す） |
| `app/Http/Requests/StartCreationRequest.php` | 作成開始の入力検証（JSON） |
| `app/Services/Creation/{CreationStarter,StartedCreation,InsufficientWriteQuota,CreationRunner,StepResult,CreationLifecycle,CreationBusy,CreationProgress}.php` | 開始・ステップ・操作・進捗 JSON と文言 |
| `app/Http/Controllers/CreationController.php` | S6 と JSON エンドポイント |
| `app/Http/Middleware/RequireOwnerSession.php` | `expectsJson()` の 401／403 JSON |
| `resources/views/owner/creation.blade.php`, `resources/js/creation-runner.js`, `resources/js/lib/creation-runner.js` | S6 |
| `resources/js/owner-edit.js`, `resources/js/lib/owner-edit.js`, `resources/views/owner/edit.blade.php` | 作成ボタンの本実装 |
| `app/Http/Controllers/{ProposalController,OwnerAuthController,OwnerEditController,OwnerDeleteController}.php`, `resources/views/proposals/show.blade.php`, `partials/owner-login.blade.php`, `owner/delete-confirm.blade.php` | S4 の状態・リンク、戻り先、S5→S6、U14 |
| `app/Console/Commands/PruneExpiredData.php` | 作成一式の明示削除 |

---

### Task 1: MySQL CI ジョブと SQLite 前提テストの修正（Issue #20）

**Files:**
- Create: `tests/Support/FailsQueries.php`, `tests/Feature/MySql/MySqlCollationTest.php`, `tests/Feature/MySql/MySqlLockingTest.php`
- Modify: `.github/workflows/ci.yml`, `tests/Feature/StoreProposalTest.php`（`test_database_error_redirects_with_message_and_logs_no_bindings`）, `tests/Feature/PruneExpiredDataTest.php`（`test_failing_step_does_not_stop_the_others_and_exits_non_zero`）
- Test: 上記

**Interfaces:**
- Produces:
  - trait `Tests\Support\FailsQueries`: `failQueriesOn(string $table, string $sqlstate = '42S02'): void` — `DB::connection()->beforeExecuting()` で、SQL が `$table` を対象にする（`/\b(from|into|update|table)\s+["`]?{$table}["`]?/i`）ときに `QueryException(connectionName, $sql, $bindings, $pdo)` を投げる。`$pdo` は `getCode()` が `$sqlstate` を返す `PDOException`（`protected $code` を設定した無名サブクラス）。DDL を使わないので MySQL の暗黙コミットで `RefreshDatabase` のトランザクションが壊れない。
  - PHPUnit グループ名 `mysql`（`#[Group('mysql')]`）。CI ジョブはこのグループだけを MySQL で流す。以後のタスクは予約・作成のテストクラスにもこのグループを付ける。

- [ ] **Step 1: ブランチを作る**

Run: `git switch main && git pull && git switch -c phase3-creation`

- [ ] **Step 2: 2 件のテストを DDL なしに書き換え、グループを付ける**

```php
// StoreProposalTest（use FailsQueries; メソッドに #[Group('mysql')]）
$this->failQueriesOn('proposal_items');   // 旧: Schema::drop('proposal_items')
// 以降の assert は同じ。ログの検査に sqlstate が 5 文字の SQLSTATE であることを追加:
// && preg_match('/"sqlstate":"[0-9A-Z]{5}"/', json_encode($ctx)) === 1

// PruneExpiredDataTest（use FailsQueries; #[Group('mysql')]）
$this->failQueriesOn('quota_usage');      // 旧: Schema::drop('quota_usage')
// '"sqlstate":"HY000"' の検査を '"sqlstate":"42S02"' に変える（ドライバに依存しない値）
```

- [ ] **Step 3: MySQL 専用テストを書く（SQLite ではスキップ）**

```php
#[Group('mysql')]
class MySqlCollationTest extends TestCase   // RefreshDatabase
{
    public function test_youtube_id_columns_are_utf8mb4_bin(): void
    {
        // information_schema.COLUMNS（TABLE_SCHEMA = DATABASE()）で 000500 の COLUMNS 一覧の各列が utf8mb4_bin
        // Task 2 で新テーブルの列（owner_channel_id, youtube_playlist_id, video_id, youtube_item_id）を足す
    }
    public function test_ids_differing_only_in_case_are_distinct(): void
    {
        // channel_caches に 'UC'.str_repeat('a',22) と 'UC'.str_repeat('A',22) を両方 insert でき、
        // where('channel_id', 'UC'.str_repeat('A',22)) が 1 件だけ返す
    }
}

#[Group('mysql')]
class MySqlLockingTest extends TestCase     // DatabaseTruncation
{
    public function test_for_update_blocks_a_second_connection(): void
    {
        // config に 'mysql_second' = config('database.connections.mysql') の複製を足す。
        // 既定接続で quota_usage(write) 行を insert（DatabaseTruncation なのでコミット済みになり、別接続から見える）。
        // 既定接続: beginTransaction → lockForUpdate()->first()
        // 第 2 接続: SET SESSION innodb_lock_wait_timeout = 1 → 同じ行を lockForUpdate → QueryException（errorInfo[1] === 1205）
    }
}
```

`MySqlLockingTest` は `RefreshDatabase` ではなく `DatabaseTruncation` を使う（クラス単位で統一し、`$exceptTables = ['migrations']`）。

- [ ] **Step 4: CI ジョブを追加する**

`.github/workflows/ci.yml` の `jobs:` に `php` と同じ手順（checkout・setup-php 8.4・composer キャッシュ・install・`.env` コピーと `key:generate`）で次を追加。`composer audit` と Pint は既存ジョブだけで行う。秘密値は使わない（root の空パスワードはジョブ内のサービス限定）。

```yaml
  php-mysql:
    name: PHP tests (MySQL group)
    runs-on: ubuntu-latest
    timeout-minutes: 15
    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
          MYSQL_DATABASE: listmaker_test
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1"
          --health-interval=5s --health-timeout=5s --health-retries=20
    env:
      DB_CONNECTION: mysql
      DB_HOST: 127.0.0.1
      DB_PORT: '3306'
      DB_DATABASE: listmaker_test
      DB_USERNAME: root
      DB_PASSWORD: ''
    # steps: … setup-php に extensions: pdo_mysql … 最後に:
    #   - name: Run MySQL test group
    #     run: php artisan test --group=mysql
```

phpunit.xml の `<env>` は `force` 無しなので、ジョブの環境変数（`DB_CONNECTION=mysql` 等）が優先される。冒頭コメントを「PHP テスト（SQLite と MySQL グループ）…」に直す。

- [ ] **Step 5: 実行して確認**

Run: `php artisan test` → PASS（MySQL 専用 3 件は SKIPPED）。ローカルに MySQL があれば `DB_CONNECTION=mysql DB_DATABASE=listmaker_test DB_USERNAME=root DB_PASSWORD= php artisan test --group=mysql` → PASS。

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add .github/workflows/ci.yml tests
git commit -m "ci: MySQL のテストグループを流すジョブを追加し、DDL に頼る 2 件のテストをドライバ非依存にする"
```

---

### Task 2: 作成テーブル・モデル・削除バッチ

**Files:**
- Create: `database/migrations/2026_11_15_000100_create_playlist_creation_tables.php`, `app/Models/PlaylistCreation.php`, `app/Models/PlaylistCreationItem.php`, `app/Models/QuotaReservation.php`
- Modify: `app/Models/Proposal.php`（`creation()`）, `app/Console/Commands/PruneExpiredData.php`（`pruneProposals`）, `tests/Feature/MySql/MySqlCollationTest.php`
- Test: `tests/Feature/DatabaseSchemaTest.php`, `tests/Feature/PruneExpiredDataTest.php`

**Interfaces:**
- Produces:
  - テーブル（spec 4.2 に、本計画で 2 列を追加）:
    - `playlist_creations`: `id`, `proposal_id`（FK cascade, UNIQUE）, `owner_channel_id` varchar(24), `title` varchar(150), `description` text, `privacy_status` enum(private,unlisted,public), `status` enum（Global Constraints の 6 値）, `pause_reason` varchar(20) null, `youtube_playlist_id` varchar(64) null, `playlist_attempted_at` datetime null, **`playlist_leased_at` datetime null**（playlists.insert の送信中の印。リース判定用）, **`playlist_attempts` tinyint unsigned default 0**（playlists.insert の送信回数）, `estimated_units` int unsigned, `started_at` datetime, `completed_at` datetime null, `updated_at` datetime。created_at は持たない。
    - `playlist_creation_items`: `id`, `creation_id`（FK → playlist_creations cascade）, `position` smallint unsigned, `video_id` char(11), `title` varchar(255), `thumbnail_url` varchar(255) null, `is_outside_channel` bool, `status` enum（5 値）INDEX, `attempts` tinyint unsigned default 0, `leased_at` datetime null, `last_error` varchar(64) null, `youtube_item_id` varchar(128) null。UNIQUE(creation_id, position)、UNIQUE(creation_id, video_id)。
    - `quota_reservations`: `id`, `creation_id`（FK cascade）, `day_pt` date, `reserved_units` int unsigned, `consumed_units` int unsigned default 0, `status` enum(active,released,expired), `created_at`, `updated_at`。UNIQUE(creation_id, day_pt)、INDEX(day_pt, status)。
    - MySQL/MariaDB のときだけ、000500 と同じ書き方で `owner_channel_id`・`video_id`（NOT NULL）と `youtube_playlist_id`・`youtube_item_id`（NULL）を `utf8mb4_bin` に `ALTER`（SQLite は何もしない）。
  - `PlaylistCreation`（`CREATED_AT = null`、`$guarded = []`）: 定数 `PENDING='pending'`, `CREATING_PLAYLIST='creating_playlist'`, `ADDING='adding'`, `PAUSED='paused'`, `COMPLETED='completed'`, `NEEDS_CHECK='needs_check'`, `PAUSE_AUTH='auth_expired'`, `PAUSE_QUOTA_EXCEEDED='quota_exceeded'`, `PAUSE_QUOTA_BUDGET='quota_budget'`, `PAUSE_USER='user'`。casts: 日時は `immutable_datetime`、数値は `integer`。`items(): HasMany`（position 昇順）、`proposal(): BelongsTo`、`reservations(): HasMany`、`playlistUrl(): ?string`（`https://www.youtube.com/playlist?list=`＋id、id が null なら null）。
  - `PlaylistCreationItem`（`$timestamps = false`）: `PENDING`, `IN_FLIGHT='in_flight'`, `DONE`, `FAILED`, `SKIPPED`。`creation(): BelongsTo`。
  - `QuotaReservation`: `ACTIVE`, `RELEASED`, `EXPIRED`。
  - `Proposal::creation(): HasOne`

- [ ] **Step 1: 失敗するテストを書く**

```php
// DatabaseSchemaTest
public function test_creation_tables_have_spec_columns(): void
{
    $this->assertSame(['id','proposal_id','owner_channel_id','title','description','privacy_status','status','pause_reason','youtube_playlist_id','playlist_attempted_at','playlist_leased_at','playlist_attempts','estimated_units','started_at','completed_at','updated_at'], Schema::getColumnListing('playlist_creations'));
    $this->assertSame(['id','creation_id','position','video_id','title','thumbnail_url','is_outside_channel','status','attempts','leased_at','last_error','youtube_item_id'], Schema::getColumnListing('playlist_creation_items'));
    $this->assertSame(['id','creation_id','day_pt','reserved_units','consumed_units','status','created_at','updated_at'], Schema::getColumnListing('quota_reservations'));
}
public function test_one_creation_per_proposal(): void { /* 同じ proposal_id で 2 件目の insert が QueryException */ }

// PruneExpiredDataTest
public function test_deletes_creation_records_with_expired_proposal(): void
{
    // 期限切れ提案に creation＋items 2 件＋reservation 1 件、期限内の提案にも同じ一式
    // → 実行後、期限切れ側の 3 テーブルの行が 0、期限内側は残る。出力 'proposals: 1'
}

// MySqlCollationTest::test_youtube_id_columns_are_utf8mb4_bin に新テーブルの 4 列を追加
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='DatabaseSchemaTest|PruneExpiredDataTest'`
Expected: FAIL（テーブル無し）

- [ ] **Step 3: マイグレーション・モデル・削除バッチを実装する**

`pruneProposals` のチャンク内トランザクションで、`proposal_items` の前に `quota_reservations`→`playlist_creation_items`→`playlist_creations` を `whereIn` で明示削除する（FK cascade に頼らない既存方針どおり）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add database app/Models app/Console tests
git commit -m "feat: 作成スナップショットと書込予約のテーブル・モデルを追加し、期限切れ提案と一緒に削除する"
```

---

### Task 3: 書込枠の予約・確定・解放（QuotaLedger と WriteLock）

**Files:**
- Create: `app/Services/YouTube/WriteLock.php`, `tests/Feature/QuotaReservationTest.php`, `tests/Feature/MySql/MySqlConcurrentReservationTest.php`, `tests/Support/mysql/reserve-worker.php`
- Modify: `app/Services/YouTube/QuotaLedger.php`
- Test: `tests/Feature/QuotaReservationTest.php`, `tests/Feature/QuotaLedgerTest.php`（既存が通ること）, `tests/Feature/RemainingTodayViewTest.php`

**Interfaces:**
- Consumes: `QuotaReservation`, `QuotaClock::todayPt()`, `QuotaPolicy::ITEM_UNITS`
- Produces:
  - `QuotaLedger::writeFreeUnits(): int` — 5.4 の式（当日の active 予約の `reserved − consumed` を差し引く）。ロックなしの読み取り（表示用）。`remainingToday()` は変更不要でこれに従う。
  - `QuotaLedger::ensureWriteRow(): void` — 当日の write 行を `insertOrIgnore`。**呼び出し側が自分のトランザクションを開く前に**呼ぶ（InnoDB の挿入ロックによるデッドロック回避。既存 `locked()` のコメントと同じ理由）。
  - `QuotaLedger::withWriteLock(callable $fn): mixed` — `DB::transactionLevel() === 0` なら `ensureWriteRow()` してから、`DB::transaction(..., 3)` 内で当日の write 行を `lockForUpdate` し `$fn(WriteLock $lock)` を返す。外側のトランザクション内で呼ばれ行が無いときは、その場で `insertOrIgnore` して取り直す。
  - `final class WriteLock`（`withWriteLock` だけが生成。コンストラクタは `string $dayPt`）:
    - `free(): int`（ロック下での空き）
    - `unused(int $creationId): int`（当日 active の `reserved − consumed`、無ければ 0）
    - `hasActiveToday(int $creationId): bool`
    - `reserve(int $creationId, int $units): bool` — `free() < $units` なら何も変えず false。この作成の**前日以前の active を `expired` に**し、当日行が無ければ active で作成、active なら `reserved += units`、released／expired なら `status=active, reserved = consumed + units`。
    - `ensure(int $creationId, int $needed): bool` — `reserve($creationId, max(0, $needed − unused()))`（0 なら何もせず true。前日以前の expired 化はする）
    - `consume(int $creationId): bool` — 当日 active が無ければ false（日付またぎ。Runner が先に `ensure` する）。`unused() < 50` なら `reserve(50)`、できなければ false。`consumed += 50` と `quota_usage(write) += 50`。false のときは何も変えない。
    - `release(int $creationId): void` — 当日 active を `released`、前日以前の active を `expired`。

- [ ] **Step 1: 失敗するテストを書く**（`#[Group('mysql')]` を付ける。予約行の FK のため、提案と creation を作るヘルパ `makeCreation(int $items = 3, array $attrs = [], ?int $reserve = null): PlaylistCreation`（`createProposal` の提案に status pending の creation と items（position 0..n−1、video_id は `sprintf('v%010d', $i)`）を作る。`$reserve` が非 null なら当日の active 予約をその量で作り、quota_usage は増やさない）を `tests/Support/CreatesProposals.php` に追加し、Task 6 以降でも使う）

```php
public function test_reservations_reduce_remaining_for_others(): void
{
    config(['youtube.quota.write_limit' => 5000]);
    $c = $this->makeCreation(49);
    $ok = app(QuotaLedger::class)->withWriteLock(fn (WriteLock $l) => $l->reserve($c->id, 2500));
    $this->assertTrue($ok);
    $this->assertSame(2500, app(QuotaLedger::class)->writeFreeUnits());
    $this->assertSame(49, app(QuotaLedger::class)->remainingToday()->items);   // floor((2500-50)/50)
}
public function test_reserve_refuses_without_change_when_short(): void { /* free 149 に 150 → false、行は増えない */ }
public function test_consume_moves_units_from_reservation_to_usage(): void
{
    // reserve 150 → consume ×3 → used(WRITE)=150, consumed=150, free は reserve 前と同じ 5000-150
}
public function test_consume_tops_up_by_50_when_short_and_fails_without_free(): void
{
    // write_limit 200: c1 reserve 100、c2 reserve 100 → c1 が consume ×2 → 3 回目は free 0 で false（usage 100 のまま）
}
public function test_release_returns_unused_units(): void { /* reserve 500, consume 1 回 → release → free = limit-50、status released */ }
public function test_old_day_reservation_is_not_counted_and_is_expired_on_reserve(): void
{
    // PT 2026-11-14 23:59 に reserve 2000 → travel 2 分 → writeFreeUnits() = 5000（前日分は数えない）、
    // hasActiveToday false、consume false → ensure(…, 300) で前日行が expired、当日行が active 300
}
public function test_reserve_reactivates_a_released_row_same_day(): void { /* release 後の reserve(100) → active, reserved = consumed + 100 */ }
public function test_existing_ledger_behaviour_is_unchanged(): void { /* 予約なしで writeFreeUnits = limit - used(WRITE) */ }
```

MySQL の同時予約（`MySqlConcurrentReservationTest`、`DatabaseTruncation`、mysql 以外はスキップ）:

```php
public function test_concurrent_reservations_never_exceed_the_limit(): void
{
    // write_limit 5000。creation を 5 件作って commit。各 2000 を予約する子プロセスを 5 本、proc_open で同時に起動:
    //   php tests/Support/mysql/reserve-worker.php {creationId} 2000
    // 子の環境変数は DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD を config('database.connections.mysql') から、
    // APP_ENV=testing・APP_KEY・QUOTA_WRITE_LIMIT=5000 を明示して渡す。
    // 標準出力 'ok' が ちょうど 2 本、Σ(active reserved) = 4000 ≤ 5000
}
```

`reserve-worker.php`: `vendor/autoload.php` と `bootstrap/app.php` を読み、Console Kernel を `bootstrap()` し、`QuotaLedger::withWriteLock(fn (WriteLock $l) => $l->reserve($id, $units))` の結果を `ok`／`no` で出力する（クラス・名前空間なし）。

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=QuotaReservationTest`
Expected: FAIL（`WriteLock` 未定義）

- [ ] **Step 3: 上記シグネチャで実装する**

`writeFreeUnits()` のコメント「Phase 3 subtracts…」を実装に置き換える。`WriteLock` の集計は `QuotaReservation::where('day_pt', $day)->where('status','active')->sum(DB::raw('reserved_units - consumed_units'))`。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（`QuotaLedgerTest`・`RemainingTodayViewTest` も変化なし）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/YouTube tests
git commit -m "feat: 書込枠の予約・確定・解放（WriteLock）を追加し、本日の残りから他の作成の予約を差し引く"
```

---

### Task 4: YouTubeOwnerClient の書込・照合・オーナートークンでの動画確認

**Files:**
- Create: `app/Services/YouTube/OwnerPlaylist.php`, `app/Services/YouTube/OwnerPlaylistsPage.php`
- Modify: `app/Services/YouTube/YouTubeOwnerClient.php`, `app/Services/YouTube/YouTubeReader.php`（動画 1 件の解析を public static に切り出す）, `tests/Support/FakesGoogle.php`
- Test: `tests/Feature/YouTubeOwnerClientTest.php`, `tests/Feature/YouTubeReaderTest.php`（既存が通ること）

**Interfaces:**
- Consumes: `QuotaLedger::OWNER_READ`, `YouTubeError`, `YouTubeApiException`, `VideoInfo`
- Produces:
  - `YouTubeReader::videoInfo(array $item): ?VideoInfo`（`videos()` のループ本体。id が 11 文字でなければ null）。`videos()` はこれを使う。
  - `YouTubeOwnerClient::insertPlaylist(#[\SensitiveParameter] string $token, string $title, string $description, string $privacy): string` — `POST playlists?part=snippet,status`、本文 `{"snippet":{"title","description"},"status":{"privacyStatus"}}`。応答の `id`（`/^[A-Za-z0-9_-]{2,64}$/`）を返す。不正なら `malformed`。**台帳に記録しない**（`WriteLock::consume()` が記録済み）。
  - `YouTubeOwnerClient::insertPlaylistItem(#[\SensitiveParameter] string $token, string $playlistId, string $videoId, int $position): string` — `POST playlistItems?part=snippet`、本文 `{"snippet":{"playlistId","position","resourceId":{"kind":"youtube#video","videoId"}}}`。応答の `id`（`/^[A-Za-z0-9_=-]{1,128}$/`）。台帳に記録しない。
  - `YouTubeOwnerClient::myPlaylists(#[\SensitiveParameter] string $token): OwnerPlaylistsPage` — `GET playlists?part=snippet&mine=true&maxResults=50`（1 ページのみ、owner_read 1）。`OwnerPlaylistsPage(list<OwnerPlaylist> $items, bool $hasMore)`、`OwnerPlaylist(string $id, string $title, ?CarbonImmutable $publishedAt)`。
  - `YouTubeOwnerClient::playlistVideoItems(#[\SensitiveParameter] string $token, string $playlistId, int $maxPages): array<string, string>` — `GET playlistItems?part=snippet&maxResults=50&playlistId=…` をページング（owner_read 1/ページ、最大 `$maxPages`）。`videoId => playlistItemId`（同じ動画が複数なら最初の 1 件）。
  - `YouTubeOwnerClient::videos(#[\SensitiveParameter] string $token, array $ids): array<string, VideoInfo>` — オーナートークンの `GET videos?part=snippet,contentDetails,status&id=…`（50 件ずつ、owner_read 1/回）。`YouTubeReader::videoInfo` で解析。
  - POST は private `post(string $token, string $endpoint, array $query, array $body): array`（`asJson()`、`get()` と同じタイムアウト・非追従・例外の分類。台帳記録なし）。
  - `FakesGoogle` に追加: `fakePlaylistInsert(string $id = 'PLnew', int $status = 200, ?string $reason = null)`, `fakePlaylistItemInsert(callable|array $responder)`（リクエスト順に応答を返す配列、またはクロージャ）, `fakeMyPlaylists(array $items, bool $hasMore = false)`, `fakePlaylistItemsList(array $videoIdToItemId)`, `fakeOwnerVideos(array $videoResources)`。エラー応答本文は `['error' => ['code' => $status, 'errors' => [['reason' => $reason]]]]`。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_insert_playlist_posts_snippet_and_status_without_recording_quota(): void
{
    $this->fakePlaylistInsert('PLabc');
    $id = app(YouTubeOwnerClient::class)->insertPlaylist('ya29.tok', '名前<>', '説明', 'unlisted');
    $this->assertSame('PLabc', $id);
    $req = Http::recorded()->sole()[0];
    $this->assertSame('POST', $req->method());
    $this->assertSame('Bearer ya29.tok', $req->header('Authorization')[0]);
    $this->assertSame(['snippet' => ['title' => '名前<>', 'description' => '説明'], 'status' => ['privacyStatus' => 'unlisted']], $req->data());
    $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
    $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
}
public function test_insert_item_sends_position_and_resource_id(): void
{
    // data() === ['snippet' => ['playlistId' => 'PLabc', 'position' => 3, 'resourceId' => ['kind' => 'youtube#video', 'videoId' => 'aaaaaaaaaaa']]]
}
#[DataProvider('errors')]
public function test_write_errors_are_classified(int $status, ?string $reason, YouTubeErrorKind $kind): void {}
// [500,null,Transient] [409,null,Transient] [403,'quotaExceeded',GoogleQuota] [401,null,Auth] [404,'videoNotFound',ItemPermanent] [400,'invalidValue',InvalidInput]
public function test_connection_error_on_write_is_transient_with_null_status(): void {}
public function test_my_playlists_reads_one_page_and_records_owner_read(): void { /* hasMore は nextPageToken の有無 */ }
public function test_playlist_video_items_pages_up_to_max(): void { /* 2 ページ → 2 ユニット、videoId=>itemId */ }
public function test_owner_videos_use_token_and_parse_like_reader(): void { /* X-Goog-Api-Key 無し、privacyStatus unlisted を保持 */ }
public function test_write_failure_log_has_no_token_or_body(): void { /* ログに 'ya29' や本文の文字列が無い */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='YouTubeOwnerClientTest|YouTubeReaderTest'`
Expected: FAIL（メソッド未定義）

- [ ] **Step 3: 上記シグネチャで実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/YouTube tests
git commit -m "feat: チャンネル主のトークンで再生リスト作成・動画追加（position 指定）・照合用の一覧取得・動画確認を行うクライアントを追加"
```

---

### Task 5: 作成開始（RequireOwnerSession の JSON 経路・最終確認・予約・二重開始防止）

**Files:**
- Create: `app/Support/TextInput.php`, `app/Http/Requests/StartCreationRequest.php`, `app/Services/Creation/CreationStarter.php`, `app/Services/Creation/StartedCreation.php`, `app/Services/Creation/InsufficientWriteQuota.php`, `app/Http/Controllers/CreationController.php`（`start` のみ）
- Modify: `app/Http/Requests/StoreProposalRequest.php`（整形を `TextInput` に委譲）, `app/Http/Middleware/RequireOwnerSession.php`, `app/Support/RemainingToday.php`, `app/Providers/AppServiceProvider.php`（`creation-start`）, `routes/web.php`, `tests/Feature/PagesTest.php`
- Test: `tests/Feature/CreationStartTest.php`, `tests/Feature/OwnerEditAccessTest.php`（JSON 経路）, `tests/Feature/StoreProposalTest.php`（既存が通ること）

**Interfaces:**
- Consumes: `WriteLock::reserve`, `QuotaLedger::ensureWriteRow/withWriteLock/remainingToday`, `YouTubeReader::videos($ids, QuotaLedger::OWNER_READ)`, `YouTubeOwnerClient::videos`, `UnavailableVideos`（既存。`->ids`）, `RequireOwnerSession::ownerApiFailureJson`
- Produces:
  - `TextInput::singleLine(mixed $v): mixed`, `TextInput::multiLine(mixed $v): mixed`（`StoreProposalRequest` の private static をそのまま移す）
  - `RequireOwnerSession`: `$request->expectsJson()` のとき、未ログイン・期限切れ → `loginRequiredJson()`（401）、不一致 → `response()->json(['message' => self::MISMATCH], 403)`、`MISMATCH = 'ログイン中のチャンネルはこの提案の対象ではありません'`。提案なしは従来どおり 404（JSON で描画される）。
  - `StartCreationRequest`（JSON 本文）: `title` required string max:150、`description` nullable string max:1000、`privacy` required in:private,unlisted,public、`video_ids` required array min:1 max:`QuotaPolicy::maxItemsPerPlaylist()`、`video_ids.*` distinct regex `/^[A-Za-z0-9_-]{11}$/D`。メッセージは `StoreProposalRequest` と同じ文言（「再生リスト名を入力してください」等）＋ `privacy.*` →「公開範囲の値が正しくありません」。失敗は 422 JSON（FormRequest 既定）。
  - `RemainingToday::sentence(): string`（5.5 の 2 文のどちらか）、`RemainingToday::shortageSentence(int $n): string`（`sentence()` に、`! exhausted()` かつ `n > items` なら「。あと {n−items} 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 {n月j日 G時} です）」を続ける）
  - `InsufficientWriteQuota extends RuntimeException(public RemainingToday $remaining, public int $items)`
  - `StartedCreation(PlaylistCreation $creation, bool $created)`
  - `CreationStarter::start(Proposal $proposal, OwnerLogin $owner, array $validated): StartedCreation` — 手順:
    1. 既に `$proposal->creation` があればそれを `created=false` で返す（読取も予約もしない）。
    2. 最終確認: `reader->videos($ids, OWNER_READ)` で `privacyStatus === 'public'` を受け付け、残りを `owner->videos($owner->accessToken, $rest)` で確認し `channelId === $owner->channelId && privacyStatus === 'unlisted'` だけ受け付ける。受け付けられない ID があれば `UnavailableVideos($ids)`（除外はしない）。
    3. `ledger->ensureWriteRow()` → `withWriteLock`: もう一度 creation の有無を確認（あれば `created=false`）→ `reserve` する見込み `50 + 50×n` を先に `free()` と比べ、足りなければ `InsufficientWriteQuota(new RemainingToday(QuotaPolicy::itemsAffordable($lock->free()), QuotaClock::nextResetJst()), n)` → creation（status pending、`owner_channel_id = $proposal->channel_id`、`estimated_units`、`started_at=now`）、items（position 0..n−1、title・thumbnail は最終確認の値、`is_outside_channel = channelId !== proposal.channel_id`）、`$lock->reserve($creation->id, estimated)`。
    4. 一意制約違反（`QueryException` の SQLSTATE `23000`）は既存の creation を `created=false` で返す。
    5. `Log::info('Creation started', ['creation_id', 'items', 'reserved_units'])`。
  - `CreationController::start(StartCreationRequest $request, CreationStarter $starter, OwnerSession $owners): JsonResponse` — 成功 201 `{redirect: 'creation'}`＋`session()->flash('creation_autostart', true)`、既存 200 `{redirect: 'creation'}`、`UnavailableVideos` 422 `{message: 'この動画は削除または非公開になっています。外してから作成してください', unavailable_ids: [...]}`、`InsufficientWriteQuota` 409 `{message: $e->remaining->shortageSentence($e->items)}`、`YouTubeApiException` → `ownerApiFailureJson($e, $owners, 'YouTube との通信に失敗しました。時間をおいて再度お試しください')`。
  - ルート `proposals.creation.start`: `[RequireSameOriginFetch::class, 'throttle:creation-start', RequireOwnerSession::class]`
  - リミッタ `creation-start`: `Limit::perHour(5)->by('creation-start:'.(OwnerSession の channelId ?? 'ip:'.ip))`（Task 7 の再試行も共有）

- [ ] **Step 1: 失敗するテストを書く**（`CreationStartTest`: `CreatesProposals, FakesGoogle, FakesYouTube, RefreshDatabase`、`#[Group('mysql')]`。リクエストは `postJson("/p/$key/creation", $body, ['Sec-Fetch-Site' => 'same-origin'])`）

```php
public function test_start_reserves_snapshots_and_redirects(): void
{
    $key = $this->createProposal();                       // 2 本
    $this->fakeVideos([...2 本 public...]);              // API キー側
    $res = $this->actAsOwner($this->channelId)->postJson(...)->assertCreated()->assertExactJson(['redirect' => 'creation']);
    $c = PlaylistCreation::sole();
    $this->assertSame([PlaylistCreation::PENDING, 150, 'private'], [$c->status, $c->estimated_units, $c->privacy_status]);
    $this->assertSame([0, 1], $c->items()->pluck('position')->all());
    $this->assertSame(150, QuotaReservation::sole()->reserved_units);
    $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
    $this->assertSame(2, ProposalItem::count());          // 提案は書き換えない
    $res->assertSessionHas('creation_autostart', true);
}
public function test_edited_order_title_and_privacy_are_used(): void { /* 順序を入れ替え・名前変更・public */ }
public function test_insufficient_quota_creates_nothing_and_explains(): void
{
    // write_limit 100 → 2 本は不可。409、message に「本日の残り」と「あと 1 本減らすか」。creation・reservation 0 件
}
public function test_unavailable_videos_are_returned_not_dropped(): void { /* 1 本 private（他人）→ 422、unavailable_ids、creation 0 件 */ }
public function test_owner_unlisted_video_is_accepted_via_owner_token(): void
{
    // API キー側で privacy unlisted の自分の動画 → owner videos.list（Bearer）で channelId 一致・unlisted → 受理。
    // proposal_items には書かれない、playlist_creation_items に入る
}
public function test_unlisted_video_of_another_channel_is_rejected(): void {}
public function test_owner_401_forgets_login_and_returns_401(): void { /* owner videos.list 401 → 401 NOTICE、セッションの owner 無し */ }
public function test_second_start_redirects_without_new_reservation(): void { /* 2 回目 200 redirect、Http 送信 0、予約 1 件のまま */ }
public function test_unique_violation_is_treated_as_existing(): void
{
    // withWriteLock 内の 2 回目の存在確認をすり抜けた想定: DB::beforeExecuting で playlist_creations への最初の insert の直前に
    // 別の creation を同じ proposal_id で insert しておく（フラグで 1 回だけ・再入しない）→ 200 redirect、creation 1 件、予約 1 件
}
public function test_validation_errors_are_422_json(): void { /* title 空・151 文字・privacy 'friends'・100 本・重複 */ }
public function test_json_routes_get_401_or_403_json_from_middleware(): void
{
    // 未ログイン → 401 {message: RequireOwnerSession::NOTICE}、別チャンネル → 403 {message: MISMATCH}、リダイレクトしない
}
public function test_cross_site_and_missing_csrf_are_rejected(): void { /* Sec-Fetch-Site cross-site → 403 JSON、CSRF 無し → 419 */ }
public function test_start_is_limited_to_5_per_hour_per_channel(): void {}
```

`OwnerEditAccessTest` には HTML 経路が従来どおりリダイレクト／不一致画面であることの回帰テストを 1 件足す。

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='CreationStartTest|OwnerEditAccessTest|StoreProposalTest|PagesTest'`
Expected: FAIL（ルート・クラス未定義）

- [ ] **Step 3: 上記シグネチャで実装する**

`PagesTest` の期待一覧に `p/{key}/creation` を追加（この時点では POST のみ。GET は Task 8）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php tests
git commit -m "feat: 作成開始（最終確認・限定公開はオーナートークンで検証・書込枠の予約・二重開始防止）と RequireOwnerSession の JSON 応答を追加"
```

---

### Task 6: CreationRunner（1 ステップ 1 書込・position・照合・予約の確定と解放）

**Files:**
- Create: `app/Services/Creation/CreationRunner.php`, `app/Services/Creation/StepResult.php`
- Test: `tests/Feature/CreationRunnerTest.php`（`#[Group('mysql')]`）

**Interfaces:**
- Consumes: `QuotaLedger::ensureWriteRow/withWriteLock`, `WriteLock::{hasActiveToday,ensure,consume,release}`, `YouTubeOwnerClient::{insertPlaylist,insertPlaylistItem,myPlaylists,playlistVideoItems}`, `YouTubeErrorKind`, モデル定数
- Produces:
  - `final readonly class StepResult(int $nextDelayMs, ?string $message = null, bool $authExpired = false, ?string $redirect = null)`
  - `CreationRunner`: `NORMAL_DELAY_MS = 1000`, `LEASE_SECONDS = 60`, `MAX_ATTEMPTS = 6`, `MAX_PLAYLIST_ATTEMPTS = 6`
    - `step(PlaylistCreation $creation, OwnerLogin $owner): StepResult`
    - `remainingUnitsNeeded(PlaylistCreation $c): int` = `(youtube_playlist_id === null ? 50 : 0) + 50 × (pending + in_flight の件数)`（Task 7 も使う）
    - `static positionFor(PlaylistCreation $c, PlaylistCreationItem $item): int` = この項目より position が小さい `done` の件数
  - 文言定数（S6 と JSON で使う）: `REJECTED_INPUT = 'YouTube が再生リスト名または説明を受け付けませんでした。「<」「>」などの記号を除いて、編集画面から作成し直してください'`, `PLAYLIST_FAILED = '再生リストを作成できませんでした'`

**ステップの手順**（spec 7.3 の「行ロック」は状態の遷移に使い、YouTube 呼び出し中はロックを持たない。同時実行はリースで 1 つに絞る）:

1. **決定（短いトランザクション A）**: `ensureWriteRow()` → `DB::transaction`: creation を `lockForUpdate` で取り直す（`owner_channel_id` とログイン中チャンネルの照合は Task 7 の Controller が呼ぶ前に行う）。
   - `paused`／`completed`／`needs_check` → 何もせず `StepResult(0)`。
   - **予約の日付またぎ**: 当日の active 予約が無ければ `withWriteLock` で `ensure(remainingUnitsNeeded)`。できなければ `paused(quota_budget)`、`StepResult(0, remaining->sentence())`。
   - `creating_playlist`:
     - `playlist_leased_at` が 60 秒以内 → 実行中（別タブ）。`StepResult(1000)`。
     - それ以外（リースが空＝前回の結果不明、または 60 秒超）→ **照合**を予定（書込なし）。
   - `pending` → `playlist_attempts >= MAX_PLAYLIST_ATTEMPTS` なら `needs_check`。そうでなければ `consume` → false なら `paused(quota_budget)`。true なら `status=creating_playlist`、`playlist_attempted_at ??= now`、`playlist_leased_at=now`、`playlist_attempts++` → **insertPlaylist** を予定。
   - `adding`:
     - `in_flight` で `leased_at` が 60 秒以内の項目がある → `StepResult(1000)`。
     - `in_flight` で `leased_at` が null または 60 秒超の項目がある → その最小 position の項目を**照合**に予定（書込なし）。
     - `pending` が無い → `completed`、`completed_at=now`、`release`、`Log::info('Creation completed', [...])`、`StepResult(0)`。
     - 次の `pending`（position 昇順）: `consume` → false なら `paused(quota_budget)`。true なら `in_flight`、`leased_at=now`、`attempts++`、送る position は `positionFor()` → **insertPlaylistItem** を予定。
2. **送信（トランザクション外）**: 予定した API を 1 回だけ呼ぶ。
3. **記録（短いトランザクション B）**: creation を再び `lockForUpdate`。この間に利用者が一時停止していたら `status` は `paused` のまま（結果だけ書く）。
   - insertPlaylist 成功 → `youtube_playlist_id`、`playlist_leased_at=null`、`status = (status === creating_playlist ? adding : status)`。
   - insertPlaylist 失敗（`YouTubeApiException` の kind）:
     - `Transient` かつ `status === null`（接続・タイムアウト。作られたか不明）→ `playlist_leased_at=null` のまま `creating_playlist`（次で照合）。`StepResult(1000 × 2^playlist_attempts)`。
     - `Transient`（HTTP 応答あり）→ `status=pending`、`playlist_leased_at=null`、バックオフ。
     - `GoogleQuota` → `paused(quota_exceeded)`、`playlist_attempted_at=null`、`status` の戻り先は pending。
     - `Auth` → `paused(auth_expired)`、`playlist_attempted_at=null`、`StepResult(0, authExpired: true)`。
     - `InvalidInput`（400）→ **作成記録を削除**（予約・items ごと）し `StepResult(0, REJECTED_INPUT, redirect: 'edit')`（spec の不整合 1 を参照）。
     - その他の恒久エラー・不明 → `completed`、全項目 `skipped`、`release`、`StepResult(0, PLAYLIST_FAILED)`。
   - 照合（playlists）: `myPlaylists()` の中で `title === creation.title` かつ `publishedAt >= playlist_attempted_at − 60 秒` のもの。1 件 → 採用（`youtube_playlist_id`、`adding`）。0 件かつ `hasMore=false` → `status=pending`（次で insert）。0 件で `hasMore=true`、または 2 件以上 → `needs_check`。読取が `Auth` → `paused(auth_expired)`、その他のエラー → そのまま（次で再照合）、バックオフ。
   - insertPlaylistItem 成功 → `done`、`youtube_item_id`、`leased_at=null`、`last_error=null`。
   - insertPlaylistItem 失敗:
     - `Transient` かつ `status === null` → `in_flight` のまま `leased_at=null`（次で照合）。
     - `Transient`（HTTP 応答あり）→ `attempts >= MAX_ATTEMPTS` なら `failed`（`last_error = reason ?? 'transient'`）、そうでなければ `pending`、`StepResult(1000 × 2^attempts)`。
     - `GoogleQuota` → 項目 `pending`、`paused(quota_exceeded)`。
     - `Auth` → 項目 `pending`、`paused(auth_expired)`、`authExpired: true`。
     - `ItemPermanent`／`InvalidInput`／`Unknown` → `failed`、`last_error = reason ?? kind->value`。
   - 照合（item）: `playlistVideoItems(token, playlistId, maxPages: intdiv(total + 49, 50))` に video_id があれば `done`＋item id、無ければ `pending`（再送。attempts はそのまま）。
   - 記録の後、`adding` で `pending`・`in_flight` が 0 なら `completed`＋`release`。
   - 正常時の `StepResult(NORMAL_DELAY_MS)`。

- [ ] **Step 1: 失敗するテストを書く**（`makeCreation(3, reserve: 200)`、`actAsOwner` ではなく `new OwnerLogin('ya29.tok', now()->addHour(), $this->channelId, 'ch')` を直接渡す）

```php
public function test_first_step_creates_playlist_and_consumes_50(): void
{
    $this->fakePlaylistInsert('PLx');
    $r = app(CreationRunner::class)->step($c, $owner);
    $c->refresh();
    $this->assertSame([PlaylistCreation::ADDING, 'PLx', 1000], [$c->status, $c->youtube_playlist_id, $r->nextDelayMs]);
    $this->assertSame(50, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
    $this->assertSame(50, QuotaReservation::sole()->consumed_units);
}
public function test_items_are_added_in_order_with_position_and_then_completed_and_released(): void
{
    // ステップ 4 回 → 送った position が [0,1,2]、全 done、completed、予約 released、used(WRITE)=200、free = limit-200
}
public function test_retrying_a_failed_item_inserts_it_at_its_proposal_position(): void
{
    // 2 本目が 404 videoNotFound → failed → 3 本目は position 1 → （再試行で 2 本目を pending に戻した想定）→ 送る position は 1
}
public function test_transient_error_backs_off_and_fails_after_6_sends(): void
{
    // 500 を返し続ける → delay 2000,4000,8000,16000,32000 → 6 回目で failed（last_error 'transient' ではなく reason があれば reason）
}
public function test_transient_retry_beyond_reservation_tops_up_or_pauses_quota_budget(): void
{
    // write_limit を予約ちょうどにし、他の作成で空き 0 → 再送時の consume false → paused(quota_budget)、送信なし
}
public function test_google_quota_pauses_and_keeps_reservation(): void {}
public function test_google_401_pauses_auth_expired_and_flags(): void { /* authExpired true、項目は pending */ }
public function test_fresh_lease_makes_concurrent_step_busy_without_sending(): void
{
    // 1 本目を in_flight・leased_at=now-10s にしておく → step は送信 0・delay 1000・状態不変（consume もしない）
}
public function test_stale_in_flight_is_reconciled_before_resend(): void
{
    // leased_at=now-61s、playlistItems.list に video あり → done（insert は 0 回、owner_read 1）
    // 無い場合 → pending（insert 0 回）→ 次の step で insert
}
public function test_connection_error_on_item_is_reconciled_next_step(): void {}
public function test_ambiguous_playlist_is_adopted_or_reinserted(): void
{
    // creating_playlist・leased null・attempted_at=T。myPlaylists に同名・T 以降が 1 件 → 採用、insert 0 回
    // 0 件・hasMore false → pending → 次で insert。2 件 → needs_check。0 件・hasMore true → needs_check
}
public function test_invalid_playlist_input_deletes_creation_and_redirects_to_edit(): void
{
    // 400 invalidValue → creation・items・予約が消える、redirect 'edit'、used(WRITE) は 50 のまま
}
public function test_day_rollover_rereserves_remaining_or_pauses(): void
{
    // adding で 1 本 done・2 本 pending、前日の予約。travelTo 翌 PT 日 → step で当日予約 100 が作られ、前日分は expired、送信 1 回
    // 空き不足にした場合は paused(quota_budget)・送信 0
}
public function test_pause_during_send_keeps_paused_status(): void
{
    // B の前に status を paused(user) に変える（Http::fake のクロージャ内で DB を更新）→ 結果は done、status は paused のまま
}
public function test_paused_completed_and_needs_check_do_nothing(): void {}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=CreationRunnerTest`
Expected: FAIL（クラス未定義）

- [ ] **Step 3: 上記の手順で実装する**

決定・送信・記録は private メソッドに分ける（`decide(): ?PlannedCall`、`send(PlannedCall, OwnerLogin): CallOutcome`、`record(...)`）。`PlannedCall` と `CallOutcome` はこのファイル内の private な小さな値オブジェクトでよい。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/Creation tests
git commit -m "feat: 作成を 1 ステップ 1 書込で進める CreationRunner（position 指定・リースと照合で二重送信防止・予約の確定と解放・日付またぎの再予約）を追加"
```

---

### Task 7: 作成操作の JSON エンドポイント（ステップ・一時停止・再開・残りを中止・失敗分再試行・作り直し）

**Files:**
- Create: `app/Services/Creation/CreationLifecycle.php`, `app/Services/Creation/CreationBusy.php`, `app/Services/Creation/CreationProgress.php`
- Modify: `app/Http/Controllers/CreationController.php`, `app/Providers/AppServiceProvider.php`（`creation-step`）, `routes/web.php`
- Test: `tests/Feature/CreationEndpointsTest.php`（`#[Group('mysql')]`）

**Interfaces:**
- Consumes: `CreationRunner::{step,remainingUnitsNeeded}`, `StepResult`, `WriteLock::{ensure,release}`, `InsufficientWriteQuota`, `RequireOwnerSession::{loginRequiredJson}`, `OwnerSession::forget()`
- Produces:
  - `CreationLifecycle`（各メソッドは `ensureWriteRow()` → `DB::transaction` で creation を `lockForUpdate`）:
    - `pause(PlaylistCreation $c): void` — `pending`／`creating_playlist`／`adding` のときだけ `paused(user)`。他は何もしない。
    - `resume(PlaylistCreation $c): void` — `paused` のときだけ。`withWriteLock(ensure(remainingUnitsNeeded))`、不可なら `InsufficientWriteQuota`。戻り先 = `youtube_playlist_id ? adding : (playlist_attempted_at ? creating_playlist : pending)`、`pause_reason=null`。
    - `abort(PlaylistCreation $c): void` — `completed` 以外。60 秒以内のリース（item または playlist）があれば `CreationBusy`。`pending` と（リースが空または 60 秒超の）`in_flight` を `skipped`（後者は `last_error='unknown_result'`）、`completed`、`release`。
    - `retryFailed(PlaylistCreation $c): void` — `completed` かつ `youtube_playlist_id` あり かつ `failed` ≥ 1 のときだけ。`ensure(failed × 50)`、不可なら `InsufficientWriteQuota`。`failed` → `pending`（attempts 0・last_error null・leased null）、`adding`、`completed_at=null`。
    - `recreate(PlaylistCreation $c): void` — `needs_check` のときだけ。`pending`、`playlist_attempted_at=null`、`playlist_leased_at=null`、`playlist_attempts=0`。
  - `CreationProgress::toArray(PlaylistCreation $c, RemainingToday $r): array` — `{status, pause_reason, done, failed, skipped, total, playlist_url, message, remaining_today, items: [{position, status, error}]}`。`message` は状態の文言（下表）、`remaining_today` は `$r->sentence()`、`error` は `errorLabel(last_error)`。
  - `CreationProgress::errorLabel(?string $reason): ?string`: `videoNotFound` →「動画が見つかりません（削除または非公開）」、`forbidden`／`videoNotAvailable`／`invalidValue` →「この動画は追加できません」、`transient` →「YouTube との通信に失敗しました」、`unknown_result` →「追加されたか確認できませんでした（YouTube で確認してください）」、その他の非 null →「追加できませんでした」。
  - 状態の文言（`CreationProgress::message()`）:
    | 状態 | 文言 |
    |---|---|
    | paused/quota_exceeded | 「YouTube 側の本日の上限に達したため一時停止しました。リセット後に「続きから」を押してください（次にリセットされるのは 日本時間 {n月j日 G時} です）」 |
    | paused/quota_budget | 「本日の作成枠が足りないため一時停止しました。」＋`sentence()` |
    | paused/auth_expired | 「ログインの有効期限が切れたため一時停止しました。もう一度ログインして「続きから」を押してください」 |
    | paused/user | 「一時停止中です。「続きから」で再開できます」 |
    | needs_check | 「再生リストが作成されたか確認できませんでした。YouTube Studio で「{title}」が作成されていないか確認し、見つからなければ「見つからなかったので作り直す」を押してください」 |
    | completed（playlist あり） | 「作成が完了しました（追加 {done} 本・失敗 {failed} 本・中止 {skipped} 本）」 |
    | completed（playlist なし） | 「再生リストは作成されませんでした」 |
    | その他 | null |
  - `CreationController`（すべて `RequireOwnerSession` 通過後。`$proposal->creation` が無ければ 404 JSON。`$creation->owner_channel_id !== $owner->channelId` なら 403 JSON `RequireOwnerSession::MISMATCH`（6.4 の 3 か所目））:
    - `step(Request, CreationRunner, QuotaLedger, OwnerSession)`: `$result = $runner->step(...)`。`authExpired` → `$owners->forget()` して `loginRequiredJson()`。`redirect` → 200 `{redirect, message}`（creation は削除済み）。それ以外 → 200 `CreationProgress::toArray(fresh) + ['next_delay_ms' => $result->nextDelayMs, 'message' => $result->message ?? progress.message]`。
    - `pause`／`resume`／`abort`／`retry`／`recreate`: 成功 200 `CreationProgress::toArray(...)`。`InsufficientWriteQuota` → 409 `{message: shortageSentence}`（retry は `failed` 件数）。`CreationBusy` → 409 `{message: '処理中の動画があります。数秒待ってからもう一度押してください'}`。
  - ルート（すべて `[RequireSameOriginFetch::class, <throttle>, RequireOwnerSession::class]`）: step は `throttle:creation-step`、retry は `throttle:creation-start`、その他は制限なし（グローバル 120/分のみ）。
  - リミッタ `creation-step`: `Limit::perSecond(2)->by('creation-step:'.$request->session()->getId())`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_step_returns_progress_json(): void
{
    // 3 本、playlist insert 成功 → 200 JSON keys: status, pause_reason, done, failed, skipped, total, playlist_url, message, remaining_today, items, next_delay_ms
    // ->assertJson(['status' => 'adding', 'total' => 3, 'next_delay_ms' => 1000, 'playlist_url' => 'https://www.youtube.com/playlist?list=PLx'])
}
public function test_google_401_pauses_forgets_login_and_returns_401(): void
{
    // 401 → 401 {message: NOTICE}、セッションの owner 無し、creation は paused(auth_expired)、予約は active のまま
}
public function test_expired_session_gets_401_json_and_creation_is_untouched(): void {}
public function test_pause_then_resume_continues_from_same_position(): void {}
public function test_resume_after_day_change_rereserves_or_refuses_with_reset_time(): void
{
    // 翌 PT 日・空き十分 → 200、当日予約 = remainingUnitsNeeded。空き不足 → 409、message に「次にリセットされるのは」、status は paused のまま
}
public function test_abort_skips_pending_completes_and_releases(): void {}
public function test_abort_with_fresh_lease_is_409_busy(): void {}
public function test_retry_failed_reserves_and_requeues_only_failed(): void
{
    // completed・failed 1・done 2 → 200、failed→pending、予約 active 50、status adding。空き不足 → 409
}
public function test_recreate_only_from_needs_check(): void {}
public function test_invalid_input_redirect_payload(): void { /* 400 invalidValue → 200 {redirect: 'edit', message: REJECTED_INPUT}、creation 無し */ }
public function test_step_is_limited_to_2_per_second_per_session(): void { /* 3 回目 429 JSON */ }
public function test_other_channel_gets_403_json_and_nothing_is_sent(): void {}
public function test_progress_never_contains_token(): void {}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='CreationEndpointsTest|PagesTest'`
Expected: FAIL

- [ ] **Step 3: 上記シグネチャで実装する**

`PagesTest` の期待一覧に 6 本の POST URI を追加。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php tests
git commit -m "feat: 作成のステップ・一時停止・再開・残りを中止・失敗分再試行・作り直しの JSON エンドポイントと進捗 JSON を追加"
```

---

### Task 8: 進捗画面 S6（Blade・creation-runner.js・純関数）

**Files:**
- Create: `resources/views/owner/creation.blade.php`, `resources/js/creation-runner.js`, `resources/js/lib/creation-runner.js`, `tests/js/creation-runner.test.js`
- Modify: `app/Http/Controllers/CreationController.php`（`show`）, `routes/web.php`, `vite.config.js`
- Test: `tests/Feature/CreationScreenTest.php`, `tests/js/creation-runner.test.js`, `tests/Feature/ViteEntriesTest.php`（既存）

**Interfaces:**
- Consumes: `CreationProgress::toArray`, `QuotaLedger::remainingToday`, Task 7 の JSON
- Produces:
  - ルート `proposals.creation`（GET `/p/{key}/creation`、`[RequireDocumentNavigation::class, RequireOwnerSession::class]`）→ `CreationController::show`。creation が無ければ `redirect()->route('proposals.edit', $key)`。
  - ビュー変数: `proposal`, `creation`, `ownerChannelTitle`, `progress`（`CreationProgress::toArray`）, `runner`（`data-runner` に入れる JSON: `{progress, autostart: bool, urls: {step:'creation/step', pause:'creation/pause', resume:'creation/resume', abort:'creation/abort', retry:'creation/retry', recreate:'creation/recreate'}}`。`autostart` は `session('creation_autostart') === true`）
  - 画面の要素（JS なしでもサーバー描画で状態が読めること）: 見出し「再生リストの作成」＋再生リスト名、「ログイン中: {channel}」とログアウト（`partials.owner-logout`）、`<progress max=total value=処理済み>`＋「{処理済み} / {total} 本を処理しました（追加 {done}・失敗 {failed}・中止 {skipped}）」、状態文言（`role="status"`）、再生リストのリンク（`playlist_url` があるとき。チャンネル主本人のページなので公開範囲を問わず表示）、項目一覧（順番・タイトル・状態ラベル・エラー）、ボタン「一時停止」「続きから」「残りを中止」「失敗分を再試行（必要な枠: 動画 {m} 本）」「見つからなかったので作り直す」、`remaining_today`、再ログインのリンク「もう一度ログインする」（相対ではなく `window.location.pathname` の再読み込み。未ログインならプレビューへ送られ、ログイン後 S6 に戻る）、取り消し案内（Global Constraints の文言）、完了時「この提案を削除」（相対 `delete`）、「データ提供: YouTube」。noindex・no-store は既存の提案系ヘッダに従う。
  - `lib/creation-runner.js`（純関数。文言はサーバーの `message` を優先し、JS は代替文言だけ持つ）:
    - `TERMINAL = ['completed','paused','needs_check']`、`isTerminal(status): boolean`
    - `nextDelay(body): number`（`next_delay_ms` が 0〜60000 の整数ならそれ、それ以外 1000）
    - `processed(p): number`（done+failed+skipped）、`progressText(p): string`（上の文言）
    - `ITEM_LABELS = {pending:'待機中', in_flight:'追加中', done:'追加済み', failed:'失敗', skipped:'中止'}`、`itemLabel(status)`
    - `classify(httpStatus): 'ok'|'relogin'|'wait'|'gone'|'conflict'|'error'`（200→ok、401→relogin、429→wait、404→gone、409→conflict、他→error）
    - `networkDelay(n): number|null`（n 回目の通信失敗: 2000×2^(n−1)、n > 5 で null＝止めて「続きから」を出す）
    - `buttonsFor(p): {pause, resume, abort, retry, recreate}`（pause: pending/creating_playlist/adding、resume: paused、abort: completed 以外、retry: completed かつ failed ≥ 1 かつ playlist_url あり、recreate: needs_check）
    - 代替文言 `NETWORK_FAILED = '通信に失敗しました。「続きから」で再開できます'`、`PAGE_EXPIRED = 'ページの有効期限が切れました。ページを再読み込みしてください'`
  - `creation-runner.js`（Alpine `creationRunner`）: `autostart` か「続きから」でループ開始（`paused` なら先に resume を POST）。ステップの応答ごとに進捗を差し替え、`isTerminal` なら止める。`relogin` → ループ停止・再ログインのリンクを表示。`wait` → 1000ms 後に同じステップ。`redirect` を含む応答（Task 6 の 400 で作成記録が消えた場合）→ `message` を状態欄に出し、3 秒後に `location.assign(redirect)`（相対 `edit`。編集画面の下書きは sessionStorage に残っているので再編集できる）。419 → `PAGE_EXPIRED` で停止。fetch は `credentials: 'same-origin'` と Global Constraints のヘッダ。

- [ ] **Step 1: 失敗するテストを書く**

```php
// CreationScreenTest
public function test_shows_progress_items_and_buttons_for_owner(): void
{
    // adding・done 1/3 → 200、'1 / 3 本を処理しました（追加 1・失敗 0・中止 0）'、data-runner の JSON に urls.step === 'creation/step'、
    // 生キーが HTML に無い（assertDontSee($key)）、assertNoInlineCode、トークン無し
}
public function test_without_creation_redirects_to_edit(): void {}
public function test_not_logged_in_redirects_to_preview_and_mismatch_is_403(): void {}
public function test_autostart_flag_only_right_after_start(): void { /* flash あり → true、再読み込み → false */ }
public function test_completed_shows_playlist_link_for_owner_even_when_private(): void {}
public function test_unlisted_item_titles_are_shown_only_here(): void { /* S6 に出る。S4 には出ない（Task 9 でも確認） */ }
public function test_paused_quota_shows_remaining_and_reset_time(): void {}
```

```js
// tests/js/creation-runner.test.js
test('nextDelay clamps and defaults', () => {
  assert.equal(nextDelay({ next_delay_ms: 4000 }), 4000);
  assert.equal(nextDelay({ next_delay_ms: -1 }), 1000);
  assert.equal(nextDelay({ next_delay_ms: 600000 }), 1000);
  assert.equal(nextDelay(null), 1000);
});
test('progressText', () => {
  assert.equal(progressText({ done: 2, failed: 1, skipped: 0, total: 5 }), '3 / 5 本を処理しました（追加 2・失敗 1・中止 0）');
});
test('classify', () => { assert.equal(classify(401), 'relogin'); assert.equal(classify(429), 'wait'); assert.equal(classify(500), 'error'); });
test('networkDelay stops after 5', () => { assert.deepEqual([1,2,3,4,5,6].map(networkDelay), [2000,4000,8000,16000,32000,null]); });
test('buttonsFor', () => { /* completed・failed 1・playlist_url → retry true・pause false … */ });
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='CreationScreenTest|ViteEntriesTest|PagesTest' && npm run test:js`
Expected: FAIL

- [ ] **Step 3: 実装する**

`vite.config.js` の `input` に `resources/js/creation-runner.js` を追加。Blade の Alpine 式は既存どおりプロパティ参照と引数なしメソッドのみ（行ごとのラベルは `itemLabelText()` のような引数なしメソッドで `this.item` を読む）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test && npm run test:js && npm run build`
Expected: PASS、ビルド成功

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources vite.config.js tests
git commit -m "feat: 進捗画面 S6 とブラウザ駆動のステップ実行（一時停止・続きから・残りを中止・失敗分再試行・作り直し・再ログイン案内）を追加"
```

---

### Task 9: 作成ボタンの本実装・戻り先・S5→S6・プレビューの作成状態とリンク（7.6）

**Files:**
- Modify: `resources/js/owner-edit.js`, `resources/js/lib/owner-edit.js`, `resources/views/owner/edit.blade.php`, `app/Http/Controllers/OwnerEditController.php`, `app/Http/Controllers/OwnerAuthController.php`, `app/Http/Controllers/ProposalController.php`（`show`）, `resources/views/proposals/show.blade.php`, `resources/views/partials/owner-login.blade.php`
- Test: `tests/Feature/OwnerEditScreenTest.php`, `tests/Feature/OwnerCallbackLoginTest.php`, `tests/Feature/PreviewScreenTest.php`, `tests/js/owner-edit.test.js`

**Interfaces:**
- Consumes: `proposals.creation.start`（相対 `creation`）、開始 API の応答（Task 5）、`PlaylistCreation::playlistUrl()`
- Produces:
  - `OwnerEditController::show`: `$proposal->creation` があれば `redirect()->route('proposals.creation', $key)`（7.5）。`editor` に `'creationUrl' => 'creation'` を追加（`test_editor_payload_has_fixed_keys_and_no_secrets` の期待キーも更新）。
  - `OwnerAuthController::callback`: 成功時の戻り先を `Proposal::findLiveOrAbort($key)->creation()->exists() ? 'proposals.creation' : 'proposals.edit'`（6.2・7.4）。
  - `lib/owner-edit.js` に追加:
    - `creationRequestBody({items, title, description, privacy}) → {video_ids, title, description, privacy}`（`privacy` は `normalizePrivacy`）
    - `startFailure(httpStatus, body) → {message, relogin: boolean, unavailableIds: string[]}`（401 → `relogin: true`・body.message、419 → `PAGE_EXPIRED`、422/409/429/503 → body.message または `START_FAILED`、`unavailable_ids` は 11 文字 ID だけ）
    - `markUnavailable(items, ids) → items`（該当に `unavailable: true`、他は外す）
    - 定数 `START_FAILED = '作成を開始できませんでした。時間をおいて再度お試しください'`、`PAGE_EXPIRED = 'ページの有効期限が切れました。ページを再読み込みしてください（編集内容は残っています）'`
  - `owner-edit.js::onCreate`: 送信中はボタン無効。`fetch(creationUrl, {method:'POST', body: JSON, headers: {..., 'Content-Type':'application/json', 'X-CSRF-TOKEN'}})`。200/201 で `redirect` があれば `clearDraft` → `location.assign(redirect)`。失敗は `startFailure` の結果を `createMessage` に出し、`relogin` なら `needsRelogin`、`unavailableIds` は `markUnavailable`（行に「削除または非公開」を表示）。
  - `edit.blade.php`: 「作成機能は準備中で、現在は押しても作成されません」を削除し「押すとあなたの YouTube チャンネルに再生リストが作成されます（書き込み操作）」にする。行に `x-show="item.unavailable"` の「削除または非公開のため作成できません」。`createMessage` は `role="alert"`。
  - `ProposalController::show` のビュー変数を追加: `creationStatus`（`null`＝未作成／`'creating'`／`'created'`／`'not_created'`）と `playlistUrl`（`created` かつ（`privacy_status === 'public'` または `ownerMatches`）のときだけ非 null）。
  - `show.blade.php`: 「状態: 未作成／作成中／作成済み／再生リストは作成されませんでした」。`playlistUrl` があれば「作成された再生リストを YouTube で見る」リンク（`rel="noopener noreferrer" target="_blank"`）。本日の残りの節は未作成のときだけ。
  - `partials/owner-login.blade.php`: 一致するチャンネル主で creation があれば「作成状況を見る」（`route('proposals.creation', $key)`）、無ければ従来の「編集画面へ進む」。

- [ ] **Step 1: 失敗するテストを書く**

```php
// PreviewScreenTest
public function test_status_and_link_for_public_playlist_are_shown_to_anyone(): void { /* completed・public・PLpub → 未ログインで href 'https://www.youtube.com/playlist?list=PLpub' */ }
public function test_unlisted_or_private_playlist_link_only_for_matching_owner(): void
{
    // unlisted → 未ログイン: '作成済み' はあるが 'PLunl' が HTML のどこにも無い。別チャンネル: 無い。該当チャンネル主: リンクあり
}
public function test_creating_status_hides_remaining_and_owner_sees_progress_link(): void {}
public function test_owner_unlisted_videos_never_appear_on_preview(): void { /* creation_items の限定公開動画のタイトル・ID が S4 に無い */ }

// OwnerCallbackLoginTest
public function test_callback_returns_to_creation_page_when_creation_exists(): void {}

// OwnerEditScreenTest
public function test_edit_redirects_to_creation_once_started(): void {}
public function test_create_button_text_no_longer_says_pending(): void { /* '準備中' が無い、'creation' が data-editor にある */ }
```

```js
// owner-edit.test.js
test('creationRequestBody', () => {
  assert.deepEqual(creationRequestBody({ items: [{ id: 'aaaaaaaaaaa' }, { id: 'bbbbbbbbbbb' }], title: 't', description: '', privacy: 'x' }),
    { video_ids: ['aaaaaaaaaaa', 'bbbbbbbbbbb'], title: 't', description: '', privacy: 'private' });
});
test('startFailure', () => {
  assert.deepEqual(startFailure(401, { message: 'm' }), { message: 'm', relogin: true, unavailableIds: [] });
  assert.equal(startFailure(419, null).message, PAGE_EXPIRED);
  assert.deepEqual(startFailure(422, { message: 'x', unavailable_ids: ['aaaaaaaaaaa', 'bad'] }).unavailableIds, ['aaaaaaaaaaa']);
  assert.equal(startFailure(500, null).message, START_FAILED);
});
test('markUnavailable', () => { /* 該当だけ unavailable: true */ });
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='PreviewScreenTest|OwnerCallbackLoginTest|OwnerEditScreenTest' && npm run test:js`
Expected: FAIL

- [ ] **Step 3: 上記のとおり実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test && npm run test:js && npm run build`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app resources tests
git commit -m "feat: 作成ボタンを本実装し、作成後は S6 へ（編集画面・ログイン後の戻り先）、プレビューに作成状態と公開範囲に応じたリンクを表示する"
```

---

### Task 10: 作成中の提案の削除（U14）と仕様書への記録

**Files:**
- Modify: `app/Http/Controllers/OwnerDeleteController.php`, `resources/views/owner/delete-confirm.blade.php`, `resources/views/owner/creation.blade.php`（完了時の削除リンク）, `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`
- Test: `tests/Feature/OwnerDeleteProposalTest.php`

**Interfaces:**
- Consumes: `PlaylistCreation::COMPLETED`
- Produces:
  - U14 の扱い（案 A。**監督者の確認後に確定**。下の「判断が必要な点」）: creation があり `status !== completed` のとき、
    - `confirm`: 200 で確認ページに「作成中の提案は削除できません。作成状況の画面で「残りを中止」してから削除してください」と相対リンク `creation`（「作成状況を見る」）を出し、削除フォームを出さない。
    - `destroy`: 何も削除せず `redirect()->route('proposals.creation', $key)->with('creation_notice', 同文)`（S6 は `creation_notice` を `role="alert"` で表示）。
  - creation が `completed` のとき: 確認ページに「作成済みの再生リストは YouTube に残ります（この提案と作成の記録だけが削除されます）」を追加。`destroy` は 1 トランザクションで `quota_reservations`→`playlist_creation_items`→`playlist_creations`→`proposal_items`→`proposals` を削除。
  - 確認ページの「削除せずに戻る」リンクは、creation があれば相対 `creation`、無ければ従来の `edit`。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_in_progress_creation_blocks_delete_and_points_to_progress(): void
{
    // adding の creation → GET delete: 200、文言あり、<form> 無し、href="creation"
    // POST deletion: proposals.creation へリダイレクト・creation_notice、提案・creation・予約が残る
}
public function test_paused_or_needs_check_also_blocks_delete(): void {}
public function test_completed_creation_is_deleted_with_proposal(): void
{
    // completed → POST → 5 テーブルとも 0 件、/deleted へ。確認ページに「YouTube に残ります」
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=OwnerDeleteProposalTest`
Expected: FAIL

- [ ] **Step 3: 実装し、仕様書を更新する**

仕様書の更新（文言は本計画のとおり。監督者の判断が変われば合わせる）:
- 12.3 U14 を「（決定 → Y19）」に、12.1 に Y19「作成中（completed 以外）の提案は削除できない。S6 で残りを中止してから削除する。作成完了後は提案と作成の記録を一緒に削除し、YouTube の再生リストは残る」。2.3 の 7 と 4.3 の末尾に同じ旨。
- 4.2 playlist_creations に `playlist_leased_at`・`playlist_attempts` を追記。7.3 に「行ロックは状態の決定と記録に使い、YouTube 呼び出し中は持たない。同時実行は 60 秒のリース（items.leased_at／creations.playlist_leased_at）で 1 つに絞る。接続エラー（応答なし）は結果不明として次のステップで照合する」。
- 5.4／5.7 の「playlists.insert の恒久エラー」に「400（名前・説明の不正）は作成記録を削除して編集画面へ戻す。その他の恒久エラーは completed＋全項目 skipped」。
- 7.4 の表の「401／セッション期限切れ」に「セッション期限切れ（アプリ側）は未認証の要求で状態を変えないため paused にはしない。Google の 401 だけ paused(auth_expired)」。
- 11 章 Phase 3 の［要確認］結果欄は人手確認の後に書く（下の「人手で行う確認」）。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test && npm run test:js`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app resources docs tests
git commit -m "feat: 作成中の提案は削除させず S6 へ案内し、作成完了後は作成の記録ごと削除する（U14）。仕様書に Phase 3 の判断を記録"
```

---

## 最終確認（PR 前）

- [ ] `php artisan test`、`./vendor/bin/pint --test`、`npm run test:js`、`npm run build`、`! grep -l "new Function" public/build/assets/*.js` がすべて成功。CI の `php-mysql` ジョブが緑。
- [ ] `grep -rn '{!!' resources/views`・`grep -rn 'innerHTML\|x-html' resources/js` が 0 件。`grep -rn 'withoutVerifying' app` が 0 件。`grep -rn 'Log::' app/Services/Creation app/Http/Controllers/CreationController.php` の context にトークン・タイトル・動画 ID・生キーが無いことを目視（creation_id・件数・単位・reason のみ）。
- [ ] `OwnerSecurityTest::test_no_token_in_any_table_cache_or_log` が新テーブルを含めて通る（全テーブル走査）。
- [ ] 最終レビュー（`superpowers:requesting-code-review`）に、本計画の「Review Focus」5 項目と spec 5.4・7 章を渡す。
- [ ] `git push -u origin phase3-creation` → PR 作成（本文に Issue #20 の MySQL CI の項目を閉じる旨、末尾に指定の署名行）。

## 人手で行う確認（実 API・実ブラウザ。11 章 Phase 3 の完了条件）

1. **3 本の作成**（元の指示書 6-6）: 自分のチャンネル宛ての 3 本の提案を、公開範囲「非公開」で作成。S6 が自動で進み、YouTube Studio で 3 本が提案の順に入っていること。`quota_usage` の write が 200、owner_read が 最終確認 1＋ログイン 1 程度であること。
2. **20 本の作成**: 20 本・「限定公開」で作成（自分の限定公開動画を 1 本含める）。完走し、順序が保たれること。S4 を別ブラウザ（未ログイン）で開いて「作成済み」だけでリンクが出ないこと、ログイン中の自分には出ること。
3. **失敗分再試行の並び順**: 20 本のうち 1 本を作成直前に非公開にしておき（または存在しない ID を混ぜる手段が無ければ、作成途中で 1 本を非公開化）、`failed` になったあと公開に戻して「失敗分を再試行」→ 元の位置（`snippet.position`）に入ること。spec 7.3「position 指定に手動並び替え設定が必要か」を記録（API で作った直後の再生リストの並び替え設定を Studio で確認）。
4. **連続 insert の 409**: 1 秒間隔で 409 が出るか、出たらバックオフで吸収できたかをログ（`youtube owner api error` の status/reason）で確認し、spec 5.7・U8 に追記。
5. **`<` `>` を含む名前・説明**: 「a<b>c」を名前・説明に入れて作成し、受け付けられるか（400 なら Task 6 の経路で編集画面に戻ること）。spec 8.2・U8 に追記し、拒否されるならフォームで弾く追加対応を Issue 化。
6. **タブを閉じて再開**: 20 本の途中でタブを閉じ、S6 を開き直して「続きから」で続くこと。別タブ 2 つで同時に「続きから」を押しても重複が出ないこと。
7. **ログイン切れ**: 作成途中で `https://myaccount.google.com/permissions` から承認を取り消し、S6 が「もう一度ログインする」を出すこと → ログイン後 S6 に戻り「続きから」で再開できること。
8. **playlists.list の並び**: 照合（Task 6）が前提とする「`mine=true` の 1 ページ目に作ったばかりの再生リストが入るか」を、再生リストが 50 を超えるチャンネルで確認（入らなければ `needs_check` になるだけだが、頻発するなら照合方法を見直す）。
9. **本番 MySQL（CORESERVER）**: `migrate --force` 後に `SHOW FULL COLUMNS FROM playlist_creation_items` で `video_id` が `utf8mb4_bin` であること。MySQL のバージョンを記録（CI は 8.4）。
10. **S6・S5 の画面操作（Alpine 部分は自動テストが無い）**: 作成ボタン → S6 自動開始、一時停止／続きから、残りを中止、失敗分再試行、枠不足時のリセット時刻表示、作り直しボタン（`needs_check` は DB を直接書き換えて表示確認）、コンソールに CSP 違反・JS エラーが無いこと。
11. 結果を spec の 5.7・7.3・8.2・12.3 U8 と 11 章 Phase 3 に追記し、Issue #13（U8）を更新する。

**今回は対応しない（記録のみ）**: 自分の限定公開動画が作成の最終確認後に非公開化された場合は item の恒久エラーとして `failed` で扱う（別途の検出はしない）。作成途中に YouTube 側で並びを手で変えた場合の順序は保証しない（spec 7.3）。放置された作成の予約は PT の日付が変わるまで保持（Y15）。
