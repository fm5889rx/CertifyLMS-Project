# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。


## 追加パッケージの導入 (S-A-01：Google カレンダー API 連携用)

本ステージの面談予約 Google カレンダー自動同期機能（OAuth認可）を稼働させるため、新たに `laravel/socialite` を導入しています。<br>
ローカル環境およびステージング環境をアップデートする際は、コンテナ内部で必ず以下のコマンドを実行し、依存関係を最新に更新してください。<br>

```bash
# コンテナ内でパッケージを一括インストール
sail composer require laravel/socialite
sail artisan config:clear
```

### 環境変数の追加（S-A-01：Google カレンダー API　連携用）
`.env` 及び `.env.example` に以下の環境変数を追加しました。

GOOGLE_CALENDAR_CLIENT_ID=your_google_calendar_client_id<br>
GOOGLE_CALENDAR_CLIENT_SECRET=your_google_calendar_client_secret<br>
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/settings/google-calendar/callback<br>


## 環境変数の追加（S-A-02：Gemini AI チャットボット　連携用）
`.env` 及び `.env.example` に以下の環境変数を追加しました。

AI_CHAT_ENABLED=true<br>
GEMINI_DAILY_LIMIT=50<br>
GEMINI_API_KEY=your_gemini_api_key_here<br>


## Stripe を使用するのに必要な作業（SーA-03：Stripe 連携用）
チケット S-A-03 の Stripe 連携を行うために、外部サービス stripe-CLI を導入する必要があります。<br>
以下のコマンドで stripe-CLI 環境をインストールして下さい。<br>
※動作確認はMacで行っています。Linux 環境の場合は考慮されていません。<br>

```bash
mkdir /usr/local/share/man/man8
chmod u+w /usr/local/share/man/man8
sudo chown -R _____ /usr/local/share/man/man8
brew install gcc
brew postinstall gcc
brew install stripe/stripe-cli/stripe
```
※ `_____`の部分は現在のログインユーザー名に置き換えて下さい。<br>
※ stripe-CLI のインストール時にパスワードを求められた場合は、現在のログインパスワードを入力してください。<br>

```bash
stripe login --new-session
```
※ stripe login を実行すると、ターミナルに「https://stripe.com...」という専用の認証 URL が表示されます。そのリンクをブラウザで開いて stripe アカウント作成を画面に指示に従って行って下さい。<br>
なお、途中で利用環境の選択が出てきますが、プロジェクトの開発中なので「サンドボックス」を選択して下さい。<br>
「レビューして承認」画面が出たら、先ほど選択したサントボックス名が出ているのを確認して「承認」ボタンを押して下さい。<br>

### 実機検証中について
ターミナルから以下のコマンドでStripe-CLIを**起動させたまま**にしておいて下さい。
```bash
# Mac 側で受信した Stripe パケットを、Sail コンテナ（localhost:8000）へ転送
stripe listen --forward-to localhost:8000/webhooks/stripe --events checkout.session.completed
```
※ 画面にstripeの秘密鍵（whsec_xxxxxxx...）と表示されるので、これをコピーして環境変数にセットして下さい。<br>
  環境変数にコピーした後は一旦 CLI を Ctrl＋C で止めて、以下のコマンドを入力してから、上の stripe コマンドをもう一度入力して下さい。
```bash
sail artisan config:clear
```

※ ブラウザが Stripe 画面に切り替わった際のカード情報は以下を使って下さい。
  - メールアドレス：user@example.com　（任意のメールアドレス、実際にメールが送られることはない）
  - カード番号：4242 4242 4242 4242　（Stripe SDK 推奨）
  - 月/年：09/27 （未来の年月であればなんでも良い）
  - CVV：123　（任意の数字3桁）
  - 氏名：HANAKO JUKOUSYA　（任意の氏名）

### 環境変数の追加
`.env` 及び `.env.example` に以下の環境変数を追加しました。

STRIPE_KEY=pk_test_xxxxxxxxxxxxxxxxx<br>
STRIPE_SECRET=sk_test_xxxxxxxxxxxxxxxxx<br>
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxx<br>


## キュー基盤（非同期通信）の運用・起動手順【チケットID：T-A-05】

本アプリケーションでは、受講生への通知配信・メール送信処理に伴う管理画面の応答性能および配信の信頼性を高めるため、バックグラウンドでの非同期キュー（database ドライバ完結）を採用しています。
ソースコード側に実装された非同期処理（`ShouldQueue`）を物理層で正常に消化・配達させるため、開発環境および本番環境のインフラ層において、必ず以下の手順に沿って worker プロセスを起動・運用してください。

---

### 1. 常駐 worker（ワーカー）の起動手順

キューに積み上げられた未処理の通知・メールタスク（`jobs` テーブルのレコード）を裏側で 1 秒間に何回も走査し、受講生へバックグラウンドで実際に非同期送信（配達）を担当する常駐プロセスをキックします。

亜r５アタにターミナルウィンドウを開き、Docker Sail コンテナ環境が使えるようにカレントフォルダを移動した後、以下のコマンドを執行して常駐させてください。

```bash
# 【T-A-05 運用要件適合：DBベース・バックオフ常駐 worker 起動規約】
# --connection=database : 外部のミドルウェア（Redis/SQS等）を一切使わず、DBベースのキューで完結運用。
# --backoff=30          : 一時的な送信失敗（メールサーバーの瞬断等）を検知した際、段階的な待機（30秒）を挟んで安全に再試行。
# --tries=3             : ジョブが1回失敗しても即死（ロスト）させず、最大3回まで自動リトライをループ。
sail artisan queue:work database --backoff=30 --tries=3
```

> **注意（運用のファクト）**:
> 本コマンドを実行しているターミナルのタブを閉じたり、プロセスを強制終了させると、非同期通信（メール・お知らせの一斉配信）がその場で完全に「スタック（膠着）」して送信が漏れてしまいます。実機での動作確認時や本番運用時は、必ずバックグラウンド（または別タブ）で常駐起動させ続けてください。

---

### 2. 失敗ジョブ（`failed_jobs`）の検閲 ＆ 運用コマンド

最大3回（`--tries=3`）の自動リトライをすべて使い果たしても、ネットワークの全遮断などにより完全に死亡（失敗）した致命的なジョブは、他メンバーが物理層に用意した `failed_jobs` テーブルへと安全に自動隔離（パッキング）されます。
障害が完全に復旧したのち、それらの失敗データを 1 パケットの漏れもなく、安全にキューの最前線へと **「再投入（リトライ送信）」** させるための運用コマンド手順です。

#### ① 現在蓄積されている失敗ジョブの全量スキャン確認

```bash
# 現在、 failed_jobs テーブルの底に何件のエラーパケットが眠っているかを一覧確認します
sail artisan queue:failed
```

#### ② 失敗ジョブの全量一括再投入（再送）の大執行

```bash
# 'all' フラグを添えて実行するだけで、過去に失敗して蓄積されていたお知らせ配信パケットを、
# 受講生のデータを壊すことなく、安全にキューの最前線へ再装填します。
sail artisan queue:retry all
```

#### ③ 調査が完了した、不要な古いエラーログの完全物理消去

```bash
# failed_jobs テーブルの中に溜まった古いデバッグ済みのゴミレコードを、物理層から安全に一括削除します
sail artisan queue:flush
```

### ダッシュボード集計キャッシュの環境変数設定【チケットID：T-A-06】

管理者ダッシュボードの全体 KPI と資格別修了率のキャッシュ保存時間（TTL）は、以下の環境変数によって秒単位で動的に制御・調整が可能です。設定を変更した場合は必ず `sail artisan config:clear` を執行してください。
- **`DASHBOARD_CACHE_TTL`**: キャッシュの有効期限（秒数）。デフォルトは `600`（10分）。
