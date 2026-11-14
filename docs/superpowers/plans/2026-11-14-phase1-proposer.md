# Phase 1 提案者（List Maker）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 提案者がログインなしでチャンネルの公開動画（＋URL 指定の他チャンネル動画）から再生リスト案を作って保存し、共有 URL（`/p/{key}`）でプレビューできるようにする。一覧は差分取り込み＋チャンネル単位 30 日期限のキャッシュ、読取は 1 日 2,000 ユニットの予算内に収める。

**Architecture:** YouTube Data API は `YouTubeReader`（API キー・`X-Goog-Api-Key` ヘッダ・Laravel HTTP クライアント）だけが呼び、送信前に必ず `QuotaLedger` で読取予算を消費する。チャンネル一覧は `ChannelVideoCatalog` が `channel_caches`／`channel_cache_videos` に差分取り込みし、全ページ読み終えてから 1 トランザクションで書く。画面は Blade（サーバー描画）＋ページ別の JS エントリ（`@alpinejs/csp` の `Alpine.data` コンポーネント）で、ロジックは `resources/js/lib/*.js` の純関数にして `node --test` で検査する。動画数上限は `QuotaPolicy::maxItemsPerPlaylist()` の 1 か所だけで導出する。

**Tech Stack:** PHP 8.4 / Laravel 13 / PHPUnit（SQLite in-memory、`Http::fake()`）/ Vite 8 + Tailwind 4 / `@alpinejs/csp` 3.17 / Node 22（`node --test`）

**Spec:** `docs/superpowers/specs/2026-11-14-playlist-proposal-design.md`（2.2・2.5 S1〜S4・3.1・4.2・4.3・5.1〜5.6・5.7・8.1〜8.4・8.8・9.1・9.3・10.3・10.5・11 章 Phase 1）

**Branch:** `phase1-proposer`（`main` から切る。全タスクを 1 PR にまとめる）

## Global Constraints

- Phase 0 の制約はすべて継続: Blade は `{{ }}` のみ（`{!! !!}` 禁止）、インライン `<script>`（`src` 無し）／`<style>`／`style=`／`on*=` 属性を書かない、Alpine は `@alpinejs/csp` のみ、新しい JS エントリは必ず `vite.config.js` の `input` に追加、外部 CDN・解析タグなし。
- サーバー→JS のデータ受け渡しは要素の `data-*` 属性（`data-catalog="{{ json_encode($payload, JSON_UNESCAPED_UNICODE) }}"` 等）で行う。`<script type="application/json">` も使わない（既存の `assertNoInlineCode` が `src` 無しの `<script>` を禁止している）。
- Alpine CSP ビルドで動く式だけを書く: Blade 側の式は「プロパティ参照」「引数なしメソッド名」に限る。`x-for` のループ変数はメソッド内で `this.<変数名>` として参照する。JS 側は `textContent`／`x-text` のみ（`innerHTML`・`x-html` 禁止）。
- 各ページ用 JS エントリは `import Alpine from '@alpinejs/csp'` して `Alpine.data()` を登録するだけ。`Alpine.start()` は `resources/js/app.js` が `DOMContentLoaded` で 1 回だけ呼ぶ（Task 12 で変更）。
- YouTube API: ベース URL は定数 `https://www.googleapis.com/youtube/v3/`、API キーは `X-Goog-Api-Key` ヘッダ（クエリに載せない）、接続 5 秒・全体 15 秒タイムアウト。`search.list` は使わない。ログには endpoint・HTTP ステータス・reason のみ（URL・キー・本文を出さない）。
- テストは実 API を呼ばない: `Tests\TestCase::setUp()` で `Http::preventStrayRequests()`（Task 4）。時刻は `$this->travelTo()`。
- 読取の区分: Phase 1 の API 呼び出しはすべて `read`（匿名側・予算 `config('youtube.quota.read_budget')`＝2,000）。日付は太平洋時間（`America/Los_Angeles`）。
- 動画数上限: `QuotaPolicy::maxItemsPerPlaylist()` = `floor((QUOTA_WRITE_LIMIT − 50) / 50)`（既定 99）。FormRequest・Blade・JS（`data-*` で受け取る）はこの値だけを使い、JS 側で式を持たない。
- 保持期間: `config('listmaker.retention_days')`（30）。提案は `created_at + 30 日`、一覧キャッシュは `cached_since + 30 日`、期限切れ記録は `expired_at + 30 日`、`quota_usage` は 90 日。期限判定は `expires_at <= now()` を「期限切れ」とする（表示・取り込み・バッチで統一）。
- 日時は DB に UTC で保存し、表示は `Asia/Tokyo`。モデルの日時キャストは `immutable_datetime`（Blade で `->timezone()` しても元を書き換えない）。表示形式: 日付 `n月j日`、日時 `n月j日 G時i分`、リセット時刻 `n月j日 G時`。
- 公開キー: `random_bytes(32)` の base64url（パディング無し 43 文字、`[A-Za-z0-9_-]{43}`）。DB には `hash('sha256', $key)`（hex 64 文字）のみ。生キーはログ・DB に出さない。
- 画面文言（spec そのまま）:
  - 「1 つの再生リストに入れられるのは最大 {N} 本」
  - 「本日の残り: 再生リスト 1 つ＋動画あと {N} 本まで作成できます」／「本日はこれ以上作成できません。次にリセットされるのは 日本時間 {n月j日 G時} です」
  - S2 注記「作成はチャンネル主が操作した時点の残りで決まります」
  - 「この一覧は {n月j日}に取り込み、{n月j日}に削除されます（削除後に開くと全件を取り込み直します）」
  - 「前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています」
  - 「一覧の最終取り込み: {n月j日 G時i分}」＋予算切れ時「（本日の取得上限に達したため更新していません）」
  - 「本日の取得上限に達しました」＋（記録あり時）「前回の一覧は 30 日の保存期限で削除されました」
  - 「最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください」、`truncated` 時「これより古い動画は動画 URL で追加してください」
  - 「この動画は削除または非公開になっています。外して保存してください」
  - 「対象チャンネル外」（バッジ）、「データ提供: YouTube」
- 新規ルート名（固定。Phase 2 以降が参照）: `channels.open`（GET `/channels`）、`channels.propose`（GET `/channels/{channelId}/propose`）、`channels.video`（GET `/channels/{channelId}/videos/lookup`）、`proposals.store`（POST `/channels/{channelId}/proposals`）、`proposals.shared`（GET `/p/{key}/shared`）、`proposals.show`（GET `/p/{key}`）。`{channelId}` は `UC[A-Za-z0-9_-]{22}`、`{key}` は `[A-Za-z0-9_-]{43}` の制約付き。
- ルートを追加するタスクは `tests/Feature/PagesTest.php::test_only_spec_routes_are_registered` の期待一覧も更新する。
- コミット前に毎回 `./vendor/bin/pint` と `php artisan test` を通す。

## Review Focus

1. **アップロードが 0 本のチャンネル**: uploads プレイリストに対する `playlistItems.list` は 404 `playlistNotFound` を返しうる。エラーではなく「0 本の一覧」として取り込み完了（`Imported`）にすべき。→ Task 4 `test_uploads_page_treats_playlist_not_found_as_empty`、Task 5 `test_channel_without_uploads_imports_empty_list`。
2. **ハンドル入力の揺れ**: 大文字の `@Handle`、日本語ハンドルのパーセントエンコード URL（`/@%E3%81%82…`）、末尾に `/videos` やクエリが付いた URL、`m.youtube.com`。同じチャンネルに解決されるべき。→ Task 3 `test_channel_input_accepts_handle_variants`。
3. **文字数カウンタとサーバー検証のずれ**: 絵文字（サロゲートペア）や改行（ブラウザは CRLF で送る）で JS のカウンタが「入る」と言ったのにサーバーが拒否する。改行は LF に正規化してから数え、両側ともコードポイント数で数えるべき。→ Task 11 `countChars` のテスト、Task 13 `test_description_crlf_counts_as_one_char_and_emoji_title_at_limit_passes`。
4. **壊れた／使えない localStorage と古い選択**: プライベートモードで例外、JSON 破損、一覧から消えた動画 ID、上限超えの保存値。画面が壊れず、一覧にある動画は最新値に置き換え、URL 追加分は保持し、上限で切るべき。→ Task 11 `restoreSelection`・`storage` のテスト。
5. **読取予算の日付境界**: 予算は日本時間 0 時ではなく太平洋時間 0 時（夏時間 16 時・標準時間 17 時 JST）に戻るべき。→ Task 2 `test_read_budget_resets_at_pacific_midnight`。

---

## File Structure

| ファイル | 責務 |
|---|---|
| `app/Support/QuotaClock.php` | PT 日付と次回リセット時刻（JST）の計算 |
| `app/Support/QuotaPolicy.php` | ユニット単価、動画数上限の導出（唯一の式）、空き→本数換算、起動時の設定検証 |
| `app/Support/RemainingToday.php` | 「本日の残り」の値オブジェクト |
| `app/Support/ChannelInput.php`, `ChannelRef.php`, `VideoUrl.php`, `ProposalKey.php`, `IsoDuration.php`, `DurationFormat.php` | 入力解析・キー・時間表記の純関数 |
| `app/Support/ChannelFetchThrottle.php` | キャッシュ無し時だけ消費するチャンネル取得のレート制限 |
| `app/Services/YouTube/QuotaLedger.php` | `quota_usage` の記録・読取予算の判定・書込の空き |
| `app/Services/YouTube/YouTubeReader.php` ＋ DTO（`ChannelInfo`, `PlaylistItem`, `PlaylistItemsPage`, `VideoInfo`）、`YouTubeError.php`, `YouTubeErrorKind.php`, `YouTubeApiException.php`, `ReadBudgetExceeded.php` | API キーでの読取とエラー分類 |
| `app/Services/ChannelVideoCatalog.php`, `CatalogResult.php`, `CatalogStatus.php` | 一覧キャッシュ（6 時間再利用・全件／差分取り込み・チャンネル単位の期限切れ） |
| `app/Services/ProposalCreator.php`, `CreatedProposal.php`, `UnavailableVideos.php`, `ChannelCacheMissing.php` | 提案保存（再取得・スナップショット・キー発行） |
| `app/Models/{ChannelCache,ChannelCacheVideo,ChannelCacheExpiration,Proposal,ProposalItem}.php` | Eloquent モデル |
| `app/Http/Controllers/ChannelController.php` | `open`（入力→チャンネル ID 解決）、`video`（URL 追加 API） |
| `app/Http/Controllers/ProposalController.php` | `create`（S2）、`store`、`shared`（S3）、`show`（S4） |
| `app/Http/Requests/StoreProposalRequest.php` | 提案保存の入力上限・正規化 |
| `app/Http/Middleware/LimitRequestBody.php` | リクエストボディ 64KB 上限 |
| `resources/views/proposals/{create,catalog-unavailable,shared,show}.blade.php`, `partials/{remaining-today,max-items}.blade.php`, `errors/{404,429}.blade.php` | 画面 |
| `resources/js/lib/{selection,filter,storage,text}.js` | 純関数（`tests/js/*.test.js`） |
| `resources/js/{propose,proposal-shared,home}.js` | ページ別エントリ |
| `tests/Support/FakesYouTube.php`, `tests/Support/AssertsNoInlineCode.php` | テスト補助 |

---

### Task 1: QuotaClock と QuotaPolicy（起動時検証）

**Files:**
- Create: `app/Support/QuotaClock.php`, `app/Support/QuotaPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/QuotaClockTest.php`, `tests/Unit/QuotaPolicyTest.php`（どちらも `Tests\TestCase` を継承）

**Interfaces:**
- Produces:
  - `QuotaClock::TIMEZONE = 'America/Los_Angeles'`, `QuotaClock::DISPLAY_TIMEZONE = 'Asia/Tokyo'`
  - `QuotaClock::todayPt(): string`（`Y-m-d`）
  - `QuotaClock::nextResetJst(): CarbonImmutable`（次の PT 0 時を `Asia/Tokyo` で返す。固定オフセットを持たずタイムゾーン DB で計算）
  - `QuotaPolicy::PLAYLIST_UNITS = 50`, `QuotaPolicy::ITEM_UNITS = 50`
  - `QuotaPolicy::maxItemsPerPlaylist(): int`、`QuotaPolicy::itemsAffordable(int $freeUnits): int`（`max(0, floor(($free − 50) / 50))`）、`QuotaPolicy::validateConfig(): void`（違反で `InvalidArgumentException`）

- [ ] **Step 1: ブランチを作る**

Run: `git switch main && git pull && git switch -c phase1-proposer`

- [ ] **Step 2: 失敗するテストを書く**

```php
// QuotaClockTest
public function test_reset_is_16_jst_during_daylight_time(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-07-01 12:00', 'Asia/Tokyo'));
    $this->assertSame('2026-07-01 16:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
    $this->assertSame('Asia/Tokyo', QuotaClock::nextResetJst()->timezoneName);
}
public function test_reset_is_17_jst_during_standard_time(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-12-01 18:00', 'Asia/Tokyo'));
    $this->assertSame('2026-12-02 17:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
}
public function test_reset_across_dst_end(): void // 2026-11-01 に夏時間終了
{
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Tokyo'));
    $this->assertSame('2026-11-01 16:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
    $this->travelTo(CarbonImmutable::parse('2026-11-01 17:00', 'Asia/Tokyo'));
    $this->assertSame('2026-11-02 17:00', QuotaClock::nextResetJst()->format('Y-m-d H:i'));
}
public function test_today_pt_changes_at_pacific_midnight(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-01 15:59', 'Asia/Tokyo'));
    $this->assertSame('2026-10-31', QuotaClock::todayPt());
    $this->travelTo(CarbonImmutable::parse('2026-11-01 16:00', 'Asia/Tokyo'));
    $this->assertSame('2026-11-01', QuotaClock::todayPt());
}

// QuotaPolicyTest
#[DataProvider('limits')] // [5000, 99], [1000, 19], [100, 1]
public function test_max_items_is_derived_from_write_limit(int $limit, int $expected): void
{
    config(['youtube.quota.write_limit' => $limit]);
    $this->assertSame($expected, QuotaPolicy::maxItemsPerPlaylist());
}
public function test_items_affordable(): void
{
    $this->assertSame(1, QuotaPolicy::itemsAffordable(149));
    $this->assertSame(0, QuotaPolicy::itemsAffordable(99));
    $this->assertSame(0, QuotaPolicy::itemsAffordable(0));
    $this->assertSame(0, QuotaPolicy::itemsAffordable(-50));
    $this->assertSame(99, QuotaPolicy::itemsAffordable(5000));
}
public function test_validate_rejects_write_limit_below_100(): void
{
    config(['youtube.quota.write_limit' => 99]);
    $this->expectException(InvalidArgumentException::class);
    QuotaPolicy::validateConfig();
}
public function test_validate_rejects_limits_exceeding_google_daily(): void
{
    config(['youtube.quota.write_limit' => 9000, 'youtube.quota.read_budget' => 2000, 'youtube.quota.google_daily' => 10000]);
    $this->expectException(InvalidArgumentException::class);
    QuotaPolicy::validateConfig();
}
public function test_default_config_is_valid(): void
{
    QuotaPolicy::validateConfig();
    $this->addToAssertionCount(1);
}
```

- [ ] **Step 3: テストが失敗することを確認**

Run: `php artisan test --filter='QuotaClockTest|QuotaPolicyTest'`
Expected: FAIL（`Class "App\Support\QuotaClock" not found`）

- [ ] **Step 4: 実装する**

`QuotaClock`・`QuotaPolicy` を上記シグネチャで実装（`final class`、static メソッド、`now()` を使い `travelTo` が効くこと）。`nextResetJst()` は `now()->setTimezone(TIMEZONE)->startOfDay()->addDay()->setTimezone(DISPLAY_TIMEZONE)`（`CarbonImmutable`）。`AppServiceProvider::boot()` の先頭で `QuotaPolicy::validateConfig()` を呼ぶ。

- [ ] **Step 5: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（既存テスト含め全件）

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add app/Support app/Providers/AppServiceProvider.php tests/Unit
git commit -m "feat: QuotaClock と QuotaPolicy（動画数上限の導出・起動時検証）を追加"
```

---

### Task 2: quota_usage と QuotaLedger（読取予算・本日の残り）

**Files:**
- Create: `database/migrations/2026_11_14_000100_create_quota_usage_table.php`, `app/Services/YouTube/QuotaLedger.php`, `app/Support/RemainingToday.php`, `resources/views/partials/remaining-today.blade.php`, `resources/views/partials/max-items.blade.php`
- Test: `tests/Feature/QuotaLedgerTest.php`, `tests/Feature/RemainingTodayViewTest.php`

**Interfaces:**
- Consumes: `QuotaClock::todayPt()`, `QuotaClock::nextResetJst()`, `QuotaPolicy::itemsAffordable()`, `QuotaPolicy::maxItemsPerPlaylist()`
- Produces:
  - テーブル `quota_usage`: `day_pt` date、`bucket` enum(`read`,`owner_read`,`write`)、`units` unsignedInteger default 0、PK(`day_pt`,`bucket`)。タイムスタンプ無し。
  - `QuotaLedger::READ = 'read'`, `OWNER_READ = 'owner_read'`, `WRITE = 'write'`
  - `QuotaLedger::used(string $bucket, ?string $dayPt = null): int`
  - `QuotaLedger::record(string $bucket, int $units): void`（上限判定なしで加算）
  - `QuotaLedger::tryConsumeRead(int $units): bool`（予算内なら加算して true、超えるなら加算せず false）
  - `QuotaLedger::readRemaining(): int`
  - `QuotaLedger::writeFreeUnits(): int`（`write_limit − used(WRITE)`。Phase 3 でここに「当日 active 予約の reserved − consumed」の差し引きを追加する。呼び出し側は変えない）
  - `QuotaLedger::remainingToday(): RemainingToday`
  - `RemainingToday(public readonly int $items, public readonly CarbonImmutable $resetAtJst)`、`exhausted(): bool`（`items < 1`）、`covers(int $n): bool`（`n <= items`）
  - Blade `@include('partials.remaining-today', ['remaining' => RemainingToday, 'itemCount' => ?int, 'note' => ?string])`、`@include('partials.max-items')`

- [ ] **Step 1: 失敗するテストを書く**

```php
// QuotaLedgerTest (RefreshDatabase)
public function test_record_accumulates_per_pt_day_and_bucket(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Asia/Tokyo'));
    $ledger = app(QuotaLedger::class);
    $ledger->record(QuotaLedger::WRITE, 50);
    $ledger->record(QuotaLedger::WRITE, 50);
    $ledger->record(QuotaLedger::OWNER_READ, 1);
    $this->assertSame(100, $ledger->used(QuotaLedger::WRITE));
    $this->assertSame(1, $ledger->used(QuotaLedger::OWNER_READ));
    $this->assertSame(0, $ledger->used(QuotaLedger::READ));
}
public function test_try_consume_read_stops_at_budget(): void
{
    config(['youtube.quota.read_budget' => 3]);
    $ledger = app(QuotaLedger::class);
    $this->assertTrue($ledger->tryConsumeRead(2));
    $this->assertFalse($ledger->tryConsumeRead(2));   // 2+2 > 3 は加算しない
    $this->assertTrue($ledger->tryConsumeRead(1));
    $this->assertSame(3, $ledger->used(QuotaLedger::READ));
    $this->assertSame(0, $ledger->readRemaining());
}
public function test_read_budget_resets_at_pacific_midnight(): void
{
    config(['youtube.quota.read_budget' => 1]);
    $ledger = app(QuotaLedger::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 16:30', 'Asia/Tokyo')); // PT 11/13 23:30（標準時間）
    $this->assertTrue($ledger->tryConsumeRead(1));
    $this->travelTo(CarbonImmutable::parse('2026-11-15 00:30', 'Asia/Tokyo')); // JST は日付が変わったが PT はまだ 11/14 07:30
    $this->assertTrue($ledger->tryConsumeRead(1));
    $this->travelTo(CarbonImmutable::parse('2026-11-15 16:59', 'Asia/Tokyo')); // PT 11/14 23:59
    $this->assertFalse($ledger->tryConsumeRead(1));
    $this->travelTo(CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo')); // PT 11/15 00:00
    $this->assertTrue($ledger->tryConsumeRead(1));
}
public function test_remaining_today_converts_free_units_to_items(): void
{
    app(QuotaLedger::class)->record(QuotaLedger::WRITE, 4851); // 空き 149
    $r = app(QuotaLedger::class)->remainingToday();
    $this->assertSame(1, $r->items);
    $this->assertFalse($r->exhausted());
    $this->assertFalse($r->covers(2));
}

// RemainingTodayViewTest
public function test_shows_items_left(): void
{
    $html = view('partials.remaining-today', ['remaining' => new RemainingToday(12, CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo')), 'itemCount' => null, 'note' => null])->render();
    $this->assertStringContainsString('本日の残り: 再生リスト 1 つ＋動画あと 12 本まで作成できます', $html);
}
public function test_shows_reset_time_when_exhausted(): void
{
    $html = view('partials.remaining-today', ['remaining' => new RemainingToday(0, CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo')), 'itemCount' => null, 'note' => null])->render();
    $this->assertStringContainsString('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です', $html);
}
public function test_shows_reset_time_when_item_count_exceeds_remaining(): void
{
    $html = view('partials.remaining-today', ['remaining' => new RemainingToday(2, CarbonImmutable::parse('2026-11-15 17:00', 'Asia/Tokyo')), 'itemCount' => 3, 'note' => null])->render();
    $this->assertStringContainsString('この提案（動画 3 本）は本日の残りを超えています。次にリセットされるのは 日本時間 11月15日 17時 です', $html);
}
public function test_max_items_partial_uses_policy(): void
{
    config(['youtube.quota.write_limit' => 1000]);
    $this->assertStringContainsString('1 つの再生リストに入れられるのは最大 19 本', view('partials.max-items')->render());
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='QuotaLedgerTest|RemainingTodayViewTest'`
Expected: FAIL（クラス・テーブル・ビューが無い）

- [ ] **Step 3: マイグレーションと QuotaLedger を実装**

加算は MySQL/SQLite 両対応にするため「トランザクション内で `insertOrIgnore(['day_pt'=>…, 'bucket'=>…, 'units'=>0])` → `lockForUpdate()` で行を読む → `increment('units', $n)`」とする（spec 5.4 の「upsert で units + ?」と同じ効果。`ON DUPLICATE KEY` は書かない）。`tryConsumeRead` は同じロックの下で `units + n > read_budget` なら加算せず false。Eloquent モデルは作らず `DB::table('quota_usage')` を使う。

- [ ] **Step 4: RemainingToday と 2 つのパーシャルを実装**

`partials/remaining-today` は `$remaining->exhausted()` で 2 文言を出し分け、`$itemCount` があり `! covers($itemCount)` かつ未枯渇なら上記の超過文言を追加、`$note` があれば小さく併記。日時は `$remaining->resetAtJst->format('n月j日 G時')`。

- [ ] **Step 5: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add database/migrations app/Services app/Support resources/views/partials tests/Feature
git commit -m "feat: QuotaLedger（PT 日付の台帳・読取予算）と本日の残り表示を追加"
```

---

### Task 3: ChannelInput / VideoUrl / ProposalKey

**Files:**
- Create: `app/Support/ChannelRef.php`, `app/Support/ChannelInput.php`, `app/Support/VideoUrl.php`, `app/Support/ProposalKey.php`
- Test: `tests/Unit/ChannelInputTest.php`, `tests/Unit/VideoUrlTest.php`, `tests/Unit/ProposalKeyTest.php`（`PHPUnit\Framework\TestCase` で可）

**Interfaces:**
- Produces:
  - `ChannelRef`: `ChannelRef::id(string $id): self`、`ChannelRef::handle(string $handle): self`、`public readonly string $type`（`'id'|'handle'`）、`public readonly string $value`、`isId(): bool`。ハンドルの `value` は `@` 無し・`mb_strtolower` 済み。
  - `ChannelInput::MAX_LENGTH = 200`、`ChannelInput::ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/'`、`ChannelInput::parse(string $input): ?ChannelRef`
  - `VideoUrl::MAX_LENGTH = 200`、`VideoUrl::ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/'`、`VideoUrl::extractId(string $input): ?string`
  - `ProposalKey::ROUTE_PATTERN = '[A-Za-z0-9_-]{43}'`、`ProposalKey::generate(): string`、`ProposalKey::hash(string $key): string`、`ProposalKey::isWellFormed(string $key): bool`

受け付ける形式（これ以外は null）:
- ChannelInput: `UC…`（24 文字）、`@handle`（`@` 必須。ハンドル部は `[\p{L}\p{N}._-]{3,30}`、`u` 修飾子）、URL（スキーム省略可。ホスト `youtube.com`／`www.youtube.com`／`m.youtube.com`）のパス先頭が `/channel/UC…` または `/@handle`（パーセントデコードしてから判定、後続パス・クエリは無視）。`/c/…`・`/user/…`・他ホスト・200 文字超は null。
- VideoUrl: 11 文字 ID 単体、`youtu.be/{id}`、ホスト `youtube.com`／`www.`／`m.`／`music.youtube.com` の `/watch?v={id}`・`/shorts/{id}`・`/embed/{id}`・`/live/{id}`。スキーム省略可、200 文字超は null。

- [ ] **Step 1: 失敗するテストを書く**

```php
// ChannelInputTest
public function test_channel_id_and_url(): void
{
    $id = 'UC'.str_repeat('a', 22);
    $this->assertSame(['id', $id], self::pair(ChannelInput::parse($id)));
    $this->assertSame(['id', $id], self::pair(ChannelInput::parse("https://www.youtube.com/channel/$id/videos")));
    $this->assertSame(['id', $id], self::pair(ChannelInput::parse("  youtube.com/channel/$id  ")));
}
public function test_channel_input_accepts_handle_variants(): void
{
    $this->assertSame(['handle', 'foo.bar'], self::pair(ChannelInput::parse('@Foo.Bar')));
    $this->assertSame(['handle', 'foo.bar'], self::pair(ChannelInput::parse('https://m.youtube.com/@Foo.Bar/videos?view=0')));
    $this->assertSame(['handle', 'あいうえお'], self::pair(ChannelInput::parse('https://www.youtube.com/@%E3%81%82%E3%81%84%E3%81%86%E3%81%88%E3%81%8A')));
}
public function test_rejects_unsupported_input(): void
{
    foreach (['foo', '@ab', 'https://example.com/@foo', 'https://www.youtube.com/c/foo', 'https://www.youtube.com/user/foo',
        'UC'.str_repeat('a', 21), '@'.str_repeat('a', 200), 'javascript:alert(1)'] as $bad) {
        $this->assertNull(ChannelInput::parse($bad), $bad);
    }
}
// self::pair(?ChannelRef) は [$ref->type, $ref->value] を返す private static ヘルパ

// VideoUrlTest
public function test_extracts_id_from_supported_forms(): void
{
    foreach (['dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'https://youtu.be/dQw4w9WgXcQ?si=x',
        'youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube.com/live/dQw4w9WgXcQ'] as $in) {
        $this->assertSame('dQw4w9WgXcQ', VideoUrl::extractId($in), $in);
    }
}
public function test_rejects_other_hosts_and_bad_ids(): void
{
    foreach (['https://example.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=short', 'dQw4w9WgXc!', 'https://www.youtube.com/playlist?list=PL1'] as $in) {
        $this->assertNull(VideoUrl::extractId($in), $in);
    }
}

// ProposalKeyTest
public function test_generate_is_43_char_base64url_and_unique(): void
{
    $a = ProposalKey::generate(); $b = ProposalKey::generate();
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
    $this->assertNotSame($a, $b);
    $this->assertTrue(ProposalKey::isWellFormed($a));
    $this->assertFalse(ProposalKey::isWellFormed($a.'='));
}
public function test_hash_is_sha256_hex(): void
{
    $this->assertSame(hash('sha256', 'k'), ProposalKey::hash('k'));
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='ChannelInputTest|VideoUrlTest|ProposalKeyTest'`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 4 クラスを実装**

URL 判定は `parse_url`（スキームが無ければ `https://` を補ってから）とホストの完全一致で行う。正規表現で URL 全体を緩く拾わない。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test --filter='ChannelInputTest|VideoUrlTest|ProposalKeyTest'`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Support tests/Unit
git commit -m "feat: チャンネル入力・動画 URL の解析と提案キーの生成を追加"
```

---

### Task 4: YouTubeReader と YouTubeError

**Files:**
- Create: `app/Services/YouTube/{YouTubeReader,ChannelInfo,PlaylistItem,PlaylistItemsPage,VideoInfo,YouTubeError,YouTubeErrorKind,YouTubeApiException,ReadBudgetExceeded}.php`, `app/Support/IsoDuration.php`, `tests/Support/FakesYouTube.php`
- Modify: `tests/TestCase.php`（`Http::preventStrayRequests()` を追加）
- Test: `tests/Feature/YouTubeReaderTest.php`, `tests/Unit/YouTubeErrorTest.php`, `tests/Unit/IsoDurationTest.php`

**Interfaces:**
- Consumes: `QuotaLedger::tryConsumeRead()`, `QuotaLedger::record()`, `ChannelRef`
- Produces（すべて `final readonly class`、名前空間 `App\Services\YouTube`）:
  - `ChannelInfo(string $id, string $title, ?string $thumbnailUrl, string $uploadsPlaylistId, ?string $handle)`（`handle` は `snippet.customUrl` から `@` を除き小文字化）
  - `PlaylistItem(string $videoId, string $title, ?string $thumbnailUrl, ?CarbonImmutable $publishedAt, string $privacyStatus)`（`publishedAt` は `contentDetails.videoPublishedAt`）
  - `PlaylistItemsPage(array $items /* list<PlaylistItem> */, ?string $nextPageToken)`
  - `VideoInfo(string $id, string $title, ?string $thumbnailUrl, string $channelId, string $channelTitle, ?int $durationSeconds, ?CarbonImmutable $publishedAt, string $privacyStatus)`、`isPublic(): bool`
  - `enum YouTubeErrorKind: string { Transient, GoogleQuota, Auth, ItemPermanent, InvalidInput, Unknown }`（値は小文字スネーク）
  - `YouTubeError(YouTubeErrorKind $kind, ?int $status, ?string $reason)`、`YouTubeError::fromResponse(Response $r): self`、`YouTubeError::connection(): self`
  - `YouTubeApiException(YouTubeError $error)`（`public readonly YouTubeError $error`。メッセージは kind・status・reason のみ）、`ReadBudgetExceeded extends RuntimeException`
  - `YouTubeReader::BASE_URL = 'https://www.googleapis.com/youtube/v3/'`
  - `YouTubeReader::channel(ChannelRef $ref): ?ChannelInfo`（`channels` `part=snippet,contentDetails` と `id` または `forHandle=@{value}`、items 空なら null）
  - `YouTubeReader::uploadsPage(string $playlistId, ?string $pageToken): PlaylistItemsPage`（`playlistItems` `part=snippet,contentDetails,status&maxResults=50`。404 `playlistNotFound` は空ページ）
  - `YouTubeReader::videos(array $ids, string $bucket = QuotaLedger::READ): array<string, VideoInfo>`（50 件ずつ `videos` `part=snippet,contentDetails,status`。返らなかった ID はキーに無い）
  - `IsoDuration::toSeconds(?string $iso): ?int`（`PT1H2M3S`→3723、`P1DT1S`→86401、`P0D`→0、不正→null）
  - テスト補助 `Tests\Support\FakesYouTube`（trait）: `fakeChannel(string $id, array $o = []): void`（channels を固定応答。`$o` で title/handle/uploads を上書き、`['missing'=>true]` で items 空）、`fakeUploads(string $playlistId, array $pages): void`（`$pages` = `list<list<array>>`、2 ページ目以降のトークンは `p2`,`p3`…、`[]` 指定時は 404 `playlistNotFound`）、`fakeVideos(array $videos): void`（`id` クエリで絞って返す）、`uploadItem(string $id, string $publishedAt, string $privacy = 'public', ?string $title = null): array`、`videoResource(string $id, string $channelId, array $o = []): array`（`$o` のキー: `title`, `channelTitle`, `duration`〈ISO 8601〉, `privacy`, `thumbnail`）、`fakeYouTubeError(string $endpoint, int $status, string $reason): void`。チャンネル ID `UC…` の uploads は `UU…`（2 文字目以降同じ）を既定にする。

エラー分類（spec 5.7）: 5xx・409・429・接続失敗 → Transient／403 で reason `quotaExceeded`・`dailyLimitExceeded` → GoogleQuota／403 で `rateLimitExceeded`・`userRateLimitExceeded` → Transient／401 → Auth／404・その他の 403 → ItemPermanent／400 → InvalidInput／それ以外 → Unknown。reason は `error.errors[0].reason`。

サムネイル: 動画は `https://i.ytimg.com/` で始まる URL のみ（`medium`→`default`→`high` の順で採用）、チャンネルは `https://yt3.ggpht.com/` または `https://i.ytimg.com/` で始まるもののみ。それ以外は null（spec 8.3）。

- [ ] **Step 1: 失敗するテストを書く**

```php
// YouTubeReaderTest (RefreshDatabase, FakesYouTube)
public function test_sends_key_in_header_not_query_and_records_read_unit(): void
{
    config(['youtube.api_key' => 'secret-key']);
    $this->fakeChannel('UC'.str_repeat('a', 22));
    $info = app(YouTubeReader::class)->channel(ChannelRef::id('UC'.str_repeat('a', 22)));
    $this->assertSame('UU'.str_repeat('a', 22), $info->uploadsPlaylistId);
    Http::assertSent(fn ($r) => $r->hasHeader('X-Goog-Api-Key', 'secret-key') && ! str_contains($r->url(), 'secret-key') && ! str_contains($r->url(), 'key='));
    $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
}
public function test_handle_lookup_uses_for_handle(): void { /* fakeChannel(…, ['handle'=>'@Foo']) → channel(ChannelRef::handle('foo')) の送信 URL に forHandle=%40foo、$info->handle === 'foo' */ }
public function test_missing_channel_returns_null(): void { /* fakeChannel(id, ['missing'=>true]) → null */ }
public function test_budget_exhausted_throws_without_sending(): void
{
    config(['youtube.quota.read_budget' => 0]);
    Http::fake();
    $this->expectException(ReadBudgetExceeded::class);
    try { app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']); } finally { Http::assertNothingSent(); }
}
public function test_videos_chunks_by_50_and_maps_fields(): void
{
    // 51 件 → リクエスト 2 回・read 2 ユニット。duration PT1M5S → 65、非 i.ytimg サムネは null、privacyStatus を写す
}
public function test_uploads_page_treats_playlist_not_found_as_empty(): void
{
    $this->fakeUploads('UU'.str_repeat('a', 22), []);
    $page = app(YouTubeReader::class)->uploadsPage('UU'.str_repeat('a', 22), null);
    $this->assertSame([], $page->items);
    $this->assertNull($page->nextPageToken);
}
public function test_api_error_throws_classified_exception_and_logs_without_key(): void
{
    config(['youtube.api_key' => 'secret-key']);
    Log::spy();
    $this->fakeYouTubeError('videos', 403, 'quotaExceeded');
    try { app(YouTubeReader::class)->videos(['dQw4w9WgXcQ']); $this->fail(); }
    catch (YouTubeApiException $e) { $this->assertSame(YouTubeErrorKind::GoogleQuota, $e->error->kind); $this->assertStringNotContainsString('secret-key', $e->getMessage()); }
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => ! str_contains(json_encode($ctx), 'secret-key') && ($ctx['reason'] ?? null) === 'quotaExceeded');
}
public function test_connection_failure_is_transient(): void { /* Http::fake(fn () => throw new ConnectionException('x')) → kind Transient */ }

// YouTubeErrorTest: 上記分類表を DataProvider で 1 行ずつ（500, 503, 409, 429, 403 quotaExceeded, 403 dailyLimitExceeded, 403 rateLimitExceeded, 403 userRateLimitExceeded, 401, 404 videoNotFound, 403 forbidden, 400 invalidValue, 418）
// IsoDurationTest: PT1H2M3S=3723, PT45S=45, P1DT1S=86401, P0D=0, 'x'=null, null=null
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='YouTubeReaderTest|YouTubeErrorTest|IsoDurationTest'`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: 実装する**

`YouTubeReader` のコンストラクタは `QuotaLedger` を受ける。全メソッドは private `get(string $endpoint, array $query, string $bucket): array` を通す: `READ` なら `tryConsumeRead(1)` が false で `ReadBudgetExceeded`、それ以外は `record($bucket, 1)` → `Http::withHeaders(['X-Goog-Api-Key' => key])->acceptJson()->connectTimeout(5)->timeout(15)->get(BASE_URL.$endpoint, $query)`。`ConnectionException` は `YouTubeError::connection()`、非 2xx は `fromResponse`。失敗時は `Log::warning('youtube api error', ['endpoint'=>…, 'status'=>…, 'reason'=>…, 'kind'=>…])` のみ。`tests/TestCase.php` の `setUp()` に `Http::preventStrayRequests();` を追加。

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/YouTube app/Support/IsoDuration.php tests
git commit -m "feat: API キーでの読取（YouTubeReader）とエラー分類を追加"
```

---

### Task 5: 一覧キャッシュ — 全件取り込み・6 時間再利用・チャンネル単位の期限切れ

**Files:**
- Create: `database/migrations/2026_11_14_000200_create_channel_cache_tables.php`, `app/Models/{ChannelCache,ChannelCacheVideo,ChannelCacheExpiration}.php`, `app/Services/{ChannelVideoCatalog,CatalogResult,CatalogStatus}.php`
- Modify: `tests/Feature/DatabaseSchemaTest.php`
- Test: `tests/Feature/ChannelCatalogImportTest.php`

**Interfaces:**
- Consumes: `YouTubeReader::channel()`, `YouTubeReader::uploadsPage()`, `ChannelRef::id()`, `FakesYouTube`
- Produces:
  - テーブル（spec 4.2 の列どおり）: `channel_caches`（`channel_id` string(24) PK、`handle` string nullable index、`title`、`thumbnail_url` nullable、`uploads_playlist_id`、`meta_fetched_at`、`cached_since`、`last_synced_at`、`truncated` bool、`expires_at` index）、`channel_cache_videos`（`channel_id` FK→`channel_caches.channel_id` cascadeOnDelete、`video_id` char(11)、`title`、`thumbnail_url` nullable、`published_at`、`fetched_at`、PK(`channel_id`,`video_id`)、index(`channel_id`,`published_at`)）、`channel_cache_expirations`（`channel_id` string(24) PK、`expired_at` index の 2 列のみ）
  - モデル: `ChannelCache`（`$primaryKey='channel_id'`, `$incrementing=false`, `$keyType='string'`, `$timestamps=false`, 日時は `immutable_datetime`, `videos(): HasMany`）、`ChannelCacheVideo`（読み取り用。`$timestamps=false`。書き込みはクエリビルダ）、`ChannelCacheExpiration`（PK `channel_id`, `$timestamps=false`）
  - `enum CatalogStatus { Reused, Synced, Imported, BudgetExhausted, Failed, NotFound }`
  - `CatalogResult(CatalogStatus $status, ?ChannelCache $channel, bool $hadExpired = false)`（`final readonly`。`hadExpired` = 取り込み開始時点で `channel_cache_expirations` に記録があった）
  - `ChannelVideoCatalog::load(string $channelId): CatalogResult`
  - `ChannelVideoCatalog::isReusable(string $channelId): bool`（期限内かつ `last_synced_at` が `cache_reuse_minutes` 以内）
  - `ChannelVideoCatalog::expire(ChannelCache $cache): void`（1 トランザクションでチャンネル行を削除〈動画行は cascade〉し、`channel_cache_expirations` に `channel_id`・`expired_at=now` を upsert）
  - `ChannelVideoCatalog::expireStale(): int`（`expires_at <= now` の全チャンネルに `expire`。件数を返す）

`load()` の手順（このタスクでは全件取り込みモードのみ。差分モードは Task 6）:
1. 行があり `expires_at <= now` なら `expire()` して行なし扱い。
2. 行があり `isReusable` なら API を呼ばず `Reused`。
3. `Cache::lock("channel-fetch:{$id}", 60)->block(40, …)` の中で 1〜2 を再判定してから取り込む（待機側は相手の結果を読む）。`LockTimeoutException` は `Failed`（行があればその行付き）。
4. `channel(ChannelRef::id($id))`。null なら `NotFound`（何も書かない）。
5. 全件取り込み: `uploadsPage` を先頭から読み、`public` 以外は捨て、同じ ID は 1 回だけ。最大 `fetch_max_videos / 50` ページ。読み終えた時点で `nextPageToken` が残っていれば `truncated=true`、末尾到達なら `false`。
6. 1 トランザクションで書く: チャンネル行（`title`・`thumbnail_url`・`uploads_playlist_id`・`handle`・`meta_fetched_at=now`・`last_synced_at=now`・`cached_since=now`・`expires_at=now+retention_days`・`truncated`）、動画行（`fetched_at=now`）、`channel_cache_expirations` の記録削除。結果は `Imported`（`hadExpired` 付き）。
7. `ReadBudgetExceeded` → `BudgetExhausted`、`YouTubeApiException` → `Failed`。どちらも何も書かない（行があればその行を返す）。

- [ ] **Step 1: 失敗するテストを書く**

```php
// ChannelCatalogImportTest (RefreshDatabase, FakesYouTube)。$id = 'UC'.str_repeat('a', 22), $uu = 'UU'.str_repeat('a', 22)
public function test_first_load_imports_all_pages_until_end(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
    $this->fakeChannel($id);
    $this->fakeUploads($uu, [$this->items(0, 50), $this->items(50, 20)]); // items(start, n) は新しい順の uploadItem を返すテスト内ヘルパ
    $r = app(ChannelVideoCatalog::class)->load($id);
    $this->assertSame(CatalogStatus::Imported, $r->status);
    $this->assertFalse($r->hadExpired);
    $this->assertSame(70, $r->channel->videos()->count());
    $this->assertFalse($r->channel->truncated);
    $this->assertTrue($r->channel->cached_since->equalTo(now()));
    $this->assertTrue($r->channel->expires_at->equalTo(now()->addDays(30)));
}
public function test_full_import_stops_at_20_pages_and_marks_truncated(): void
{
    // 21 ページ分用意 → 送信は channels 1 + playlistItems 20、動画行 1000、truncated=true、read 21 ユニット
}
public function test_reuses_cache_within_6_hours_without_api_calls(): void
{
    // 1 回目 load の後 Http::fake() をやり直し、5 時間 59 分後の load が Reused・Http::assertNothingSent()
}
public function test_private_items_are_not_imported(): void { /* privacy 'private' / 'unlisted' の item は行にならない */ }
public function test_channel_without_uploads_imports_empty_list(): void
{
    $this->fakeChannel($id);
    $this->fakeUploads($uu, []); // 404 playlistNotFound
    $r = app(ChannelVideoCatalog::class)->load($id);
    $this->assertSame(CatalogStatus::Imported, $r->status);
    $this->assertSame(0, $r->channel->videos()->count());
}
public function test_unknown_channel_is_not_found_and_writes_nothing(): void { /* fakeChannel(['missing'=>true]) → NotFound、channel_caches 0 行 */ }
public function test_channel_expires_as_a_unit_after_30_days_and_is_recorded(): void
{
    // 11/14 に全件取り込み → 11/20 に差分で 1 行足したことにする（DB に直接 fetched_at=11/20 の行を追加）
    // 12/13 23:59（期限前）に expireStale() → 0、行は残る
    // 12/14 03:00（cached_since+30日）に expireStale() → 1、channel_caches・channel_cache_videos ともに 0 行
    // channel_cache_expirations に channel_id と expired_at=now の 1 行
}
public function test_load_after_expiry_reimports_and_clears_record(): void
{
    // 期限切れ（バッチ前）の行が残った状態で 12/15 に load → その場で expire → 全件取り込み、status Imported、hadExpired=true、
    // 新しい cached_since=now・expires_at=now+30日、channel_cache_expirations は 0 行
}
public function test_budget_exhausted_without_cache_writes_nothing(): void
{
    // read_budget=0 → BudgetExhausted、channel null、channel_caches 0 行。期限切れ記録があれば hadExpired=true
}

// DatabaseSchemaTest に追加
public function test_expiration_record_holds_no_api_data(): void
{
    $this->assertSame(['channel_id', 'expired_at'], Schema::getColumnListing('channel_cache_expirations'));
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='ChannelCatalogImportTest|DatabaseSchemaTest'`
Expected: FAIL

- [ ] **Step 3: マイグレーション・モデル・ChannelVideoCatalog（全件モード）を実装**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add database/migrations app/Models app/Services tests/Feature
git commit -m "feat: チャンネル一覧キャッシュの全件取り込み・6 時間再利用・チャンネル単位の期限切れを追加"
```

---

### Task 6: 差分取り込み（打ち切り判定・非公開除去・行数上限・途中終了）

**Files:**
- Modify: `app/Services/ChannelVideoCatalog.php`
- Test: `tests/Feature/ChannelCatalogDiffTest.php`

**Interfaces:**
- Consumes/Produces: Task 5 と同じ（`load()` が期限内の行に対して差分モードで動き、`Synced` を返す）

差分モードのアルゴリズム（spec 5.3。シグネチャとテストから決まらないため明記）:

```
known = 既存動画行の video_id 集合;  threshold = min(sync_known_run, count(known))
new = []; remove = []; token = null; pages = 0; deadline = now()->addSeconds(30)
loop:
    if pages >= fetch_max_videos/50: break                     // truncated は変更しない
    if now() > deadline: 中断（何も書かない → Failed）
    page = uploadsPage(uploads, token); pages++
    run = 0; stop = false                                      // run はページごとにリセット
    foreach item in page.items:
        if item.privacy != 'public': if item.id in known: remove[] = item.id; continue   // run に影響しない
        if item.id in known: run++; if run >= threshold: stop = true                 // ページ内の残りは読み続ける
        else: new[item.id] ??= item; run = 0
    token = page.next
    if stop or token == null: break
```
- `threshold` が 0（既存行 0 件）のときは 1 ページ目を読んだら終了。
- 書き込み（1 トランザクション）: チャンネル情報の上書き、`new` を `fetched_at=now` で追加、`remove` を削除、行数が `fetch_max_videos` を超えた分を `published_at` 昇順（同時刻は `video_id` 昇順）で削除、`last_synced_at=now`。`cached_since`・`expires_at`・`truncated` は変更しない。既存行は一切更新しない。
- 30 秒の打ち切りは全件モードにも適用する。途中終了（予算切れ・API エラー・30 秒超過）はすべて何も書かず、既存行を返す（`BudgetExhausted` または `Failed`）。

- [ ] **Step 1: 失敗するテストを書く**

各テストは「11/14 に全件取り込み（`fakeUploads` で既知 20 本）→ `travel(7)->hours()` → `Http::fake()` を作り直して差分用のページを用意 → `load()`」の形。

```php
public function test_three_new_then_known_stops_on_first_page(): void
{
    // 1 ページ目: 新規 3 本 + 既知 20 本 + 次トークンあり
    // → Synced、送信は channels 1 + playlistItems 1（Http::recorded() で数える）、行は 23、
    //   既知行の title・fetched_at は全件取り込み時の値のまま、cached_since・expires_at は不変
}
public function test_sixty_new_reads_second_page(): void { /* 1 ページ目 50 本すべて新規、2 ページ目 新規 10 + 既知 → playlistItems 2 回、行 80 */ }
public function test_one_or_two_known_on_top_do_not_stop(): void
{
    // 1 ページ目: 既知1, 既知2, 新規A, 新規B, 既知3, 既知4, 既知5 → A・B を拾い、既知 3 連続で終了
}
public function test_channel_with_two_rows_stops_when_both_seen(): void { /* 既知 2 本だけのチャンネル: 新規1, 既知1, 既知2, (次トークンあり) → 1 ページで終了 */ }
public function test_known_limitation_new_id_below_stop_point_is_missed_until_reimport(): void
{
    // 1 ページ目: 既知1, 既知2, 既知3, 新規X → X は追加される（同じページ内なので）
    // 2 ページ目に新規 Y を置き、1 ページ目で停止 → Y は入らない
    // 30 日後の load（期限切れ→全件取り込み）で Y が入る
}
public function test_privatized_known_row_is_removed(): void { /* 既知の 1 本が 'private' で返る → その行が消える */ }
public function test_rows_over_limit_are_trimmed_oldest_first(): void
{
    // fetch_max_videos=60 に設定、既知 50 + 新規 20 → 行 60、消えたのは published_at が古い 10 本
}
public function test_budget_exhaustion_mid_sync_writes_nothing(): void
{
    // read_budget = 全件取り込みで使った量 + 2（channels 1 + 1 ページで尽きる）、1 ページ目すべて新規＋次トークンあり
    // → BudgetExhausted、channel は既存行、行数・last_synced_at・cached_since 不変
}
public function test_timeout_mid_import_writes_nothing(): void
{
    // 全件モード: 2 ページ目の応答コールバック内で $this->travel(31)->seconds()、3 ページ目あり → Failed、channel_caches 0 行
}
public function test_api_error_keeps_existing_cache(): void { /* 差分中 playlistItems が 500 → Failed、既存行のまま */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=ChannelCatalogDiffTest`
Expected: FAIL（差分モード未実装のため全件取り込みとして動き、件数・送信回数が合わない）

- [ ] **Step 3: 差分モードと共通の打ち切り・書き込みを実装**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test --filter='ChannelCatalog'`
Expected: PASS（Task 5 のテストも含む）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Services/ChannelVideoCatalog.php tests/Feature/ChannelCatalogDiffTest.php
git commit -m "feat: チャンネル一覧の差分取り込み（既知 3 件連続で打ち切り・途中終了時は書き込まない）を追加"
```

---

### Task 7: レート制限・ボディ上限・エラー画面

**Files:**
- Create: `app/Support/ChannelFetchThrottle.php`, `app/Http/Middleware/LimitRequestBody.php`, `resources/views/errors/404.blade.php`, `resources/views/errors/429.blade.php`, `tests/Support/AssertsNoInlineCode.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `tests/Feature/PagesTest.php`（`assertNoInlineCode` を trait に移す）
- Test: `tests/Feature/RateLimitTest.php`

**Interfaces:**
- Produces:
  - 名前付きリミッタ（`RateLimiter::for`、キーは `$request->ip()`。throttle ミドルウェアがキーを md5 化して cache に置く）: `global`（120/分）、`video-lookup`（30/分）、`proposal-store`（`Limit::perMinutes(10, 5)->by('m:'.ip)`、`Limit::perDay(30)->by('d:'.ip)`、`Limit::perDay(500)->by('all')`）
  - `bootstrap/app.php`: `$middleware->appendToGroup('web', 'throttle:global')`、`$middleware->append(LimitRequestBody::class)`、`ThrottleRequestsException` を `expectsJson()` のとき `{"message":"アクセスが集中しています。しばらく待ってから再度お試しください"}`（429、元のヘッダ付き）で返す render
  - `ChannelFetchThrottle::attempt(Request $request): bool`（キー `channel-fetch:m:`／`channel-fetch:h:` ＋ `hash('sha256', ip)`、5 回/60 秒と 30 回/3600 秒。両方に空きがあれば両方 hit して true、どちらか超過なら hit せず false）
  - `LimitRequestBody::MAX_BYTES = 65536`（`CONTENT_LENGTH` またはボディ長が超えたら `PostTooLargeException`）
  - `errors/404`: 「ページが見つかりません。URL が正しいか確認してください（提案は保存期限の 30 日を過ぎると削除されます）」。`errors/429`: 「アクセスが集中しています。しばらく待ってから再度お試しください」。どちらも `errors.minimal` を継承。
  - `Tests\Support\AssertsNoInlineCode::assertNoInlineCode(string $html): void`（PagesTest の実装をそのまま移す）

- [ ] **Step 1: 失敗するテストを書く**

```php
// RateLimitTest (AssertsNoInlineCode)
public function test_global_limit_returns_japanese_429_without_inline_code(): void
{
    for ($i = 0; $i < 120; $i++) { $this->get('/')->assertOk(); }
    $res = $this->get('/')->assertStatus(429);
    $res->assertSee('アクセスが集中しています');
    $this->assertNoInlineCode($res->getContent());
}
public function test_json_429_message_is_japanese(): void
{
    for ($i = 0; $i < 120; $i++) { $this->get('/'); }
    $this->getJson('/')->assertStatus(429)->assertJson(['message' => 'アクセスが集中しています。しばらく待ってから再度お試しください']);
}
public function test_channel_fetch_throttle_minute_and_hour(): void
{
    $req = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.1']);
    $t = app(ChannelFetchThrottle::class);
    for ($i = 0; $i < 5; $i++) { $this->assertTrue($t->attempt($req)); }
    $this->assertFalse($t->attempt($req));
    // 61 秒ずつ進めて合計 30 回 hit した後、31 回目は false（1 時間枠）
}
public function test_body_over_64kb_is_413(): void
{
    $this->call('POST', '/contact', [], [], [], ['CONTENT_LENGTH' => 65537])->assertStatus(413);
}
public function test_404_page_is_japanese(): void
{
    $this->get('/p/'.str_repeat('a', 43))->assertNotFound()->assertSee('ページが見つかりません'); // Task 15 前でもルート未定義で 404
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=RateLimitTest`
Expected: FAIL

- [ ] **Step 3: 実装し、PagesTest を trait 利用に置き換える**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS（SecurityHeadersTest の 413 テストも引き続き通る）

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app bootstrap/app.php resources/views/errors tests
git commit -m "feat: レート制限（全体・URL 追加・提案保存・チャンネル取得）とボディ上限、日本語の 404/429 画面を追加"
```

---

### Task 8: ホームのチャンネル入力とハンドル解決

**Files:**
- Create: `app/Http/Controllers/ChannelController.php`
- Modify: `app/Services/ChannelVideoCatalog.php`（`resolveHandle` 追加）、`routes/web.php`、`resources/views/home.blade.php`、`tests/Feature/PagesTest.php`（ルート一覧に `channels`）
- Test: `tests/Feature/ChannelOpenTest.php`

**Interfaces:**
- Consumes: `ChannelInput::parse()`, `ChannelFetchThrottle::attempt()`, `YouTubeReader::channel()`
- Produces:
  - `ChannelVideoCatalog::resolveHandle(string $handle): ?string`（期限内の `channel_caches.handle` 一致があれば API なしでその ID。無ければ `channel(ChannelRef::handle())` の ID。キャッシュ行は書かない。例外 `ReadBudgetExceeded`／`YouTubeApiException` はそのまま投げる）
  - ルート `GET /channels` → `ChannelController::open(Request $request): RedirectResponse`、名前 `channels.open`
  - 振る舞い: `q` を検証（必須・文字列・200 文字以内）→ `ChannelInput::parse` が null なら `home` へ戻し `errors.q`＝「チャンネル URL・ハンドル（@…）・チャンネル ID（UC…）を入力してください」。ID ならそのまま `channels.propose` へ。ハンドルは期限内キャッシュで解決できればそのまま、API が必要なら先に `ChannelFetchThrottle::attempt` を通し（超過は `abort(429)`）、見つからなければ「チャンネルが見つかりません」、予算切れは「本日の取得上限に達しました。時間をおいて再度お試しください」、API エラーは「YouTube との通信に失敗しました。時間をおいて再度お試しください」を `errors.q` で `home` に戻す（`withInput`）。
  - ホームの「準備中」を GET フォーム（`action=route('channels.open')`、`name="q"`、`maxlength="200"`、エラー表示）に置き換える。

- [ ] **Step 1: 失敗するテストを書く**

```php
// ChannelOpenTest (RefreshDatabase, FakesYouTube)
public function test_channel_id_redirects_without_api(): void
{
    Http::fake();
    $id = 'UC'.str_repeat('a', 22);
    $this->get('/channels?q='.urlencode("https://www.youtube.com/channel/$id"))->assertRedirect(route('channels.propose', $id));
    Http::assertNothingSent();
}
public function test_handle_resolved_via_api_once_then_from_cache(): void
{
    // fakeChannel(id, ['handle'=>'@foo']) → /channels?q=@Foo が channels.propose へ。read 1 ユニット
    // channel_caches に handle='foo'・期限内の行を作った後は Http::assertNothingSent()
}
public function test_invalid_input_returns_home_with_message(): void
{
    $this->from('/')->get('/channels?q=foo')->assertRedirect('/');
    $this->get('/')->assertSee('チャンネル URL・ハンドル（@…）・チャンネル ID（UC…）を入力してください');
}
public function test_unknown_handle_and_budget_and_api_error_messages(): void { /* 3 ケースの文言 */ }
public function test_handle_lookup_is_rate_limited(): void { /* 6 回目の API 解決は 429。キャッシュ解決は制限を消費しない */ }
public function test_home_has_channel_form(): void { $this->get('/')->assertSee('action="'.route('channels.open').'"', false)->assertSee('maxlength="200"', false); }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='ChannelOpenTest|PagesTest'`
Expected: FAIL

- [ ] **Step 3: 実装する**（リダイレクト先の `channels.propose` は Task 9 で中身を作る。このタスクでは `ProposalController::create` を `abort(404)` だけの仮実装でルート登録しておく）

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views/home.blade.php tests
git commit -m "feat: ホームのチャンネル入力とハンドルからチャンネル ID への解決を追加"
```

---

### Task 9: 提案作成画面 S2（サーバー側）

**Files:**
- Create: `app/Http/Controllers/ProposalController.php`（`create`）、`resources/views/proposals/create.blade.php`, `resources/views/proposals/catalog-unavailable.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php`（`<head>` の `@vite` の直後に `@stack('vite')`）、`tests/Feature/PagesTest.php`（ルート一覧）
- Test: `tests/Feature/ProposeScreenTest.php`

**Interfaces:**
- Consumes: `ChannelVideoCatalog::isReusable()/load()`, `ChannelFetchThrottle::attempt()`, `QuotaLedger::remainingToday()`, `QuotaPolicy::maxItemsPerPlaylist()`, パーシャル `remaining-today`／`max-items`
- Produces:
  - `ProposalController::create(Request $request, string $channelId): Response`、ルート `channels.propose`（`{channelId}` 制約 `UC[A-Za-z0-9_-]{22}`）
  - 流れ: `! isReusable($id)` かつ `! ChannelFetchThrottle::attempt` なら `abort(429)` → `load($id)` → `NotFound` は `catalog-unavailable` を 404（「チャンネルが見つかりません」）、`channel === null`（予算切れ・失敗）は `catalog-unavailable` を 503（予算切れ: 「本日の取得上限に達しました」＋`hadExpired` なら「前回の一覧は 30 日の保存期限で削除されました」、失敗: 「YouTube との通信に失敗しました。時間をおいて再度お試しください」）。それ以外は `proposals.create`。
  - `proposals.create` の内容: チャンネル見出し（アイコン・名前・`https://www.youtube.com/channel/{id}` へのリンク・「データ提供: YouTube」）、`max-items`、`remaining-today`（`note`＝「作成はチャンネル主が操作した時点の残りで決まります」）、Global Constraints の一覧関連文言（期限表示は常に、最終取り込みは常に、予算切れ・失敗時は「（本日の取得上限に達したため更新していません）」／「（更新できませんでした）」を強調、`Imported && hadExpired` で取り込み直しの文、注記、`truncated` 時の文）、後続 Task 12 が使うルート要素 `<div id="proposer" x-data="proposer" data-catalog="…">`。
  - `data-catalog` の JSON: `{"channelId": string, "maxItems": int, "lookupUrl": route('channels.video', id), "videos": [{"id","title","thumbnail_url","published_at"(ISO 8601)}…新しい順], "unavailableIds": session('unavailable_video_ids', [])}`
  - 外部リンクは `rel="noopener noreferrer" target="_blank"`。

- [ ] **Step 1: 失敗するテストを書く**

```php
// ProposeScreenTest (RefreshDatabase, FakesYouTube, AssertsNoInlineCode)
public function test_shows_list_limits_remaining_and_expiry(): void
{
    $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00')); // JST 12:00
    // fakeChannel + 3 本 → GET channels.propose
    $res = $this->get(route('channels.propose', $id))->assertOk();
    $res->assertSee('1 つの再生リストに入れられるのは最大 99 本');
    $res->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます');
    $res->assertSee('作成はチャンネル主が操作した時点の残りで決まります');
    $res->assertSee('この一覧は 11月14日に取り込み、12月14日に削除されます（削除後に開くと全件を取り込み直します）');
    $res->assertSee('一覧の最終取り込み: 11月14日 12時00分');
    $res->assertSee('データ提供: YouTube');
    $res->assertSee('最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください');
    $res->assertDontSee('前回の一覧は 30 日の保存期限で削除');
    $this->assertNoInlineCode($res->getContent());
    $payload = $this->catalogPayload($res);
    $this->assertSame(99, $payload['maxItems']);
    $this->assertCount(3, $payload['videos']);
}
public function test_reimport_after_expiry_shows_notice_once(): void { /* 期限切れ記録あり → 取り込み直しの文が出る。記録は消え、次の GET では出ない */ }
public function test_budget_exhausted_shows_cached_list_with_marker(): void
{
    // 取り込み済み → 7 時間後、read_budget を使い切った状態で GET → 200、一覧・期限表示あり、「（本日の取得上限に達したため更新していません）」
}
public function test_budget_exhausted_without_cache_shows_limit_message(): void { /* 503、「本日の取得上限に達しました」、記録ありなら「前回の一覧は 30 日の保存期限で削除されました」も */ }
public function test_unknown_channel_is_404(): void {}
public function test_truncated_notice(): void {}
public function test_zero_remaining_shows_reset_time(): void { /* write 5000 記録済み → 「本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です」 */ }
public function test_fetch_rate_limit_only_when_api_needed(): void { /* キャッシュ再利用中は 10 回開いても 200、キャッシュ無しの別チャンネル 6 回目は 429 */ }
// catalogPayload(TestResponse): array は data-catalog 属性値を正規表現で取り出し html_entity_decode → json_decode する private ヘルパ（Task 12 でも使う）
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=ProposeScreenTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views tests
git commit -m "feat: 提案作成画面（一覧・上限・本日の残り・一覧の期限表示）を追加"
```

---

### Task 10: 動画 URL 追加 API

**Files:**
- Modify: `app/Http/Controllers/ChannelController.php`（`video`）、`routes/web.php`、`tests/Feature/PagesTest.php`
- Test: `tests/Feature/VideoLookupTest.php`

**Interfaces:**
- Consumes: `VideoUrl::extractId()`, `YouTubeReader::videos()`
- Produces:
  - `ChannelController::video(Request $request, string $channelId): JsonResponse`、ルート `GET /channels/{channelId}/videos/lookup?url=…`、名前 `channels.video`、ミドルウェア `throttle:video-lookup`
  - 応答 200: `{"video": {"id","title","thumbnail_url","channel_id","channel_title","published_at","outside": bool}}`（`outside` = 動画のチャンネル ≠ `{channelId}`）
  - 422 `{"message":"動画の URL を確認してください"}`（`url` 欠落・200 文字超・ID 抽出不可）、404 `{"message":"この動画は削除または非公開になっています"}`（返らない・`public` 以外）、503 `{"message":"本日の取得上限に達しました"}`／`{"message":"YouTube との通信に失敗しました。時間をおいて再度お試しください"}`
  - 取得結果は `Cache::remember("video-lookup:{$videoId}", 600, …)` で 10 分保持（見つからなかった結果も保持。`outside` は保持値から毎回計算）

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_returns_video_with_outside_flag(): void { /* 他チャンネルの動画 → outside true、対象チャンネルの動画 → false */ }
public function test_same_video_within_10_minutes_hits_cache(): void { /* 2 回呼んで送信 1 回・read 1 ユニット。11 分後は再送信 */ }
public function test_invalid_url_is_422_and_sends_nothing(): void {}
public function test_private_or_missing_video_is_404(): void {}
public function test_budget_exhausted_is_503(): void {}
public function test_rate_limited_after_30_per_minute(): void { /* 31 回目 429 で日本語 JSON */ }
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=VideoLookupTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php tests
git commit -m "feat: 動画 URL から 1 本を取得する API（対象チャンネル外の判定・10 分キャッシュ）を追加"
```

---

### Task 11: フロントの純関数と node --test

**Files:**
- Create: `resources/js/lib/selection.js`, `resources/js/lib/filter.js`, `resources/js/lib/storage.js`, `resources/js/lib/text.js`
- Modify: `package.json`（`"test:js": "node --test \"tests/js/**/*.test.js\""`）、`.github/workflows/ci.yml`（frontend ジョブの `npm ci` の後に `npm run test:js`）
- Test: `tests/js/selection.test.js`, `tests/js/filter.test.js`, `tests/js/storage.test.js`, `tests/js/text.test.js`

**Interfaces:**
- Produces（すべて ES module の named export、引数を変更せず新しい配列を返す）。選択要素の形は `{id, title, thumbnail_url, outside: boolean, unavailable?: boolean}`。
  - `selection.js`: `toggleVideo(list, video, max) → {list, error: null|'limit'}`（あれば外す、無ければ末尾に追加、`list.length >= max` で追加不可）、`addVideo(list, video, max) → {list, error: null|'limit'|'duplicate'}`、`removeVideo(list, id)`、`moveVideo(list, id, delta)`（範囲外は端で止める）、`restoreSelection(stored, catalogById, max)`（不正な要素を捨て、一覧にある ID は一覧の値＋`outside:false` に置き換え、無い ID は保存値を残し、重複除去、先頭から `max` 件）、`markUnavailable(list, ids)`
  - `filter.js`: `normalizeText(s)`（NFKC＋小文字化＋前後空白除去）、`filterVideos(videos, query)`（空白区切りの全語がタイトルに含まれるもの。空なら全件）
  - `storage.js`（`storage` は `localStorage` 互換または null。全関数 try/catch で例外を外に出さない）: `loadSelection(storage, channelId) → array`（キー `listmaker:selection:{channelId}`。破損・null は `[]`）、`saveSelection(storage, channelId, list) → boolean`、`clearSelection(storage, channelId)`、`rememberProposal(storage, entry, now) → boolean`（キー `listmaker:proposals`、`entry = {url, title, channelTitle, expiresAt}`、同じ url は置き換え、期限切れを除去、新しい順に最大 50 件）、`listProposals(storage, now) → array`（期限切れと `url` が `/p/` を含まないものを除外）
  - `text.js`: `countChars(s)`（`\r\n`→`\n` に正規化してからコードポイント数 `[...s].length`）

- [ ] **Step 1: 失敗するテストを書く**

```js
// tests/js/selection.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toggleVideo, addVideo, moveVideo, restoreSelection, markUnavailable } from '../../resources/js/lib/selection.js';

const v = (id, extra = {}) => ({ id, title: `t${id}`, thumbnail_url: null, outside: false, ...extra });

test('toggle adds until max then refuses', () => {
  let r = toggleVideo([v('a')], v('b'), 2);
  assert.deepEqual(r.list.map(x => x.id), ['a', 'b']);
  r = toggleVideo(r.list, v('c'), 2);
  assert.equal(r.error, 'limit');
  assert.equal(r.list.length, 2);
  assert.deepEqual(toggleVideo(r.list, v('a'), 2).list.map(x => x.id), ['b']);
});
test('addVideo reports duplicate', () => { assert.equal(addVideo([v('a')], v('a'), 99).error, 'duplicate'); });
test('move clamps at edges', () => {
  assert.deepEqual(moveVideo([v('a'), v('b'), v('c')], 'c', -1).map(x => x.id), ['a', 'c', 'b']);
  assert.deepEqual(moveVideo([v('a'), v('b')], 'a', -1).map(x => x.id), ['a', 'b']);
});
test('restore refreshes catalog items, keeps url-added, drops junk, caps at max', () => {
  const catalog = new Map([['a', v('a', { title: 'new' })]]);
  const stored = [v('a', { title: 'old' }), v('x', { outside: true }), { id: 1 }, null, v('a'), v('y')];
  const list = restoreSelection(stored, catalog, 2);
  assert.deepEqual(list.map(x => [x.id, x.title]), [['a', 'new'], ['x', 'tx']]);
});
test('markUnavailable flags ids', () => { assert.equal(markUnavailable([v('a')], ['a'])[0].unavailable, true); });

// filter.test.js: 全角英数・大文字が一致（'ＡＢＣ' で 'abc 配信' がヒット）、'a b' は両方含むものだけ、空クエリは全件
// storage.test.js:
//   getItem/setItem が throw するストレージで load→[]、save→false（例外が出ない）
//   '{broken' が入っていると load→[]、storage=null でも動く
//   rememberProposal: 同じ url は 1 件、expiresAt が now 以前のものは listProposals に出ない、'javascript:' の url は出ない、51 件目で最古が落ちる
// text.test.js: countChars('a\r\nb') === 3、countChars('😀') === 1、countChars('👨‍👩‍👧') === 5（ZWJ 連結はコードポイント数）
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `npm run test:js`
Expected: FAIL（モジュールが無い）

- [ ] **Step 3: 4 モジュールを実装し、package.json と CI を更新**

- [ ] **Step 4: テストが通ることを確認**

Run: `npm run test:js`
Expected: PASS（全テスト ok）

- [ ] **Step 5: コミット**

```bash
git add resources/js/lib tests/js package.json .github/workflows/ci.yml
git commit -m "feat: 選択・絞り込み・端末内保存・文字数の純関数と node --test を追加"
```

---

### Task 12: 提案作成画面の操作（propose.js）

**Files:**
- Create: `resources/js/propose.js`, `database/seeders/DemoChannelSeeder.php`（ローカル目視確認用。API 由来でない架空データ。`DatabaseSeeder` からは呼ばない）
- Modify: `resources/js/app.js`（`document.addEventListener('DOMContentLoaded', () => Alpine.start())` に変更）、`vite.config.js`（`input` に `resources/js/propose.js`）、`resources/views/proposals/create.blade.php`
- Test: `tests/Feature/ProposeScreenTest.php`（追記）、`tests/Feature/ViteEntriesTest.php`

**Interfaces:**
- Consumes: Task 9 の `#proposer`／`data-catalog`、Task 10 の `channels.video`、Task 11 の lib 関数
- Produces:
  - `Alpine.data('proposer', () => ({…}))`。保持する状態: `videos`（一覧）、`query`、`selected`（Task 11 の形）、`urlInput`、`urlMessage`、`limitMessage`、`title`、`description`、`comment`、`proposerName`。算出: `filtered`、`shownCount`、`selectedCount`、`maxItems`、`titleCount`（`countChars`）ほか各カウンタ、`isFull`。メソッド（引数なし。ループ内は `this.video`／`this.item` を参照）: `toggle`、`isSelected`、`clearAll`、`remove`、`moveUp`、`moveDown`、`addByUrl`（`fetch(lookupUrl+'?url=…', {headers: {Accept: 'application/json'}})`、エラーは応答の `message` を `urlMessage` に `x-text` で表示）。
  - 選択が変わるたび `saveSelection`、初期化時に `restoreSelection(loadSelection(...), catalogById, maxItems)` → `markUnavailable(…, unavailableIds)`。
  - Blade 側: 絞り込み入力（`x-model="query"`）、「表示 {shownCount} 件／選択 {selectedCount} / {maxItems}」、動画カード（`x-for="video in filtered"`、`:src` は `thumbnail_url` が null なら描画しない、`loading="lazy"`、公開日、チェックボックス `:checked="isSelected"`・`@change="toggle"`・`:disabled` は満杯かつ未選択）、「全解除」、URL 追加欄、選択済みパネル（`x-for="item in selected"`、↑↓・削除、`item.outside` で「対象チャンネル外」バッジ、`item.unavailable` で「この動画は削除または非公開になっています。外して保存してください」）、保存フォーム（`method=POST action=route('proposals.store', id)`、`@csrf`、`name="title" maxlength="150" required`、`description` 1000、`comment` 500、`proposer_name` 30（「実名は書かないでください」）、各カウンタ、ハニーポット `name="website"`（`class="hidden"`・`tabindex="-1"`・`autocomplete="off"`・`aria-hidden="true"`）、`x-for` で `<input type="hidden" name="video_ids[]" :value="item.id">`、`old()` の復元、エラー表示、保存ボタン近くの同意文「保存すると、[利用規約] と [プライバシーポリシー] に同意したものとみなされます」、送信時に保存ボタンを無効化）。
  - JS が動かない場合は「この画面の操作には JavaScript が必要です」を `<noscript>` で表示。

- [ ] **Step 1: 失敗するテストを書く**

```php
// ProposeScreenTest 追記
public function test_form_has_limits_honeypot_and_consent(): void
{
    $res = $this->get(route('channels.propose', $id))->assertOk();
    foreach (['x-data="proposer"', 'name="title"', 'maxlength="150"', 'maxlength="1000"', 'maxlength="500"', 'maxlength="30"',
        'name="website"', 'name="video_ids[]"', 'action="'.route('proposals.store', $id).'"'] as $s) { $res->assertSee($s, false); }
    $res->assertSee('同意したものとみなされます');
    $this->assertNoInlineCode($res->getContent());
}
public function test_unavailable_ids_are_passed_to_js(): void
{
    $res = $this->withSession(['unavailable_video_ids' => ['dQw4w9WgXcQ']])->get(route('channels.propose', $id));
    $this->assertSame(['dQw4w9WgXcQ'], $this->catalogPayload($res)['unavailableIds']);
}

// ViteEntriesTest
public function test_page_entries_are_registered_in_vite_input(): void
{
    $config = file_get_contents(base_path('vite.config.js'));
    foreach (glob(resource_path('js/*.js')) as $entry) {
        $this->assertStringContainsString('resources/js/'.basename($entry), $config, basename($entry));
    }
}
```

（`proposals.store` ルートは Task 13 で作る。このタスクでは `routes/web.php` に POST ルートを `ProposalController::store` の空実装 `abort(404)` で先に登録する）

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='ProposeScreenTest|ViteEntriesTest'`
Expected: FAIL

- [ ] **Step 3: propose.js と Blade を実装し、app.js・vite.config.js を更新**

- [ ] **Step 4: テストとビルドを確認**

Run: `php artisan test && npm run test:js && npm run build && ! grep -l "new Function" public/build/assets/*.js`
Expected: すべて成功

- [ ] **Step 5: 目視確認**

Run: `php artisan migrate:fresh && php artisan db:seed --class=DemoChannelSeeder && php artisan serve`（`.env` は `APP_ENV=local`、`npm run build` 済み）。シーダーは期限内・`last_synced_at=now` のチャンネル 1 件と動画 120 本（サムネイル null）を入れ、ID を出力する。`/channels/{id}/propose` で次を確認し、ブラウザのコンソールに CSP 違反・Alpine のエラーが出ないこと:
絞り込みで件数が変わる／チェックで選択数が増え 99 本で他のチェックが無効になる（`QUOTA_WRITE_LIMIT=250` にすると上限 4 本で確認しやすい）／リロードしても選択が残る／↑↓・削除・全解除が効く／文字数カウンタが増減する。

- [ ] **Step 6: コミット**

```bash
./vendor/bin/pint
git add resources/js resources/views vite.config.js database/seeders routes/web.php tests
git commit -m "feat: 提案作成画面の絞り込み・選択保持・99 本上限・並べ替え・URL 追加を追加"
```

---

### Task 13: 提案の保存

**Files:**
- Create: `database/migrations/2026_11_14_000300_create_proposals_tables.php`, `app/Models/Proposal.php`, `app/Models/ProposalItem.php`, `app/Http/Requests/StoreProposalRequest.php`, `app/Services/{ProposalCreator,CreatedProposal,UnavailableVideos,ChannelCacheMissing}.php`
- Modify: `app/Http/Controllers/ProposalController.php`（`store`）、`routes/web.php`（`throttle:proposal-store`）、`tests/Feature/PagesTest.php`
- Test: `tests/Feature/StoreProposalTest.php`

**Interfaces:**
- Consumes: `ProposalKey`, `YouTubeReader::videos()`, `ChannelCache`, `QuotaPolicy::maxItemsPerPlaylist()`
- Produces:
  - テーブル（spec 4.2 どおり）: `proposals`（`id`、`key_hash` char(64) unique、`channel_id` string(24)、`channel_title`、`channel_thumbnail_url` nullable、`title` string(150)、`description` text、`comment` text nullable、`proposer_name` string(30) nullable、`item_count` unsignedSmallInteger、`created_at`、`expires_at` index。`updated_at` なし）、`proposal_items`（`id`、`proposal_id` FK cascadeOnDelete、`position` unsignedSmallInteger、`video_id` char(11)、`video_channel_id` string(24)、`video_channel_title`、`is_outside_channel` bool、`title`、`thumbnail_url` nullable、`duration_seconds` unsignedInteger nullable、`published_at` nullable、unique(`proposal_id`,`position`)、unique(`proposal_id`,`video_id`)）
  - `Proposal`（`UPDATED_AT = null`、`items(): HasMany` は `position` 昇順、日時 `immutable_datetime`、`static findLiveByKey(string $key): ?self` は `isWellFormed` かつ `key_hash = hash` かつ `expires_at > now()`）
  - `StoreProposalRequest`: `prepareForValidation` で `title`・`proposer_name` は制御文字（改行含む）除去＋前後空白除去、`description`・`comment` は `\r\n`/`\r`→`\n` 後に `\n` と `\t` 以外の制御文字を除去＋前後空白除去、空文字は null。ルール: `title` required|string|max:150、`description` nullable|string|max:1000、`comment` nullable|string|max:500、`proposer_name` nullable|string|max:30、`video_ids` required|array|min:1|max:{maxItems}、`video_ids.*` required|string|distinct|regex:`/^[A-Za-z0-9_-]{11}$/`、`website` nullable|string。`messages()` で日本語（例: `video_ids.max` →「1 つの再生リストに入れられるのは最大 :max 本です」、`video_ids.min`/`required` →「動画を 1 本以上選んでください」、`video_ids.*.distinct` →「同じ動画が重複しています」、`video_ids.*.regex` →「動画 ID の形式が正しくありません」、`title.required` →「再生リスト名を入力してください」、各 `max` →「:attribute は :max 文字以内で入力してください」）と `attributes()`（再生リスト名・説明・チャンネル主へのコメント・ニックネーム）。
  - `ProposalCreator::create(string $channelId, array $validated): CreatedProposal`（`CreatedProposal(Proposal $proposal, string $key)`）。期限内の `ChannelCache` が無ければ `ChannelCacheMissing`。`videos($ids)` で再取得し、返らない／`public` 以外の ID があれば `UnavailableVideos(array $ids)`。1 トランザクションで提案と items（送信順に position 0..n−1、値はすべて `VideoInfo` から。`is_outside_channel` = `channelId !== $channelId`、`description` は null を `''` で保存）を作成、`expires_at = now + retention_days`。`ReadBudgetExceeded`／`YouTubeApiException` はそのまま投げる。
  - `ProposalController::store(StoreProposalRequest $request, string $channelId, ProposalCreator $creator): RedirectResponse`: `website` が空でなければ保存せず `home` へリダイレクト（エラー表示なし）。例外ごとに `back()->withInput()->withErrors(['video_ids' => …])`: `UnavailableVideos` →「この動画は削除または非公開になっています。外して保存してください」＋`with('unavailable_video_ids', $e->ids)`、`ReadBudgetExceeded` →「本日の取得上限に達したため、いまは保存できません。時間をおいて再度お試しください」、`YouTubeApiException` →「YouTube との通信に失敗しました。時間をおいて再度お試しください」、`ChannelCacheMissing` → `channels.propose` へ「一覧の保存期限が切れたため開き直しました。もう一度保存してください」。成功時 `session()->flash('proposal_key_shown', $key)` して `proposals.shared` へ。ログには `key_hash` 先頭 8 文字と本数のみ（info）。

- [ ] **Step 1: 失敗するテストを書く**

```php
// StoreProposalTest (RefreshDatabase, FakesYouTube)。setUp で期限内の channel_caches 行（$id）を用意
public function test_saves_server_values_in_submitted_order_and_hashes_key(): void
{
    $this->fakeVideos([$this->videoResource('bbbbbbbbbbb', $id, ['title' => 'サーバー値', 'duration' => 'PT1M5S']), $this->videoResource('aaaaaaaaaaa', $id)]);
    $res = $this->post(route('proposals.store', $id), ['title' => ' 案 ', 'video_ids' => ['bbbbbbbbbbb', 'aaaaaaaaaaa'], 'video_titles' => ['偽装']]);
    $p = Proposal::sole();
    $key = session('proposal_key_shown');
    $res->assertRedirect(route('proposals.shared', $key));
    $this->assertSame(ProposalKey::hash($key), $p->key_hash);
    $this->assertSame('案', $p->title);
    $this->assertSame(['bbbbbbbbbbb', 'aaaaaaaaaaa'], $p->items->pluck('video_id')->all());
    $this->assertSame('サーバー値', $p->items[0]->title);
    $this->assertSame(65, $p->items[0]->duration_seconds);
    $this->assertTrue($p->expires_at->equalTo(now()->addDays(30)));
    $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::READ));
    foreach (['proposals', 'proposal_items'] as $t) { $this->assertStringNotContainsString($key, json_encode(DB::table($t)->get())); }
}
public function test_rejects_more_than_max_items(): void { /* 100 本 → errors video_ids、proposals 0 行、API 送信なし */ }
public function test_rejects_duplicates_and_bad_ids(): void {}
public function test_deleted_or_private_video_rejects_save_and_flags_ids(): void
{
    // videos.list が 1 本しか返さない（もう 1 本は private で返す版も）→ errors.video_ids に「この動画は削除または非公開になっています。外して保存してください」、
    // session('unavailable_video_ids') に該当 ID、proposals 0 行
}
public function test_outside_channel_video_is_included_with_flag(): void { /* 他チャンネル動画 → 保存され is_outside_channel true、video_channel_title が写る */ }
public function test_honeypot_saves_nothing(): void { /* website=x → home へリダイレクト、0 行、API 送信なし */ }
public function test_rate_limited_after_5_in_10_minutes(): void { /* 6 回目 429 */ }
public function test_description_crlf_counts_as_one_char_and_emoji_title_at_limit_passes(): void
{
    // description = str_repeat("あ\r\n", 500)（正規化後 1000 文字）→ 保存成功、保存値に \r が無い
    // title = str_repeat('😀', 150) → 成功、151 → errors.title
}
public function test_control_characters_are_stripped(): void { /* title "a\u{0007}b\nc" → "abc" */ }
public function test_missing_channel_cache_redirects_to_propose(): void {}
public function test_budget_exhausted_and_api_error_messages(): void {}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=StoreProposalTest`
Expected: FAIL

- [ ] **Step 3: マイグレーション・モデル・FormRequest・ProposalCreator・store を実装**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add database/migrations app routes/web.php tests
git commit -m "feat: 提案の保存（videos.list 再取得・対象チャンネル外の既定包含・ハニーポット・ハッシュ化した公開キー）を追加"
```

---

### Task 14: 完了画面 S3 と端末内の提案一覧

**Files:**
- Create: `resources/views/proposals/shared.blade.php`, `resources/js/proposal-shared.js`, `resources/js/home.js`
- Modify: `app/Http/Controllers/ProposalController.php`（`shared`）、`routes/web.php`、`resources/views/home.blade.php`、`vite.config.js`、`tests/Feature/PagesTest.php`
- Test: `tests/Feature/SharedScreenTest.php`

**Interfaces:**
- Consumes: `Proposal::findLiveByKey()`、`session('proposal_key_shown')`、Task 11 の `rememberProposal`／`listProposals`／`clearSelection`
- Produces:
  - `ProposalController::shared(string $key): Response|RedirectResponse`、ルート `proposals.shared`。`hash_equals((string) session('proposal_key_shown'), $key)` かつ提案が有効なら表示、それ以外は `proposals.show` へリダイレクト（flash なので再読み込みでプレビューへ移る＝直後 1 回のみ）。
  - 画面: 共有 URL（`route('proposals.show', $key)` を読み取り専用 input に表示）、コピーボタン、「有効期限: {n月j日}（この日を過ぎると削除されます）」、「この URL を知っている人は誰でも閲覧できます」、「この URL は再表示できません。必ず控えてください」、プレビューへのリンク。ルート要素 `<div id="proposal-shared" data-url data-title data-channel-title data-channel-id data-expires-at(ISO)>`。
  - `proposal-shared.js`: コピー（`navigator.clipboard.writeText`、失敗時は input を選択して「コピーできませんでした。選択された URL を手動でコピーしてください」）、`rememberProposal`、`clearSelection`。
  - `home.js`: `#my-proposals` 要素に `listProposals` の結果を `textContent` とリンク `href` で描画（1 件も無ければ要素ごと非表示）。見出し「この端末で作った提案」。ホームは引き続きインデックス可。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_shown_once_right_after_store(): void
{
    // store → followRedirects の結果に共有 URL・有効期限・注意文、data-url 属性
    // 同じ /p/{key}/shared を再度 GET → proposals.show へのリダイレクト
}
public function test_direct_access_without_flash_redirects_to_preview(): void {}
public function test_home_has_my_proposals_container_and_no_inline_code(): void {}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='SharedScreenTest|ViteEntriesTest|PagesTest'`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テスト・ビルド・目視確認**

Run: `php artisan test && npm run build`
Expected: PASS。`php artisan serve` で Task 12 のデモデータから保存（`Http::fake` は効かないので、ローカルでは実 API キーを `.env` に入れるか、この目視確認は最後の手動確認にまとめる）し、コピー・ホームの「この端末で作った提案」・選択のクリアを確認。

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources vite.config.js tests
git commit -m "feat: 提案完了画面（共有 URL のコピー・有効期限）と端末内の提案一覧を追加"
```

---

### Task 15: プレビュー S4

**Files:**
- Create: `resources/views/proposals/show.blade.php`, `app/Support/DurationFormat.php`
- Modify: `app/Http/Controllers/ProposalController.php`（`show`）、`routes/web.php`、`tests/Feature/PagesTest.php`
- Test: `tests/Feature/PreviewScreenTest.php`, `tests/Unit/DurationFormatTest.php`

**Interfaces:**
- Consumes: `Proposal::findLiveByKey()`、`QuotaLedger::remainingToday()`、パーシャル
- Produces:
  - `ProposalController::show(string $key): View`、ルート `proposals.show`（`{key}` 制約 `ProposalKey::ROUTE_PATTERN`）。見つからない・期限切れは `abort(404)`（同一応答）。
  - `DurationFormat::clock(?int $s): string`（`65`→`1:05`、`3723`→`1:02:03`、null→`''`）、`DurationFormat::total(int $s): string`（`3723`→`1 時間 2 分`、`65`→`1 分`、`30`→`1 分未満`）
  - 画面: 再生リスト名・説明（`whitespace-pre-line`、自動リンクしない）・本数・合計時間・動画リスト（番号・サムネ・タイトル〈`https://www.youtube.com/watch?v={id}` へのリンク〉・チャンネル名・長さ・「対象チャンネル外」バッジ）、提案者コメント・ニックネーム（無ければ「匿名」）、「作成日 {n月j日}／削除予定日 {n月j日}」、状態「未作成」、`max-items`、`remaining-today`（`itemCount` = `item_count`）、「データ提供: YouTube」、チャンネル主向け欄「チャンネル主の方のログインは準備中です」（Phase 2 で置き換え）。

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_renders_proposal_escaped_with_badges_and_limits(): void
{
    // 提案を直接作成（title '<script>alert(1)</script>'、comment '<b>x</b> https://spam.example'、items 2 本で 1 本は outside、duration 65 と 3658）
    $res = $this->get(route('proposals.show', $key))->assertOk();
    $res->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    $res->assertDontSee('<b>x</b>', false);
    $res->assertDontSee('href="https://spam.example', false);
    $res->assertSee('対象チャンネル外');
    $res->assertSee('合計 1 時間 2 分');
    $res->assertSee('1 つの再生リストに入れられるのは最大 99 本');
    $res->assertSee('本日の残り: 再生リスト 1 つ＋動画あと 99 本まで作成できます');
    $res->assertSee('データ提供: YouTube');
    $res->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertNoInlineCode($res->getContent());
}
public function test_shows_reset_time_when_items_exceed_remaining(): void { /* write 4851 記録（残り 1 本）、提案 2 本 → 超過文言とリセット時刻 */ }
public function test_missing_and_expired_keys_return_identical_404(): void
{
    // 期限切れ（expires_at = now）の提案と、存在しない well-formed キー、形式不正キーの 3 つ → すべて 404 で本文が同一
}
// DurationFormatTest: 上記の値
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter='PreviewScreenTest|DurationFormatTest'`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app routes/web.php resources/views tests
git commit -m "feat: 提案のプレビュー画面（最大本数・本日の残り・同一の 404）を追加"
```

---

### Task 16: 削除バッチ（app:prune-expired）

**Files:**
- Modify: `app/Console/Commands/PruneExpiredData.php`
- Test: `tests/Feature/PruneExpiredDataTest.php`（既存 2 件は残して追記）

**Interfaces:**
- Consumes: `ChannelVideoCatalog::expireStale()`、`QuotaClock::todayPt()`、`Proposal`、`ChannelCacheExpiration`
- Produces: `PruneExpiredData::handle(ChannelVideoCatalog $catalog): int`。順に削除し、件数を 1 行ずつ出力（`proposals: n` 等）:
  1. `proposals` の `expires_at <= now`（items は cascade）
  2. `expireStale()`（チャンネル行＋全動画行を削除し期限切れ記録を作成）
  3. `channel_cache_expirations` の `expired_at <= now − retention_days`
  4. `quota_usage` の `day_pt < todayPt − 90 日`
  5. `cache`・`cache_locks` の `expiration <= time()`
  6. `sessions` の `last_activity < now − session.lifetime 分`

- [ ] **Step 1: 失敗するテストを書く**

```php
public function test_deletes_expired_proposals_with_items_only(): void { /* 29 日目の提案は残り、30 日経過分は proposals・proposal_items とも消える */ }
public function test_expires_channel_caches_as_unit_and_records(): void { /* cached_since+30 日の行と差分で足した行がまとめて消え、記録 1 行（列は channel_id・expired_at） */ }
public function test_deletes_expiration_records_after_30_days(): void { /* expired_at が 30 日前の記録は消え、29 日前は残る */ }
public function test_deletes_quota_usage_older_than_90_days(): void {}
public function test_deletes_expired_cache_rows_and_stale_sessions(): void {}
public function test_only_prune_is_scheduled(): void
{
    // 定期取り直しジョブが無いこと（spec 10.5）
    $events = app(Schedule::class)->events();
    $this->assertCount(1, $events);
    $this->assertStringContainsString('app:prune-expired', (string) $events[0]->command);
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `php artisan test --filter=PruneExpiredDataTest`
Expected: FAIL

- [ ] **Step 3: 実装する**

- [ ] **Step 4: テストが通ることを確認**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
./vendor/bin/pint
git add app/Console/Commands/PruneExpiredData.php tests/Feature/PruneExpiredDataTest.php
git commit -m "feat: 日次削除バッチ（提案・一覧キャッシュのチャンネル単位削除・期限切れ記録・台帳・cache・sessions）を実装"
```

---

## 最終確認（PR 前）

- [ ] `php artisan test`、`./vendor/bin/pint --test`、`npm run test:js`、`npm run build`、`! grep -l "new Function" public/build/assets/*.js` がすべて成功。
- [ ] `grep -rn '{!!' resources/views` と `grep -rn 'innerHTML\|x-html' resources/js` が 0 件。`grep -rn 'withoutVerifying\|key=' app/Services` で API キーをクエリに載せる箇所・TLS 検証の無効化が無い。
- [ ] `git push -u origin phase1-proposer` → PR 作成（本文末尾に指定の署名行）。

## 人手で行う確認（実在チャンネル・実 API。11 章 Phase 1 の完了条件）

1. ローカル `.env` に本ツール専用 GCP プロジェクトの `YOUTUBE_API_KEY` を設定し、実在チャンネル（ハンドル入力と UC ID 入力の両方）で一覧が表示されること。
2. 動画を選び（URL 追加で他チャンネル動画を 1 本含め、「対象チャンネル外」バッジを確認）保存し、完了画面の URL を**別ブラウザ**で開いてプレビューできること。コピーボタン・「この端末で作った提案」が動くこと。
3. 2 回目の取り込み: `channel_caches.last_synced_at` を 7 時間前に書き換えて（または 6 時間待って）同じチャンネルを開き、`quota_usage` の当日 `read` が **2 増える**（channels.list 1＋playlistItems 1 ページ）ことを `php artisan tinker` で確認（新規 50 本未満のチャンネルで）。
4. 全ページ（ホーム・S2・S3・S4・404・429）でブラウザのコンソールに CSP 違反が出ないこと。
5. spec U8 の実測と追記: `X-Goog-Api-Key` ヘッダが受け付けられること、uploads に API キーで公開動画のみが出ること、`snippet.customUrl` がハンドルと一致すること、アップロード 0 本のチャンネルで `playlistNotFound` になるか、uploads の並び（プレミア公開等）。結果を spec に追記する。
6. 本番（`https://list-maker.alpacasandbag.jp`）にデプロイし、1・2 を本番でも確認。`php artisan app:prune-expired` を手動実行してエラーが無いこと。
