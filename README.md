# List Maker（仮称）

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
   LOG_LEVEL=warning
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

   MAIL_MAILER=smtp
   MAIL_HOST=
   MAIL_PORT=
   MAIL_USERNAME=                # 秘密
   MAIL_PASSWORD=                # 秘密
   MAIL_SCHEME=                  # 旧 MAIL_ENCRYPTION。名称・値は要確認
   MAIL_FROM_ADDRESS=            # サブドメインと同じドメインにする（SPF/DKIM）
   MAIL_FROM_NAME="${APP_NAME}"
   CONTACT_TO_ADDRESS=           # 問い合わせの宛先
   ```

5. `./deploy.sh` を実行する（origin/main 一致確認 → テスト → ビルド → rsync → migrate → キャッシュクリア。APP_KEY が空なら生成される）。
   デプロイ後、秘密ファイルが公開されていないことを確認する（どちらも **403 か 404** であること。200 ならすぐにドキュメントルート設定を見直す）:

   ```bash
   curl -sI https://list-maker.alpacasandbag.jp/.env | head -1
   curl -sI https://list-maker.alpacasandbag.jp/composer.json | head -1
   curl -sI http://list-maker.alpacasandbag.jp/ | head -1    # 301/302 で https へ転送されること
   ```

6. サーバーの cron に以下を追加する（毎分。Laravel のスケジューラが日次 04:00 JST に `app:prune-expired` を起動します）:

   ```
   * * * * * cd /path/to/public_html && /usr/local/php84/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
   ```

### 2回目以降

```bash
./deploy.sh
```

`.exclude-list` に載っているもの（`.env`・`.env.*`（`.env.example` を含む）、`deploy.env`、`auth.json`、`.DS_Store`、`docs`、`tests`、`*.ini`、`storage/logs`、`storage/framework`、`public/hot` など）は転送されません。

`deploy.sh` は HEAD が `origin/main` と一致し、追跡ファイルに未コミットの変更が無い場合だけ進みます。緊急時のみ `ALLOW_NON_MAIN_DEPLOY=1 ./deploy.sh` で迂回できます（古いブランチから出すと本番が巻き戻るため通常は使わない）。

## Google Cloud セットアップ

1. **本ツール専用の**プロジェクトを新規作成する（既存サービスのプロジェクトは使わない）。YouTube Data API v3 を有効化する。
2. API キーを作成し、API 制限を「YouTube Data API v3 のみ」にする。アプリケーション制限は、サーバーの送信元 IP が固定と確認できれば IP 制限、できなければ「なし」。
3. OAuth 同意画面: 外部、アプリ名「List Maker」（仮称。YouTube を含めない）、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL（`https://list-maker.alpacasandbag.jp/...`）、承認済みドメイン `alpacasandbag.jp`、スコープに `.../auth/youtube` を追加。公開ステータスを「本番環境」にする（未審査のまま）。
4. OAuth クライアント ID（ウェブアプリケーション）を作成し、承認済みのリダイレクト URI に次の 2 つを登録する:
   - `https://list-maker.alpacasandbag.jp/auth/google/callback`
   - `http://localhost:8000/auth/google/callback`（ローカル用）
5. Search Console でドメイン所有確認をする（審査前）。
6. クオータ使用量は Google Cloud コンソールの「API とサービス → 割り当て」で確認する。リセットは太平洋時間 0 時（日本時間 16 時〈夏時間〉／17 時〈標準時間〉）。アプリの台帳（`quota_usage`）と見比べ、見込みのずれを確認する。

## 運用メモ

- 日次バッチ `app:prune-expired` は毎日 04:00（Asia/Tokyo）に実行されます（cron の `schedule:run` 経由）。
- ログは `storage/logs`（daily、14 日保持）。
- **削除バッチは cron が毎日動いていることが前提です。** 一覧キャッシュ・提案は「次回実行までに期限が来る行（現在 + 24 時間）」を削除するため、毎日動いていれば表示した削除日を過ぎて残ることはありません。cron が止まる・失敗すると、その分だけ保存期間（30 日）を超えて残ります。`storage/logs/cron.log` と `storage/logs/laravel-*.log`（`app:prune-expired` の結果・エラー）を定期的に確認してください。バッチが失敗したステップがあると終了コードが 0 以外になります。
- **ゲートウェイのタイムアウト（要確認: CORESERVER）**: チャンネルの取り込みは最悪で約 85 秒かかることがあります（同じチャンネルを取り込み中の別リクエストのロック待ち最大 40 秒 + 取り込み全体の上限 30 秒 + 最後の API 呼び出しのタイムアウト 15 秒）。CORESERVER の Web サーバー／PHP の実行時間制限（`max_execution_time`、リバースプロキシのタイムアウト）がこれより短いと、取り込みの途中で 504 などになります（途中まで読んだ分は保存されないため、データは壊れません）。実際の制限値を確認し、短い場合は `ChannelVideoCatalog` のロック待ち時間・取り込み上限を下げることを検討してください。

## リクエストボディの上限（要確認: CORESERVER）

`LimitRequestBody` ミドルウェアは `Content-Length` と読み込んだボディが 64KB を超えると 413 を返しますが、PHP のミドルウェアでは multipart ボディを PHP が解析する前に制限できません。サーバー側でも約 64KB に抑えるため、`post_max_size` / `upload_max_filesize`（`.user.ini` やコントロールパネルで設定）や、Web サーバーの `LimitRequestBody` を設定してください。CORESERVER で設定できるか・反映されるかは未確認です。
