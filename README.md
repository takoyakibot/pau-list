# List Maker

YouTube チャンネルの動画から再生リストの作成を支援する Laravel 13 アプリです。ユーザーテーブルは持たず、Google OAuth で本人の YouTube に再生リストを作成します。
設計書: [docs/superpowers/specs/2026-11-14-playlist-proposal-design.md](docs/superpowers/specs/2026-11-14-playlist-proposal-design.md)

## ローカル開発

```bash
composer install
npm install
cp .env.example .env          # ローカル開発用の値です（本番へそのままコピーしないこと）
php artisan key:generate
touch database/database.sqlite
php artisan migrate           # sessions / cache / cache_locks が必要（スケジューラの withoutOverlapping が cache_locks を使う）
npm run build                 # または npm run dev
php artisan serve
php artisan test
./vendor/bin/pint
```

- CSP（`script-src 'self'` 等）の下で `npm run dev`（Vite dev サーバー）を使う場合は `APP_ENV=local` が必要です（SecurityHeaders が local のときだけ Vite の接続先を許可します）。
- 新しい JS エントリを追加したら、必ず `vite.config.js` の `input` にも追加してください。
- Alpine は CSP ビルド（`@alpinejs/csp`）のみ使います。Blade にインライン `<script>` / `<style>` / `style="..."` を書かないでください。

## 本番デプロイ（CoreServer 系の共有ホスティング）

サーバーに composer / node は無いため、ローカルでビルドして rsync で送ります（channel-calendar と同じ方式）。
サーバーの PHP は `/usr/local/php84/bin/php` を使います。パッチバージョンは **8.4.1 以上**が必要です（`composer.json` の `config.platform.php` が 8.4.1）。

### 初回のみ

1. `cp deploy.env.example deploy.env` して、サーバーの SSH 接続先・鍵・配置パス・公開 URL を記入する（`deploy.env` は git 管理外。`deploy.env.example` を参照）。
2. DirectAdmin で MySQL のデータベースとユーザーを作成する。
3. サブドメイン `list-maker.alpacasandbag.jp` を作成し、ホスティングの管理画面で SSL 証明書（Let's Encrypt）を発行する。
   - **DirectAdmin でサブドメインのドキュメントルートを `<REMOTE_PATH>/public` に設定する**（`<REMOTE_PATH>` は `deploy.env` の配置パス）。deploy.sh はプロジェクトルートを丸ごと `<REMOTE_PATH>` に送るため、ドキュメントルートが `<REMOTE_PATH>` のままだと `.env`・`vendor`・`storage` が Web から見えてしまう（安全網としてルートの `.htaccess` が全拒否するが、それに頼らないこと）。
   - 管理画面の「SSL を強制（HTTP→HTTPS リダイレクト）」を有効にする。`public/.htaccess` にはリダイレクト規則を書かない（プロキシ構成が未確認でループの恐れがあるため）。
4. サーバーの `.env` を作成する（rsync では送られない）。**`.env.example` はローカル開発用の値（`APP_ENV=local`、`APP_DEBUG=true`、`DB_CONNECTION=sqlite`、`MAIL_MAILER=log` など）なので、本番へそのままコピーしないこと。** 以下の本番値にする。秘密は管理画面・サーバー上で直接入力し、リポジトリには書かない。

   ```env
   APP_NAME="List Maker"
   APP_ENV=production
   APP_KEY=                      # 空のままで可。deploy.sh が生成します
   APP_DEBUG=false
   APP_URL=https://list-maker.alpacasandbag.jp
   LOG_CHANNEL=daily
   LOG_LEVEL=info                # 8.8 の記録（作成の開始・完了、制限超過、問い合わせの送信など）が info のため
   LOG_DAILY_DAYS=14

   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   # DB_SOCKET=/var/lib/mysql/mysql.sock   # 共有ホスティングで socket 接続が必要な場合のみ有効にする（パスは要確認）
   DB_DATABASE=（作成したDB名）
   DB_USERNAME=（作成したユーザー）
   DB_PASSWORD=（パスワード）

   SESSION_DRIVER=database
   SESSION_ENCRYPT=true
   SESSION_LIFETIME=120
   SESSION_SECURE_COOKIE=true
   SESSION_SAME_SITE=lax
   CACHE_STORE=database
   QUEUE_CONNECTION=sync

   YOUTUBE_API_KEY=              # 秘密
   GOOGLE_CLIENT_ID=             # 秘密
   GOOGLE_CLIENT_SECRET=         # 秘密
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

   QUOTA_WRITE_LIMIT=5000
   QUOTA_READ_BUDGET=2000
   QUOTA_GOOGLE_DAILY=10000
   CHANNEL_FETCH_MAX_VIDEOS=1000
   CHANNEL_CACHE_REUSE_MINUTES=360
   CHANNEL_SYNC_KNOWN_RUN=3
   DATA_RETENTION_DAYS=30
   CAPTCHA_ENABLED=false

   # MAIL_* には CORESERVER の SMTP の値（ホスト・ポート・MAIL_SCHEME・認証）を入れる
   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=
   MAIL_USERNAME=                # 秘密
   MAIL_PASSWORD=                # 秘密
   MAIL_SCHEME=                  # 旧 MAIL_ENCRYPTION。名称・値は要確認
   MAIL_FROM_ADDRESS=            # サブドメインと同じドメインにする（SPF/DKIM）
   MAIL_FROM_NAME="${APP_NAME}"
   CONTACT_TO_ADDRESS=           # 問い合わせの宛先（空なら問い合わせフォームは「送信できませんでした」になる）

   # #7 で REMOTE_ADDR が全員同じプロキシ IP だと分かった場合だけ、そのプロキシの IP/CIDR を書く。空なら信頼しない
   TRUSTED_PROXIES=
   ```

   - 本番で `MAIL_MAILER` が `log`・`array` のまま、または `CONTACT_TO_ADDRESS` が空だと、問い合わせは送信されず「送信できませんでした」になります（ログに `Contact mail not configured`）。

5. `./deploy.sh` を実行する（origin/main 一致確認 → テスト → ビルド → rsync → migrate → キャッシュクリア。APP_KEY が空なら生成される）。
   デプロイ後、秘密ファイルが公開されていないことを確認する（どちらも **403 か 404** であること。200 ならすぐにドキュメントルート設定を見直す）:

   ```bash
   curl -sI https://list-maker.alpacasandbag.jp/.env | head -1
   curl -sI https://list-maker.alpacasandbag.jp/composer.json | head -1
   curl -sI http://list-maker.alpacasandbag.jp/ | head -1    # 301/302 で https へ転送されること
   curl -sI https://list-maker.alpacasandbag.jp/ | head -1    # 200 であること（.htaccess の Require が使えないと全体が 500 になる）
   ```

6. サーバーの cron に以下を追加する（毎分。Laravel のスケジューラが日次 04:00 JST に `app:prune-expired` を起動します）:

   ```
   * * * * * cd /path/to/public_html && /usr/local/php84/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
   ```

### 2回目以降

```bash
./deploy.sh
```

`.exclude-list` に載っているもの（`.env`・`.env.*`（`.env.example` を含む）、`deploy.env`、`auth.json`、`.DS_Store`、ルートの `/docs`・`/tests`・`/README.md`・`/phpunit.xml`（`vendor` 内の同名は送る）、`*.ini`、`storage/logs`、`storage/framework`、`public/hot` など）は転送されません。rsync は `--delete-after`（転送が終わってから本番側の余分なファイルを消す）で、`deploy.env` の `REMOTE_PATH` は `/` で始まる絶対パス（英数字と `. _ / -` のみ、`/` だけは不可）でないと止まります。rsync の後（サーバー側の migrate など）で失敗した場合は「ファイルは本番に転送済みです」と表示されるので、原因を直して再実行してください。

`deploy.sh` は HEAD が `origin/main` と一致し、追跡ファイルに未コミットの変更が無い場合だけ進みます。緊急時のみ `ALLOW_NON_MAIN_DEPLOY=1 ./deploy.sh` で迂回できます（古いブランチから出すと本番が巻き戻るため通常は使わない）。

## Google Cloud セットアップ

1. **本ツール専用の**プロジェクトを新規作成する（既存サービスのプロジェクトは使わない）。YouTube Data API v3 を有効化する。
2. API キーを作成し、API 制限を「YouTube Data API v3 のみ」にする。アプリケーション制限は、サーバーの送信元 IP が固定と確認できれば IP 制限、できなければ「なし」。
3. OAuth 同意画面: 外部、アプリ名「List Maker」（YouTube を含めない）、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL（`https://list-maker.alpacasandbag.jp/...`）、承認済みドメイン `alpacasandbag.jp`、スコープに `.../auth/youtube` を追加し、スコープの用途説明に「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」と書く。公開ステータスを「本番環境」にする（未審査のまま）。
4. OAuth クライアント ID（ウェブアプリケーション）を作成し、承認済みのリダイレクト URI に次の 2 つを登録する:
   - `https://list-maker.alpacasandbag.jp/auth/google/callback`
   - `http://localhost:8000/auth/google/callback`（ローカル用）
5. Search Console でドメイン所有確認をする（審査前）。
6. クオータ使用量は Google Cloud コンソールの「API とサービス → 割り当て」で確認する。リセットは太平洋時間 0 時（日本時間 16 時〈夏時間〉／17 時〈標準時間〉）。アプリの台帳（`quota_usage`）と見比べ、見込みのずれを確認する。

## 運用

### 日次バッチ・ログイン


- 日次バッチ `app:prune-expired` は毎日 04:00（Asia/Tokyo）に実行されます（cron の `schedule:run` 経由）。
- ログは `storage/logs`（daily、14 日保持）。
- **削除バッチは cron が毎日動いていることが前提です。** 一覧キャッシュ・提案は「次回実行までに期限が来る行（現在 + 24 時間）」を削除するため、毎日動いていれば表示した削除日を過ぎて残ることはありません。cron が止まる・失敗すると、その分だけ保存期間（30 日）を超えて残ります。`storage/logs/cron.log` と `storage/logs/laravel-*.log`（`app:prune-expired` の結果・エラー）を定期的に確認してください。バッチが失敗したステップがあると終了コードが 0 以外になります。
- **ゲートウェイのタイムアウト（要確認: CORESERVER）**: チャンネルの取り込みは最悪で約 85 秒かかることがあります（同じチャンネルを取り込み中の別リクエストのロック待ち最大 40 秒 + 取り込み全体の上限 30 秒 + 最後の API 呼び出しのタイムアウト 15 秒）。CORESERVER の Web サーバー／PHP の実行時間制限（`max_execution_time`、リバースプロキシのタイムアウト）がこれより短いと、取り込みの途中で 504 などになります（途中まで読んだ分は保存されないため、データは壊れません）。実際の制限値を確認し、短い場合は `ChannelVideoCatalog` のロック待ち時間・取り込み上限を下げることを検討してください。
  - **チャンネル主のログインのコールバック**（`/auth/google/callback`）も最悪で約 45 秒かかります（トークン交換 15 秒 + チャンネル確認 15 秒 + 失敗時のトークン取り消し 15 秒。それぞれ接続 5 秒・全体 15 秒のタイムアウト、再試行なし）。途中で切れた場合は画面にエラーが出ますが、プレビューからもう一度ログインし直せば済みます（PHP 側の処理が続いてログインが完了していることもあります。取得したトークンはセッション以外には保存されず、約 1 時間で失効します）。
  - **限定公開の動画の読み込み**（`/owner/videos/unlisted`）も最悪で約 45 秒かかります（ページングの上限 30 秒 + 最後の API 呼び出しのタイムアウト 15 秒）。途中で切れても何も保存されず、画面には「通信に失敗しました」と表示されるだけです。
  - これらより制限が短い場合は、`YouTubeOwnerClient::DEADLINE_SECONDS` や各呼び出しのタイムアウトを下げることを検討してください。

- **チャンネル主のログイン（Phase 2）**: ログイン中のアクセストークンは暗号化 DB セッション（`SESSION_DRIVER=database`・`SESSION_ENCRYPT=true`）にだけ置きます。保持は最長 `SESSION_LIFETIME`（120 分）で、トークン自体も約 1 時間で失効します。ログアウトでセッションを即削除し、Google 側の承認も取り消します（取り消しに失敗したときはログアウト後の画面で https://myaccount.google.com/permissions からの取り消しを案内します）。refresh_token は保存しません。
- **コールバック URL とアクセスログ**: `/auth/google/callback?code=...&state=...` は Web サーバーのアクセスログに残りえます。認可コードは一回限り・短命で、state もセッションから一度で取り出して削除するため再利用できません（spec 6.7 I3）。本番でアクセスログに残るかは確認して spec に記録してください。
- **`GOOGLE_REDIRECT_URI`**: GCP に登録したリダイレクト URI と完全に一致させてください（http/https・末尾スラッシュも含む）。本番は `https://` 以外だとログインが 503（未設定扱い）になります。
- **`APP_DEBUG`**: 本番で `true` だと起動時に `APP_DEBUG is enabled in production` の警告をログに出します。本番は必ず `false` にしてください。

### 削除依頼

問い合わせフォーム（種別「削除依頼」）でメールを受けたら、**7 日以内**に提案を削除します（Developer Policies III.E.4.g）。

```bash
php artisan app:delete-proposal <提案の URL またはキー>
# サーバーでは: cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan app:delete-proposal <提案の URL またはキー>
```

- 対象を表示して確認を求めます（`--force` で確認を省略）。
- 作成中の提案でも、作成の記録ごと削除します（書込の予約は解放。チャンネル主のブラウザの次のステップは 404 で止まります）。チャンネル主自身の削除（作成中は不可）とは扱いが違います。
- YouTube に作成済みの再生リストは削除されません（チャンネル主の資産のため）。必要なら依頼者にそう伝えてください。
- 対応後、問い合わせメールはメールボックスから **90 日以内**に削除します。ログには `Proposal deleted by operator` が残ります。

### 放置された作成の予約の解放

作成中のまま放置された作成が書込の予約を持ち続け、他のチャンネルが作成できないときに使います。ログの `Creation started` の `channel`（チャンネル ID の先頭 8 文字）と `creation_id` で対象を特定します。

```bash
php artisan app:release-creation-reservation <creation_id>
```

実行中の作成は一時停止になり、未使用の予約が解放されます（ログに `Creation reservation released by operator`）。チャンネル主は後から「続きから」で再予約して再開できます。

### レート制限

値は spec 8.1 の表のとおりです（Phase 4 で見直し、変更なし）。

| 対象 | 制限 | 変更箇所 |
|---|---|---|
| 全ページ共通 | 120 回/分/利用者 | `AppServiceProvider`（`global`） |
| 動画 URL 追加 | 30 回/分/利用者 | `AppServiceProvider`（`video-lookup`） |
| OAuth 開始・コールバック | 10 回/分/利用者 | `AppServiceProvider`（`oauth`） |
| 限定公開動画の読み込み | 5 回/10 分/チャンネル | `AppServiceProvider`（`owner-unlisted`） |
| 作成開始・失敗分再試行 | 5 回/時/チャンネル | `AppServiceProvider`（`creation-start`） |
| 作成ステップ | 2 回/秒/セッション | `AppServiceProvider`（`creation-step`） |
| 一時停止・続きから・中止・作り直し | 30 回/分/セッション | `AppServiceProvider`（`creation-operation`） |
| 提案保存の POST（成否を問わない） | 20 回/10 分/利用者 | `AppServiceProvider`（`proposal-post`） |
| 提案保存（成功した回だけ） | 5 回/10 分・30 回/日/利用者、全体 500 件/日 | `ProposalStoreLimits` |
| チャンネル取得（キャッシュ無し時） | 5 回/分・30 回/時/利用者 | `ChannelFetchThrottle` |
| 問い合わせ送信（送信を試みた回） | 3 回/10 分・10 回/日/利用者、全体 50 件/日 | `ContactLimits` |

- 「利用者」のキーは `ClientKey`（`APP_KEY` による IP の HMAC。IPv6 は /64 単位）で、生の IP は cache にも保存しません。
- `TRUSTED_PROXIES` が空なら `REMOTE_ADDR` をそのまま使います。前段のプロキシで全員が同じ IP に見える場合だけ、そのプロキシを `TRUSTED_PROXIES` に書きます（`*` や不正な値は起動時に警告ログ）。

### ログ

`storage/logs/laravel-YYYY-MM-DD.log`（daily、14 日保持、本番は `LOG_LEVEL=info`）。

- 出すもの: API エラー（メソッド・HTTP ステータス・reason）、クオータ超過・予約不足・読取予算切れ、レート制限超過（制限の名前のみ）、作成の開始・完了・一時停止（creation id、本数、予約量。開始はチャンネル ID の先頭 8 文字も）、運用者の削除・予約の解放、問い合わせ送信の成否（例外の種類のみ）、未処理の DB・HTTP 例外（SQLSTATE・例外クラス・HTTP ステータスのみ）。
- 出さないもの: アクセストークン・API キー・認可コード・提案の公開キー・IP・問い合わせの本文とメールアドレス・SQL のバインド値・リクエスト URL とクエリ・スタックトレースの引数。
- 主なメッセージ名: `Creation started`・`Creation completed`・`Creation paused`・`Write quota insufficient`・`Read budget exhausted`・`Rate limit exceeded`・`Contact sent`・`Contact mail failed`・`Proposal deleted by operator`。

### 問い合わせの送信テスト

1. 本番の `/contact` からフォームで 1 通送る（種別「一般」、本文は「送信テスト」など）。
2. `CONTACT_TO_ADDRESS` に件名 `[List Maker] お問い合わせ（一般）` のメールが届くことを確認する。
3. サーバーの `storage/logs/laravel-*.log` に `Contact sent` が出ていることを確認する（本文・アドレスは出ない）。
4. DB のテーブルの件数が増えていないことを確認する（問い合わせは保存しない）。

## リクエストボディの上限（要確認: CORESERVER）

`LimitRequestBody` ミドルウェアは `Content-Length` と読み込んだボディが 64KB を超えると 413 を返しますが、PHP のミドルウェアでは multipart ボディを PHP が解析する前に制限できません。サーバー側でも約 64KB に抑えるため、`post_max_size` / `upload_max_filesize`（`.user.ini` やコントロールパネルで設定）や、Web サーバーの `LimitRequestBody` を設定してください。CORESERVER で設定できるか・反映されるかは未確認です。

## 公開前の人手作業

- [ ] #7: 本番で `REMOTE_ADDR` が利用者ごとの IP かを確認し、全員同じなら `.env` に `TRUSTED_PROXIES` を設定する（未設定のままなら信頼しない）。送信元 IP が固定なら API キーを IP 制限にする。
- [ ] SMTP 疎通: CORESERVER の SMTP 値（ホスト・ポート・`MAIL_SCHEME`・認証）を本番 `.env` に入れ、送信元ドメインの SPF/DKIM を確認し、本番フォームから 1 通送って `CONTACT_TO_ADDRESS` に届くこと・DB に残らないことを確認する（上の「問い合わせの送信テスト」。Phase 4 の完了条件）。
- [ ] 本番 `.env` を `LOG_LEVEL=info` に変更し、デプロイ後に `curl -sI https://list-maker.alpacasandbag.jp/ | head -1` が 200 であることを確認する。
- [ ] OAuth 同意画面: アプリ名「List Maker」、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL、承認済みドメイン `alpacasandbag.jp`、スコープ `.../auth/youtube`、スコープの用途説明（「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」）を登録し、ブランディングを公開、公開ステータスを「本番環境」にする（未審査のまま）。
- [ ] Search Console で `alpacasandbag.jp`（またはサブドメイン）の所有権を確認する（プロジェクトのオーナー／編集者のアカウントで）。
- [ ] 第三者アカウント（自分の別アカウント）で、ログイン前の案内どおりに未確認アプリの警告を越え、提案 → ログイン → 編集 → 作成 → ログアウト（承認が消える。https://myaccount.google.com/permissions で確認）まで完走できることを確認する（Phase 4 の完了条件）。実際の警告画面・権限の確認画面の文言が `resources/views/partials/unverified-app.blade.php` の案内（「このアプリは Google で確認されていません」「詳細」「（アプリ名またはドメイン）（安全ではないページ）に移動」「続行」、権限のチェックボックス）と合うかを確かめ、違えば文言を直す。必要なら警告画面のスクリーンショットを撮る（画像付き説明を付けるかは監督者判断）。
- [ ] 英語のデモ動画（Phase 5 の準備）: 同意画面に「List Maker」、アドレスバーに OAuth クライアント ID が見える状態で、ログイン〜限定公開動画の表示〜作成までを英語で説明し、YouTube に限定公開でアップロードする。
- [ ] spec 9.1 のチェックリストを本番で目視確認する（全ページのフッター、S2/S4 の同意文、出典表示、S6 の取り消し案内、プライバシーポリシー・規約の本文）。
- [ ] Google の審査を申請するときは、ログインの流れの案内（`partials/unverified-app.blade.php` の「本サービスは Google の審査を申請する前のため、この警告が表示されます」）を「審査中」の文言に直す（審査が通って警告が出なくなったら案内ごと見直す）。
