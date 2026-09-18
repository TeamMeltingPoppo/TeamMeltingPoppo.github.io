# Swingby：Astroを保ったままWordPressへ移行

## 維持するもの

- ページは `src/pages/*.astro`、部品は `src/components/`、ロジックはTypeScript。
- デザインはTailwind CSSと `src/styles/global.css`。
- 記事は `content/blog/<記事フォルダ>/index.md` と同じフォルダの画像。
- 元のGitHub Pagesワークフローは維持。WordPress用は独立した `.github/workflows/wordpress.yml`。
- 通常の `yarn build` は従来のGitHub Pages用。`WORDPRESS_SITE_URL` を設定したときだけWordPress用のURLとメタデータを出力します。

変更後の経路：Mac → git push → GitHub ActionsでAstroビルド → HTTPSで専用プラグインに送信 → WordPressでプレビュー・公開。
EC2でNode.jsを常時動かす必要はありません。SSHをGitHub Actions向けに開ける必要もありません。

## WordPressの役割と制限（必読）

これは既存AstroをPHPやブロックテーマへ書き換える移行ではありません。
プラグイン0.3.0から、記事と指定のサイト編集項目を双方向に同期します。Astro/TypeScript/Markdown/Tailwindは維持し、専用テーマがビルド済みHTML/CSS/JSを表示します。

- 記事本文はネイティブ投稿に、ページ本文は固定ページにも保存するため、WordPressの一覧・検索・RSSで扱えます。
- 投稿はWordPressの通常エディターでも編集できます。次回同期でGitHubへ保存してAstroを再ビルドします。両側の変更が衝突した場合は上書きせず停止します。
- 固定ページとデザインは「ツール → Swingby サイト編集」の項目を `src/data/site-settings.json` と双方向同期します。任意のAstroコード、WordPressのサイトエディター全体・プラグイン設定・ユーザー・認証情報・DB全体を同期する仕組みではありません。
- 問い合わせフォームのURLはサイト編集画面から設定できます。外部の仮画像は元サイトのままです。
- プラグイン0.2.0以降の記事URLはWordPressのパーマリンク設定に従います（例 `/blog/%year%%monthnum%%day%/%post_id%`）。元の `/blog/blog/swingbytshirt/` は301転送し、一覧・本文リンク・canonical・共有用URLも表示時に置換します。記事IDとソースの識別キーは維持します。固定ページのURLは従来どおりです。
- 新規ページは自動検出。`Base.astro` を使い、`<main>` 内にページ内容を置いてください。
- `draft: true` は下書き、未来日時は予約投稿です。下書き・予約日時前の記事は一覧から除外します。予約公開後の一覧反映は次回同期です。
- 削除・移動したソースはWordPressから自動削除しません。パス変更は別レコードとして登録します。不要な古い投稿は確認してから管理画面で処理してください。
- 画像はAstroで最適化したものをメディアライブラリにも登録。CSS/JS/フォントはuploads配下のリリース専用領域に置きます。下書きに使う画像も通常のWordPressアップロードと同様、URLを知っていれば取得可能です。
- `/rss.xml` はWordPressの公開投稿RSSへ、サイトマップは `wp-sitemap.xml` へ置き換えます。
- 404は旧HPのデザインを維持。WordPressで作った新規記事は、次回同期で `content/blog/wp-投稿ID/index.md` に取り込みます。同期前はテーマの簡易表示です。
- SSL証明書やNginxの既存設定はパッケージから変更しません。

## 初回導入（本番の変更はここから）

### 1. バックアップ

EC2のスナップショットに加え、WordPressのDBと `/var/www/wordpress` を保存してください。
本プラグインの切り戻しは直前のコンテンツ公開状態用で、DB障害・テーマ変更・プラグイン更新を含む完全バックアップの代わりではありません。

### 2. アップロード上限

現在の初回 `site.zip` は約5 MBです。Nginxのデフォルト上限1 MBを超えます。
EC2の `/etc/nginx/conf.d/wordpress.conf` の **melting-poppo.com用HTTPS serverブロック**に次を追加します。

```nginx
client_max_body_size 64m;
```

HTTP serverが証明書取得時に分割されている場合は、443側に入れてください。既存の証明書・転送・PHP設定は残します。
PHPでは `/etc/php.d/99-swingby-upload.ini` を次の内容で作成します。

```ini
upload_max_filesize = 64M
post_max_size = 72M
memory_limit = 256M
max_execution_time = 180
```

64 MiBぎりぎりのZIPはmultipart分が加わるため、実ファイルは60 MiB程度までにしてください。

```bash
sudo nginx -t
```

成功した場合だけ以下を実行します。

```bash
sudo systemctl reload nginx
sudo systemctl restart php-fpm
```

### 3. プラグイン・テーマ・初回ビルド

1. WordPress管理画面 → プラグイン → 新規追加 → アップロードで `swingby-git-sync.zip` をインストール・有効化。
2. 外観 → テーマ → 新規追加 → アップロードで `swingby-astro.zip` をインストール。**まだ有効化しません。**
3. ツール → Swingby Git Sync → 「初回のビルド取り込み」で `site.zip` を取り込む。
4. 全8件のプレビューを確認。既存の公開ページはこの時点では変更されません。
5. 公開準備ができたらテーマ「Swingby Astro Bridge」を有効化し、ツール → Swingby Git Sync → 「このビルドを公開」。この2操作は続けて実行してください。テーマ有効化直後から公開までの間は簡易表示になります。
6. トップ、活動紹介、記事、タグ、スマートフォンのメニュー、明暗切り替え、画像、HTTPSを確認。

プレビューは選択ページ単体です。リンクを押すと現在の公開サイトへ移動します。

### 4. GitHubへの接続

1. GitHubリポジトリのSettings → Environmentsで `wordpress-production` を作成。デプロイ対象を `main` に制限することを推奨します。
2. WordPressで同期専用の管理者ユーザーを作成し、そのプロフィールでアプリケーションパスワード「GitHub Swingby Sync」を発行します。普段ログインするパスワードは使いません。
3. `wordpress-production` のEnvironment secretsへ次を登録します。

| 名前 | 値 |
| --- | --- |
| `WORDPRESS_USERNAME` | 同期専用管理者のユーザー名 |
| `WORDPRESS_APP_PASSWORD` | 発行したアプリケーションパスワード |

**パスワードをチャット、ソース、記事、Actionsログに貼らないでください。**

このプラグインはJavaScriptを含むサイト全体を更新するため、管理者権限を要求します。アプリケーションパスワードは当該ユーザーの権限を引き継ぎ、このエンドポイント専用に制限されたトークンではありません。GitHubの書き込み権限は信頼できるメンバーに限定し、mainのPRレビューと環境保護を設定してください。必要なくなったらWordPressのプロフィールで失効できます。

4. この変更を `main` にマージします。
5. Actions → **Sync Astro to WordPress** → Run workflow。初回には既存の全記事・ページを送信します。
6. WordPressのツール画面に「公開待ち」が出ることを確認。
7. 以後もレビュー後に公開できます。pushだけで全更新を公開する運用にしたい場合は、同画面の「次回以降、GitHubから届いたビルドを自動公開する」を明示的に有効化します。

初期状態では自動公開はオフです。秘密情報が未設定の場合、ActionsはZIPをArtifactsに残してエラー終了します。本番へは送信しません。

## 通常の編集

```bash
git clone https://github.com/TeamMeltingPoppo/TeamMeltingPoppo.github.io.git
cd TeamMeltingPoppo.github.io
```

Node.js 22とYarn 4.18.0を使います。既存のyarn.lockを維持します。

```bash
npm exec --yes --package=@yarnpkg/cli-dist@4.18.0 -- yarn install --immutable
```

従来と同じファイルを編集し、通常のcommit/pushを行います。専用PHPファイルを触るのは同期の仕組みを変更するときだけです。

ローカルでWordPress用パッケージを作る場合：

```bash
ASTRO_TELEMETRY_DISABLED=1 WORDPRESS_SITE_URL=https://melting-poppo.com node node_modules/astro/astro.js build
node --import tsx wordpress/scripts/package.ts
php wordpress/tests/validate.php
```

`wordpress-build/` に3つのZIPを生成します。このフォルダと認証情報はGit管理しません。
GitHub Actionsはサイト出力のみを更新します。プラグイン・テーマのPHPコード自体を更新した場合は、対応するZIPを管理画面から別途更新してください。

## 失敗時・切り戻し

- 401/403：ユーザー名、アプリケーションパスワード、管理者権限、HTTPSを確認。
- 404：プラグイン有効化とWordPressのREST APIを確認。送信URLは `/?rest_route=/swingby-git/v1/stage` なのでパーマリンク再設定は不要です。
- 413／ファイルが届かない：Nginx・PHPの上限を確認。
- タイムアウト：Actionsを再実行。パスをキーに照合するため、同じビルドで記事・画像が増殖しません。
- 409「Another import」：実行中の処理がないか確認。PHP異常終了後にロックが残った場合のみ、WP-CLIで `wp option delete swingby_git_lock` を実行（WP-CLIの追加導入が必要です）。
- 「このビルドを公開」後の問題：ツール画面の「直前の公開状態に戻す」。旧ビルドの画像は削除しないため戻せます。最初の公開を戻した場合は、以前のテーマも手動で再度有効化してください。
- 世代ごとに静的ファイルを保持します。容量を監視し、公開中・公開待ち・切り戻し対象の3世代を確認してから不要な世代を手動整理してください。
- プラグインを無効化しても投稿・画像は削除しません。ルーティングとAstro表示は無効になるため、無効化はテーマと公開先の復旧と合わせて行います。

## 検証

`wordpress/tests/validate.php` は実ビルドのマニフェスト、アセットSHA-256、パストラバーサル、PHPファイルの拒否を確認します。
`wordpress/tests/integration.php` は使い捨てWordPressで初回下書き、重複防止、画像登録、公開、日付/IDパーマリンクと旧URL変換、テーマ表示、切り戻し、未来日付の下書き、悪意あるZIP拒否、自動公開オプトインを確認します。
本番とは別のWordPress + 公式SQLite Database Integrationで実行しました。EC2のMariaDB構成と実ネットワークでの導入確認は別途必要です。

## 公式資料

- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- https://docs.astro.build/en/guides/deploy/
- https://docs.github.com/en/actions/how-tos/security-for-github-actions/security-guides/using-secrets-in-github-actions

## pushによる自動公開の確認

`main` の対象ファイルを変更してpushしたあと、Actionsの **Sync Astro to WordPress** を開きます。
最後の **Send to WordPress staging** ステップに `status=published` が出れば、自動公開まで完了しています。
`status=staged` の場合は取り込みのみ完了で、WordPress側の自動公開設定がオフです。

確認用にこの手順書だけを更新すれば、サイト本文やデザインを変えずに同じ経路をテストできます。
自動公開をオンにする前から残っている公開待ちビルドは、保存操作だけでは公開されません。


## 0.3.0への更新と双方向編集

1. 最新のActions成果物またはローカル生成物の `swingby-git-sync.zip` を「プラグイン → 新規プラグインを追加 → プラグインのアップロード」で選び、既存版を置き換えます。テーマの再インストールは不要です。
2. 「ツール → Swingby Git Sync」の自動公開をオンにします。
3. GitHub Actionsの **Sync Astro to WordPress → Run workflow** を実行します。新APIがない間はビルド成果物だけを作り、本番への上書きを停止します。
4. 一度取り込むと「ツール → Swingby サイト編集」に編集項目が表示されます。

- GitHubへのpush時と約15分間隔でWordPressの変更を確認します。GitHubのスケジュール実行には遅延があり、厳密に15分以内を保証するものではありません。急ぐときはRun workflowを使います。
- 同期対象の記事情報：新規記事、本文、タイトル、日時、タグ、カテゴリー、下書き/承認待ち/予約/公開、抜粋、アイキャッチの参照、削除。
- WordPressで本文を編集した記事は、HTMLを含むMarkdownとして保存します。ブロックのHTMLとコメントは保持します。本文が変わっていない場合は元のMarkdown本文を維持します。元のMarkdown記法へ完全に逆変換するものではありません。
- `wordpressFormat: html` の記事は本文がHTMLです。GitHubでMarkdown記法に書き直す場合はこの項目を削除してください。`wordpressId` と `wordpressRevision` は同期用なので通常は変更しません。
- WordPressにアップロードした画像は記事内のURL・アイキャッチ参照としてGitHubに記録します。アップロード済みファイル全体のGit保存/バックアップではありません。WordPress固有の動的ブロック・ショートコード・第三者プラグインの表示処理はAstroでの再現対象外です。
- 固定ページの標準エディターを直接変更すると同期を停止します。文章・配色は専用のサイト編集画面を使い、レイアウト構造や編集項目の追加はAstro側で行います。
- 非公開/パスワード付き記事の変更は公開リポジトリに送らず、同期を停止します。下書きは従来のMarkdown下書きと同様GitHubには保存されます。

### 記事の削除

WordPressでゴミ箱へ入れた記事・完全削除した記事を検出し、対応する記事フォルダを画像ごと `archive/wordpress-deleted/blog/` へ移します。保管フォルダはAstroの読み込み対象外なので公開されません。不要になったタグ・ページ送りの生成ページも公開対象から外します。

移動前にGitHub側も変更されていた場合は、消さずに競合として停止します。保管後に復元する場合は、WordPressのゴミ箱から戻し、GitHubの保管フォルダを元の場所へ戻してpushしてください。完全削除済みの投稿は元IDへ復元できないため、別記事としての復元作業が必要です。

### 競合と再試行

Actionsのエラーに出たファイルとWordPressの編集内容を比較して統合してください。双方の変更は残ります。公開前にGitHubへのcommit/pushを成功させるため、ブランチ保護で自動commitが拒否された場合も本番は上書きしません。

GitHubへ保存できたあと公開に失敗した場合は、次回の同期でも再公開を試みます。復旧のために記事を削除・作り直す必要はありません。初回移行時に旧版プラグイン下でWordPressを編集済みで、同期基準がない場合も停止するため、初回の差分を確認してから進めてください。

GitHub Actionsには `contents: write` が必要です。既存のWordPress用Secretsを使用し、追加のGitHubアクセストークンをEC2へ保存する必要はありません。自動commitは同じ実行内でビルド・公開するため、無限のpushループを作りません。

追加検証：`node --import tsx --test wordpress/tests/pull.test.ts`。変更検出・再試行・競合停止・画像を含む保管・パス制限・サイト設定の競合を確認します。
