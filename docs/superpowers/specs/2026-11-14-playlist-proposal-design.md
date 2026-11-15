# 再生リスト提案・作成ツール「List Maker」設計書

- 作成日: 2026-11-14（同日改訂: クオータ予約制・動画数上限の導出・保持期限・問い合わせフォーム等を反映。第 2 改訂: チャンネル動画一覧の差分取り込み（行単位の 30 日削除）、OAuth の参照ライブラリ安全対策チェックリスト（6.7）を反映。第 3 改訂: 一覧キャッシュの 30 日期限を行単位からチャンネル単位に変更し、needs_rescan による全走査を廃止（Y9）。第 4 改訂（2026-11-15）: 未決事項 U1〜U5・U11・U12 の決定、チャンネル主の限定公開動画の追加、チャンネル主による提案削除、提案保存のレート制限の数え方、読取の日次上限を設けない方針を反映（Y10〜Y18）。同日追記: S5 の URL 追加で自分の限定公開動画を追加できるようにした（D45）、S5 の公開範囲を既定なしのラジオボタンにした（D46）、S2・S5 に「絞り込み結果をすべて選択」を加えた（D47））
- 入力: ユーザーとの合意事項（本書に反映済み）、元の指示書（`YouTube再生リスト作成支援ツール 実装指示書.ini`。方針の一部は本書で変更済み）、既存サービス channel-calendar / ycs。調査で確認した事実は**付録 A** に転記しており、本書だけで完結する
- 表記: **［事実］** = 公式ドキュメント・既存コード・コマンドで確認済み（出典は付録 A）／**［要確認］** = 推測または未検証で、実装前・実装中に確かめる／**［ユーザー決定］** = ユーザーが明示的に決めたこと／**［設計判断］** = 要件に明記がなく監督者・設計者が決めたこと（12 章に一覧）

---

## 1. 目的と背景

### 1.1 なぜ作るか
- YouTube Studio では検索のたびに選択が消えるなど、多数の動画から再生リストを組むのが不便。
- 再生リストを作りたいのはチャンネル主本人とは限らない。ファンや身内（提案者）が「この並びで作ってほしい」と案を作り、チャンネル主はそれを確認・手直しして、ワンクリックで自分のチャンネルに作れるようにする。
- 元の指示書は「フロントのみ・GIS・URL ハッシュ受け渡し・自分専用」だったが、「Laravel サーバー側 OAuth・DB 保存＋キー URL・誰でも提案可」に変更済み。

### 1.2 ゴール
1. 提案者がログインなしで、任意チャンネルの公開動画（＋URL 指定の他チャンネル動画）から再生リスト案を作り、共有 URL を得られる。
2. チャンネル主が共有 URL で「作成後の見え方」をプレビューし、Google ログイン後に編集して自分のチャンネルに再生リストを作れる。
3. 作成が途中で失敗・中断しても、作成済み分を保ったまま続きから再開できる。二重作成しない。
4. 「本日あと何本作れるか」が画面で分かり、枠が足りない作成は始めない（途中で枠切れになって中途半端に残る事態を避ける）。
5. Google OAuth 審査に通せる形（ホームページ、プライバシーポリシー、取り消し案内、30 日ルール、問い合わせ窓口）で最初から作る。

### 1.3 非ゴール（今回やらない）
- 提案者アカウント、提案の編集・履歴管理（提案は作成後に変更不可）。
- チャンネル主への通知（メール・DM 送信など）。URL は提案者が自分で渡す。
- 他チャンネルの動画一覧取得、チャンネル主の出演有無の検証 ［ユーザー決定］。
- 非公開動画の扱い（提案者にもチャンネル主にも表示しない）。限定公開動画も提案者には見えない。例外として、チャンネル主はログイン後の S5 で**自分の限定公開動画**をボタン操作で読み込んで、または動画 URL で追加できる（Y11・D45。読み込んだ一覧はそのチャンネル主のセッションに 10 分だけ置き、URL での追加結果は応答にだけ載せる。どちらも共有キャッシュには置かない）。
- 既存再生リストへの追加・更新、作成後の同期。
- 動画データの定期取り直し ［ユーザー決定］。
- watch_videos を作成手段にすること（不採用。プレビュー補助リンクは任意機能）。
- 管理画面（運営操作は SSH＋artisan コマンド）。

### 1.4 アンチゴール（やってはいけない）
- **ログイン情報を溜め込む**: リフレッシュトークン取得、アクセストークンの DB/ログ保存、ユーザーテーブル作成。
- **提案者・問い合わせ者の個人情報収集**: メール・本名の必須化、IP アドレスの DB 保存、アクセス解析タグの埋め込み、問い合わせ内容の DB 保存。
- **search.list の使用**（不要なうえ 1 日 100 回の別枠上限がある ［事実: A.1］）。
- **クオータを他人に食い潰されること**: 匿名の提案者操作がチャンネル主の書込枠を奪う状態。アプリ全体で Google 側の日次枠（10,000）を使い切る状態（アプリ側上限で書込 5,000・読取 2,000 に抑える）。
- **枠不足のまま作成を始めること**（途中で止まり、中途半端な再生リストが残る）。
- **公開ページとして SEO スパムの踏み台になること**（提案ページのインデックス、URL の自動リンク化）。
- YouTube 公式・提携と誤認させる名称やロゴの使用。

---

## 2. 利用者と主要フロー

### 2.1 利用者
| 役割 | 認証 | できること |
|---|---|---|
| 提案者 | なし | チャンネル指定、動画選択、提案保存、共有 URL 取得 |
| チャンネル主 | Google OAuth（youtube スコープ） | プレビュー（ログイン不要）、編集、再生リスト作成、作成状況確認、自分のチャンネル宛ての提案の削除（Y14） |
| 問い合わせ者 | なし | 問い合わせフォーム送信（削除依頼を含む） |
| 運営（自分） | サーバー SSH | デプロイ、.env、ログ確認、問い合わせメール対応、削除依頼の実行（artisan コマンド）。管理画面は作らない ［設計判断］ |

### 2.2 提案者フロー
1. ホームでチャンネル URL / ハンドル / UC… ID を入力。
2. サーバーがチャンネルの動画一覧を用意する。前回の取り込みから 6 時間以内なら API を呼ばずキャッシュを使う。それ以降は channels.list → uploads プレイリストを playlistItems.list で新しい順に読み、**キャッシュに無い新しい動画だけを追加する差分取り込み**を行う（5.3）。キャッシュが無い（初回、または 30 日の保存期限で削除された後）ときは全件取り込みを行い、そこから新たに 30 日の期限が始まる（Y9）。
3. 一覧を絞り込み（クライアント側）、チェック。選択は動画 ID の集合としてブラウザに保持（localStorage にも退避し、リロードで消えない）。画面に「1 つの再生リストに入れられるのは最大 99 本」を常時表示し、99 本に達したらそれ以上チェックできない。絞り込み中は「絞り込み結果をすべて選択（N 本）」で、絞り込まれたまだ選んでいない動画を表示順にまとめて選べる（D47）。
4. 必要なら動画 URL を貼って他チャンネルの動画を追加（videos.list）。公開動画以外は「この動画は追加できません（削除・非公開・限定公開の動画は、ここでは追加できません。限定公開の動画は、その動画のチャンネルの持ち主がログイン後の編集画面で追加できます）」と表示する（API キーでは削除・非公開・限定公開を区別できないため。D45）。**対象チャンネル外の動画も弾かず、既定で提案に含める**。「対象チャンネル外」バッジを付けて表示するだけで、チャンネル主の出演は検証しない ［ユーザー決定］。
5. 選択済みパネルで並べ替え・削除。再生リスト名・説明・チャンネル主へのコメント・ニックネーム（任意）を入力。
6. 保存 → 共有 URL（`/p/{key}`）と有効期限（30 日後）を表示。コピーボタン。

### 2.3 チャンネル主フロー
1. 共有 URL を開く → プレビュー（ログイン不要）。作成済みなら状態を表示（リンクの表示条件は 7.6）。
2. 「Google でログインして作成する」→ OAuth（未審査警告あり）。
3. 戻ってきたら channels.list mine=true でチャンネル ID を取得し、提案の対象チャンネルと照合。不一致なら作成画面に進ませない（2.4）。
4. 編集画面: 動画の削除・並べ替え・追加（対象チャンネル一覧から／URL 指定（自分の限定公開動画も可。D45）／「限定公開の動画も表示する」ボタンで読み込む自分の限定公開動画（Y11）、最大 99 本）、名前・説明修正、公開範囲選択（非公開／限定公開／公開のラジオボタン。既定なしで、選ばないと作成できない。D46）。対象チャンネル一覧の絞り込みには S2 と同じ「絞り込み結果をすべて選択」がある（D47）。「この内容に必要な枠: 再生リスト 1 つ＋動画 n 本」と「本日の残り: 再生リスト 1 つ＋動画あと N 本まで作成できます」を並べて表示（5.5）。n > N なら作成ボタンを押せず、減らす本数とリセット時刻を表示。
5. 「作成」→ サーバーが枠を予約（5.4）。予約できなければ開始しない。予約できたら進捗画面へ。ブラウザが 1 ステップずつサーバーを呼び、進捗を表示（7 章）。
6. 完了 → 再生リスト URL、失敗分の再試行ボタン、ログアウト（トークン取り消し）と Google 側の承認取り消し案内。
7. 提案の削除（任意、Y14）: 編集画面の「この提案を削除」→ 確認ページ（`GET /p/{key}/delete`。「削除すると元に戻せません。共有された URL でも表示できなくなります」）→「削除する」（`POST /p/{key}/deletion`、CSRF 検証つき）→ 提案と動画明細を 1 トランザクションで削除 →「削除しました」ページ（`/deleted`）。このページはそのタブの編集下書き（sessionStorage）を消す。ログインは保たれる。作成を始めた提案は、作成中（`completed` 以外）は削除できず、確認ページから S6 へ案内して「残りを中止」してから削除させる。作成完了後は S6 の「この提案を削除」から、提案と作成の記録を一緒に削除する（YouTube の再生リストは残る。Y19）。

### 2.4 チャンネル不一致時 ［設計判断］
- **作成させない**（明示確認での続行も不可）。理由: 誤チャンネルへの作成は取り消しが面倒で、提案は「そのチャンネルのため」に作られている。ブランドアカウントで別チャンネルを選んだだけのケースが多いと想定し、「別のチャンネルでログインし直す」（`prompt=select_account` 付き再認可）を案内する。
- 別チャンネルに作りたい場合は、そのチャンネルを対象に提案を作り直してもらう。

### 2.5 画面一覧
| # | パス | 画面 | 主な要素 |
|---|---|---|---|
| S1 | `/` | ホーム（審査用ホームページ兼用） | アプリ名（List Maker）、ツール説明（提案→作成の流れ図）、チャンネル入力欄、YouTube データ利用の説明、フッタ（プライバシー・規約・YouTube 利用規約・Google プライバシーポリシー・お問い合わせ） |
| S2 | `/channels/{channelId}/propose` | 提案作成 | チャンネル見出し（アイコン・名前、出典表示）、**上限表示「1 つの再生リストに入れられるのは最大 99 本」**、**本日の残り表示**（5.5）、絞り込み入力、「絞り込み結果をすべて選択（N 本）」ボタン（D47）、表示件数／選択数（n / 99）、動画カード（サムネ・タイトル・公開日・チェック）、全解除、URL 追加欄、選択済みパネル（並べ替え・削除・対象チャンネル外バッジ）、名前・説明・コメント・ニックネーム、文字数カウンタ、保存ボタン（近くに規約・プライバシーポリシーへの同意文）、取得上限の注記、一覧の最終取り込み日時（読取予算切れでキャッシュのみ表示する場合は強調表示）、一覧の保存期限（「この一覧は○月○日に取り込み、○月○日に削除されます」）、期限切れ後の取り込み直しの案内（5.3） |
| S3 | `/p/{key}/shared`（直後 1 回のみ） | 提案完了 | 共有 URL とコピー、有効期限、「この URL を知っている人は誰でも閲覧できる」注意、プレビューへのリンク |
| S4 | `/p/{key}` | プレビュー | 再生リスト風表示（名前・説明・本数・合計時間・動画リスト・対象チャンネル外バッジ）、提案者コメント・ニックネーム、作成日・削除予定日、状態（未作成／作成中／作成済み）、作成済み再生リストのリンク（public のみ。unlisted/private はログイン中の該当チャンネル主にのみ。7.6）、作成を始めたが再生リストが作られなかった場合（中止・恒久エラー）は「再生リストは作成されませんでした」、ログイン中の該当チャンネル主には作成後は編集画面ではなく「作成状況を見る」（S6）、**上限表示（最大 99 本）**、**本日の残り表示**（未作成時）、ログインボタン（未確認アプリ警告の案内・同意文付き）、任意の watch_videos プレビューリンク |
| S5 | `/p/{key}/edit` | 編集・作成（要ログイン＋一致） | ログイン中チャンネル表示、編集可能リスト（n / 99）、追加 UI（対象チャンネル一覧／URL。URL では自分の限定公開動画も追加でき、その旨を入力欄の下に案内する。D45）、名前・説明・公開範囲（既定なしのラジオボタン 3 つ。D46）、「絞り込み結果をすべて選択（N 本）」ボタン（D47）、**必要な枠と本日の残り**、不足時のメッセージとリセット時刻、作成ボタン（書込操作であることを明記）、「限定公開の動画も表示する」ボタン（Y11）、「この提案を削除」リンク（Y14）、ログアウト |
| S6 | `/p/{key}/creation` | 進捗・結果（要ログイン＋一致。`RequireOwnerSession`。7.2） | 全体進捗バー、項目ごとの状態、一時停止／再開、残りを中止、失敗分再試行（必要な枠と本日の残りを表示）、再ログイン促し、枠不足時のリセット時刻、完了時の再生リスト URL、完了後の「この提案を削除」（Y19）、取り消し案内 |
| S5a | `/p/{key}/delete` → `/deleted` | 提案の削除確認・削除完了（要ログイン＋一致、Y14・Y19） | 確認: 提案名・本数、元に戻せない旨、「削除する」ボタン（POST）、編集画面へ戻るリンク（作成があれば S6 へ戻るリンク。作成完了後は「作成済みの再生リストは YouTube に残ります」、作成中は削除フォームを出さず S6 へ案内）。完了: 「削除しました」、トップへのリンク。どちらも noindex・no-store |
| S7 | `/privacy`, `/terms` | 静的ページ | 9 章の必須事項 |
| S8 | `/contact` | 問い合わせフォーム | 種別（一般／削除依頼／不具合）、本文、返信用メールアドレス（任意）、ハニーポット、送信完了表示（8.7） |
| — | エラー表示 | 共通 | 提案なし／期限切れ（区別しない 404）、チャンネル不一致、枠不足（リセット時刻つき）、Google 側クオータ超過、レート制限 |

---

## 3. 技術構成

| 項目 | 方針 |
|---|---|
| 言語 | PHP 8.4（サーバー: `/usr/local/php84/bin/php`、ローカル 8.4.18 を確認 ［事実］）。`composer.json` の `config.platform.php` を `8.4.x` に固定 |
| フレームワーク | Laravel 13 系。2026-11-14 時点で `composer show -a laravel/framework` の最新安定版は v13.42.0 ［事実］。Laravel 13 の PHP 要件は 8.3 以上と認識 ［要確認］ |
| フロント | Blade ＋ Vite ＋ Tailwind（スケルトン既定のバージョン）＋ Alpine.js の **CSP ビルド**（`@alpinejs/csp`。標準ビルドは `unsafe-eval` が必要なため） ［設計判断］。選択・絞り込み・並べ替え・作成ランナー・残り表示の計算は JS モジュールで、ロジック部分は純関数にして `node --test` でテスト（channel-calendar の `test:js` に倣う） |
| DB | MySQL（DirectAdmin で作成）。テストは SQLite in-memory |
| セッション | `database` ドライバ＋`SESSION_ENCRYPT=true`（6.5） |
| キャッシュ／レート制限 | `database` ストア（共用サーバーに Redis なし前提） |
| キュー | 使わない（`QUEUE_CONNECTION=sync`）。常駐ワーカーを置けないため ［事実: 既存と同条件］。問い合わせメールも同期送信 |
| メール | CORESERVER の SMTP（`.env` の `MAIL_*`）。問い合わせフォームのみで使用 |
| HTTP クライアント | Laravel HTTP クライアント（Guzzle）で YouTube Data API / OAuth を素の HTTP で呼ぶ（6.1） |
| ページ配信 | `https://list-maker.alpacasandbag.jp` ［ユーザー決定］。HTTPS 必須 |
| Google Cloud | **本ツール専用の GCP プロジェクト**。既存サービス（ycs 等）とは共用しない ［設計判断: 審査・未審査 100 人上限・クオータがプロジェクト単位。A.3, A.5］ |

### 3.1 ディレクトリ構成方針
```
app/
  Http/Controllers/    HomeController, ChannelController(取得API), ProposalController,
                       OwnerAuthController, OwnerEditController, CreationController,
                       ContactController, StaticPageController
  Http/Middleware/     SecurityHeaders, RequireOwnerSession(ログイン＋チャンネル一致)
  Http/Requests/       StoreProposalRequest, StartCreationRequest, ContactRequest など（入力上限はここに集約）
  Mail/                ContactMessage(テキストメール)
  Services/YouTube/    YouTubeReader(APIキー読取), YouTubeOwnerClient(OAuth書込),
                       GoogleOAuth, QuotaLedger(記録・予約), YouTubeError(エラー分類)
  Services/            ChannelVideoCatalog(キャッシュ付き一覧・差分取り込み), CreationRunner(1ステップ実行)
  Support/             ChannelInput, VideoUrl（channel-calendar からコピーして調整。依存はしない）,
                       ProposalKey, QuotaPolicy(単価・動画数上限の導出・残り本数の換算), QuotaClock(PT日付・リセット時刻)
  Models/              Proposal, ProposalItem, ChannelCache, ChannelCacheVideo, PlaylistCreation, PlaylistCreationItem,
                       QuotaUsage, QuotaReservation
  Console/Commands/    PruneExpiredData, DeleteProposal(削除依頼対応)
config/youtube.php     上限値・予算などの設定（.env から）。動画数上限は持たない（QuotaPolicy が導出）
resources/js/          propose.js, owner-edit.js, creation-runner.js, lib/*.js(純関数)
```
- 新しい JS エントリは必ず `vite.config.js` の `input` に追加（ycs の CLAUDE.md にある本番 500 事故の教訓） ［事実］。
- **動画数上限の導出式は `QuotaPolicy` の 1 か所のみ**に置く。FormRequest の検証、Blade の表示、JS へ渡す値（`data-max-items` 等）はすべてここから取る。JS 側で式を再実装しない。

---

## 4. データモデル

### 4.1 方針
- **提案は作成後に不変**。チャンネル主の編集結果は提案を書き換えず、作成開始時点の内容を `playlist_creations`／`playlist_creation_items` に**別スナップショットとして持つ** ［設計判断］。
  - 理由: 提案者の案とチャンネル主が実際に作った内容を混同しない。プレビュー URL を再訪した提案者に「何が変わったか」を誤解させない。作成処理は不変のスナップショットに対して進めるので再開が単純。
  - 作成前の編集中の状態はブラウザ（sessionStorage）のみに保持し、「作成」押下時に確定内容を POST する。編集はログイン後に行うので OAuth リダイレクトで消える問題はない。
  - 下書きはログアウト後画面・削除完了画面で**そのタブの分だけ**消す。**既知の制限**: 別のタブの sessionStorage にある下書き（自分の限定公開動画の ID を含みうる）は、そのタブを閉じるまで残る（sessionStorage はタブ単位で、他のタブからは消せない）。
- 提案 1 件につき作成は 1 回まで（`playlist_creations.proposal_id` UNIQUE）。
- YouTube 由来データ（タイトル・サムネ URL 等）は**取得から最長 30 日**で削除する ［ユーザー決定］。定期的な取り直しはしない ［ユーザー決定］（9.3）。

### 4.2 テーブル

**proposals**
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | 内部用。URL には出さない |
| key_hash | char(64) UNIQUE | 公開キーの SHA-256（hex）。**生のキーは保存しない** ［設計判断］ |
| channel_id | varchar(24) | 対象チャンネル（UC…） |
| channel_title, channel_thumbnail_url | varchar | スナップショット |
| title | varchar(150) | 再生リスト名案 |
| description | text | ≤1,000 文字 |
| comment | text null | チャンネル主へのコメント ≤500 文字 |
| proposer_name | varchar(30) null | 任意ニックネーム（実名を書かないよう注意文） |
| item_count | smallint | 1〜99（QuotaPolicy の上限） |
| created_at | datetime | |
| expires_at | datetime INDEX | created_at + 30日。削除バッチの対象条件 |

- 公開キー: `random_bytes(32)` を base64url（43 文字）。URL `/p/{key}`。照会は `hash('sha256', key)` で検索。生キーは保存しないので、提案者には完了画面で 1 回だけ表示し、ブラウザの localStorage に「自分が作った提案」として保存してあげる（端末内のみ）。

**proposal_items**
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| proposal_id | FK cascade | |
| position | smallint | (proposal_id, position) UNIQUE |
| video_id | char(11) | (proposal_id, video_id) UNIQUE（重複禁止） |
| video_channel_id, video_channel_title | varchar | 対象チャンネル外の判定・表示用 |
| is_outside_channel | bool | 対象チャンネル外（バッジ表示のみ。除外しない） |
| title, thumbnail_url | varchar | スナップショット |
| duration_seconds | int null | |
| published_at | datetime null | |

**channel_caches**（チャンネル単位の情報と取り込み状態。動画は `channel_cache_videos` に 1 動画 1 行で持つ。**30 日の期限はチャンネル単位で管理**） ［ユーザー決定: Y9］
| カラム | 型 | 備考 |
|---|---|---|
| channel_id | varchar(24) PK | |
| handle | varchar null INDEX | ハンドル→ID 解決の再利用 |
| title, thumbnail_url, uploads_playlist_id | varchar | channels.list の結果。取り込みのたびに上書き |
| meta_fetched_at | datetime | 上記チャンネル情報の取得日時 |
| cached_since | datetime | **期限の基準日時**。キャッシュが無い状態（初回・期限切れ削除後）から全件取り込みを完了した日時。差分取り込みでは変えない |
| last_synced_at | datetime | 最後に一覧を取り込んだ日時。**これから 6 時間以内なら API を呼ばない**（使い回し時間） ［設計判断］ |
| truncated | bool | 全件取り込みが上限（`CHANNEL_FETCH_MAX_VIDEOS`）で止まり、それより古い動画が一覧に無い |
| expires_at | datetime INDEX | **cached_since + 30 日**。この時点でチャンネル行と動画行（差分で後から足した行を含む全行）をまとめて削除（FK cascade） ［ユーザー決定: Y9］ |

**channel_cache_videos**（取り込んだ動画。削除はチャンネル単位で、親の `channel_caches` 削除に cascade で従う） ［ユーザー決定: Y9］
| カラム | 型 | 備考 |
|---|---|---|
| channel_id | FK cascade | PK(channel_id, video_id) |
| video_id | char(11) | |
| title, thumbnail_url | varchar | playlistItems.list の snippet |
| published_at | datetime | `contentDetails.videoPublishedAt`。表示の並び（新しい順）と上限超過時の切り捨て順に使う |
| fetched_at | datetime | この行を取り込んだ日時（記録用。削除判定には使わない）。**既存行は再取得で上書きしない** |

**channel_cache_expirations**（30 日の保存期限で削除したチャンネルの記録。**API 由来データは持たない**） ［ユーザー決定: Y9／保持期間は設計判断］
| カラム | 型 | 備考 |
|---|---|---|
| channel_id | varchar(24) PK | 期限切れで削除したチャンネルの ID のみ（タイトル・サムネ等は持たない） |
| expired_at | datetime INDEX | 削除した日時。この記録自体も 30 日で削除（4.3） |

- 6 時間を過ぎた後に誰かが同じチャンネルを開いた場合のみ差分取り込みを行う（5.3）。cron 等による定期取り直しはしない。
- どの動画行も `fetched_at` ≥ `cached_since` で、`cached_since + 30 日` にチャンネル単位でまとめて消えるので、どの行の保持も取得から 30 日以下になる（9.3）。
- 1 チャンネルの動画行は最大 `CHANNEL_FETCH_MAX_VIDEOS`（1,000）行。超えたら `published_at` の古い順に削除する。

**playlist_creations**
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| proposal_id | FK cascade, UNIQUE | 二重作成防止の要 |
| owner_channel_id | varchar(24) | 作成者のチャンネル（＝提案の channel_id） |
| title, description, privacy_status | | 確定内容。privacy_status はプレビューでのリンク表示判定にも使う（7.6） |
| status | enum | `pending` / `creating_playlist` / `adding` / `paused` / `completed` / `needs_check` |
| pause_reason | varchar null | `auth_expired` / `quota_exceeded`（Google 側の枠切れ） / `quota_budget`（アプリの書込上限・予約不足） / `user` |
| youtube_playlist_id | varchar null | |
| playlist_attempted_at | datetime null | playlists.insert の送信時刻（曖昧時の照合用） |
| playlist_leased_at | datetime null | playlists.insert の送信中リース（7.3。60 秒） |
| playlist_attempts | tinyint | playlists.insert の送信回数（バックオフ・再試行上限用） |
| estimated_units | int | 50 + 50×本数（QuotaPolicy） |
| started_at, completed_at, updated_at | datetime | |
| paused_day_pt | date null | `paused(quota_exceeded)` にした PT 日付。この日のうちは再開させない（7.4）。再開・中止で消す |
| owner_last_active_at | datetime null | （Phase 4 最終レビュー）ログイン中のチャンネル主の最後の利用（開始・ステップ・操作・S6 の表示・ログアウト）。既存行は `updated_at` から。7 日の削除の起点（4.3・D42） |
| authorized_data_pruned_at | datetime null | 項目と公開以外の再生リスト ID を削除した日時（D42） |
| pruned_had_playlist | bool | 削除前に再生リストの ID があったか（S4/S6 の「作成済み」の判定に使う） |
| done_count / failed_count / skipped_count / total_count | smallint null | 削除時点の項目の件数（削除後の S6 の表示用） |

**playlist_creation_items**
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| creation_id | FK cascade | |
| position | smallint | 確定した並び順（0 始まり）。UNIQUE(creation_id, position) |
| video_id | char(11) | UNIQUE(creation_id, video_id) |
| title, thumbnail_url, is_outside_channel | | 表示用スナップショット（作成前の最終確認で取り直した値） |
| status | enum INDEX | `pending` / `in_flight` / `done` / `failed` / `skipped` |
| attempts | tinyint | |
| leased_at | datetime null | in_flight の開始時刻 |
| last_error | varchar null | YouTube の reason（例 `videoNotFound`） |
| youtube_item_id | varchar null | |

**quota_usage**（送信実績の記録）
| カラム | 型 | 備考 |
|---|---|---|
| day_pt | date | 太平洋時間の日付。PK(day_pt, bucket) |
| bucket | enum | `read`（匿名側の読取） / `owner_read`（チャンネル主操作に伴う読取） / `write`（書込） |
| units | int | 送信したリクエストのユニット累計（失敗分も計上） |

**quota_reservations**（書込枠の予約。5.4） ［設計判断］
| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| creation_id | FK cascade | |
| day_pt | date | 予約した PT 日付。UNIQUE(creation_id, day_pt) |
| reserved_units | int | 予約量（追加予約で増える） |
| consumed_units | int | 予約から実際に送信に使った量 |
| status | enum | `active` / `released` / `expired` |
| created_at, updated_at | datetime | |

**sessions**, **cache**, **cache_locks**: Laravel 標準。

問い合わせ内容を保存するテーブルは**作らない**（8.7）。

### 4.3 保持期限と削除
| データ | 期限 | 方法 |
|---|---|---|
| proposals ＋ items ＋ creations ＋ creation_items ＋ quota_reservations | 提案作成から 30 日（作成済み・未閲覧も同じ）。ただし creation_items と非公開・限定公開の `youtube_playlist_id` は下の行（7 日）が先に来る | 日次バッチで `expires_at < now()` を削除。作成行を id 順にロック→当日の書込行をロック→子（予約・作成項目・作成行・提案項目）を明示的に削除してから提案を削除（FK cascade は予備） |
| （Phase 4 最終レビュー）チャンネル主の承認で得たデータ: playlist_creation_items と、公開以外の `youtube_playlist_id` | チャンネル主の最後の利用（`owner_last_active_at`: 作成の開始・ステップ・操作・S6 の表示・ログアウト）から 7 日（Developer Policies III.D.2。D42） | 日次バッチ（`authorized_data` ステップ）で `owner_last_active_at <= now + 24h − 7 日` の作成を、作成行→当日の書込行の順にロックし、最後の利用をロック下で再確認してから項目を削除・ID を NULL にする。件数（`done_count` 等）と `pruned_had_playlist` を残し、未完了なら `completed` にして予約を解放する。公開の再生リストの ID は提案と一緒に 30 日 |
| channel_caches ＋ channel_cache_videos | **チャンネル単位**: 全件取り込みの完了（cached_since）から 30 日。差分で後から足した行も同時に消える ［ユーザー決定: Y9］ | 日次バッチで `expires_at < now()` のチャンネルを削除（動画行は cascade）し、同一トランザクションで `channel_cache_expirations` に ID と削除日時を記録。取り込み時に期限切れの行を見つけた場合もその場で同じ処理をする。期限切れ後に開かれたら全件取り込みし直す（5.3）。定期取り直しはしない |
| channel_cache_expirations | 記録から 30 日 | 日次バッチで `expired_at` から 30 日経過した記録を削除。期限切れ後の全件取り込みが完了した時点でも削除する |
| quota_usage | 90 日 | 日次バッチ（YouTube データを含まない） |
| sessions | 最終アクセスから `SESSION_LIFETIME`（120 分）操作が無いと使えなくなる。アクセストークンは失効（約 1 時間）後の最初のリクエストでセッションから外す | 毎リクエスト `DropExpiredOwnerData` が期限切れのログイン・10 分を過ぎた限定公開動画の一覧をセッションから外す（ログアウトは除く）。暗号化された行は Laravel のセッション GC ＋毎時の `app:prune-sessions`（日次の `app:prune-expired` でも）で、使えなくなってから約 1 時間以内に消える（D44） |
| cache（レート制限カウンタ、URL 追加の一時キャッシュ等） | 各 TTL（最長でも 1 日。日次削除のため行は最長 2 日残る） | 日次で期限切れ行を削除（database ストアは自動削除されないため） |
| アプリログ | 14 日 | `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14` |
| 問い合わせ | アプリでは保存しない。運営者のメールボックスで対応完了後 90 日以内に削除 ［設計判断］ | 運用（10.6） |

- 作成済み提案も 30 日で消えるため、二重作成防止は「提案が存在する間」有効。提案が消えれば作成ボタン自体が存在しない。
- 削除済み・存在しないキーはどちらも同じ 404 を返す（キーの存在を推測させない）。
- 削除依頼（問い合わせフォーム経由）は 7 日以内に `php artisan app:delete-proposal {URL またはキー}` で即時削除する（Developer Policies III.E.4.g ［事実: A.6］）。
- チャンネル主は自分のチャンネル宛ての提案を 30 日を待たずに S5 から削除できる（Y14、流れは 2.3 の 7）。提案と動画明細を 1 トランザクションで削除し、削除後のキーは存在しないキーと同じ 404 になる。他のチャンネルでログイン中は 403（不一致画面）、未ログイン・トークン期限切れはプレビューへ戻す。
- 作成中（`completed` 以外）の提案は削除しない（何も消さずに S6 へ戻し「残りを中止してから削除」と案内）。作成完了後の削除は、作成行・当日の書込行の順にロックし（5.4 のロック順）、予約の未使用分を解放したうえで `quota_reservations` → `playlist_creation_items` → `playlist_creations` → `proposal_items` → `proposals` を 1 トランザクションで明示的に削除する（FK cascade だけに頼らない）。YouTube の再生リストは残る（Y19）。
- （Phase 4 追記）`app:delete-proposal` は作成の状態にかかわらず提案と作成記録を削除する（予約は解放。チャンネル主自身の削除と違い、作成中でも削除する。D36）。削除処理は `ProposalDeleter` に共通化し、ロック順・削除順は上と同じ。
- （Phase 4 最終レビュー）`app:delete-proposal --channel=UC…` はチャンネル単位の削除依頼に応じ、`channel_id` または作成の `owner_channel_id` が一致する提案を上と同じ方法で 1 件ずつ削除し、そのチャンネルの channel_caches・channel_cache_videos・channel_cache_expirations も削除する（取り込みと同じチャンネルのロックを取る。期限切れの記録は新たに作らない）。件数だけを表示し、`--force` か対話での確認が必要。提案の URL・キーは引数を省略すると非表示の入力で受け取る（シェル履歴に残さない）。本人確認が必要なときは運営者がチャンネルの公開の連絡先を通じて確かめる（D43）。

---

## 5. YouTube API 呼び出しとクオータ設計

### 5.1 操作と API（ユニット数は公式クオータ表 ［事実: A.1］）
| 操作 | API | 認証 | ユニット | 台帳の区分 |
|---|---|---|---|---|
| チャンネル解決 | channels.list `part=snippet,contentDetails`（`id` または `forHandle`） | API キー | 1 | read |
| アップロード一覧 | playlistItems.list `part=snippet,contentDetails,status` `maxResults=50` ページング | API キー | 1/ページ | read |
| 個別動画追加・提案保存時の再取得 | videos.list `part=snippet,contentDetails,status` `id=最大50件` | API キー | 1/50件 | read |
| 作成前の最終確認（チャンネル主操作） | videos.list（同上） | API キー | 1/50件 | owner_read |
| ログイン後のチャンネル確認 | channels.list `mine=true part=snippet` | OAuth | 1 | owner_read |
| 自分の限定公開動画の読み込み（Y11。S5 のボタン操作時のみ） | playlistItems.list `part=snippet,contentDetails,status` `maxResults=50` `playlistId=UU…`（UC→UU で導出）ページング | OAuth | 1/ページ。最大 `CHANNEL_FETCH_MAX_VIDEOS`÷50 ＝ 20 ページ（20 ユニット）。限定公開が 300 本を超えた時点で打ち切り（保持・表示は新しい順に 300 本まで） | owner_read（`QuotaPolicy::ownerReadGuard` で全体を制限。5.4） |
| S5 の URL 追加で公開動画として見つからなかった動画の確認（D45） | videos.list `part=snippet,contentDetails,status` `id=1件` | OAuth | 1 | owner_read（`QuotaPolicy::ownerReadGuard` で全体を制限。5.4） |
| 再生リスト作成 | playlists.insert `part=snippet,status` | OAuth | 50 | write（予約から消費） |
| 動画追加 | playlistItems.insert `part=snippet`（`snippet.position` 指定） | OAuth | 50 | write（予約から消費） |
| 曖昧失敗の照合 | playlists.list `mine=true maxResults=50` / playlistItems.list `playlistId=作成分` | OAuth | 1/ページ | owner_read |

- search.list は使わない。
- uploads プレイリスト ID は `contentDetails.relatedPlaylists.uploads` を使う（UC→UU 置換は既存コードで実績ありだが、公式フィールドを優先） ［設計判断］。
- 例外: チャンネル主の限定公開動画の読み込みでは、uploads プレイリスト ID をチャンネル ID から **UC→UU 置換で導出**し、channels.list を呼ばない ［設計判断］。チャンネル ID はログイン時に channels.list `mine=true` で確認済みで、1 回の読み込みごとに 1 ユニット節約できるため。導出した ID が存在しない（`playlistNotFound`）場合は限定公開の動画なしとして扱う。
- 一覧の公開状態: uploads に API キーで見えるのは公開動画のみと想定 ［要確認］。`status.privacyStatus` が `public` 以外の項目は除外する。メンバー限定は uploads に出ない ［事実: channel-calendar のコメント］。
- 再生時間は playlistItems では取れないため、一覧画面では出さず、**提案保存時**に選択分だけ videos.list で取得（99 本で 2 ユニット）。この時点で削除・非公開になった動画は保存を拒否し、外して保存し直すよう表示。
- API キーはクエリ文字列ではなく `X-Goog-Api-Key` ヘッダで送り、例外メッセージやログに URL ごとキーが残るのを避ける ［設計判断・ヘッダ対応は要確認。不可ならログ出力時にマスク］。
- 無効なリクエストも最低 1 ユニット消費する ［事実: A.1］。書込の失敗も 50 ユニット消費したものとして扱う（安全側）。

### 5.2 アプリ側の上限と動画数上限の導出 ［ユーザー決定］
| 設定 | 既定値 | 意味 |
|---|---|---|
| `QUOTA_WRITE_LIMIT` | 5,000 | 書込（playlists.insert / playlistItems.insert）の 1 日上限。予約制（5.4） |
| `QUOTA_READ_BUDGET` | 2,000 | 匿名側の読取の 1 日上限。書込とは別枠。超えたら新規取得を止める |
| `QUOTA_GOOGLE_DAILY` | 10,000 | Google 側の日次枠（参考値・起動時検証用） |

- **1 提案あたりの動画数上限は独立した設定値を持たず、書込上限から導出する**: `maxItems = floor((QUOTA_WRITE_LIMIT − 50) / 50)`。既定 5,000 では **99 本**。式は `QuotaPolicy::maxItemsPerPlaylist()` の 1 か所のみ。
- 意味: 1 つの再生リスト（50）＋全動画（50×本数）が 1 日の書込上限内に収まる最大本数。つまり最大本数の作成でも、枠が空いていれば 1 日で完走できる。
- 画面には常に「1 つの再生リストに入れられるのは最大 99 本」と表示（S2, S4, S5。数値は導出値）。
- 起動時検証（`AppServiceProvider` で設定読込時）: `maxItems ≥ 1`（つまり `QUOTA_WRITE_LIMIT ≥ 100`）、`QUOTA_WRITE_LIMIT + QUOTA_READ_BUDGET ≤ QUOTA_GOOGLE_DAILY`。満たさなければ例外で起動させない ［設計判断］。
- 受け入れたトレードオフ: 99 本の作成 1 件でその日の書込上限 5,000 をすべて使うため、同じ日の他のチャンネル主は作成できなくなる。残り表示（5.5）とリセット時刻の表示で、待てば作れることを伝える。

### 5.3 キャッシュと差分取り込み ［ユーザー決定／打ち切り判定の詳細は設計判断］
**使い回し**
- `last_synced_at` から **6 時間以内なら API を呼ばない**（`CHANNEL_CACHE_REUSE_MINUTES=360`）。ハンドル→チャンネル ID も `channel_caches` で解決（channels.list 省略）。
- **30 日の期限はチャンネル単位**（Y9）: 全件取り込みを完了した時点（`cached_since`）から 30 日で、そのチャンネルのキャッシュ（チャンネル行と、差分で後から足した行を含む全動画行）をまとめて削除する（4.3）。期限内は差分取り込み、期限切れで消えた後の初回は全件取り込み（そこから新たに 30 日）。
- 表示は動画行を `published_at` の新しい順に並べる。`expires_at` を過ぎたキャッシュ（日次バッチ前）は無いものとして扱う。

**取り込み**（6 時間を過ぎて誰かが開いたとき、またはキャッシュが無いとき。`ChannelVideoCatalog`）
1. 期限切れ（`expires_at ≤ now()`）のキャッシュが残っていれば、まずその場で削除し `channel_cache_expirations` に記録する（日次バッチと同じ処理）。
2. channels.list（1 ユニット）でチャンネル情報と uploads プレイリスト ID を取り直す。
3. 取り込みモードを決める:
   - **差分モード**: 期限内のキャッシュ（`channel_caches` 行）がある。
   - **全件取り込みモード**: キャッシュが無い（初回、または期限切れで削除済み）。
4. uploads を playlistItems.list（`maxResults=50`）で**先頭（新しい順）から 1 ページずつ**読む。各項目について:
   - `status.privacyStatus` が `public` 以外 → 追加しない。既存行があれば削除（非公開化・限定公開化の反映）。
   - キャッシュに行がある（＝既知 ID）→ **何も書かない**（再取得で上書きしない。`fetched_at` も変えない）。
   - キャッシュに無い（＝新規 ID）→ `fetched_at=now` で追加。
5. 打ち切り判定（ページを読み終えるごと）:
   - 差分モード: そのページの中で**既知 ID が `CHANNEL_SYNC_KNOWN_RUN`（既定 3）件連続**したら、そのページで終了（ページ内の残りの新規 ID は追加してから止める）。動画行が 3 件未満のチャンネルは「動画行すべてが連続して出た」で終了。ページ全体が新規なら次ページへ（50 件以上新しい動画がある場合）。
   - 全件取り込みモード: `nextPageToken` が無くなる（末尾到達）か上限まで読む。
   - 共通の上限: 1 回の取り込みで最大 `CHANNEL_FETCH_MAX_VIDEOS / 50`（既定 20）ページ、合計 30 秒（5.7）。全件取り込みが上限で止まり続きがある場合は `truncated=true`、末尾到達なら `false`。差分モードでは `truncated` を変更しない。
6. 読み終えたら **1 トランザクションで書き込む**: チャンネル情報の上書き、動画行の追加・削除、動画行が `CHANNEL_FETCH_MAX_VIDEOS` を超えた分の削除（`published_at` の古い順）、`last_synced_at=now`。全件取り込みモードでは加えて `cached_since=now`、`expires_at=now + 30 日` を設定し、`channel_cache_expirations` の記録があれば削除する。差分モードでは `cached_since`・`expires_at` を変えない（期限は延びない）。

**期限の表示（S2）** ［ユーザー決定: Y9／文言は設計判断］
- 期限内のキャッシュを表示するとき: 「この一覧は ○月○日に取り込み、○月○日に削除されます（削除後に開くと全件を取り込み直します）」（`cached_since` と `expires_at` を日本時間で表示）。最終取り込み日時（`last_synced_at`）も従来どおり表示する。
- 期限切れ後の全件取り込みのとき（`channel_cache_expirations` に記録があった場合）: 「前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています」。取り込み完了後の一覧にも同じ文を添え、記録はその時点で削除する。
- 初回（記録が無い）は特別な案内を出さない。

**打ち切り判定を「1 件の既知 ID」ではなく「既知 ID の連続」にする理由** ［設計判断］: uploads の先頭付近では、プレミア公開や公開日時の調整で既知の動画が新規動画の上に並ぶことがある（と想定 ［要確認: U8］）。1 件目の既知 ID で止めると、その下の新規動画を取りこぼす。3 件連続なら軽い並びの揺らぎは吸収でき、追加の読取は通常 0〜1 ページで済む。

**期限をチャンネル単位にする理由**（行単位削除との比較）
- 行単位で消すと一覧の途中や末尾に抜けができ、先頭の既知 ID で止まる差分モードでは埋められない（抜けを埋めるには毎回全ページをたどり直す必要がある）。チャンネル単位でまとめて消せば抜けは生じず、期限切れ後は全件取り込みで一覧全体が作り直される。

**途中で止まった場合** ［設計判断］
- 読取予算切れ・タイムアウト・API エラーで途中終了したら、**何も書き込まない**（手順 6 の書き込みは最後にまとめて行うため、それまでに読んだ分は破棄）。`last_synced_at`・`cached_since` も更新しない。差分モードなら既存のキャッシュをそのまま表示し、全件取り込みモードならキャッシュが無いままで、次に開かれたときに最初から取り込み直す。
- 理由: 差分モードで新しい側だけ書いて止まると、次回は先頭の既知 ID で打ち切られ、その下の新規分が期限まで抜けたままになる。破棄すれば抜けは生じない（読んだ分のユニットは無駄になるが、途中終了はまれと想定）。

**既知の制限（設計判断として受け入れる）**
- uploads の並びが新しい順でない動画（後から非公開→公開に戻した古い動画、プレミア公開、公開日時を過去に設定した動画など）は、差分モードの打ち切り位置より下に入ると**取りこぼす**。このような取りこぼしは、そのチャンネルのキャッシュが期限切れで削除された後の**全件取り込みで解消される**（目安: 最長で 30 日程度）。提案者は動画 URL 追加で回避できる。S2 の取得上限の注記に「最近公開に切り替えた古い動画などは一覧に出ないことがあります。動画 URL で追加してください」を併記する。
- YouTube 側で削除・非公開化された動画は、差分モードでは検出できず、キャッシュの期限切れ（その後の全件取り込みでは入らない）まで一覧に残りうる。これは**提案保存時の videos.list 再取得（5.1）で弾き**、「この動画は削除または非公開になっています。外して保存してください」と表示する。チャンネル主の作成前の最終確認（7.2）でも同様に検出して確認させる。一覧の時点で消えていなくても、保存・作成に進めない点で整合する。
- 一覧が uploads の先頭 1,000 本に限られる点は従来どおり（`truncated` のとき「これより古い動画は動画 URL で追加してください」と表示）。

**読取予算切れの日** ［設計判断・ユーザー了承済み］
- 6 時間を過ぎていても新規取得はせず、期限内のキャッシュをそのまま表示し、「一覧の最終取り込み: ○月○日 ○時（本日の取得上限に達したため更新していません）」と期限の表示を明示する。キャッシュが無いチャンネルは「本日の取得上限に達しました」を表示（期限切れの記録があれば「前回の一覧は 30 日の保存期限で削除されました」を併記）。

**その他**
- 同一チャンネルの同時取り込みは `Cache::lock("channel-fetch:{id}", 60)` で 1 本に絞り、待機側は結果を待ってキャッシュを読む。
- 取得上限 `CHANNEL_FETCH_MAX_VIDEOS`（既定 1,000 本＝20 ページ） ［設計判断］。差分取り込みでもチャンネルあたりの保持行数・1 回の走査ページ数の上限として効く。
- 個別動画（URL 追加）は 10 分だけ Laravel キャッシュに置く（同じ URL の連打対策）。
- 作成中の書込はキャッシュしない。

### 5.4 サーバー全体の日次クオータ管理（QuotaLedger）
**記録（全区分共通）**
- 全 API 呼び出しを QuotaLedger 経由にし、送信前に `quota_usage` を加算（Laravel の `upsert` で `units = units + ?`）。日付は `America/Los_Angeles` の今日（`QuotaClock`）。
- `read`: 送信前に `QUOTA_READ_BUDGET` と比較し、超えるなら送信せず「本日の取得上限」を返す。→ **匿名利用が書込枠を食えない**。
- `owner_read`: チャンネル主操作に伴う少量の読取（ログイン確認、最終確認、照合、自分の限定公開動画の読み込み）。アプリ側の上限は設けず記録のみ ［設計判断］。匿名の読取予算切れでチャンネル主の作成が止まらないようにするため。想定は 1 作成あたり数ユニットで、Google 枠のうち書込・読取上限の外側（約 3,000）に十分収まる。
  - ただし任意の読取である**限定公開動画の読み込み**（Y11）だけは、送信前に当日の `owner_read` を `QuotaPolicy::ownerReadGuard()`（`QUOTA_GOOGLE_DAILY − QUOTA_WRITE_LIMIT − QUOTA_READ_BUDGET` の半分。既定 1,500）と比べ、達していれば送信せず「読み込めませんでした」と案内する ［設計判断］。ログイン確認・最終確認・照合は止めない。

**書込の予約（予約 → 確定 → 解放の 3 段階）** ［設計判断］
1. **予約**（作成開始時）: 見込み `50 + 50×本数` を予約する。トランザクション内で当日の `quota_usage(write)` 行をロック（無ければ作成してから `SELECT … FOR UPDATE`）し、
   `空き = QUOTA_WRITE_LIMIT − 当日の write 送信済み − Σ(当日 active 予約の reserved − consumed)`
   を計算。`空き ≥ 見込み` なら `quota_reservations` に `active` で登録。足りなければ**作成を開始しない**（playlist_creations も作らない）。
2. **確定**（各書込の送信直前）: 同じロックの下で当該作成の当日 active 予約から 50 を消費（`consumed_units += 50`）し、`quota_usage(write) += 50`。予約の残りが 50 未満（一時エラーの再送で見込みを超えた場合）は、空きから 50 を追加予約（`reserved_units += 50`）。空きも無ければ送信せず `paused(quota_budget)` にする。
3. **解放**: 次のときに予約の未使用分（`reserved − consumed`）を空きに戻す（`status=released`。空きの計算は active のみ数えるので、状態変更だけで戻る）。
   - 全項目が終わった（`completed`）。失敗項目があっても、その分は送信済みで消費済み。
   - 利用者が「残りを中止」した（未送信の `pending` を `skipped` にして `completed`）。
   - playlists.insert が恒久エラーで失敗し、動画を追加できない（作成を `completed`＋全項目 `skipped`）。ただし 400（名前・説明の不正）は作成の記録（予約を含む）を削除して編集画面へ戻し、直して作り直せるようにする（5.7）。
- **一時停止中（`paused` の全理由、`needs_check`）は予約を保持する**。トークン切れで再ログインする間に他人に枠を取られないため。
- **PT の日付をまたいだら予約は失効**（前日の予約は当日の空き計算に入らないので自動的に無効。再開時に `status=expired` に更新）。再開時は残り分 `(playlist 未作成なら 50) + 50×(pending＋in_flight の件数)` を当日分として再予約し、確保できなければ再開させずリセット時刻を表示。
- **失敗分の再試行**: `failed` 件数 ×50 を当日の予約に追加（当日 active 予約があれば加算、無ければ新規）。確保できなければ再試行を開始しない。
- 閉じたタブ等で放置された作成も、当日中は予約を保持し続ける（最長でも PT の日付が変わるまで） ［ユーザー決定: Y15］。無操作での解放は行わない。
  - 運用の逃げ道（最終レビュー security I1）: 放置された予約が他のチャンネル主の作成をふさいでいる場合、運用者は `php artisan app:release-creation-reservation {creation_id}` で、作成行→当日の書込行の順にロックしたうえで、その作成の active 予約を解放する（当日分は released、前日以前は expired）。実行中（pending/creating_playlist/adding）の作成は `paused(user)` にする（ステップが勝手に再予約しないため。チャンネル主は「続きから」で空きがあれば再予約して再開できる）。出力は件数のみ。creation_id は作成開始ログ（チャンネル ID の先頭 8 文字つき）で特定する。無操作での自動解放を入れるかは U15 で再検討中。
- 台帳は**見込み**であり Google 側の実値と一致しない。Google の実際の `quotaExceeded` を正とし、受けたら `paused(quota_exceeded)` にする（予約は保持し、日付が変われば失効→再予約）。

### 5.5 残り表示（「残りどれぐらいの操作が可能か」） ［ユーザー決定］
- 計算（`QuotaPolicy`）: `空き`（5.4 の式）から `N = floor((空き − 50) / 50)`。
- 表示文言:
  - `N ≥ 1`: 「本日の残り: 再生リスト 1 つ＋動画あと N 本まで作成できます」
  - `N < 1`: 「本日はこれ以上作成できません。次にリセットされるのは 日本時間 ○月○日 ○時 です」
- リセット時刻は太平洋時間 0 時を日本時間に換算して表示する。**太平洋夏時間（3 月第 2 日曜〜11 月第 1 日曜）は日本時間 16 時、標準時間は 17 時**（`QuotaClock::nextResetJst()` がタイムゾーン DB で計算し、固定値は持たない）。
- 表示場所と内容:
  | 画面 | 表示 |
  |---|---|
  | S2 提案作成 | 本日の残り（参考。「作成はチャンネル主が操作した時点の残りで決まります」と注記）、最大 99 本 |
  | S4 プレビュー（未作成時） | 本日の残りと、この提案の本数 n。n > N ならリセット時刻 |
  | S5 編集・作成 | 「この内容に必要な枠: 再生リスト 1 つ＋動画 n 本」と本日の残り。n > N なら作成ボタン無効、「あと (n − N) 本減らすか、リセット後に作成してください」とリセット時刻 |
  | S6 進捗・結果 | 一時停止（quota_budget / quota_exceeded）や再試行・再開不可のとき、本日の残りとリセット時刻 |
- 内部のユニット数は既定では表示しない（小さく「約 X ユニット」を補足表示するのは可）。
- 表示値はページ表示時点のもの。実際の可否は作成開始時の予約（サーバー側）で決まり、その時点で足りなければ同じ文言で開始を断る。

### 5.6 ユニット試算
| 場面 | read | owner_read | write |
|---|---|---|---|
| 提案者 1 人（初回または期限切れ後の全件取り込み、動画 1,000 本以上のチャンネル） | channels 1 + 20 ページ + 保存時 2 + URL 追加数件 ≒ 25 | — | — |
| 同（差分取り込み、前回以降の新規 50 本未満） | channels 1 + 1 ページ + 保存時 2 ＋ URL 追加分 ≒ 4〜7 | — | — |
| 同（6 時間以内でキャッシュ命中） | 保存時 2 ＋ URL 追加分 ≒ 2〜5 | — | — |
| チャンネル主ログイン | — | 1 | — |
| 自分の限定公開動画の読み込み（1 回） | — | 1 ページ 1（uploads 50 本ごと）。最大 20（打ち切りは 5.1）。セッションに 10 分保持し、チャンネルごとに 5 回/10 分（8.1） | — |
| 作成（n 本、n ≤ 99） | — | 最終確認 1〜2 ＋照合（発生時のみ） | 50 + 50n（49 本 ≒ 2,500、99 本 = 5,000） |
| 1 日の上限（アプリ側） | ≤ 2,000 | 数十想定（限定公開の読み込みは全体で 1,500 に達したら止める） | ≤ 5,000 |

- 一覧取り込みの比較（1,000 本以上のチャンネルを 1 日中よく開かれる＝6 時間ごとに取り込む場合）: 従来の丸ごと取り直しは 21×4 ≒ 84 ユニット/日。差分取り込みは通常 2×4 ≒ 8 ユニット/日で、全件取り込み 21 はチャンネルの期限切れ後に 1 回だけ（30 日に 1 回）加わる（その日でも ≒ 29 ユニット、30 日平均で ≒ 9 ユニット/日）。
- 1 日に作れるのは、99 本なら 1 件、49 本なら 2 件、24 本なら 4 件まで。
- 合計は最大でも約 7,000＋owner_read で、Google の 10,000 に約 3,000 の余裕を残す（照合読取・見込み誤差の吸収）。
- 運用が伸びたらクオータ増加申請（API Compliance Audit が必要 ［事実: A.6］）。申請して増えた場合は `QUOTA_WRITE_LIMIT` を上げれば動画数上限も自動で上がる。

### 5.7 エラー分類と再試行（YouTubeError）
| 応答 | 分類 | 対応 |
|---|---|---|
| 5xx, 409（`SERVICE_UNAVAILABLE` 等）, 429, 接続・タイムアウト | 一時的 | 指数バックオフ（2, 4, 8, 16, 32 秒）で同一項目を最大 5 回。待機はブラウザ側（7.3）。再送分は予約の追加が必要（5.4 の確定）。**書込（insert）の 5xx と応答なし（接続エラー・タイムアウト）は結果不明**として扱い、再送せず次のステップで照合（7.3）してから、無ければ再送する |
| 403 `quotaExceeded` / `dailyLimitExceeded` | Google 側クオータ | 再試行しない。作成は `paused(quota_exceeded)`。読取は利用者にメッセージ |
| 403 `rateLimitExceeded` / `userRateLimitExceeded` | 一時的 | 一時的扱い（間隔を広げる） |
| 401 | 認証切れ | `paused(auth_expired)`、セッションからトークン破棄、再ログイン案内 |
| 404 `videoNotFound`, 403 `forbidden`(動画追加不可) 等 | 項目固有の恒久エラー | 項目を `failed`、次へ進む。再試行ボタンで手動再試行可 |
| 再生リスト単位のエラー（`playlistNotFound`、`playlistItemsNotAccessible`、`insufficientPermissions`、`playlistContainsMaximumNumberOfVideos`、`manualSortRequired`、position に関する 400） | 再生リスト側の問題 | 項目を `failed` にせず作成を `needs_check`（以降の項目も同じ理由で失敗するため） |
| 400 `invalidValue` 等（名前・説明不正） | 入力エラー | playlists.insert なら作成の記録（予約を含む）を削除して編集画面に戻す（直して作り直せる）。その他の playlists.insert の恒久エラーは `completed`＋全項目 `skipped` |
| その他 | 不明 | 項目 `failed`、ログ記録（トークン・キー除く） |

- タイムアウト: 接続 5 秒、全体 15 秒。読取の一覧ページングは 1 リクエスト内で直列（最大 20 回、合計 30 秒で打ち切り。打ち切り時の扱いは 5.3「途中で止まった場合」）。
- 連続 insert で 409 が出るという非公式報告あり ［要確認: A.1］。間隔 1 秒＋上記再試行で吸収できるか Phase 3 で実測する。

---

## 6. OAuth 設計

### 6.1 ライブラリ選択 ［設計判断］: Laravel HTTP クライアントで素の OAuth 2.0
| 候補 | 判断 |
|---|---|
| google/apiclient | 不採用。`google/apiclient-services` が非常に大きく、vendor 丸ごと rsync する運用に重い。必要な API は 6 種類程度で恩恵が小さい。テストでモックしにくい |
| Laravel Socialite | 不採用。ログイン（本人情報取得）用で、既定で openid/email/profile を要求し `user()` が userinfo を叩く。youtube スコープのみ・本人情報不要という要件と合わない |
| 素の HTTP（採用） | 認可 URL 生成・トークン交換・取り消しの 3 エンドポイントだけで済む。`Http::fake()` でテストが簡単。依存が増えない |
- 外部ライブラリを使わない方針は維持する ［ユーザー決定］。その代わり、定評ある OAuth ライブラリが行っている安全対策を**確認項目として**取り込み、実装レビューで 1 項目ずつ照合する（6.7）。コードの模倣・移植はしない。

### 6.2 フロー（認可コード＋PKCE）
1. `GET /auth/google?p={key}`（プレビュー画面のボタン。提案存在確認後）
   - `state`（32 バイト乱数）、PKCE `code_verifier` を生成しセッションへ。戻り先の提案キーもセッションへ（URL パラメータに載せたまま戻さない）。
   - `https://accounts.google.com/o/oauth2/v2/auth` へリダイレクト: `response_type=code`, `scope=https://www.googleapis.com/auth/youtube`, `access_type=online`, `include_granted_scopes=false`, `prompt=select_account`, `state`, `code_challenge(S256)`。
2. `GET /auth/google/callback`
   - `state` をセッションから取り出して削除（成否にかかわらず一回限り）し、`hash_equals` で照合（不一致・欠落・発行から 10 分超は 400）。`error=access_denied` はプレビューに戻して案内（固定文言。6.7 F1）。
   - `https://oauth2.googleapis.com/token` でコード交換（client_secret＋code_verifier）。自動再試行はしない（6.7 D2）。応答の検証は 6.7 E。
   - 応答の `scope` に youtube が含まれるか確認（含まなければトークンを取り消してエラー表示）。リフレッシュトークンが返っても保存せず破棄（access_type=online なので本来返らない）。
   - `session()->regenerate()`（セッション固定対策）。
   - channels.list mine=true でチャンネル ID・名前を取得。チャンネルが無いアカウントは「YouTube チャンネルがありません」。
   - セッションに `owner = {access_token, expires_at, channel_id, channel_title}` を保存し、提案の channel_id と一致すれば `/p/{key}/edit`（作成済みなら `/p/{key}/creation`）、不一致ならエラー画面（2.4）。
- PKCE は Google のウェブクライアントでも利用可能 ［要確認］。不可なら state のみで成立する。

### 6.3 認可範囲
- スコープは `https://www.googleapis.com/auth/youtube` のみ（sensitive、restricted ではない ［事実: A.3］）。email/profile/openid は要求しない。
- `youtube.force-ssl` 等の別スコープは使わない（Developer Policies「使うスコープだけを要求」 ［事実: A.6］）。

### 6.4 チャンネル ID 照合
- 照合は 3 か所: ログイン直後、作成開始時、作成の各ステップ（セッションの channel_id ＝ 提案の channel_id ＝ creation.owner_channel_id）。
- `RequireOwnerSession` ミドルウェアにまとめ、トークン期限切れ（`expires_at` 経過）もここで判定する。
- プレビューで非公開・限定公開の再生リストリンクを出すかの判定（7.6）も同じ照合関数を使う（ただしプレビュー自体はログイン不要）。

### 6.5 トークンの保持
- アクセストークンは**セッションのみ**。自前テーブル・ログ・キャッシュに書かない。
- セッションは `database` ドライバ＋`SESSION_ENCRYPT=true` ［設計判断］。「トークンは DB/ファイル非保存」は「アプリのテーブル・ファイルに永続化しない」と解釈し、セッション格納そのものは避けられないため暗号化で補う。代案の `cookie` ドライバ（サーバーに一切置かない）は、サーバー側で無効化できない点と 4KB 制限から不採用。
- Cookie: `Secure`, `HttpOnly`, `SameSite=Lax`（OAuth コールバックはトップレベル GET なので Lax で可）。`SESSION_LIFETIME=120`。

### 6.6 ログアウトと取り消し
- `POST /logout`: `https://oauth2.googleapis.com/revoke` にトークンを送って**取り消し**（失敗しても続行）、セッション破棄。完了画面とログアウト後画面で表示。 ［設計判断: 取り消しまで行う。Developer Policies III.D.2.3 にも沿う ［事実: A.6］］
- 作成完了画面・プライバシーポリシーに「Google アカウントの [サードパーティのアクセス](https://myaccount.google.com/permissions) からいつでも承認を取り消せます」と案内。
- 期限切れ（約 1 時間 ［要確認: 3599 秒が通例］）でセッションの owner を破棄。

### 6.7 参照ライブラリの安全対策チェックリスト ［ユーザー決定: 直接実装を維持し、確認項目として取り込む］
- 目的: OAuth 2.0 認可コードフローと HTTP 呼び出しを自前で書くことによる見落としを防ぐ。**Laravel Socialite**、**league/oauth2-client**、**Google 公式 google/apiclient（google/auth）** が行っている安全対策を観点として列挙し、実装がそれぞれを満たすか（または対象外である理由）をレビューで確認する。
- 本表の「参照先」はライブラリの一般的な挙動に基づく観点の出典であり、ソースでの裏取りは**実装フェーズのレビュー担当が行う** ［要確認］（Phase 2 ではレビュー担当の知識に基づく照合までを行い、判定記録に記入した。ソースでの確認は U13）。ライブラリと違う判断をした項目は、理由を本節に追記する。
- 使い方: Phase 2 の完了条件として、レビュー担当が全項目に「満たす／対象外（理由）」を記入する（10.5・11 章）。各項目の「テスト」欄が空でないものは自動テストを必須とする。

**A. state（CSRF・ログイン CSRF 対策）**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| A1 | 認可開始ごとに CSPRNG（`random_bytes`）で 128 ビット以上の state を生成する（本設計は 32 バイト） | Socialite（ランダム文字列）、league（ランダム state 生成） | 生成値の長さ・毎回異なること |
| A2 | state はサーバー側セッションに保存し、URL・Cookie 平文・hidden 項目に正本を置かない | Socialite（セッション保存） | — |
| A3 | コールバックで `hash_equals` による照合。欠落・空文字・配列・不一致はすべて拒否（400）し、トークン交換に進まない | Socialite（不一致で InvalidStateException）、league（state 照合はアプリ側責務と明示） | 欠落／空／不一致／配列で 400、トークン要求が送られないこと |
| A4 | **一回限り**: 照合の成否にかかわらず、照合時にセッションから取り出して削除（pull）する。同じ state での 2 回目のコールバックは拒否 | Socialite（セッションから pull） | 同じコールバック URL の再送が 400 |
| A5 | state に有効期限（例 10 分）を持たせ、超過したら拒否 | — （本設計の追加） | 期限切れで 400 |
| A6 | 「stateless」相当の state 省略モードを作らない | Socialite の `stateless()` は state 検証を外す点に注意 | — |

**B. PKCE**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| B1 | `code_verifier` は CSPRNG 由来・43〜128 文字・RFC 7636 の非予約文字のみ（32 バイトの base64url で 43 文字） | league（PKCE 対応）、google/auth（code_verifier 生成） | 長さ・文字種 |
| B2 | `code_challenge = BASE64URL(SHA-256(verifier))`（パディング無し）、`code_challenge_method=S256` 固定。`plain` は使わない | league（S256 / plain の選択があり S256 を選ぶ） | RFC 7636 付録 B のテストベクトルで一致 |
| B3 | verifier はセッションのみに保存し、トークン交換に 1 回だけ使って削除 | — | 交換後にセッションから消えていること |

**C. redirect_uri・リダイレクト先**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| C1 | redirect_uri は設定値（`GOOGLE_REDIRECT_URI`）の固定文字列。リクエストの Host ヘッダ等から組み立てない | Socialite・league（設定値として保持） | 偽の Host ヘッダでも認可 URL の redirect_uri が設定値のまま |
| C2 | 認可リクエストとトークン交換で**完全に同じ文字列**を送る。GCP に登録した URI と完全一致（末尾スラッシュ・http/https 含む）。本番は https のみ | 全ライブラリ（同じ値を両方に送る） | 両リクエストの redirect_uri が一致 |
| C3 | ログイン後の戻り先は、セッションに保存した提案キーから**アプリ内ルートだけを組み立てる**。URL パラメータの任意 URL へ戻さない（オープンリダイレクト防止） | — | 外部 URL を渡しても内部ルートにしか飛ばない |

**D. 認可コード・コールバック**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| D1 | コードは 1 回だけ交換する。A4 により同じコールバックの再送はトークン交換前に止まる。交換で `invalid_grant` が返ったら再試行しない | 全ライブラリ（交換は 1 回） | 2 回目のコールバックでトークン要求が送られない |
| D2 | トークン交換は**自動再試行しない**（コードは一回限りのため。タイムアウト時は「もう一度ログインしてください」） | — | タイムアウトで再送が無いこと |
| D3 | コールバック処理後はリダイレクト（PRG）し、`code`・`state` を含む URL を画面・履歴に残さない。Referrer-Policy: no-referrer（8.6）で外部に送らない | — | 応答が 302 で、本文に code を含まない |
| D4 | `code` の形式検証（文字列・長さ上限・許可文字）。`error` と `code` が両方ある等の不整合は拒否 | — | 不正形式で 400 |
| D5 | 認可サーバーから `iss` パラメータが返る場合は `https://accounts.google.com` と一致することを確認（RFC 9207） ［要確認: Google が返すか］ | — | 返る場合のみ |

**E. トークン交換・応答の検証**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| E1 | トークンエンドポイントへ POST（`application/x-www-form-urlencoded`）、`grant_type=authorization_code`、client_secret は本文（またはBasic 認証）で送り、URL クエリに載せない | league・google/auth（POST 本文で送信） | 送信内容の検査 |
| E2 | 応答は HTTP 200 かつ JSON として解釈できること。`access_token`（空でない文字列）、`token_type` が `Bearer`（大文字小文字無視）、`expires_in`（正の整数、異常に大きい値は上限で丸める）を必須とし、欠けたら失敗扱い | league（AccessToken 生成時に access_token 必須を検査）、google/auth | 各フィールド欠落・型違いで失敗 |
| E3 | `scope` に `https://www.googleapis.com/auth/youtube` が含まれることを確認。含まれなければトークンを取り消してエラー（6.2） | — （google/auth は付与スコープを確認しないとみられる ［要確認］。本設計の方が厳しい） | スコープ不足で revoke が呼ばれる |
| E4 | `refresh_token` が返っても保存せず破棄。`id_token` は要求しない（OIDC を使わないため nonce・ID トークン署名検証は**対象外**。Socialite・google/auth の ID トークン検証は本設計に該当しない旨を記録） | Socialite（userinfo/id_token 利用）との違い | DB・セッションに refresh_token が無い |
| E5 | `expires_at = 取得時刻 + expires_in − 安全余裕（60 秒）` | google/auth（期限前の余裕を持った判定） | 境界時刻で期限切れ扱い |

**F. エラー応答の扱い**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| F1 | 認可画面からの `error`（`access_denied` 等）は既知の値を固定文言に対応付けて表示し、`error_description` をそのまま画面に出さない（反射型の偽メッセージ・XSS 防止） | league（IdentityProviderException で包む） | 任意文字列の error_description が画面に出ない |
| F2 | トークン交換の 4xx/5xx は例外として扱い、ログには HTTP ステータスと `error` コードのみ。応答本文全体は記録しない | league（エラー応答で例外） | ログに本文が出ない |
| F3 | revoke の失敗はログ（ステータスのみ）に残して処理を続行し、セッションは必ず破棄（6.6） | — | revoke 失敗でもログアウト完了 |

**G. セッション**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| G1 | ログイン成功時に `session()->regenerate()`（セッション固定対策）。state・verifier は regenerate 前に検証・削除済み | Laravel 標準の認証と同様 | ログイン前後でセッション ID が変わる |
| G2 | ログアウト時は `invalidate()`＋`regenerateToken()`（CSRF トークンも更新） | Laravel 標準 | ログアウト後に旧セッションで編集画面に入れない |
| G3 | トークンは暗号化セッションのみ（6.5）。キャッシュ・DB のアプリテーブル・ログ・ビューに出さない | — | 全テーブルにトークン文字列が無い（既存ケース） |

**H. HTTP 通信（OAuth・YouTube API 共通）**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| H1 | TLS 証明書検証を有効のまま使う（`withoutVerifying()` 等の無効化を書かない。コード検索で確認） | 全ライブラリ（Guzzle 既定の検証） | 静的確認 |
| H2 | エンドポイント URL（`accounts.google.com`、`oauth2.googleapis.com`、`www.googleapis.com`）は https 固定の定数。設定やリクエストから差し替えられない | google/auth（定数） | — |
| H3 | タイムアウト（接続 5 秒・全体 15 秒。5.7）を全呼び出しに設定 | google/apiclient（HTTP クライアント設定） | タイムアウトで分類どおり扱われる |
| H4 | トークン・revoke エンドポイントはリダイレクトを追従しない（`withoutRedirecting()`）。秘密情報を別ホストへ転送させない | — | 3xx 応答で失敗扱い |
| H5 | revoke はトークンを POST 本文で送る（URL クエリに載せない） | google/auth（revoke） | 送信内容の検査 |

**I. ログ・秘密情報**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| I1 | アクセストークン・認可コード・state・code_verifier・client_secret・API キー・`Authorization` ヘッダをログ・例外メッセージ・画面に出さない（8.8 のマスク用ラッパーを OAuth 呼び出しにも通す） | 全ライブラリ（トークンをログしない） | 例外を起こしてログに上記文字列が無い |
| I2 | 本番は `APP_DEBUG=false`（デバッグ画面にリクエスト・環境変数を出さない）。起動時に production で true なら警告 | — | — |
| I3 | Web サーバーのアクセスログにはコールバック URL（code を含む）が残りうる。コードは一回限り・短命で、A4・D1 により再利用できないことで許容する旨をプライバシーポリシー対応とあわせて記録 | — | — |
| I4 | client_secret は `.env` のみ。フロント（JS・HTML）に出さない | — | ビルド成果物・HTML に含まれない |

**J. その他**
| # | 確認内容（合格条件） | 参照先 | テスト |
|---|---|---|---|
| J1 | 要求スコープは `youtube` のみ、`include_granted_scopes=false`（6.3）。応答で余分なスコープが付いても使わない | google/auth（スコープ指定） | 認可 URL のパラメータ検査 |
| J2 | OAuth 開始・コールバックにレート制限（8.1）、コールバックは GET のみ | — | 既存ケース |
| J3 | 認可 URL のパラメータは `http_build_query`（RFC 3986 エンコード）で組み立て、文字列連結しない | league（クエリ組み立て） | 認可 URL の解析で全パラメータが期待どおり |

**判定記録（Phase 2）**

判定は実装者の記入を Phase 2 の最終レビューで確定した。「参照ライブラリでの裏取り」欄は最終セキュリティレビュー担当の**知識に基づく照合**であり、各ライブラリのソースを読んで確かめたものではない（「?」は担当者自身が不確かとした箇所）。全項目で本実装はライブラリと同等以上で、「満たさない」は無い。ソースでの裏取りは残課題として U13 に記録する（未記入または「満たさない」が残る間は Phase 2 を完了としない — 10.5・11 章）。

| # | 判定（満たす・対象外） | 根拠（テスト名またはファイル・理由） | 参照ライブラリでの裏取り（S = Socialite、L = league/oauth2-client、G = google/auth・google/apiclient。「?」は不確か） |
|---|---|---|---|
| A1 | 満たす | `OwnerSessionTest::test_begin_generates_fresh_43_char_state`（32 バイトの CSPRNG・base64url 43 文字・毎回異なる） | S: `Str::random(40)`／L: `bin2hex(random_bytes(16))`（128 ビット）／G: アプリ側で生成。本実装は 256 ビット CSPRNG で同等以上 |
| A2 | 満たす | 静的: state は `OwnerSession`（暗号化 DB セッション）にのみ保存。`OwnerAuthStartTest::test_start_stores_state_only_in_session`（Cookie・本文に出ない） | S: セッションの `state`／L・G: アプリ側の責務。同等 |
| A3 | 満たす | `OwnerCallbackValidationTest::test_bad_state_is_400_without_token_request`（欠落／空／不一致／配列。`hash_equals`） | S: `strlen>0 && ===`（`hash_equals` ではない）／L: アプリ側の責務（ドキュメント例は `!==`）／G: なし。本実装は時間一定比較・配列拒否でより厳しい |
| A4 | 満たす | `OwnerSessionTest::test_pull_pending_is_one_time`、`OwnerCallbackValidationTest::test_replayed_callback_is_400`（照合前に pull） | S: セッションから pull／L: ドキュメント例は失敗時のみ unset（?）／G: なし。本実装は照合前に pull し L より厳しい |
| A5 | 満たす | `OwnerCallbackValidationTest::test_state_older_than_10_minutes_is_400`（`OwnerSession::STATE_TTL_SECONDS` = 600） | いずれも期限なし（セッションの寿命に従う）。本実装は 600 秒でより厳しい |
| A6 | 満たす | 静的: state 省略モード・設定が無い。`OwnerSecurityTest::test_no_stateless_mode_exists`（`app/` と関連設定に `stateless` が 0 件） | S: `stateless()` あり／L・G: state の組み込み機能なし。本実装は省略モードなしで S より厳しい |
| B1 | 満たす | `PkceTest::test_verifier_is_43_unreserved_chars_and_random` | S: `Str::random(96)`（PKCE 時）／L: 64 文字（2.7 以降）／G: `generateCodeVerifier` 64〜128 文字（?）。本実装は 32 バイト由来の 43 文字で RFC 7636 の範囲内・同等 |
| B2 | 満たす | `PkceTest::test_challenge_matches_rfc7636_appendix_b`、`GoogleOAuthTest::test_authorization_url_parameters`（`code_challenge_method=S256` 固定） | S: S256／L: S256・plain を選択可／G: S256。本実装は S256 固定で L より厳しい |
| B3 | 満たす | `OwnerSessionTest::test_pull_pending_is_one_time`、`OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit`（交換後に pending 無し） | S: トークン取得時にセッションから pull／L・G: アプリ側の責務。同等 |
| C1 | 満たす | `OwnerAuthStartTest::test_redirect_uri_ignores_forged_host`（`config('services.google.redirect')` のみ） | S: 設定値を `url()` で絶対化（ホストはリクエスト由来）／L・G: オプションの値。本実装は設定の絶対 URL のみで S より厳しい |
| C2 | 満たす | `GoogleOAuthTest::test_is_configured_rejects_blank_and_http_in_production`、`OwnerCallbackLoginTest::test_redirect_uri_is_identical_in_both_requests`。GCP 登録値との完全一致は人手確認（README） | いずれも両リクエストに同じ値を送るが https は強制しない。本実装は本番で https 必須でより厳しい |
| C3 | 満たす | `OwnerAuthStartTest::test_external_return_url_is_ignored`、`OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit`（戻り先は `proposals.edit` ルートのみ） | いずれもアプリ側の責務。本実装は固定の内部ルートで同等以上 |
| D1 | 満たす | `OwnerCallbackValidationTest::test_replayed_callback_is_400`（2 回目はトークン要求なし）、`GoogleOAuthTest::test_invalid_grant_is_not_retried` | いずれも 1 回の呼び出しで 1 回交換。再送防止は state 次第（照合するのは S のみ）。同等 |
| D2 | 満たす | `GoogleOAuthTest::test_timeout_is_not_retried` | S・L: 再試行なし（Guzzle 既定）／G: apiclient の再試行設定は既定で無効（?）。同等 |
| D3 | 満たす | `OwnerCallbackLoginTest::test_success_logs_in_and_redirects_to_edit`（302・本文に code 無し・`Referrer-Policy: no-referrer`） | いずれもアプリ側の責務。本実装は対応済み |
| D4 | 満たす | `OwnerCallbackValidationTest::test_malformed_code_or_error_with_code_is_400`（`OwnerAuthController::CODE_PATTERN`） | いずれも code の形式を検査しない。本実装はより厳しい |
| D5 | 満たす（返る場合のみ） | `OwnerCallbackValidationTest::test_wrong_iss_is_400_and_correct_iss_passes`。Google が `iss` を返すかは人手確認 | いずれも RFC 9207 の `iss` を検査しない（?）。本実装はより厳しい |
| E1 | 満たす | `GoogleOAuthTest::test_exchange_posts_form_body`（`asForm()` の POST 本文。URL クエリに秘密なし） | いずれも client_secret を POST 本文で送る。同等 |
| E2 | 満たす | `GoogleOAuthTest::test_exchange_rejects_invalid_token_responses`（データプロバイダ） | L: `access_token` のみ必須で `token_type`・`expires_in` は検査しない／S・G: デコードした JSON を検査せず使う。本実装はより厳しい |
| E3 | 満たす | `OwnerCallbackLoginTest::test_insufficient_scope_revokes_and_explains`。応答に `scope` が無い場合も不足扱い（下記の理由 5） | いずれも付与スコープを検査しない（G は ?。旧版の本表は G が確認するとしていたが、おそらくしない）。本実装はより厳しい |
| E4 | 満たす（ID トークン検証は対象外） | `GoogleOAuthTest::test_refresh_token_is_dropped`、`OwnerCallbackLoginTest::test_session_holds_only_owner_fields`、`OwnerSecurityTest::test_no_token_in_any_table_cache_or_log`。OIDC を使わないため id_token・nonce・署名検証は対象外 | S（Google）: userinfo を使う／G: ID トークンを JWKS で検証／L: なし。OIDC を使わないため対象外。ライブラリは refresh_token を保持するが本実装は破棄する |
| E5 | 満たす | `GoogleOAuthTest::test_expires_at_has_60s_margin`・`test_expires_at_is_capped_at_3600`、`OwnerSessionTest::test_current_is_null_at_expiry_boundary`、`OwnerEditAccessTest::test_expired_owner_is_sent_back_to_preview_at_boundary` | apiclient: `isAccessTokenExpired` は 30 秒の余裕／L・S: 余裕なし（?）。本実装は 60 秒＋3600 秒上限でより厳しい |
| F1 | 満たす | `OwnerCallbackValidationTest::test_error_description_is_never_shown` | L: `IdentityProviderException` で包む／S: 特別な扱いなし。本実装は固定文言で同等以上 |
| F2 | 満たす | `GoogleOAuthTest::test_token_error_logs_only_status_and_code` | L: 例外に応答本文を保持／S: Guzzle の `ClientException`（メッセージに本文を含む）がそのまま伝わる。本実装はステータス・コードのみでより厳しい |
| F3 | 満たす | `OwnerLogoutTest::test_revoke_failure_still_logs_out`（取り消し失敗は画面で案内し、ログはステータスのみ） | G: `revokeToken` が bool を返す／S・L: 取り消し機能なし。同等 |
| G1 | 満たす | `OwnerCallbackLoginTest::test_session_id_changes_on_login`（`regenerate(true)` で旧セッション行も削除） | Laravel の標準的な手順。ライブラリはアプリ側の責務。本実装は対応済み |
| G2 | 満たす | `OwnerLogoutTest::test_logout_revokes_and_invalidates_session`、`test_csrf_token_changes_on_logout`、`test_session_id_changes_on_logout` | Laravel の標準的な手順。ライブラリはアプリ側の責務。本実装は対応済み |
| G3 | 満たす | `OwnerSecurityTest::test_no_token_in_any_table_cache_or_log`（database セッション＋暗号化・database キャッシュで全テーブルとログを検査） | いずれもトークンを永続化しない。同等 |
| H1 | 満たす | `OwnerSecurityTest::test_tls_verification_is_never_disabled`（`app/` のソース検査） | いずれも Guzzle の TLS 検証を有効のまま使う。同等 |
| H2 | 満たす | `GoogleOAuthTest::test_endpoints_are_https_constants`（`GoogleOAuth`／`YouTubeReader` の `const`） | G: エンドポイント定数／S: ハードコード／L: プロバイダクラス。同等 |
| H3 | 満たす | `GoogleOAuthTest::test_timeout_is_not_retried`、`YouTubeOwnerClientTest::test_connection_error_is_transient`（全呼び出しに `connectTimeout(5)->timeout(15)`） | いずれも Guzzle 既定でタイムアウトなし（?）。本実装は 5 秒／15 秒でより厳しい |
| H4 | 満たす | `GoogleOAuthTest::test_redirect_response_is_failure`（トークン・取り消しとも `withoutRedirecting()`） | いずれも Guzzle 既定でリダイレクトを追従（?）。本実装はより厳しい |
| H5 | 満たす | `GoogleOAuthTest::test_revoke_posts_token_in_body` | G: POST 本文／S・L: 対象外（取り消しなし）。同等 |
| I1 | 満たす | `OwnerSecurityTest::test_secrets_never_reach_logs`（トークン 500・接続例外・channels 401・取り消し 400・ログアウト時の取り消し 400）、`OwnerSecurityTest::test_secret_parameters_are_sensitive`。8.8 のマスク用ラッパーは作らず `#[\SensitiveParameter]`＋ログはステータス・コードのみで代替（下記の理由 6） | 意図的にトークンをログしないが、S・L は例外メッセージに応答本文が入りうる。本実装はより厳しい |
| I2 | 満たす | `OwnerSecurityTest::test_debug_in_production_logs_warning`・`test_boot_runs_the_debug_check`（`AppServiceProvider::warnAboutDebugInProduction()`）。本番の `APP_DEBUG=false` はデプロイ時の人手確認 | アプリ・デプロイ側の事項でライブラリは対象外 |
| I3 | 対象外（記録のみ） | Web サーバーのアクセスログはホスティング側の管理。コールバック URL の code は一回限り・短命で、A4・D1 により再利用できない。README の運用メモに記載、プライバシーポリシーへの反映は Phase 4。本番でアクセスログに残るかは人手確認 | アプリ・デプロイ側の事項でライブラリは対象外 |
| I4 | 満たす | `OwnerSecurityTest::test_client_secret_never_reaches_html_or_frontend`（S4・S5・不一致・callback-invalid・ログアウト後の HTML、`resources/js`・`.env.example` に `VITE_*GOOGLE*` 無し） | アプリ・デプロイ側の事項でライブラリは対象外 |
| J1 | 満たす | `GoogleOAuthTest::test_authorization_url_parameters`（`scope` は youtube のみ・`include_granted_scopes=false`。応答の余分なスコープは使わない） | いずれも設定したスコープを送る。S（Google）は既定で openid・profile・email を加える。本実装は youtube のみでより厳しい |
| J2 | 満たす | `OwnerAuthStartTest::test_oauth_routes_are_rate_limited`・`test_callback_is_get_only` | アプリ・デプロイ側の事項でライブラリは対象外 |
| J3 | 満たす | `GoogleOAuthTest::test_authorization_url_parameters`（`parse_str` で全パラメータ一致・RFC 3986） | L: `http_build_query`（RFC 3986）／S: `http_build_query`（RFC 1738、?）／G: Guzzle `Query::build`。同等 |

ライブラリと違う判断をした項目の理由:
1. **state の 10 分期限（A5）**: Socialite・league は state に期限を持たせない（セッションの寿命に従う）。本設計は放置されたログイン手続きを短く無効にするため 600 秒で拒否する。拒否時は「もう一度ログインしてください」とプレビューへのリンクを出す。
2. **トークン交換の再試行なし（D1・D2）**: 認可コードは一回限りで、再送は `invalid_grant` になるか、成功していた場合はトークンを二重に発行させるだけになる。タイムアウト・5xx でも再試行せず再ログインを案内する。
3. **トークン・取り消しのリダイレクト非追従（H4）**: Guzzle の既定はリダイレクトを追従するが、client_secret・認可コード・トークンを含む POST 本文を別ホストへ再送させないため `withoutRedirecting()` とし、3xx は失敗扱いにする。
4. **再ログイン時に古いトークンを取り消さない上書き（ログアウト時は取り消す）**: 同じ Google アカウント・同じクライアントの再ログインでは、古いトークンの取り消しが同じ付与（grant）ごと新しいトークンも無効にしうるため、セッション内の古いトークンは取り消さずに上書きする（古いトークンは最長 1 時間で失効し、どこにも保存されない）。ログアウト時はセッションのトークンを（期限切れでも）取り消す。
5. **`scope` 欠落を不足扱い（E3）**: RFC 6749 では要求どおりなら `scope` を省略できるが、Google は付与スコープを返すため、欠落は想定外として不足扱いにし、トークンを取り消して案内する（安全側）。
6. **マスク用ラッパーを作らない（I1）**: 8.8 のマスク用ラッパーの代わりに、秘密を受け取る引数すべてに `#[\SensitiveParameter]` を付け（例外トレースから除外）、ログは「何が・HTTP ステータス・形式を検証した `error`／`reason` コード」だけを記録する。応答本文・URL・例外メッセージは記録しない。テストで固有文字列がログに出ないことを確認する。

---

## 7. 再生リスト作成処理

### 7.1 方式 ［設計判断］: ブラウザ駆動の 1 ステップずつ実行
- 共用サーバーでキュー常駐不可、PHP の実行時間制限もある。99 本を 1 リクエストで処理すると数分かかり途中で切れる。
- そこで **進捗画面の JS が `POST /p/{key}/creation/step` を繰り返し呼ぶ**。サーバーは 1 回の呼び出しで API 書込を 1 つだけ行い、結果と次の待機秒数を返す。
- cron（毎分）で裏で進める案は、トークンをセッション外に持つ必要があり要件違反のため不採用。

### 7.2 開始（`POST /p/{key}/creation`）
1. `RequireOwnerSession` を通過（S6 の表示・開始・ステップ・再開・中止はすべてチャンネル主のログイン＋チャンネル一致が必要）。入力（編集後のリスト・名前・説明・公開範囲）を検証（8.2。本数は 1〜99）。
   - **JSON で呼ばれる経路**（ステップ等、`expectsJson()`）では、`RequireOwnerSession` は画面へのリダイレクトではなく、未ログイン・期限切れに 401 JSON（`RequireOwnerSession::NOTICE`）、チャンネル不一致に 403 JSON を返す（Phase 2 の `RequireOwnerSession` は HTML 画面用のリダイレクト／不一致画面のみだったため、Phase 3 で追加した）。
2. 作成前の最終確認として videos.list で全動画の存在と公開状態を再取得（owner_read）。消えた動画は除外せず、画面に戻して確認させる。
   - **チャンネル主自身の限定公開動画**（Y11。S5 で追加したもの）は、公開動画と同じ API キーの確認では落とさず、**チャンネル主のトークン**で videos.list を呼び、`snippet.channelId` がログイン中のチャンネルと一致し `status.privacyStatus` が `unlisted` であるものだけを受け付ける。それ以外の非公開・他チャンネルの限定公開は拒否する。
   - 限定公開動画の ID は `proposal_items` にも、プレビュー（S4）・共有画面など第三者が見られる画面にも**決して書かない／出さない**（作成の記録 `playlist_creation_items` にのみ置き、表示はログイン中の該当チャンネル主の S6 だけ）。
   - 最終確認やステップで Google が 401 を返したら（トークン失効・取り消し）、`OwnerSession` のログインを消して（`forget()`）再ログインを求める（JSON では 401＋`RequireOwnerSession::NOTICE`。Phase 2 の `RequireOwnerSession::ownerApiFailureJson()` を使う）。
3. トランザクションで
   - `playlist_creations`（UNIQUE proposal_id）の存在確認。既に存在すれば何もせず進捗画面へリダイレクト（**二重作成防止その 1: DB 一意制約**）。
   - **書込枠の予約**（5.4）。足りなければロールバックし、編集画面に「本日の残り: 動画あと N 本」とリセット時刻を表示（作成は始まらない）。
   - `playlist_creations` と items（position は確定順 0..n−1）、`quota_reservations` を作成。
4. 進捗画面へ。ステップは「作成」を押した直後だけ自動で始まる（S6 を開き直しただけでは始めず「続きから」を押させる）。作成を始めた後は編集画面（S5）は開かず S6 へ移す（7.5）。

### 7.3 ステップ（`POST /p/{key}/creation/step`）
- 行ロック（`SELECT … FOR UPDATE` on creation）で同時実行を 1 つに制限（**二重作成防止その 2**。タブ 2 つ開いても安全）。行ロックは状態の決定と記録に使い、YouTube 呼び出し中は持たない。同時実行は 60 秒のリース（items.leased_at／creations.playlist_leased_at）で 1 つに絞る。接続エラー（応答なし）は結果不明として次のステップで照合する。
- 書込の送信直前に予約から 50 を確定（5.4）。確定できなければ送信せず `paused(quota_budget)`。
- 状態ごとの処理:
  - `pending` → `creating_playlist` にして `playlist_attempted_at` 記録 → playlists.insert。成功で `youtube_playlist_id` 保存、`adding` へ。
  - `creating_playlist` のまま残っている（前回の結果不明: タイムアウト等）→ **再送しない**。playlists.list mine=true で「同名・`playlist_attempted_at` 以降作成」を探し、見つかれば採用、無ければ insert を再実行。判断できなければ（同名候補が複数ある等の曖昧な場合も含む）`needs_check` にして画面で「YouTube Studio を確認してください」と表示し、「見つからなかったので作り直す」ボタンを出す（**二重作成防止その 3**）。作り直しは古い再生リスト ID を捨て、全項目を `pending` に戻して新しい再生リストに全項目を入れ直す（再生リスト＋全項目分を再予約）。
  - `adding` → 次の `pending` 項目（position 昇順）を `in_flight`（leased_at）にして playlistItems.insert（`snippet.playlistId`, `snippet.resourceId{kind: youtube#video, videoId}`, **`snippet.position`**）。成功で `done`＋item id。
  - `in_flight` が 60 秒以上前のまま残っている項目 → 結果不明。作成した再生リストを playlistItems.list で確認し（50 件 1 ユニット、owner_read）、既にあれば `done`、無ければ再送。
  - 全項目が `done/failed/skipped` → `completed`、予約を解放。
- **並び順の保持（`snippet.position`）** ［設計判断］: 送信する position は「この項目より前（creation の position が小さい）で `done` の項目数」。初回の順送りでは末尾追加と同じ結果になり、失敗分を後から再試行したときも提案（確定）どおりの位置に入る。position 指定に追加ユニットはかからない（insert 1 回 50 のまま）。position が現在の項目数を超えるとエラーになる可能性があるため、`done` 件数で上限を取る。position 指定には再生リストが手動並び替え設定である必要がある可能性 ［要確認: Phase 3。API で作成した直後の既定値を確認］。チャンネル主が作成途中に YouTube 側で並びを変えた場合の順序は保証しない。
- 応答: `{status, done, failed, total, next_delay_ms, message, remaining_today}`。正常時 `next_delay_ms=1000`、一時エラー時はバックオフ秒数。
- 一時エラーの再試行回数は `attempts` に保存（ブラウザを閉じても回数が残る）。上限超過で `failed`。

### 7.4 一時停止・再開・中止
| 状況 | 状態 | 予約 | 再開 |
|---|---|---|---|
| Google の `quotaExceeded` | `paused(quota_exceeded)`（その PT 日付を `paused_day_pt` に記録） | 保持（日付が変われば失効） | 翌 PT 日以降（`paused_day_pt` より後の PT 日）。リセット時刻を表示 |
| アプリの書込上限で確定できない | `paused(quota_budget)` | 保持 | 空きができるかリセット後。再予約できたら再開 |
| 401／セッション期限切れ | Google の 401 だけ `paused(auth_expired)` にし、セッションのログインを消す（`forget()`、7.2）。セッション期限切れ（アプリ側）は未認証の要求で状態を変えないため paused にはしない（401 JSON を返すだけ） | 保持 | 「再ログインして続きから」。ログイン後、作成がある提案は `/p/{key}/creation` へ戻る（6.2） |
| タブを閉じた・回線断 | 変化なし | 保持 | URL を開き直して「続きから」 |
| 利用者の一時停止ボタン | `paused(user)`（表示用） | 保持 | 「続きから」 |
| 利用者の「残りを中止」 | 未送信を `skipped` → `completed` | 解放 | 不可（失敗分の再試行のみ可） |
- 再開時、当日の active 予約が無ければ（日付をまたいだ）残り分を再予約（5.4）。確保できなければ再開させず、本日の残りとリセット時刻を表示。

### 7.5 完了後
- 成功数・失敗数、失敗項目と理由、`https://www.youtube.com/playlist?list={id}` を表示。
- 「失敗分を再試行」: 必要な枠（動画 m 本）と本日の残りを表示し、予約できたら `failed` → `pending` に戻して再開（恒久エラーも本人判断で再試行可）。position 指定により元の並び順の位置に入る。
- 編集画面は開けない。

### 7.6 プレビューでの作成済みリンクの表示 ［設計判断］
| privacy_status | 未ログイン・他チャンネルでログイン | ログイン中の該当チャンネル主（セッションの channel_id ＝ 提案の channel_id） |
|---|---|---|
| public | 「作成済み」＋再生リストへのリンク | 同左 |
| unlisted / private | 「作成済み」のみ（リンク・ID は HTML に出さない） | 「作成済み」＋リンク |
- 理由: 共有 URL は誰でも開けるため、限定公開・非公開の再生リスト ID を第三者に渡さない。
- 同じ理由で、チャンネル主が追加した**自分の限定公開動画**（Y11）の ID・タイトルはプレビューに一切出さない（`proposal_items` に書かないため、プレビューに出る動画は提案時の内容のみ。7.2）。

### 7.7 進捗に保持するもの
- 作成内容、各項目状態、YouTube 側 ID、エラー理由、予約量のみ。**トークンは保持しない**。

---

## 8. 悪用対策

### 8.1 レート制限（`RateLimiter`、キーは IP。初期値、運用で調整） ［設計判断］
| 対象 | 制限 |
|---|---|
| チャンネル取得（キャッシュ無し時のみ消費） | 5 回/分・30 回/時/IP |
| 動画 URL 追加 | 30 回/分/IP |
| 提案保存（**保存に成功した回だけ**数える。Y17） | 5 回/10 分・30 回/日/IP、全体 500 件/日 |
| 提案保存の POST（成否を問わずすべて。Y17） | 20 回/10 分/IP |
| チャンネル主の限定公開動画の読み込み（Y11） | 5 回/10 分/チャンネル |
| チャンネル主の動画 URL 追加（S5。D45） | 30 回/分/チャンネル |
| 　うちチャンネル主のトークンで確認する回（D45。セッションの一覧・公開動画で見つからなかったときだけ数える） | 10 回/10 分/チャンネル（超過時は 429「動画の確認が続いています。10 分ほど待ってから再度お試しください」） |
| OAuth 開始・コールバック | 10 回/分/IP |
| 作成開始・失敗分再試行 | 5 回/時/チャンネル |
| 作成ステップ | 2 回/秒/セッション（ブラウザは 1 秒間隔） |
| 一時停止・続きから・残りを中止・作り直し（共通） | 30 回/分/セッション |
| 問い合わせ送信 | 3 回/10 分・10 回/日/IP、全体 50 件/日 |
| 全ページ共通 | 120 回/分/IP |
- レート制限キーは IP をハッシュ化して cache に置くだけ（TTL 付き）。DB のアプリテーブルには保存しない。
- 超過時は 429 と日本語メッセージ。提案保存（上の 2 行）の超過は、自サイトのフォームからなら入力を残したまま提案作成画面へ戻す。
- 提案保存の成功回数の制限は保存前に判定し、保存に成功したときだけ数える（検証エラー・ハニーポット・削除済み/非公開動画による失敗では減らない）。成功回数を数える制限は同時送信でわずかに超えうるが、POST 全体の 20 回/10 分で上限が決まる ［ユーザー決定: Y17］。チャンネル入力（チャンネル取得）の制限は変えない。
- 匿名の読取予算（`QUOTA_READ_BUDGET`）に対し、IP ごと・チャンネルごとの日次上限は設けない ［ユーザー決定: Y18］。上表の短時間の制限は維持し、読取予算の日次の使い切りが頻発するようなら見直す。
- 共用サーバーの前段がリバースプロキシの場合、`TrustProxies` の設定次第で全員同じ IP になる ［要確認: CORESERVER の構成］。Phase 0 で `REMOTE_ADDR` を確認する。
- （Phase 4 追記）レート制限のキーは `ClientKey`（app.key による IP の HMAC。IPv6 は /64 単位）。`TRUSTED_PROXIES`（空なら信頼しない）で前段のプロキシを信頼できる。値は Phase 4 で見直し、変更なし（D34）。
- （本番確認 2026-11-15、#7）本番では前段のプロキシなし。`REMOTE_ADDR` は利用者の IP そのもの（IPv4・IPv6 とも一致、`X-Forwarded-For` 等の転送ヘッダは付かない）。`TRUSTED_PROXIES` は空のままにする。

### 8.2 入力上限・検証
| 項目 | 上限・規則 |
|---|---|
| チャンネル入力 | 200 文字。ChannelInput で URL/ハンドル/UC ID のみ許可 |
| 動画 URL | 200 文字。VideoUrl で 11 文字 ID を抽出、形式 `[A-Za-z0-9_-]{11}` |
| 動画数 | 1〜`QuotaPolicy::maxItemsPerPlaylist()`（既定 99）、重複不可。提案保存・作成開始とも同じ |
| 対象チャンネル外の動画 | 許可（既定で含める）。サーバーが `is_outside_channel` を判定してバッジ表示 |
| 再生リスト名 | 1〜150 文字（YouTube の上限 ［要確認］）、前後空白除去、制御文字除去 |
| 説明 | ≤1,000 文字（YouTube 上限 5,000 バイトより余裕を取る ［要確認］） |
| コメント / ニックネーム | ≤500 / ≤30 文字 |
| `<` `>` | 再生リスト名・説明で YouTube が拒否する可能性 ［要確認］。Phase 3 で確かめ、拒否されるならフォームで弾く |
| 公開範囲 | `private` / `unlisted` / `public` のみ。必須で既定なし（D46。D10 の既定 `private` を改める）。未指定は「公開範囲を選んでください」、それ以外の値は「公開範囲の値が正しくありません」の 422 |
| 問い合わせ | 本文 1〜2,000 文字、返信用メール任意（RFC 形式・≤254 文字・改行不可）、種別は固定 3 値 |
| リクエストボディ | 64KB 上限 |
- 提案保存時、動画 ID はサーバー側で videos.list により実在確認し、クライアントから来たタイトル等は使わない（サーバー取得値で上書き）。

### 8.3 出力・XSS
- Blade の `{{ }}` のみ使用（`{!! !!}` 禁止）。JS 側は `textContent` でのみ挿入（`innerHTML` 禁止。Alpine は `x-text`）。
- 自由記述中の URL は自動リンクしない（スパム誘導対策）。
- サムネイルは `i.ytimg.com` の URL のみ許可（API 応答を検証）。

### 8.4 reCAPTCHA ［設計判断］: 初期は入れない
- 理由: レート制限＋全体上限＋読取予算・書込予約で被害の上限が決まっている。reCAPTCHA は訪問者情報を Google に送るためプライバシーポリシーの記載が増え、「個人情報を集めない」方針と相性が悪い。
- 代わりに提案保存フォームと問い合わせフォームにハニーポット項目を置く。
- 濫用を観測したら提案保存・問い合わせにだけ CAPTCHA を足せるよう、FormRequest の前段にミドルウェアの差し込み口を用意（`.env` の `CAPTCHA_ENABLED`）。

### 8.5 CSRF とセッション
- 全 POST は Laravel 標準の CSRF 検証（作成ステップの fetch も `X-CSRF-TOKEN` ヘッダ）。
- 状態変更は GET で行わない（ログアウトも POST）。

### 8.6 セキュリティヘッダ（SecurityHeaders ミドルウェア）
- `Content-Security-Policy`: `default-src 'self'; img-src 'self' https://i.ytimg.com https://yt3.ggpht.com; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'self'`（yt3 はチャンネルアイコン ［要確認: ドメイン］）。
- `Referrer-Policy: no-referrer`（**提案 URL のキーが YouTube 等へのリンク先に漏れない**ため。全ページ一律）。
- `X-Content-Type-Options: nosniff`, `Strict-Transport-Security: max-age=31536000`, `Permissions-Policy` で不要機能を無効化。
- 提案系ページ・問い合わせページは `X-Robots-Tag: noindex, nofollow` と `Cache-Control: no-store`。ホーム・静的ページのみインデックス可。リンクプレビュー（OGP・Twitter Card）は noindex のページにも出す（D49）。

### 8.7 問い合わせフォーム（S8） ［ユーザー決定: フォーム／詳細は設計判断］
- `GET /contact` で表示、`POST /contact` で送信。項目: 種別（一般／削除依頼／不具合）、本文、返信用メールアドレス（任意）、ハニーポット。
- 送信内容は `.env` の `CONTACT_TO_ADDRESS`（運営者アドレス）へメール送信するだけで、**DB に保存しない**（ログにも本文・アドレスを出さない）。
- メールはプレーンテキスト。件名は固定（`[List Maker] お問い合わせ（種別）`）で、利用者入力をヘッダに入れない（ヘッダインジェクション対策。Reply-To も設定しない）。**返信用メールアドレスは入力された場合のみ本文に含める**。IP・User-Agent は本文に含めない。
- 送信は `MAIL_*`（CORESERVER の SMTP）で同期送信。失敗時は「送信できませんでした。時間をおいて再度お試しください」を表示し、ログには失敗した事実と例外の種類のみ記録。
- ハニーポットに値があれば送信せず成功画面を表示（ボットに判別させない）。
- 送信回数の制限: 同一利用者（`ClientKey`）10 分 3 回・1 日 10 回、全体 1 日 50 件。数えるのは送信を試みた回（成功・失敗とも）。ハニーポットは送信者自身の枠だけを、枠が残っている間だけ使う（全体の 50 件には数えない。1 つのボットがフォームを 24 時間止められないように）。それでも 5 程度の利用者（IP／IPv6 の /64）が上限まで送れば全体の 50 件/日を使い切れる ［設計判断: 受け入れる。運営はログの「Contact sent」「Rate limit exceeded」の件数を見て、濫用なら CAPTCHA（8.4）や値の見直しで対応］。
- フォーム上に「入力内容は運営者へのメールとして送られ、本サービスのデータベースには保存されません。返信が必要な場合のみメールアドレスを入力してください」と表示。
- 削除依頼の場合は本文に提案 URL を書いてもらう（運営は 7 日以内に `app:delete-proposal` で削除）。
- （Phase 4 追記）検証エラー・送信失敗・制限超過はリダイレクトせずフォームを直接描画する（入力をセッションにも置かない）。制限は送信を試みた回（成功・失敗）とハニーポットだけ数える。SMTP のタイムアウトは 10 秒（D35）。

### 8.8 ログ
- 記録する: API エラー（メソッド、HTTP ステータス、reason）、クオータ超過・予約不足、レート制限超過の件数、作成の開始・完了（creation id、本数、予約量。開始はチャンネル ID の先頭 8 文字も）、運用者による予約の解放（creation id、件数）、問い合わせ送信の成否。
- 記録しない: アクセストークン、API キー、認可コード、公開キー（必要なら key_hash の先頭 8 文字）、IP（Web サーバーのアクセスログはホスティング側管理）、問い合わせの本文・メールアドレス。
- HTTP クライアントの例外をログに出す前に、URL のクエリと `Authorization` を除去するラッパーを通す。
- （Phase 4 追記）追加・整理したログ:
  - `Rate limit exceeded {limiter}`（info。制限の名前のみ、IP なし。`RateLimitLog`）
  - `Read budget exhausted`（warning。PT の日ごとに 1 回）、`Write quota insufficient {items, free_units}`（info）、`Creation paused {creation_id, reason}`（warning。`quota_exceeded`・`quota_budget` のとき）
  - `Contact sent`（info）、`Contact mail failed {exception}`（error。例外クラスのみ）、`Contact mail not configured`（error）
  - `Proposal deleted by operator {key_hash_prefix, creation}`（info）、`Proposal delete raced`（warning）
  - 未処理の `QueryException` は `Database error {sqlstate}`、HTTP クライアントの例外は `HTTP client error {exception, status}` だけを出す（メッセージのバインド値・URL を出さない）。別の例外に包まれていても `getPrevious()` をたどって同じ扱いにし、包んだ例外のクラスを `wrapped_in` に出す。
  - スタックトレースに引数の値を出さない（`zend.exception_ignore_args=1` を `bootstrap/app.php` で設定）。
  - 本番の `LOG_LEVEL` は `info`（上の記録の多くが info のため。10.2、D37）。

---

## 9. YouTube API Developer Policies／OAuth 審査対応

### 9.1 必要なページ・表示
| 要件 | 対応 |
|---|---|
| ホームページ（ログイン不要で閲覧可、機能説明） | S1。アプリ名、提案→作成の流れ、youtube スコープで何をするか（「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」にのみ使う）を明記。S4 のログイン欄も同じ内容（S4 では「ログインの流れと権限」の折りたたみの中。D48） |
| プライバシーポリシー | S7 `/privacy`。YouTube API Services を使う旨、[Google プライバシーポリシー](https://policies.google.com/privacy) へのリンク、取得するデータ（提案内容、YouTube の公開動画情報、ログイン中のチャンネル ID とアクセストークン、チャンネル主が読み込んだ自分の限定公開動画の一覧（セッションに 10 分のみ）、トークンの用途（動画 URL で追加する動画の確認を含む。確認結果は保存しない。D45））、保存場所と期間（4.3: 提案・取り込んだ動画情報は 30 日、トークンはセッションのみ）、Cookie（セッション Cookie と CSRF 対策の `XSRF-TOKEN` のみ。Phase 4 で訂正）、第三者提供なし、承認取り消し方法、**問い合わせフォームの扱い**（運営者へメール送信のみ・DB 保存なし・返信用アドレスは任意・メールボックスで対応後 90 日以内に削除）、削除依頼の方法（問い合わせフォーム、7 日以内に対応） |
| 利用規約 | S7 `/terms`。[YouTube 利用規約](https://www.youtube.com/t/terms) へのリンクと「本サービスの利用は YouTube 利用規約への同意を含む」旨、提案内容の責任、30 日削除、無保証 |
| 利用前の同意 | 提案保存ボタン・ログインボタンの近くに「利用規約・プライバシーポリシーに同意のうえ」の文言とリンク（Developer Policies III.A.2 ［事実: A.6］） |
| 書込操作の明示 | 作成ボタンに「あなたのチャンネルに再生リストを作成します」と明記し、押下で初めて書込（III.E.3 ［事実: A.6］） |
| 取り消し案内 | 6.6 |
| 出典表示 | YouTube 由来のデータ（一覧・プレビュー）の近くに「データ提供: YouTube」と元動画・チャンネルへのリンク |
| ブランド表記 | アプリ名は「**List Maker**」（Y10。YouTube を名称に含めない）。YouTube のロゴは使わない。説明文で「YouTube の再生リストを作成するツール」と記述するのは可 ［設計判断・審査で要確認］ |
- フッタ全ページ: プライバシー・規約・YouTube 利用規約・お問い合わせ（S8 フォームへのリンク）。

### 9.2 OAuth 審査
- **GCP プロジェクトは本ツール専用**。未審査の新規ユーザー 100 人上限はプロジェクトの生涯で数えられリセットできず ［事実: A.3］、審査・クオータもプロジェクト単位のため、既存サービスと共用すると互いに影響する。Developer Policies も 1 つの API クライアントにつき 1 つのプロジェクトを求めている ［事実: A.6］。
- 当面: 公開ステータス「本番」・未審査。未確認アプリ警告が出る（「詳細→移動」で続行可）、新規ユーザー累計 100 人上限 ［事実: A.3］。テストユーザー登録はしない。S4 のログインボタン近くに「確認されていないアプリの警告が表示されます」の案内と画像付き説明を置く。
- 審査時に必要: ログイン不要のホームページ、同一ドメインのプライバシーポリシー（同意画面からリンク）、Search Console でのドメイン所有確認、英語のデモ動画（同意画面に正しいアプリ名、アドレスバーに OAuth クライアント ID が見える状態でログイン〜作成の流れ）、スコープごとの用途説明 ［事実: A.4］。審査は最大 10 日程度 ［事実: A.4］。
- ページは日本語のみとし、英語はデモ動画だけにする ［ユーザー決定: Y13］。審査で英語ページが求められて却下された場合に英語ページを追加する。
- （Phase 4 追記）ログインの流れの案内（S1・S4 の `partials/unverified-app`）は文章のみとし、画像は付けない（D38）。警告画面のリンク名は「（アプリ名またはドメイン）（安全ではないページ）に移動」のように幅を持たせ、権限のチェックボックスが出た場合は YouTube の権限にチェックを入れるよう案内する。審査を申請したら「審査を申請する前のため」の文言を直す（README の公開前の人手作業）。

### 9.3 30 日ルール
- API 由来データ（タイトル、サムネ URL、再生時間、チャンネル名）は、取得時刻から 30 日以内に削除される設計 ［ユーザー決定］:
  - 取り込んだ動画一覧（channel_caches ＋ channel_cache_videos）: **チャンネル単位で、全件取り込みの完了時点（cached_since）から 30 日でまとめて削除**（Y9）。6 時間以内は使い回し、それ以降に開かれたら新しい動画だけを差分で追加する（既存行は上書きせず、期限も延ばさない。定期取り直しはしない）。期限切れで削除された後に開かれたら全件を取り込み直し、そこから新たに 30 日（5.3）。
  - 30 日ルールとの整合: 差分で後から足した行は取得が `cached_since` より後なので、`cached_since + 30 日` で消えるまでの保持は 30 日より短い。全件取り込み時の行とチャンネル情報（取り込みのたびに上書き）もちょうど 30 日以内に消える。したがって**どの行も保持は取得から 30 日以下**で、全データが 30 日以内という条件を満たす。
  - 期限切れの記録（channel_cache_expirations）はチャンネル ID と削除日時のみで、タイトル・サムネ等の API データを持たない。この記録も 30 日で削除する。
  - 提案: 保存時に取り直したスナップショットを持ち、提案作成から 30 日で削除。作成スナップショットは作成前に取り直した値で、提案と同時に削除（作成は提案より後なので取得から 30 日を超えない）。
  - （Phase 4 最終レビュー）作成スナップショットのうちチャンネル主の承認で得たデータ（項目と、公開以外の再生リスト ID）は、さらに短く、チャンネル主の最後の利用・ログアウトから 7 日で削除する（4.3、D42）。
- サムネイル画像はダウンロード保存せず YouTube の URL を表示するだけ。
- 作成された再生リスト自体はチャンネル主の YouTube 上の資産であり、本サービスは保持しない（ID は公開なら提案とともに 30 日、それ以外はチャンネル主の最後の利用から 7 日で削除）。

---

## 10. 運用

### 10.1 デプロイ（channel-calendar 方式踏襲）
- `deploy.sh` を channel-calendar から流用: `deploy.env`（git 管理外）読込 → origin/main 一致チェック（`ALLOW_NON_MAIN_DEPLOY=1` で迂回）→ `php artisan test` → `npm run build` → `composer install --no-dev -o` → 環境依存キャッシュ・`public/hot` 削除 → rsync（`.exclude-list`）→ サーバーで `config/cache/route/view:clear` と `migrate --force`（PHP は `/usr/local/php84/bin/php`）→ ローカルの dev 依存復元。失敗時の trap による復元も踏襲 ［事実: channel-calendar/deploy.sh］。
- `.exclude-list` も同等（`.env`, `deploy.env`, `docs`, `tests`, `node_modules`, `storage/logs`, `storage/framework` 等）。
- サーバーの `.env` は手動管理。初回は README 手順で作成。`list-maker.alpacasandbag.jp` の SSL を管理画面で発行。

### 10.2 .env 項目
```
APP_NAME="List Maker", APP_ENV=production, APP_KEY, APP_DEBUG=false, APP_URL=https://list-maker.alpacasandbag.jp
LOG_CHANNEL=daily, LOG_LEVEL=info, LOG_DAILY_DAYS=14
DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_SOCKET, DB_DATABASE, DB_USERNAME, DB_PASSWORD
SESSION_DRIVER=database, SESSION_ENCRYPT=true, SESSION_LIFETIME=120, SESSION_SECURE_COOKIE=true, SESSION_SAME_SITE=lax
CACHE_STORE=database, QUEUE_CONNECTION=sync
YOUTUBE_API_KEY=
GOOGLE_CLIENT_ID=, GOOGLE_CLIENT_SECRET=, GOOGLE_REDIRECT_URI=${APP_URL}/auth/google/callback
QUOTA_WRITE_LIMIT=5000, QUOTA_READ_BUDGET=2000, QUOTA_GOOGLE_DAILY=10000
CHANNEL_FETCH_MAX_VIDEOS=1000, CHANNEL_CACHE_REUSE_MINUTES=360, CHANNEL_SYNC_KNOWN_RUN=3
DATA_RETENTION_DAYS=30
CAPTCHA_ENABLED=false
MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_SCHEME, MAIL_FROM_ADDRESS, MAIL_FROM_NAME
CONTACT_TO_ADDRESS=
TRUSTED_PROXIES=
```
- （Phase 4 最終レビュー）`OPERATOR_CONTACT`: 問い合わせフォームが使えないときの削除依頼の窓口として /contact・/privacy に表示する公開の連絡先。サーバーの `.env` にだけ書き、リポジトリには個人の連絡先を書かない（空なら「運営者の連絡先（README で設定）」と表示）。
- （Phase 4 追記）`LOG_LEVEL` は `warning` から `info` に変更（8.8 の記録項目が info のため。D37）。`TRUSTED_PROXIES` は空なら信頼しない（8.1・D34）。`CONTACT_TO_ADDRESS` は `filled()` で判定し、空文字は未設定扱い。
- 動画数上限（`PROPOSAL_MAX_ITEMS`）は**置かない**。`QUOTA_WRITE_LIMIT` から導出（5.2）。
- `MAIL_SCHEME`（旧 `MAIL_ENCRYPTION`）の名称と CORESERVER の SMTP ホスト・ポート・認証方式 ［要確認: Phase 0］。送信元アドレスはサブドメインと同じドメインにし、SPF/DKIM で迷惑メール判定を避ける ［要確認］。

### 10.3 cron
```
* * * * * cd <REMOTE_PATH> && /usr/local/php84/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
```
スケジュール: `app:prune-expired`（日次 04:00 JST: 提案一式・7 日使われていない作成のチャンネル主のデータ・期限切れチャンネルのキャッシュ（チャンネル行と全動画行をまとめて削除し `channel_cache_expirations` に記録）・30 日経過の期限切れ記録・quota_usage・期限切れ cache/sessions）、`app:prune-sessions`（毎時: 期限切れのセッション行）。作成処理・予約の失効・動画データの取り直しは cron に依存しない（失効は日付比較で判定）。

### 10.4 Google Cloud セットアップ（README に書く要点）
1. **本ツール専用の**プロジェクトを新規作成（既存サービスのプロジェクトは使わない）、YouTube Data API v3 を有効化。
2. API キー作成 → API 制限「YouTube Data API v3 のみ」。アプリケーション制限は、サーバーの送信元 IP が固定と確認できれば IP 制限、できなければ「なし」（ycs の手順と同じ） ［要確認: CORESERVER の送信元 IP］。
3. OAuth 同意画面: 外部、アプリ名「List Maker」（Y10・YouTube を含めない）、サポートメール、ホームページ・プライバシーポリシー・利用規約 URL（`https://list-maker.alpacasandbag.jp/...`）、承認済みドメイン `alpacasandbag.jp`、スコープに `.../auth/youtube` を追加。公開ステータスを「本番環境」にする（未審査のまま）。
4. OAuth クライアント ID（ウェブアプリケーション）: 承認済みリダイレクト URI に `https://list-maker.alpacasandbag.jp/auth/google/callback`、ローカル用に `http://localhost:8000/auth/google/callback`。
5. Search Console でドメイン所有確認（審査前）。
6. クオータ使用量の確認場所（API とサービス → 割り当て）とリセット時刻（PT 0 時＝日本時間 16 時〈夏時間〉／17 時〈標準時間〉）。アプリの台帳（quota_usage）と見比べ、見込みのずれを確認する。

### 10.5 テスト方針
- PHPUnit の Feature テスト中心。DB は SQLite in-memory（MySQL 固有の `ON DUPLICATE KEY` は Laravel の `upsert` で書き、両方で動かす）。`SELECT … FOR UPDATE` は SQLite では効かないため、同時予約・同時ステップのロックは MySQL 用テストグループ（`mysql`）で確認し、CI の MySQL 8.4 で毎回実行する（D30）。
- YouTube / OAuth / メールは `Http::fake()` / `Mail::fake()` で固定。実 API はテストで呼ばない（`Http::preventStrayRequests()`）。時刻は `travelTo` で PT の日付境界・夏時間切替をまたぐ。
- 必須ケース:
  - QuotaPolicy: 書込上限 5,000 → 99 本、1,000 → 19 本、100 → 1 本、99 以下・読取＋書込が Google 枠超過 → 起動時エラー。残り本数の換算（空き 149 → 1 本、99 → 0 本）。
  - QuotaClock: リセット時刻が標準時間で 17 時、夏時間で 16 時（JST）になる。
  - チャンネル一覧（差分取り込み。`Http::fake()` でページ列を用意し、送られたリクエスト数を検査）:
    - 入力の解析。6 時間以内は API を 1 回も呼ばない。
    - 初回: 全件取り込みで上限（20 ページ＝1,000 本）まで取り込み、続きがあれば `truncated=true`、末尾到達なら `false`。`cached_since=now`、`expires_at=now + 30 日`。
    - 差分: 新規 3 本＋既知が続く 1 ページ目で終了し、新規 3 行だけ追加・既知行の `title`・`fetched_at` が変わらない。新規 60 本なら 2 ページ目まで読む。`cached_since`・`expires_at` は変わらない。
    - 打ち切り判定: 先頭に既知 1〜2 件が混ざっても止まらず、その下の新規を拾う。既知 3 件連続で止まる。動画行が 2 件しかないチャンネルでも正しく止まる。
    - チャンネル単位の削除: `cached_since` から 30 日経過で、差分で後から足した行（取得から 30 日未満）も含めチャンネル行と全動画行が消え、`channel_cache_expirations` に ID と削除日時だけが記録される（API 由来の列が無いこと）。期限前日には何も消えない。日次バッチ前でも期限切れのキャッシュは表示・差分に使われず、取り込み時にその場で削除・記録される。
    - 期限切れ後の取り込み: 全件取り込みモードになり、新しい `cached_since` から 30 日の期限が始まる。S2 に「前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています」が出て、完了後に記録が消える。初回（記録なし）ではこの文が出ない。期限内のキャッシュ表示で「この一覧は○月○日に取り込み、○月○日に削除されます」が出る。
    - 期限切れ記録は 30 日経過で日次バッチにより削除される。
    - 非公開化: ページ内で `privacyStatus` が public 以外になった既存行が消える。
    - 上限: 行数が 1,000 を超えたら `published_at` の古い順に削除。
    - 途中終了: 読取予算切れ・タイムアウトで途中終了したら何も書き込まれない（差分モードは既存キャッシュがそのまま、全件取り込みモードはキャッシュ無しのまま）。`last_synced_at`・`cached_since` は据え置き。
    - 既知の制限の再現: 打ち切り位置より下に新規 ID がある場合は差分では拾わず、期限切れ後の全件取り込みで拾う（仕様どおりであることをテストで固定）。
    - 一覧に残った削除済み動画を含む提案保存が、videos.list 再取得で拒否される。
    - 読取予算超過で取得停止（期限内のキャッシュを最終取り込み日時・期限付きで表示）、キャッシュ無しなら上限メッセージ（期限切れ記録があれば削除済みの旨も）。定期取り直しジョブが存在しない。
  - 提案保存: 上限（99 本）超過・重複・不正 ID・削除済み動画・対象チャンネル外の動画が**既定で含まれバッジが付く**・ハニーポット・レート制限。
  - 表示: S2/S4/S5 に最大本数と本日の残りが出る、残り 0 でリセット時刻が出る、S5 で n > N なら作成ボタン無効。
  - プレビュー: 存在しない／期限切れキーは同じ 404、エスケープ、noindex・Referrer-Policy ヘッダ。作成済みリンクは public のみ匿名に表示、unlisted/private は該当チャンネル主のセッションのみ表示（HTML に ID が出ないこと）。
  - OAuth: state 不一致、access_denied、スコープ不足、チャンネル不一致、トークンがセッション以外に書かれないこと（DB 全テーブルにトークン文字列が無いことを検査）。加えて **6.7 チェックリストの「テスト」欄にある項目すべて**（state の一回限り・期限、PKCE のテストベクトル、redirect_uri の固定と一致、コード交換の再試行なし、トークン応答の必須項目検証、error_description を出さない、セッション ID の再生成、リダイレクト非追従、ログに秘密情報が出ない 等）。
- **実装レビューの観点（OAuth/HTTP）**: Phase 2 のコードレビューでは、レビュー担当が 6.7 の全項目について Laravel Socialite・league/oauth2-client・google/apiclient（google/auth）の該当処理をソースで確認し、本実装が「満たす／対象外（理由）」のどちらかであることを記録する。満たさない項目がある場合は Phase 2 を完了としない。コードの模倣ではなく観点の照合であることに注意する。
  - 予約: 枠不足で開始しない（creation が作られない）、予約→各ステップで確定→完了で解放、残りを中止で解放、playlists.insert 恒久エラーで解放、一時停止中は保持、日付をまたぐと失効し再開時に再予約（不足なら再開不可）、一時エラー再送で追加予約・追加不可で `paused(quota_budget)`、失敗分再試行の予約、他の作成の予約が空きから差し引かれる。
  - 作成: 二重開始、同時ステップ（ロック）、5xx 再試行、quotaExceeded で停止、401 で停止→再ログイン→再開、in_flight 曖昧時の照合、playlists.insert 曖昧時の照合、失敗分再試行で `snippet.position` が提案順の位置になる、完了。
  - 問い合わせ: 正常送信（宛先・本文に返信用アドレスがある／無い）、DB にレコードが増えない、件名・ヘッダに利用者入力が入らない、改行入りアドレスの拒否、ハニーポット、レート制限、送信失敗時のメッセージ、ログに本文が出ない。
  - 削除: 30 日経過分の cascade 削除、`app:delete-proposal`。
- JS: 選択集合（99 本上限）・絞り込み・並べ替え・ランナーの待機計算・残り表示の文言組み立てを純関数にし `node --test`（上限値・残り値はサーバーから受け取り、JS で式を持たない）。
- 実 API の手動確認: Phase 3 で 3 本程度の非公開再生リストを実際に作る（元の指示書 6-6）。position 指定の挙動と失敗分再試行時の並びも確認。

---

## 11. 実装フェーズと完了条件

| Phase | 内容 | 完了条件 |
|---|---|---|
| 0 基盤 | 専用 GCP プロジェクト作成、Laravel 13 プロジェクト、Tailwind/Vite/Alpine(CSP)、SecurityHeaders、ホーム・静的ページの骨組み、deploy.sh/.exclude-list/deploy.env.example、README 初版、`app:prune-expired` の空実装と cron、SMTP の疎通確認 | `https://list-maker.alpacasandbag.jp` にデプロイでき、`php artisan test` が通る。ヘッダがテストで確認できる。プロキシ配下の IP 取得を確認済み。サーバーから運営者アドレスへテストメールが届く |
| 1 提案者 | ChannelInput/VideoUrl、YouTubeReader、QuotaPolicy/QuotaClock、QuotaLedger（read 記録・予算）、channel_caches／channel_cache_videos（6 時間再利用・差分取り込み・チャンネル単位の 30 日削除と期限切れ記録（channel_cache_expirations）・期限切れ後の全件取り込み・S2 の期限表示）、提案作成 UI（絞り込み・選択保持・99 本上限・並べ替え・URL 追加とバッジ）、提案保存、完了画面、プレビュー（最大本数・本日の残り表示）、レート制限、削除バッチ | 実在チャンネルで提案を作り、別ブラウザで URL からプレビューできる。上限・不正入力・対象チャンネル外の既定包含・レート制限・404・30 日削除・差分取り込み（10.5 の各ケース）・QuotaPolicy の Feature/単体テストが通る。実在チャンネルで 6 時間後の 2 回目の取り込み（新規 50 本未満）が 2 ユニット（channels.list 1＋1 ページ）で済むことを台帳で確認 |
| 2 チャンネル主ログイン | GoogleOAuth（state/PKCE）、セッション保持、チャンネル照合、編集画面（書込なし。必要な枠・本日の残り・不足時の作成ボタン無効とリセット時刻）、自分の限定公開動画の読み込み（Y11）、チャンネル主による提案削除（Y14）、ログアウト＋取り消し | 自分のチャンネルでログイン→編集画面、別チャンネルで不一致画面、ログアウトで Google 側の承認が消えることを確認。トークンが DB に残らないテストが通る。残り表示のテストが通る。**6.7 チェックリストの全項目がレビューで「満たす／対象外（理由）」と記録され、テスト欄の自動テストが通る** |
| 3 作成 | playlist_creations、quota_reservations と予約→確定→解放、CreationRunner（position 指定）、ステップ API、進捗画面、再試行・一時停止・再開・残りを中止、二重作成防止、失敗分再試行、プレビューの作成済みリンク表示制御 | 実 API で 3 本／20 本の作成が成功し、失敗分再試行で並び順が保たれる。途中でタブを閉じて再開できる。枠不足で開始しないこと、日付またぎで再予約されることをテストで確認。同時予約が上限を超えないことを MySQL で確認。作成系・予約系 Feature テストが全て通る。409/`<>`/position と手動並び替え等の［要確認］事項の結果をこの設計書に追記 |
| 4 公開準備 | 問い合わせフォーム（S8）、`app:delete-proposal`、プライバシーポリシー・規約の本文（問い合わせの扱いを含む）、利用前の同意文、出典表示、未確認アプリ警告の案内、ログ整理、レート制限値の見直し、README 完成、OAuth 同意画面を本番公開 | 9.1 のチェックリストをすべて満たす。フォームから送った問い合わせが運営者に届き DB に残らない。第三者（自分の別アカウント）が警告画面を越えて作成まで完走できる |
| 5 審査 | Search Console 確認（アプリ名は List Maker で確定済み。Y10）、英語デモ動画、審査申請、指摘対応 | 審査通過（未確認アプリ警告が消える） |
| 任意 | watch_videos プレビューリンク（≤50 本、ID 検証済みのみ）、CAPTCHA 有効化、英語ページ（審査で求められた場合。Y13） | 必要に応じて |

---

## 12. 決定事項・設計判断・未決事項

### 12.1 ユーザー決定（本改訂で反映）
| # | 決定 |
|---|---|
| Y1 | 書込の 1 日上限はアプリ側で 5,000 ユニット（`QUOTA_WRITE_LIMIT`、.env で変更可）。読取予算 2,000 は別枠。残りは「再生リスト 1 つ＋動画あと N 本」の形で S2/S4/S5（と S6）に表示。足りなければ作成を開始させずリセット時刻を表示 |
| Y2 | 1 提案あたりの動画数上限は `floor((書込上限 − 50) / 50)`（既定 99 本）で導出し、画面に常時表示。独立した設定値は持たない |
| Y3 | サブドメインは `list-maker.alpacasandbag.jp` |
| Y4 | 問い合わせは問い合わせフォーム |
| Y5 | 取り込んだ動画一覧データは取得から 30 日以内に削除（第 3 改訂で**チャンネル単位の期限**に変更。詳細は Y9）。使い回しは 6 時間（その間は API を呼ばない）。定期取り直しなし。読取予算切れの日は 30 日以内のキャッシュを取得日時付きで表示（了承済み） |
| Y6 | 対象チャンネル外の動画は弾かず既定で含め、バッジ表示のみ。チャンネル主の出演検証はしない |
| Y7 | チャンネル動画一覧は**差分取り込み**: uploads を playlistItems.list で新しい順に 50 件ずつ読み、キャッシュ済み（30 日以内）の動画 ID に行き当たったページで打ち切って新規分だけ追加。50 件以上新しければ次ページへ。取り込み済みの内容は再取得しない。期限切れで消えた後は全件取り込みで再取得される（Y9）。並びが新しい順でない動画の取りこぼし（5.3 既知の制限）は受け入れる（期限切れ後の全件取り込みで解消） |
| Y8 | OAuth/HTTP は外部ライブラリを使わず直接実装する（D4 を維持）。代わりに Socialite・league/oauth2-client・google/apiclient（google/auth）の安全対策を確認項目として列挙し（6.7）、実装レビューの観点に含める |
| Y9 | 一覧キャッシュの 30 日期限は**チャンネル単位**で管理する。全件取り込み（初回または期限切れ後）の完了時点を基準に 30 日でそのチャンネルのキャッシュ（channel_caches と channel_cache_videos の全行。差分で後から足した行も含む）をまとめて削除。期限内は差分取り込み、期限切れ後の初回は全件取り込み（そこから新たに 30 日）。期限切れで削除したチャンネルは ID と削除日時だけを記録し（API データは持たない）、次回取り込み時に「前回の一覧は 30 日の保存期限で削除されたため、全件を取り込み直しています」と表示。期限内は「この一覧は○月○日に取り込み、○月○日に削除されます」と表示。行単位削除と needs_rescan による全走査は廃止（第 3 改訂） |
| Y10 | （U1）アプリ名は「List Maker」で確定（仮称を外す） |
| Y11 | （U2、ユーザー決定 #9）チャンネル主はログイン後の S5 で**自分の限定公開動画**を追加できる。一覧はボタン操作でだけ読み込む専用エンドポイント（チャンネル主本人のみ、同一オリジンの fetch のみ、チャンネルごとに 5 回/10 分、owner_read を使う）から取得し、そのチャンネル主のセッションに 10 分だけ置く。共有キャッシュ（channel_caches 等）には決して置かない。非公開動画は対象外 |
| Y12 | （U3、ユーザー決定 #10）動画数上限は当面 99 本のまま。クオータ増加申請は利用状況を見て決める |
| Y13 | （U4、ユーザー決定 #11）ページは日本語のみ。英語は審査用のデモ動画だけ。審査で却下されたら英語ページを追加する |
| Y14 | （U5、ユーザー決定 #12）チャンネル主は自分のチャンネル宛ての提案を 30 日の期限前に削除できる。S5 の「この提案を削除」→ 確認ページ → CSRF 付き POST → 提案と明細を 1 トランザクションで削除 →「削除しました」ページ（そのタブの下書きを消す）。削除後のキーは存在しないキーと同じ 404。運営による削除（`app:delete-proposal`）は従来どおり |
| Y15 | （U11、ユーザー決定 #15）放置された作成の予約は当日中保持する（無操作での解放はしない） |
| Y16 | （U12）差分取り込みの取りこぼしは、チャンネル単位の 30 日期限（Y9）後の全件取り込みで解消されるため、追加の対策（`CHANNEL_SYNC_KNOWN_RUN` の増加や定期的な全件読み直し）はしない |
| Y17 | （ユーザー決定 #19）提案保存の 5 回/10 分・30 回/日/IP・全体 500 件/日は**保存に成功した回だけ**数える（検証エラー・ハニーポット・削除済み/非公開動画による失敗では減らない）。別に提案保存の POST 全体を 20 回/10 分/IP に制限する。チャンネル入力フォームの制限は変えない |
| Y18 | （ユーザー決定 #20）匿名の読取予算に IP ごと・チャンネルごとの日次上限は設けない。短時間の制限（8.1）は維持し、日次の使い切りが頻発するようなら見直す |
| Y19 | （U14）作成中（`completed` 以外）の提案は削除できない。S6 で「残りを中止」してから削除する。作成完了後は提案と作成の記録（作成・項目・予約）を一緒に削除し、YouTube の再生リストは残る |

### 12.2 設計判断（要件に明記がなく監督者・設計者が決めたもの）
| # | 判断 | 理由 |
|---|---|---|
| D1 | 提案は不変。チャンネル主の編集結果は作成スナップショット（playlist_creations/items）に別保持。作成前の編集はブラウザのみ | 提案者の案と実作成内容を混同しない。再開処理が単純 |
| D2 | チャンネル不一致は作成させない（確認ダイアログでの続行も不可）。select_account で再ログインを案内 | 誤チャンネル作成の防止 |
| D3 | 公開キーは 32 バイト乱数の base64url、DB には SHA-256 のみ保存 | DB 漏えい時に提案 URL を復元させない |
| D4 | OAuth・YouTube API はライブラリを使わず Laravel HTTP クライアントで実装。PKCE 併用（Y8 でユーザーも維持を決定） | 依存が軽い（vendor を rsync する運用）、本人情報スコープ不要、テスト容易 |
| D5 | セッションは database ドライバ＋暗号化。ログアウト時に Google の revoke まで行う | トークンを平文で永続化しない。ログイン情報を残さない |
| D6 | 作成はブラウザ駆動の 1 リクエスト 1 書込。DB 行ロック＋一意制約＋曖昧結果の照合で二重作成防止 | キュー常駐不可・実行時間制限。トークンをセッション外に置けない |
| D7 | チャンネル取得上限 1,000 本（保持行数と 1 回の走査ページ数＝20 の上限）、一覧の使い回し 6 時間。読取予算切れの日は 30 日以内のキャッシュを取得日時付きで表示 | 読取コストの上限化。保持 30 日（Y5）を無駄にしない |
| D8 | 台帳を read（匿名・予算 2,000）／owner_read（記録のみ）／write（上限 5,000・予約制）に分ける。上限の合計が Google 枠を超えないことを起動時に検証 | 匿名利用者に書込枠を食わせない。チャンネル主の作成が匿名の読取切れで止まらない |
| D9 | reCAPTCHA は初期不採用（ハニーポット＋レート制限＋全体上限）。後付け可能にする | 個人情報を集めない方針、被害上限が既に決まっている |
| D10 | 提案保存時・作成前に videos.list で取り直したデータを使う。公開範囲の既定は非公開（D46 で既定なし・必須選択に改めた） | 30 日ルールを満たす。クライアント入力を信用しない。誤公開の防止 |
| D11 | Referrer-Policy: no-referrer、提案ページ noindex | 共有 URL のキー漏えい・SEO スパム防止 |
| D12 | 管理画面は作らない（削除依頼は artisan コマンド）、API キーはヘッダ送信 | 攻撃面の縮小、ログへのキー漏えい防止 |
| D13 | 書込枠は作成開始時に `50 + 50×本数` を予約し、確保できなければ開始しない。予約→確定（送信ごと）→解放（完了・中止・恒久失敗時の未使用分）の 3 段階。一時停止中は保持、PT の日付をまたぐと失効し再開時に再予約。再送・失敗分再試行は追加予約 | 枠切れで中途半端な再生リストが残るのを防ぐ。再ログイン中に枠を奪われない（監督者判断） |
| D14 | playlistItems.insert で `snippet.position` を指定（前にある done 件数）。追加ユニットなし | 失敗分の再試行でも提案の並び順を保つ（監督者判断） |
| D15 | ログイン不要のプレビューでは作成済み再生リストのリンクを public のみ表示。unlisted/private はログイン中の該当チャンネル主にのみ表示 | 限定公開・非公開の ID を第三者に渡さない（監督者判断） |
| D16 | GCP プロジェクトは本ツール専用。既存サービスと共用しない | 審査・未審査 100 人上限・クオータがプロジェクト単位（監督者判断） |
| D17 | アプリ名は「List Maker」（Y10 で確定）。YouTube を名称に含めない | ブランド誤認の回避（監督者判断） |
| D18 | 問い合わせはメール送信のみで DB 非保存。返信用アドレスは任意で本文にのみ記載、件名固定・Reply-To 無し。レート制限・ハニーポット・文字数上限。CORESERVER の SMTP。プライバシーポリシーに記載。メールボックスでは対応後 90 日以内に削除 | 個人情報を溜めない。ヘッダインジェクション防止（監督者判断。90 日は設計者判断） |
| D19 | 予約を確保できないときは `paused(quota_budget)` として Google 側の `quota_exceeded` と区別。失敗した書込も 50 消費として計上 | 原因に応じた案内。見込みを安全側に |
| D20 | 差分取り込みの打ち切りは「1 ページ内で既知 ID が 3 件連続（`CHANNEL_SYNC_KNOWN_RUN`）」で判定し、そのページの残りの新規は追加してから止める | 先頭付近の軽い並びの揺らぎ（プレミア公開等）での取りこぼしを減らす。追加コストは通常 0〜1 ページ |
| D21 | 動画行を `channel_cache_videos` に 1 動画 1 行で持ち（`fetched_at` は記録用）、削除判定はチャンネル単位の `expires_at`（Y9）。取り込みは全ページ読み終えてから 1 トランザクションで書き、途中終了時は何も書かない。期限切れ記録（`channel_cache_expirations`）は 30 日で削除し、期限切れ後の全件取り込み完了時にも削除。期限表示・期限切れ案内の文言は 5.3 | 差分モードは先頭で止まるため、途中で書いて止まると抜けが期限まで残る。破棄すれば抜けは生じない。記録は案内表示に必要な最小限（ID と日時）に留め、それ自体も長く持たない |
| D22 | YouTube 側で削除・非公開化された動画が一覧に残りうる点は、提案保存時・作成前の videos.list 再取得で弾くことで整合させる。ページ内で非公開と分かった行は即削除。期限切れ後の全件取り込みで一覧からも消える | 差分モードでは削除を検出できない。保存・作成の時点で必ず検出できれば実害が無い |
| D23 | 6.7 チェックリストに state の有効期限 10 分、トークン交換の自動再試行なし、トークン/revoke エンドポイントのリダイレクト非追従、`expires_in` の安全余裕 60 秒を含める | 参照ライブラリの観点に加え、本設計の条件（一回限りのコード・秘密情報の転送防止）から補強 |
| D24 | （Phase 3）playlists.insert の 400（名前・説明の不正）は作成の記録（予約を含む）を削除して編集画面へ戻す。その他の恒久エラーは `completed`＋全項目 `skipped` | 直せる入力なら作り直せるようにする。一意制約で再作成が塞がれない |
| D25 | （Phase 3）行ロックは状態の決定と記録だけに使い、YouTube 呼び出し中は持たない。同時実行は 60 秒のリース（`leased_at`／`playlist_leased_at`）で 1 つに絞る。`playlist_leased_at`・`playlist_attempts` を追加 | ロックを持ったまま最大 15 秒の外部呼び出しをしない |
| D26 | （Phase 3）書込の 5xx・応答なし・ID の無い 2xx は結果不明として、再送の前に照合（playlists.list／playlistItems.list）する。照合は最大 20 ページ読み、曖昧（候補が複数等）なら `needs_check` | 二重追加の防止。費用は 5xx 1 回あたり読取 1 ユニット程度 |
| D27 | （Phase 3）再生リスト単位のエラー（5.7 の表）は項目を `failed` にせず（`pending` に戻して）作成を `needs_check` にする（`playlistContainsMaximumNumberOfVideos`・`manualSortRequired` を含む） | 以降の全項目が同じ理由で失敗するため |
| D28 | （Phase 3）アプリのセッション期限切れでは作成の状態を変えない（Google の 401 だけ `paused(auth_expired)`）。ステップの自動開始は「作成」直後だけ | 未認証の要求で状態を書き換えない。開き直しただけで書込を始めない |
| D29 | （Phase 3）Google の `quotaExceeded` で止めた PT 日付は専用カラム `paused_day_pt` に記録し、`updated_at` から導かない | 後から別の書込で `updated_at` が動いても再開判定が狂わない |
| D30 | （Phase 3）MySQL 固有のロック・同時予約のテストは `mysql` グループとして CI の MySQL 8.4（空の root パスワード）で毎回実行する。DB 名が `_test` で終わらない MySQL ではテストを実行せずスキップする（migrate:fresh・truncate で本番等を消さないため） | SQLite では `FOR UPDATE` が効かないため（10.5） |
| D31 | （Phase 3 最終レビュー）ロック順は常に 作成行 → 当日の書込行 → 項目行（中止・再生リスト恒久エラーの完了・期限切れ削除も同じ）。`playlist_creation_items` の status 単独インデックスは置かない | InnoDB の範囲更新で他の作成の行までロックしない・デッドロック回避 |
| D32 | （Phase 3 最終レビュー）照合の読取（playlists.list／playlistItems.list）が Google の `quotaExceeded` なら `paused(quota_exceeded)`、401 なら `paused(auth_expired)`、一時エラーはバックオフで再読取、それ以外の失敗が 5 回続いたら `needs_check`（`reconcile_failures`、読取に成功したら 0 に戻す）。`paused_day_pt` の無い `paused(quota_exceeded)` は再開できる。1 ステップの途中で PT の日付が変わっても、送信直前の確定で当日分を再予約する | 照合で止まり続けない・誤った一時停止をしない |
| D33 | （Phase 3 最終レビュー）作成開始は書込行のロック内で提案の存在を確かめ、削除済みなら 404（何も作らない）。S6 の開き直しでは「この画面を開いている間だけ進みます」と案内し、「残りを中止」は 2 回押しで確定。作成されなかった（または不明の）提案は「この提案を削除」してから作り直すよう S4・S6 で案内する。S4 の状態は 未作成／作成中／作成済み／再生リストは作成されませんでした／再生リストが作成されたか確認できませんでした の 5 つ | 競合時の 500 防止と、行き止まりをなくす |
| D34 | （Phase 4）レート制限のキーは `ClientKey`（app.key による IP の HMAC、IPv6 は /64）に統一し、`ProposalStoreLimits`・`ChannelFetchThrottle`・`ContactLimits` も同じキーを使う。`TRUSTED_PROXIES` は設定の差し込み口だけ用意し、空なら信頼しない（`*`・不正な値は警告ログ。最終レビューで Symfony のキーワード `REMOTE_ADDR`（全送信元を信頼）・`PRIVATE_SUBNETS`／`private_ranges`（全プライベート帯を信頼）も警告。信頼する転送ヘッダは For と Proto のみ） | 生の IP を cache に置かない。IPv6 のアドレス替えで制限を逃れさせない。プロキシ構成（#7）が未確認のため既定は安全側 |
| D35 | （Phase 4）問い合わせの入力はセッションにも置かない（失敗時はリダイレクトせずフォームを直接描画）。制限は送信を試みた回（成功・失敗）とハニーポットだけ数え、ハニーポットは全体の 50 件に数えない | 本文・アドレスをサーバーに残さない。失敗の連打で SMTP を叩かせない。ボットに全体枠を止めさせない |
| D36 | （Phase 4）運営の削除（`app:delete-proposal`）は作成中でも提案と作成記録を削除し、予約を解放する（チャンネル主の削除は作成中は不可のまま。Y19） | 削除依頼は 7 日以内に確実に応じる必要がある。作成途中のブラウザは次のステップが 404 で止まる |
| D37 | （Phase 4）本番の `LOG_LEVEL` は `info` | 8.8 で記録する作成の開始・完了、制限超過、問い合わせ送信などが info のため（warning では残らない） |
| D38 | （Phase 4）未確認アプリの案内は文章のみ（画像なし）。CAPTCHA の差し込み口（8.4）は Phase 4 では作らない。#18 の GitHub Actions の SHA 固定と SecurityHeaders の no-store 上書きは対象外（デプロイ・秘密を使うジョブが無い／キャッシュを使う画面が無い） | 必要になった時点で足せる。現状では効果が無い |
| D39 | （Phase 4）Laravel 標準の `XSRF-TOKEN` Cookie は残し、プライバシーポリシーにセッション Cookie と併せて記載する。セッションは「120 分操作が無いと使えなくなり、暗号化された行はセッション GC／日次の削除で消える」と書く（最終レビューで毎時の削除に変更。D44） | 標準の CSRF 対策で害が無い。実際の挙動どおりに書く |
| D40 | （Phase 4）ホーム・S4 のスコープの用途の文言は「チャンネルの確認・自分の限定公開動画の表示・再生リストの作成と動画の追加」のまま（作成結果の確認の読み取りは作成の一部）とし、プライバシーポリシーで確認のための読み取りを開示する。Google の画面に広い権限（YouTube アカウントの管理）が表示されることを案内で予告する | 審査の用途説明と画面の文言を一致させる。権限の表示に驚いて離脱させない |
| D41 | （Phase 4）利用規約の責任の制限は、故意・重過失を除く | 消費者契約法上、全部免責は無効になりうるため |
| D42 | （Phase 4 最終レビュー）チャンネル主の承認で得たデータ（playlist_creation_items、公開以外の `youtube_playlist_id`）は、チャンネル主の最後の利用（`owner_last_active_at`: 作成の開始・ステップ・操作・S6 の表示。ログアウト＝アプリからの取り消しもその時点を最後の利用とする）から 7 日で日次バッチが削除する。件数・作成済みかどうか（`done_count`・`failed_count`・`skipped_count`・`total_count`・`pruned_had_playlist`）と `authorized_data_pruned_at` を残し、S4/S6 は作成の結果を表示し続ける。未完了（一時停止・要確認・放置された実行中）も対象で、`completed` にして予約を解放する（以後は操作できない）。公開の再生リスト ID と、チャンネル主が入力した名前・説明・公開設定は提案と一緒に 30 日 | Developer Policies III.D.2（承認の取り消し・利用停止から 7 日以内の削除）。Google 側で取り消された場合はアプリから検知できないため、最後の利用を起点にする |
| D43 | （Phase 4 最終レビュー）削除依頼は提案の URL かチャンネルの URL／ID で受け付け、チャンネル単位は `app:delete-proposal --channel` で提案・作成・取り込んだ一覧をまとめて削除する。本人確認が必要なら運営者がチャンネルの公開の連絡先で確かめる。フォームが使えない（全体の上限など）ときの窓口は `OPERATOR_CONTACT`（リポジトリに個人の連絡先を書かない） | チャンネルの持ち主がデータ全体の削除を求められるようにする。仕組み（ログイン経由の本人確認）を増やさない |
| D44 | （Phase 4 最終レビュー）毎リクエスト（ログアウトを除く）期限切れのログイン・古い限定公開動画の一覧をセッションから外し、セッション行の削除を毎時（`app:prune-sessions`）にする。プライバシーポリシーには「トークンは失効後に使えなくなり次のアクセスでセッションから外す。120 分操作が無いセッションは使えなくなり、その行は約 1 時間以内に消える」と書く | 使えなくなったトークンを日次まで残さない。ログアウトでは失効直後のトークンも取り消せるようにする |
| D45 | （2026-11-15、利用者の報告）S5 の動画 URL 追加はチャンネル主専用の `GET /p/{key}/videos/lookup`（`proposals.video`。`RequireSameOriginFetch` → `throttle:owner-video-lookup`（30 回/分/チャンネル）→ `RequireOwnerSession`、JSON の 401/403 も同じ）に変える。順に (1) このセッションに読み込み済みの限定公開動画の一覧（API なし）、(2) 提案者と同じ公開動画の確認（API キー・read 予算・共有キャッシュ `video-lookup:{id}`）、(3) 見つからなければ（read 予算の使い切りで (2) を引けないときも）チャンネル主のトークンで videos.list（owner_read 1。`ownerReadGuard` 到達後は送らない。共有の owner_read を 1 チャンネルで使い切らせないよう、この回だけ別に 10 回/10 分/チャンネル（`owner-video-check`）で制限し、超過は 429）を引き、`snippet.channelId` がログイン中のチャンネルで `status.privacyStatus` が `unlisted` のものだけを「限定公開」（`unlisted: true`・対象チャンネル外ではない）として返す（7.2 の最終確認と同じ基準）。トークンで見て `public` のもの（直前に公開に切り替えて共有キャッシュがまだ「見つからない」のときなど）は通常の公開動画として返す（共有キャッシュには書かない。共有キャッシュは API キーの結果だけ）。(3) も使えない（ガード到達）ときは、(2) が予算切れなら「本日の取得上限に達しました」、そうでなければ「この動画を確認できませんでした…」の 503。他チャンネルの限定公開・非公開・削除済みは 404「この動画は追加できません（削除・非公開、またはあなたのチャンネル以外の限定公開の動画です）」。Google の 401 はログインを消して 401。結果は応答にだけ載せ、共有キャッシュにも、セッションの限定公開一覧（一覧全体のキャッシュのまま）にも書かない。プライバシーポリシーのトークンの用途に「動画 URL から追加する動画が追加できるもの（公開の動画か自分の限定公開の動画）かの確認（結果は画面に返すだけで保存しない）」を加える（9.1）。復元した下書き・選択の仮表示（「動画 {id}」）と同じ動画を URL で追加したときは、重複とせずその位置のまま実際の情報に置き換える（提案者の画面も同じ）。作成すれば作成項目に入り、4.3 の 7 日の規則に従う。提案者の URL 追加（`channels.video`）は変えず公開動画だけを受け付け、404 の文言を「この動画は追加できません（削除・非公開・限定公開の動画は、ここでは追加できません。限定公開の動画は、その動画のチャンネルの持ち主がログイン後の編集画面で追加できます）」にする。S5 の URL 入力欄の下に「あなたの限定公開の動画は、URL を入力するか「限定公開の動画も表示する」から追加できます」と案内する | 限定公開動画の URL を貼ると「削除または非公開」と表示され、チャンネル主が自分の限定公開動画を追加できないと誤解した（一覧のボタンに気付かなかった）。匿名の経路は緩めず、チャンネル主本人の経路でだけ Y11 の範囲を URL にも広げる |
| D46 | （2026-11-15、利用者の要望）S5 の公開範囲はセレクトボックス（既定 `private`）をやめ、`fieldset`／`legend`「公開範囲（必須）」の中に常に見えるラジオボタン 3 つ（「非公開 — 自分だけが見られます」「限定公開 — リンクを知っている人だけが見られます」「公開 — だれでも検索・閲覧できます」）を並べる。**最初はどれも選ばれていない**（サーバーの描画でも `data-editor` でも既定値を渡さない）。下書き（sessionStorage）は選んだ値だけを `chosenPrivacy` として保ち、選んでいなければ未選択のまま復元する（以前の下書きの `privacy` は常に既定値だったので読まない）。「提案の内容に戻す」も未選択に戻す。未選択で作成ボタンを押すと送信せず、ラジオの下に「公開範囲を選んでください」と出して最初のラジオへフォーカスを移す（作成ボタン自体は公開範囲では無効にしない。押しても理由が分からないため）。サーバーは `privacy` を `required|in:private,unlisted,public` で検証し、未指定・空は「公開範囲を選んでください」、それ以外は「公開範囲の値が正しくありません」の 422。S5 は 422 の `errors.privacy` をラジオの下（`fieldset` の `aria-describedby`）に出し、失敗が公開範囲だけのときは作成ボタンの下には重ねて出さない。公開の再生リストに限定公開の動画が含まれるときの注意（M1）は選んだ値で従来どおり出す。デプロイ前に開いたタブは古い JS のまま既定の `private` を送りうる（デプロイ直後の短い間だけで、値自体は正しいので受け入れる） | 既定の「非公開」に気付かず作成し、意図と違う公開範囲になった。毎回意識して選んでもらう（D10 の「誤公開の防止」は既定ではなく明示の選択で満たす） |
| D47 | （2026-11-15、利用者の要望）S2 と S5 の対象チャンネル一覧の絞り込みの隣に「絞り込み結果をすべて選択（N 本）」ボタンを置く。N は絞り込まれた動画のうちまだ選んでいない本数。絞り込み語が空白だけでなく、N が 1 以上で、選択が上限に達していないときだけ押せる（チェックボックスと同じ。それ以外は無効のまま表示。JS なしでも無効）。本数は N が 1 以上のときだけ表示し、それ以外は「絞り込み結果をすべて選択」とだけ出す（文言を短く保つ）。押すと、絞り込まれたまだ選んでいない動画を一覧の表示順に、チェックボックスと同じ追加処理（`selection.js` の `addVideo`。S5 は限定公開・対象チャンネル外の印も同じ）で選択の末尾に加える。すでに選んだ動画（復元した仮表示を含む）は数えず、順番も変えない。結果はボタンの直下（`role=status` の控えめな通知）に「M 本を選択しました」、上限（99 本）で途中までしか入らないときは入るだけ入れて「上限 99 本のため、N 本中 M 本を選択しました」と出す（絞り込み語を変えると消える）。1 本以上入れたら、無効になりうるボタンからフォーカスを絞り込み入力へ戻す。ロジックは `resources/js/lib/select-filtered.js` の純関数 | コラボ相手の名前などで絞り込んだ動画をまとめて入れたいが、1 本ずつのチェックが手間だった。絞り込みなしの「全部選択」は誤操作で上限まで埋まるため設けない |
| D48 | （2026-11-15、利用者の報告）S4 の「チャンネル主の方へ」の枠を題名・説明の直下（動画一覧より前）に強調表示で置き、右側の欄からは外す。未ログインでは「あなたのチャンネルに再生リストを作成できます」→ ボタン「Google でログインして作成する」→ 同意文（9.1。ボタンの隣に常に表示）→「ログインの流れと権限」の折りたたみ（未確認アプリの手順と youtube スコープの用途説明。9.1 の「S4 のログイン欄も同じ内容」はこの中で満たす）。対象チャンネルでログイン済みなら「編集画面へ進む」／「作成状況を見る」を同じ位置に置く。提案者向けの「提案した方は、この URL をチャンネル主に送ってください」は枠の外、題名の下に一行（対象チャンネルでログイン中は出さない）。動画が 8 本以上のときは一覧の下に主ボタンだけを繰り返す（ログインなら「チャンネル主の方は Google でログイン」の隣に同意文と「権限について」（プライバシーポリシーの取得情報の節）へのリンク） | 共有 URL を開いたチャンネル主が、最下部のログイン欄に気付かず何をすればよいか分からなかった。スマホで題名の直後にボタンが見えるよう、長い説明は折りたたむ |
| D49 | （2026-11-15、利用者の要望）Discord・X などに URL を貼ったときのリンクプレビューのため、`layouts.app` の head に OGP と Twitter Card を**サーバー描画で**出す（`partials.og`・`App\Support\OpenGraph`。値はすべて `{{ }}` でエスケープ）。全ページ共通: `og:site_name`「List Maker」・`og:type` website・`og:locale` ja_JP・`og:url`（そのページの絶対 URL。`og:url`・`og:image` の絶対 URL はリクエストの Host ではなく `APP_URL` から作る（偽の Host でクローラーのキャッシュを汚させない）。クエリ文字列は付けない。ただし S4 以外の `/p/{key}` 配下（共有直後・S5・S5a・S6 とそのエラー）とルートに当たらない 404 はトップの URL にする。生のキーをそれらのページに書かない規則と、どのキーでも同じ 404 を保つため）・`og:image`（静的な `public/images/og-card.png`、1200×630、`og:image:width/height/alt`）・`twitter:card` summary_large_image と `twitter:title/description/image/image:alt`・`theme-color` #2563eb。既定のタイトルは「List Maker — 再生リストの提案ツール」、説明は「チャンネルの動画を選んで、再生リストの案を URL で届けよう。チャンネル主はログインしてそのまま作成できます。」。**S4（`proposals.show`）だけ** コントローラーから `og` を渡し、タイトル「「{提案名}」の再生リスト案」、説明「{N} 本の動画 ・ {チャンネル名} への提案 ・ List Maker」にする。提案名・チャンネル名は制御文字・改行・空白の連続を 1 つの空白にして前後を詰め、表示幅で提案名 120・チャンネル名 100 を超える分は「…」で切る（`mb_strimwidth`）。提案者名・コメント（チャンネル主あての文）・動画名は載せない。存在しない／期限切れのキーは従来どおりの 404（既定のカードのみ）。共有直後（`/p/{key}/shared`）・S5・S5a・S6・エラー画面は公開して広める URL ではないので既定のカードのまま（提案名を出さない）。S4 には `RequireDocumentNavigation` を付けない（Fetch Metadata を送らないクローラーも 200）。S4 の GET は DB に書かず、YouTube API も呼ばず、機能ごとのレート制限も数えない（数えるのは web グループ全体の `throttle:global` だけ。テストで確認）。画像は同一オリジンなので CSP は変えない。`X-Robots-Tag: noindex, nofollow` は提案系ページで従来どおり（カードは noindex でも表示される。robots.txt は全許可のまま。X のクローラーは robots.txt に従うため `/p/` を Disallow しない）。画像の元は `resources/og/og-card.html`（Hiragino Sans）で、headless Chrome で PNG にする（README） | URL を貼っても何も表示されず、受け取った人が何のリンクか分からなかった。提案の中身のうち公開してよい最小限（提案名・本数・対象チャンネル）だけを出し、チャンネル主あてのコメントや提案者名は広めない |

### 12.3 未決事項
| # | 事項 | 決める人／時期 |
|---|---|---|
| U1 | （決定済み → Y10）アプリの正式名 | — |
| U2 | （決定済み → Y11）チャンネル主が自分の限定公開動画を追加できるようにするか | — |
| U3 | （決定済み → Y12：当面 99 本）99 本を超える需要があればクオータ増加申請（API Compliance Audit）を行い `QUOTA_WRITE_LIMIT` を上げる | ユーザー／利用状況を見て |
| U4 | （決定済み → Y13）審査用の英語ページ | — |
| U5 | （決定済み → Y14）チャンネル主による提案削除 | — |
| U6 | ［解決 2026-11-15、#7］送信元 IP は固定（IPv4 `163.44.176.17`・IPv6 `2400:8500:1301:162::17:1`、googleapis へは IPv6 が既定経路）。API キーは両 IP で制限し、API は YouTube Data API v3 のみに制限。リバースプロキシはなく `REMOTE_ADDR` が利用者の IP（`TRUSTED_PROXIES` は空） | Phase 0 |
| U7 | ［要確認］CORESERVER の SMTP 設定（ホスト・ポート・暗号化・認証）、送信元ドメインの SPF/DKIM、Laravel 13 の `MAIL_SCHEME` 名称 | Phase 0 |
| U8 | ［要確認］YouTube 側の制約: 再生リスト名・説明の上限と `<>` 可否、連続 insert の 409、同一動画の重複追加可否、uploads の公開範囲、`X-Goog-Api-Key` ヘッダ対応、PKCE 対応、`snippet.position` 指定に手動並び替え設定が必要か、**uploads プレイリストの並び順（プレミア公開・非公開→公開の古い動画・公開日時を過去にした動画がどこに入るか）**、コールバックの `iss` パラメータの有無 | Phase 1〜3 で実測し本書に追記 |
| U12 | （解決済み → Y16）差分取り込みの取りこぼしへの追加対策 | — |
| U13 | （Phase 2 で実施・注記あり）6.7 の参照先の裏取りと、ライブラリと異なる判断をした項目の理由の追記。判定記録の「参照ライブラリでの裏取り」欄はレビュー担当の知識に基づく照合で記入済み・理由も追記済み。**注記**: 各ライブラリのソースを読んでの確認は未実施で、「?」の付いた箇所（L の state 削除、G の verifier 長・再試行設定・付与スコープ確認、S の RFC 1738、タイムアウト・リダイレクト既定など）は必要になった時点で確かめる | 必要時（任意） |
| U9 | ［要確認］Laravel 13 の正確な PHP 要件、スケルトン既定の Tailwind/Vite バージョン | Phase 0 |
| U10 | アプリ台帳と Google 側実使用量のずれが大きい場合、`QUOTA_WRITE_LIMIT` の安全余裕を見直すか | 運用開始後 |
| U11 | （決定済み → Y15）放置された作成の予約の扱い | — |
| U14 | （決定 → Y19）作成中（Phase 3 の playlist_creations が進行中）の提案をチャンネル主が削除した場合の扱い（削除を拒むか、作成記録ごと消すか） | — |
| U15 | （Y15 の再検討。最終レビュー security I1）放置された作成の予約を当日中保持すると、1 人のチャンネル主が最大 99 本分（5,000 ユニット）を押さえて他の作成を当日ふさげる。無操作（例: 一定時間ステップが来ない）での自動解放を入れるか。当面は Y15 のまま、運用コマンド `app:release-creation-reservation` で対応（5.4） | ユーザー |

---

## 付録 A. 調査で確認した事実（出典つき）

本書の ［事実: A.x］ はこの付録を指す。2026-11 時点の確認内容であり、仕様変更があり得るため審査申請前に再確認する。

### A.1 YouTube Data API のクオータ
出典: https://developers.google.com/youtube/v3/determine_quota_cost
| メソッド | ユニット |
|---|---|
| channels.list / playlistItems.list / playlists.list / videos.list | 各 1 |
| playlists.insert / playlistItems.insert | 各 50 |
| search.list | 1（ただし 1 日 100 回の別枠上限） |
- 既定の日次クオータは 10,000 ユニット（大半のエンドポイント合計）。太平洋時間の深夜 0 時にリセット。
- 無効なリクエストも含め、すべての API リクエストは最低 1 ユニットを消費する。
- playlistItems.list の `maxResults` 上限は 50。
- 連続して playlistItems.insert を送ると 409 等が起きるとの非公式報告がある。同一動画の重複追加を API が拒否しない可能性がある（いずれも未確認。U8）。

### A.2 OAuth スコープの分類
出典: https://support.google.com/cloud/answer/13464325
- 制限付き（restricted）スコープの一覧は Gmail・Drive・Google Fit 等で、YouTube のスコープは含まれない。`https://www.googleapis.com/auth/youtube` は sensitive（restricted ではない）。

### A.3 公開ステータスと未審査アプリのユーザー上限
出典: https://support.google.com/cloud/answer/15549945
- 「テスト」では登録したテストユーザー（最大 100 人）のみ認可でき、認可は 7 日で失効する。
- 「本番」ではどの Google アカウントでも認可できるが、未承認の sensitive/restricted スコープを要求するアプリは**新規ユーザー累計 100 人**まで。この上限は**プロジェクトの生涯で数えられ、リセットできない**。
- 未承認スコープを要求すると「確認されていないアプリ」警告が出ることがある（警告画面の「詳細→移動」で続行可）。審査済みで承認済みスコープのみなら上限なし。

### A.4 sensitive スコープの審査要件
出典: https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification
- ホームページ: ログイン不要で公開され、審査対象アプリとの関係が明確であること。
- プライバシーポリシー: ホームページと同一ドメインでホストし、同意画面からリンクし、Google ユーザーデータのアクセス・利用・保存・共有を開示すること。
- Search Console で承認済みドメインの所有権を確認（プロジェクトのオーナーまたは編集者のアカウントで）。
- スコープごとに用途の説明（より狭いスコープでは足りない理由、最小権限）。
- デモ動画: YouTube に限定公開でアップロード。英語で OAuth 付与の流れを示し、同意画面に正しいアプリ名、アドレスバーに OAuth クライアント ID が見えること。各スコープで可能になる機能を実演すること。
- sensitive スコープの審査には最大 10 日程度かかる。審査申請の前提としてブランディングの公開が必要。

### A.5 （補足）プロジェクト単位であること
- 未審査ユーザー上限（A.3）、審査（A.4）、クオータ（A.1）はいずれも Google Cloud プロジェクト（OAuth クライアント・API キーが属するプロジェクト）単位で扱われる。これが専用プロジェクト（D16）の根拠。

### A.6 YouTube API Services Developer Policies の要点
出典: https://developers.google.com/youtube/terms/developer-policies
- III.A.1: YouTube 利用規約へのリンクを表示し、利用者が同規約に拘束される旨を自サービスの規約に書く。
- III.A.2: 機能を使う前にプライバシーポリシーへの同意を求め、常に容易にアクセスできる場所に表示する。YouTube API Services を使う旨、Google プライバシーポリシーへのリンク、アクセス・保存・共有するユーザーデータ、承認の取り消し方法（Google のセキュリティ設定ページ）、プライバシーに関する問い合わせ手段を記載する。
- III.D.1: 1 つの API クライアントにつき API プロジェクトは 1 つ。API キーを第三者に共有・公開しない。
- III.D.2: 実際に使うスコープだけを要求する。アクセスを要求している主体・製品を利用者に明確に示す。取り消しを簡単にでき、利用者が取り消したらトークンを即時に取り消す。取り消し後 7 日以内に認可データを削除する。
- III.D.3: クオータ増加は API Compliance Audit を経て申請する。III.D.4: 90 日間連続で使われないプロジェクトはクオータが縮小され得る。
- III.E.3: 書込操作は明確に表示し、利用者の明示的な同意を得てから実行する。認可リクエストに紐づくチャンネルを識別する。
- III.E.4: API キーで取得した公開データ（非認可データ）は一時的にのみ保存し、30 日以内に削除または更新する。利用者の削除依頼には 7 日以内に応じる。
- III.F.2: YouTube のコンテンツを表示するページでは YouTube が出典であることを明示し、ブランド要素はブランディングガイドラインに従って使う。
