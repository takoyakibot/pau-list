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

### リンクプレビューの画像（OGP）

Discord・X などのリンクプレビューの画像 `public/images/og-card.png`（1200×630）は `resources/og/og-card.html` から作ります（アプリからは配信しない元ファイル。フォントは macOS の Hiragino Sans）。元ファイルを直したら、macOS で次を実行して PNG もコミットしてください（300 KB 未満に保つ）。

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --hide-scrollbars \
  --force-device-scale-factor=1 --window-size=1200,630 \
  --screenshot="$PWD/public/images/og-card.png" "file://$PWD/resources/og/og-card.html"
```

メタタグは `resources/views/partials/og.blade.php`（文言は `App\Support\OpenGraph`、設計書 D49）。

## 本番デプロイ（CoreServer 系の共有ホスティング）

サーバーに composer / node は無いため、ローカルでビルドして rsync で送ります（channel-calendar と同じ方式）。
サーバーの PHP は `/usr/local/php84/bin/php` を使います。パッチバージョンは **8.4.1 以上**が必要です（`composer.json` の `config.platform.php` が 8.4.1。Web 側の PHP が 8.4.1 未満だと `vendor/composer/platform_check.php` が全ページを 500 にします）。

以下、`<REMOTE_PATH>` は `deploy.env` の `REMOTE_PATH`（サーバー上の配置先の絶対パス）、`<…>` はサーバーごとの値です。サーバー上のコマンドは SSH でログインして実行します。

### 初回のみ

手順の番号は GitHub Issue の #2〜#5 に対応します（#2: サブドメインと SSL、#3: DB、#4: `.env`・cron・`deploy.env`、#5: 初回デプロイと確認）。

1. `cp deploy.env.example deploy.env` して、サーバーの SSH 接続先・鍵・配置パス・公開 URL を記入する（`deploy.env` は git 管理外。`deploy.env.example` を参照）。`REMOTE_PATH` は `/` で始まる絶対パスで、`.`・`..` の区間を含めない（deploy.sh が拒否します）。
2. （#3）DirectAdmin で MySQL のデータベースとユーザーを作成する。
   - 文字コードは **utf8mb4**（照合順序は既定の `utf8mb4_unicode_ci` などのままで可。YouTube の ID の列はマイグレーションが `utf8mb4_bin` にします）。
   - DirectAdmin ではデータベース名・ユーザー名の先頭に `<アカウント名>_` が付きます。`.env` には**プレフィックス付きの名前**を書く。
   - `DB_HOST=localhost` は PHP では UNIX ソケット接続になります。接続できない場合は `/usr/local/php84/bin/php -i | grep -i 'default_socket'` で出たパスを `DB_SOCKET` に書くか、`DB_HOST=127.0.0.1`（TCP）を試す。
3. （#2）サブドメイン `list-maker.alpacasandbag.jp` を作成し、次の順で設定する。
   1. **PHP のバージョン**: DirectAdmin の PHP バージョンの設定（「PHP バージョン選択」など）で、このサブドメインを **PHP 8.4** にする。確認は手順 7 のデプロイ後に行う（`curl` が 200 なら 8.4.1 以上で動いています。8.4.1 未満なら 500）。SSH 上の CLI は `/usr/local/php84/bin/php -v` が 8.4.1 以上であること。
   2. **ドキュメントルート**: DirectAdmin でサブドメインのドキュメントルートを `<REMOTE_PATH>/public` にする。deploy.sh はプロジェクトルートを丸ごと `<REMOTE_PATH>` に送るため、ドキュメントルートが `<REMOTE_PATH>` のままだと `.env`・`vendor`・`storage` が Web から見えてしまう（安全網としてルートの `.htaccess` が全拒否するが、それに頼らないこと）。**`<REMOTE_PATH>/public` に設定できない場合はデプロイせずに止め、Issue #2 に状況を書いて判断を仰ぐ。** ルートの `.htaccess` は消さないこと。
   3. **SSL**: ドキュメントルートを設定してから、ホスティングの管理画面で SSL 証明書（Let's Encrypt）を発行する（認証用のファイルは `<REMOTE_PATH>/public/.well-known` に置かれます。`.exclude-list` に `/public/.well-known` があるため、deploy.sh の `--delete-after` で消されません）。
   4. 管理画面の「SSL を強制（HTTP→HTTPS リダイレクト）」を有効にする。`public/.htaccess` にはリダイレクト規則を書かない（プロキシ構成が未確認でループの恐れがあるため）。
   5. **確認**: `curl -sI https://list-maker.alpacasandbag.jp/ | head -1` が証明書のエラーなく応答すること（デプロイ前なので 403/404 でも可）。
4. （#4）サーバーの `<REMOTE_PATH>/.env` を作成する（rsync では送られない）。**`.env.example` はローカル開発用の値（`APP_ENV=local`、`APP_DEBUG=true`、`DB_CONNECTION=sqlite`、`MAIL_MAILER=log` など）なので、本番へそのままコピーしないこと。** 以下の本番値にし、`<…>` をサーバーの値に置き換える。秘密は管理画面・サーバー上で直接入力し、リポジトリには書かない。

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
   # DB_SOCKET=<ソケットのパス>    # 接続できない場合のみ（手順 2 の確認方法）
   DB_DATABASE=<アカウント名>_<DB 名>
   DB_USERNAME=<アカウント名>_<ユーザー名>
   DB_PASSWORD=<パスワード>        # 秘密

   SESSION_DRIVER=database
   SESSION_ENCRYPT=true
   SESSION_LIFETIME=120
   SESSION_SECURE_COOKIE=true
   SESSION_SAME_SITE=lax
   CACHE_STORE=database
   QUEUE_CONNECTION=sync

   YOUTUBE_API_KEY=<API キー>                 # 秘密
   GOOGLE_CLIENT_ID=<OAuth クライアント ID>    # 秘密
   GOOGLE_CLIENT_SECRET=<クライアントシークレット>  # 秘密
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
   MAIL_HOST=<SMTP ホスト>
   MAIL_PORT=<ポート>
   MAIL_USERNAME=<SMTP ユーザー>   # 秘密
   MAIL_PASSWORD=<SMTP パスワード>  # 秘密
   MAIL_SCHEME=<smtp または smtps>  # 旧 MAIL_ENCRYPTION。値は要確認
   MAIL_FROM_ADDRESS=<送信元アドレス>  # サブドメインと同じドメインにする（SPF/DKIM）
   MAIL_FROM_NAME="${APP_NAME}"
   CONTACT_TO_ADDRESS=<問い合わせの宛先>  # 空なら問い合わせフォームは「送信できませんでした」になる
   # 問い合わせフォームが使えないときの削除依頼の窓口として /contact・/privacy に表示する（公開してよい連絡先だけ）
   OPERATOR_CONTACT=<公開の連絡先>

   # #7 の手順（下の「公開前の人手作業」）で前段のプロキシがあると分かった場合だけ、そのプロキシの IP/CIDR を書く。空なら信頼しない
   TRUSTED_PROXIES=
   ```

   - 本番で `MAIL_MAILER` が `log`・`array` のまま、または `CONTACT_TO_ADDRESS` が空だと、問い合わせは送信されず「送信できませんでした」になります（ログに `Contact mail not configured`）。
   - `OPERATOR_CONTACT` は公開ページにそのまま表示されます（例: 運営者の公開 SNS アカウントや YouTube チャンネルの URL）。個人のメールアドレスなど、公開したくないものは書かない。リポジトリにも書かない。空なら「運営者の連絡先（README で設定）」と表示されるので、公開前に必ず設定する。
5. （#4）**`.user.ini` は手で作る**（`*.ini` は rsync の対象外です）。`<REMOTE_PATH>/public/.user.ini` に次を書く（PHP が FPM／CGI で動いている場合だけ効きます。モジュール版の場合は管理画面の PHP 設定で同じ値にする）:

   ```ini
   post_max_size = 64K
   upload_max_filesize = 64K
   ```

6. **DB をバックアップしてからデプロイする**（初回も 2 回目以降も毎回）。deploy.sh はサーバーで `migrate --force` を実行します。マイグレーション `2026_11_14_000500`（YouTube の ID の列を `utf8mb4_bin` にする）は**元に戻せません**（`migrate:rollback` で戻すと大文字・小文字だけが違う ID が重複してエラーになりえます）。失敗したときはロールバックではなくバックアップから戻してください。バックアップは DirectAdmin のバックアップ機能、または次のコマンドで取る:

   ```bash
   mysqldump --single-transaction -u <アカウント名>_<ユーザー名> -p <アカウント名>_<DB 名> > ~/list-maker-$(date +%Y%m%d-%H%M).sql
   ```

7. （#5）`./deploy.sh` を実行する（origin/main 一致確認 → テスト → ビルド → rsync → migrate → キャッシュクリア。APP_KEY が空なら生成される）。
   デプロイ後、次を確認する（秘密ファイルはどちらも **403 か 404** であること。200 ならすぐにドキュメントルート設定を見直す）:

   ```bash
   curl -sI https://list-maker.alpacasandbag.jp/.env | head -1
   curl -sI https://list-maker.alpacasandbag.jp/composer.json | head -1
   curl -sI http://list-maker.alpacasandbag.jp/ | head -1    # 301/302 で https へ転送されること
   curl -sI https://list-maker.alpacasandbag.jp/ | head -1    # 200 であること（500 なら PHP が 8.4.1 未満か、.htaccess の Require が使えない）
   ```

8. （#4）サーバーの cron に以下を追加する（毎分。Laravel のスケジューラが毎時 `app:prune-sessions`、毎日 04:00 JST に `app:prune-expired` を起動します）:

   ```
   * * * * * cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
   ```

   `storage/logs/cron.log` は毎分追記されて大きくなります（ログの日次ローテーションの対象外）。月に 1 回ほど `ls -lh <REMOTE_PATH>/storage/logs/cron.log` で大きさを確かめ、大きければ中身を確認してから `: > <REMOTE_PATH>/storage/logs/cron.log` で空にする。

### 2回目以降

1. DB をバックアップする（上の手順 6）。
2. `./deploy.sh` を実行する。

```bash
./deploy.sh
```

`.exclude-list` に載っているもの（`.env`・`.env.*`（`.env.example` を含む）、`deploy.env`、`auth.json`、`.DS_Store`、ルートの `/docs`・`/tests`・`/README.md`・`/phpunit.xml`（`vendor` 内の同名は送る）、`*.ini`、`/public/.well-known`、`storage/logs`、`storage/framework`、`public/hot` など）は転送されず、本番側でも消されません。rsync は `--delete-after`（転送が終わってから本番側の余分なファイルを消す）で、`deploy.env` の `REMOTE_PATH` は `/` で始まる絶対パス（英数字と `. _ / -` のみ、`/` だけ・`.`／`..` の区間は不可）でないと止まります。rsync の後（サーバー側の migrate など）で失敗した場合は「ファイルは本番に転送済みです」と表示されるので、原因を直して再実行してください（DB が壊れた場合はバックアップから戻す）。

`deploy.sh` は HEAD が `origin/main` と一致し、追跡ファイルに未コミットの変更が無い場合だけ進みます。緊急時のみ `ALLOW_NON_MAIN_DEPLOY=1 ./deploy.sh` で迂回できます（古いブランチから出すと本番が巻き戻るため通常は使わない）。

## Google Cloud セットアップ

1. **本ツール専用の**プロジェクトを新規作成する（既存サービスのプロジェクトは使わない）。YouTube Data API v3 を有効化する。
2. API キーを作成し、API 制限を「YouTube Data API v3 のみ」にする。アプリケーション制限は、サーバーの送信元 IP が固定と確認できれば IP 制限、できなければ「なし」。
3. OAuth 同意画面: 外部、アプリ名「List Maker」（YouTube を含めない）、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL（`https://list-maker.alpacasandbag.jp/...`）、承認済みドメイン `alpacasandbag.jp`、スコープに `.../auth/youtube` を追加し、スコープの用途説明に「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」と書く。公開ステータスを「本番環境」にする（未審査のまま）。
4. OAuth クライアント ID（ウェブアプリケーション）を作成し、承認済みのリダイレクト URI に次の 2 つを登録する:
   - `https://list-maker.alpacasandbag.jp/auth/google/callback`
   - `http://localhost:8000/auth/google/callback`（ローカル用）
5. Search Console でドメイン所有確認をする（審査前）。
6. （公開後の運用）クオータ使用量は Google Cloud コンソールの「API とサービス → 割り当て」で確認する。リセットは太平洋時間 0 時（日本時間 16 時〈夏時間〉／17 時〈標準時間〉）。アプリの台帳（`quota_usage`）と見比べ、見込みのずれを確認する。

## 運用

### 日次バッチ・ログイン

- 日次バッチ `app:prune-expired` は毎日 04:00（Asia/Tokyo）に、`app:prune-sessions` は毎時 0 分に実行されます（cron の `schedule:run` 経由）。
- `app:prune-expired` は、期限切れの提案・一覧キャッシュなどに加え、**チャンネル主が 7 日使っていない作成の記録**（追加する動画の一覧と、公開以外の再生リストの ID）を削除します（Developer Policies III.D.2。最後の利用は作成の開始・ステップ・操作・作成状況の画面の表示・ログアウト）。件数と「作成済み」かどうかは残り、提案と一緒に 30 日で消えます。出力の `authorized_data: N` が削除した作成の数です。
- `app:prune-sessions` は操作のないまま 120 分たったセッションの行（暗号化されたアクセストークンを含む）を削除します。期限切れのトークンや古い限定公開動画の一覧は、次のリクエストでセッションから外れます。
- ログは `storage/logs`（daily、14 日保持）。
- **削除バッチは cron が動いていることが前提です。** 一覧キャッシュ・提案は「次回実行までに期限が来る行（現在 + 24 時間）」を削除するため、毎日動いていれば表示した削除日を過ぎて残ることはありません。cron が止まる・失敗すると、その分だけ保存期間（30 日）を超えて残ります。`storage/logs/cron.log` と `storage/logs/laravel-*.log`（`app:prune-expired` の結果・エラー）を定期的に確認してください。バッチが失敗したステップがあると終了コードが 0 以外になります。
- **ゲートウェイのタイムアウト（要確認: CORESERVER）**: チャンネルの取り込みは最悪で約 85 秒かかることがあります（同じチャンネルを取り込み中の別リクエストのロック待ち最大 40 秒 + 取り込み全体の上限 30 秒 + 最後の API 呼び出しのタイムアウト 15 秒）。CORESERVER の Web サーバー／PHP の実行時間制限（`max_execution_time`、リバースプロキシのタイムアウト）がこれより短いと、取り込みの途中で 504 などになります（途中まで読んだ分は保存されないため、データは壊れません）。実際の制限値を確認し、短い場合は `ChannelVideoCatalog` のロック待ち時間・取り込み上限を下げることを検討してください。
  - **チャンネル主のログインのコールバック**（`/auth/google/callback`）も最悪で約 45 秒かかります（トークン交換 15 秒 + チャンネル確認 15 秒 + 失敗時のトークン取り消し 15 秒。それぞれ接続 5 秒・全体 15 秒のタイムアウト、再試行なし）。途中で切れた場合は画面にエラーが出ますが、プレビューからもう一度ログインし直せば済みます（PHP 側の処理が続いてログインが完了していることもあります。取得したトークンはセッション以外には保存されず、約 1 時間で失効します）。
  - **限定公開の動画の読み込み**（`/owner/videos/unlisted`）も最悪で約 45 秒かかります（ページングの上限 30 秒 + 最後の API 呼び出しのタイムアウト 15 秒）。途中で切れても何も保存されず、画面には「通信に失敗しました」と表示されるだけです。
  - これらより制限が短い場合は、`YouTubeOwnerClient::DEADLINE_SECONDS` や各呼び出しのタイムアウトを下げることを検討してください。

- **チャンネル主のログイン（Phase 2）**: ログイン中のアクセストークンは暗号化 DB セッション（`SESSION_DRIVER=database`・`SESSION_ENCRYPT=true`）にだけ置きます。トークンは約 1 時間で失効し、失効後の最初のリクエストでセッションから外れます。操作のないまま `SESSION_LIFETIME`（120 分）たったセッションは使えなくなり、その行は毎時の `app:prune-sessions` で消えます。ログアウトでセッションを即削除し、Google 側の承認も取り消します（取り消しに失敗したときはログアウト後の画面で https://myaccount.google.com/permissions からの取り消しを案内します）。refresh_token は保存しません。
- **コールバック URL とアクセスログ**: `/auth/google/callback?code=...&state=...` は Web サーバーのアクセスログに残りえます。認可コードは一回限り・短命で、state もセッションから一度で取り出して削除するため再利用できません（spec 6.7 I3）。本番でアクセスログに残るかは確認して spec に記録してください。
- **`GOOGLE_REDIRECT_URI`**: GCP に登録したリダイレクト URI と完全に一致させてください（http/https・末尾スラッシュも含む）。本番は `https://` 以外だとログインが 503（未設定扱い）になります。
- **`APP_DEBUG`**: 本番で `true` だと起動時に `APP_DEBUG is enabled in production` の警告をログに出します。本番は必ず `false` にしてください。

### 削除依頼

問い合わせフォーム（種別「削除依頼」）でメールを受けたら、**7 日以内**に削除します（Developer Policies III.E.4.g）。フォームが使えない場合（全体の 1 日 50 件の上限など）は `OPERATOR_CONTACT` に書いた連絡先にも依頼が来ます。

**提案 1 件**（本文に提案の URL）:

```bash
cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan app:delete-proposal
# 「提案の URL またはキー」と聞かれるので貼り付ける（入力は表示されず、シェルの履歴にも残らない）
```

**チャンネル単位**（本文にチャンネルの URL `https://www.youtube.com/channel/UC…` または ID）:

```bash
cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan app:delete-proposal --channel=UC…
```

- 依頼者がそのチャンネルの持ち主か分からない場合は、先にチャンネルの公開の連絡先（チャンネルの概要欄など）を通じて確認する。チャンネル単位の削除は、そのチャンネル宛ての提案・そのチャンネルが作成した提案（作成の記録・予約を含む）と、取り込んだ動画の一覧・期限切れの記録をすべて消します。`@ハンドル` の URL は受け付けないので、チャンネルのページで ID（`UC…`）を確かめてから使う。
- どちらも対象（件数）を表示して確認を求めます（`--force` で確認を省略）。提案の URL・キーを引数に書くこともできますが、シェルの履歴に残るため省略して入力してください。
- 作成中の提案でも、作成の記録ごと削除します（書込の予約は解放。チャンネル主のブラウザの次のステップは 404 で止まります）。チャンネル主自身の削除（作成中は不可）とは扱いが違います。
- YouTube に作成済みの再生リストは削除されません（チャンネル主の資産のため）。必要なら依頼者にそう伝えてください。
- 対応後、問い合わせメールはメールボックスから **90 日以内**に削除します。ログには `Proposal deleted by operator`（チャンネル単位は `Channel data deleted by operator`）が残ります。

### 放置された作成の予約の解放

作成中のまま放置された作成が書込の予約を持ち続け、他のチャンネルが作成できないときに使います。ログの `Creation started` の `channel`（チャンネル ID の先頭 8 文字）と `creation_id` で対象を特定します。

```bash
cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan app:release-creation-reservation <creation_id>
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
- `TRUSTED_PROXIES` が空なら `REMOTE_ADDR` をそのまま使います。前段のプロキシで全員が同じ IP に見える場合だけ、そのプロキシを `TRUSTED_PROXIES` に書きます（`*`・`REMOTE_ADDR`・`PRIVATE_SUBNETS` や不正な値は起動時に警告ログ。確認の手順は「公開前の人手作業」の #7）。

### ログ

`storage/logs/laravel-YYYY-MM-DD.log`（daily、14 日保持、本番は `LOG_LEVEL=info`）。

- 出すもの: API エラー（メソッド・HTTP ステータス・reason）、クオータ超過・予約不足・読取予算切れ、レート制限超過（制限の名前のみ）、作成の開始・完了・一時停止（creation id、本数、予約量。開始はチャンネル ID の先頭 8 文字も）、運用者の削除・予約の解放、問い合わせ送信の成否（例外の種類のみ）、未処理の DB・HTTP 例外（SQLSTATE・例外クラス・HTTP ステータスのみ）。
- 出さないもの: アクセストークン・API キー・認可コード・提案の公開キー・IP・問い合わせの本文とメールアドレス・SQL のバインド値・リクエスト URL とクエリ・スタックトレースの引数。
- 主なメッセージ名: `Creation started`・`Creation completed`・`Creation paused`・`Write quota insufficient`・`Read budget exhausted`・`Rate limit exceeded`・`Contact sent`・`Contact mail failed`・`Proposal deleted by operator`・`Channel data deleted by operator`・`Prune step failed`。

### 問い合わせの送信テスト

1. 送る前にテーブルごとの件数を控える: `cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan db:show --counts`
2. 本番の `/contact` からフォームで 1 通送る（種別「一般」、本文は「送信テスト」など）。
3. `CONTACT_TO_ADDRESS` に件名 `[List Maker] お問い合わせ（一般）` のメールが届くことを確認する。
4. サーバーの `storage/logs/laravel-*.log` に `Contact sent` が出ていることを確認する（本文・アドレスは出ない）。
5. もう一度 `db:show --counts` を実行し、1 と比べて `sessions`・`cache`（セッションと回数制限のカウンター）以外の全テーブルの件数が変わっていないことを確認する（問い合わせは保存しない）。

## リクエストボディの上限（要確認: CORESERVER）

`LimitRequestBody` ミドルウェアは `Content-Length` と読み込んだボディが 64KB を超えると 413 を返しますが、PHP のミドルウェアでは multipart ボディを PHP が解析する前に制限できません。サーバー側でも約 64KB に抑えるため、`post_max_size` / `upload_max_filesize` を初回の手順 5 の `.user.ini`（FPM／CGI の場合）かコントロールパネルで設定してください。CORESERVER で設定できるか・反映されるかは未確認です（反映されたかは管理画面の PHP 設定の表示で確かめる。確認用の phpinfo を公開ディレクトリに置かない）。

## 公開前の人手作業

前提: Issue #2〜#5（サブドメイン・SSL・DB・`.env`・cron・初回デプロイ）が終わり、`curl -sI https://list-maker.alpacasandbag.jp/ | head -1` が 200 であること。

- [ ] #7: 前段のプロキシの有無を、コードを足さずにアクセスログで確認する。
  1. 自分の IP を 2 つ用意する（自宅の回線と、Wi-Fi を切ったスマートフォンのモバイル回線）。それぞれ「IP アドレス確認」のサイトで自分の IP を控えてから、本番のトップページを開く。
  2. ホスティングの管理画面でサブドメインのアクセスログを開き、その時刻の行の先頭の IP を見る。
  3. 判定: 2 つのアクセスがそれぞれ自分の IP で記録されていれば、**`TRUSTED_PROXIES` は空のまま**にする（これで終わり）。2 つとも同じ IP（`10.`・`172.16.`〜`172.31.`・`192.168.` で始まる内部の IP や、サーバー自身の IP など）で記録されていれば前段にプロキシがあるので、**その IP（または管理会社が示す CIDR）だけ**を `TRUSTED_PROXIES` に書く。`*`・`REMOTE_ADDR`・`PRIVATE_SUBNETS` は書かない（誰でも IP を偽れるようになる）。
  4. 書いた場合は再度 1〜2 を行い、`storage/logs/laravel-*.log` に `TRUSTED_PROXIES` の警告が出ていないことを確認する。
  5. 送信元 IP（サーバーから外へ出るときの IP）が固定と確認できれば、API キーのアプリケーション制限を IP 制限にする。
- [ ] `.env` の `OPERATOR_CONTACT` に、問い合わせフォームが使えないときの公開の連絡先を書き、`/contact` と `/privacy` の「削除の依頼」に表示されることを確認する（「運営者の連絡先（README で設定）」のままにしない）。
- [ ] SMTP 疎通: CORESERVER の SMTP 値（ホスト・ポート・`MAIL_SCHEME`・認証）を本番 `.env` に入れ、送信元ドメインの SPF/DKIM を確認し、本番フォームから 1 通送って `CONTACT_TO_ADDRESS` に届くこと・DB に残らないことを確認する（上の「問い合わせの送信テスト」。Phase 4 の完了条件）。
- [ ] 本番 `.env` を `LOG_LEVEL=info` に変更し、デプロイ後に `curl -sI https://list-maker.alpacasandbag.jp/ | head -1` が 200 であることを確認する。
- [ ] OAuth 同意画面: アプリ名「List Maker」、サポートメール、ホームページ・プライバシーポリシー・利用規約の URL、承認済みドメイン `alpacasandbag.jp`、スコープ `.../auth/youtube`、スコープの用途説明（「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」）を登録し、ブランディングを公開、公開ステータスを「本番環境」にする（未審査のまま）。
- [ ] Search Console で `alpacasandbag.jp`（またはサブドメイン）の所有権を確認する（プロジェクトのオーナー／編集者のアカウントで）。
- [ ] 第三者アカウント（自分の別アカウント）で、ログイン前の案内どおりに未確認アプリの警告を越え、提案 → ログイン → 編集 → 作成 → ログアウト（承認が消える。https://myaccount.google.com/permissions で確認）まで完走できることを確認する（Phase 4 の完了条件）。実際の警告画面・権限の確認画面の文言が `resources/views/partials/unverified-app.blade.php` の案内（「このアプリは Google で確認されていません」「詳細」「（アプリ名またはドメイン）（安全ではないページ）に移動」「続行」、権限のチェックボックス）と合うかを確かめ、違えば文言を直す。必要なら警告画面のスクリーンショットを撮る（画像付き説明を付けるかは監督者判断）。
- [ ] 英語のデモ動画（Phase 5 の準備）: 同意画面に「List Maker」、アドレスバーに OAuth クライアント ID が見える状態で、ログイン〜限定公開動画の表示〜作成までを英語で説明し、YouTube に限定公開でアップロードする。
- [ ] spec 9.1 のチェックリストを本番で目視確認する（全ページのフッター、S2/S4 の同意文、出典表示、S6 の取り消し案内、プライバシーポリシー・規約の本文）。
- [ ] Google の審査が通って警告が出なくなったら、ログインの流れの案内（`partials/unverified-app.blade.php` の「Google の審査が完了するまでは、この警告が表示されます」）を案内ごと見直す。
