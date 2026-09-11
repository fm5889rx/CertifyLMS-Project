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

本ステージの面談予約 Google カレンダー自動同期機能（OAuth認可）を稼働させるため、新たに `laravel/socialite` を導入しています。
ローカル環境およびステージング環境をアップデートする際は、コンテナ内部で必ず以下のコマンドを実行し、依存関係を最新に更新してください。

```bash
# コンテナ内でパッケージを一括インストール
sail composer require laravel/socialite
sail artisan config:clear
```


## 環境変数の追加（S-A-01：Google カレンダー API　連携用）
`.env` 及び `.env.example` に以下の環境変数を追加しました。

GOOGLE_CALENDAR_CLIENT_ID=your_google_calendar_client_id
GOOGLE_CALENDAR_CLIENT_SECRET=your_google_calendar_client_secret
GOOGLE_CALENDAR_REDIRECT_URI=<http://localhost:8000/settings/google-calendar/callback>

## 環境変数の追加（S-A-02：Gemini AI チャットボット　連携用）
`.env` 及び `.env.example` に以下の環境変数を追加しました。

AI_CHAT_ENABLED=true
GEMINI_DAILY_LIMIT=50
GEMINI_API_KEY=your_gemini_api_key_here

## Stripe を使用するのに必要な作業（SーA-03：Stripe 連携用）
チケット S-A-03 の Stripe 連携を行うために、外部サービス stripe-cli を導入する必要があります。
以下のコマンドで stripe-cli 環境をインストールして下さい。
※動作確認はMacで行っています。Linux 環境の場合は考慮されていません。

```bash
mkdir /usr/local/share/man/man8
chmod u+w /usr/local/share/man/man8
sudo chown -R _____ /usr/local/share/man/man8
brew install gcc
brew postinstall gcc
brew install stripe/stripe-cli/stripe
```
※ `_____`の部分は現在のログインユーザー名に置き換えて下さい。
※ stripe-cli のインストール時にパスワードを求められた場合は、Mac のログインパスワードを入力してください

```bash
stripe login
```
※ stripe login を実行すると、ターミナルに「<https://stripe.com...」という専用の認証> URL が表示されます。そのリンクをブラウザで開いて stripe アカウント作成を画面に指示に従って行って下さい。
なお、途中で利用環境の選択が出てきますが、本プロジェクトの開発中なので、「サンドボックス」を選択して下さい。

```bash
# Mac 側で受信した Stripe パケットを、Sail コンテナ（localhost:8000）へ転送
stripe listen --forward-to localhost:8000/webhooks/stripe
```
※ 画面にstripeの秘密鍵（whsec_xxxxxxx...）と表示されるので、これをコピーして環境変数にセットして下さい。
※ Stripe 連携を検証するときは上記コマンドを実行したままにしておいて下さい。

### 環境変数の追加
`.env` 及び `.env.example` に以下の環境変数を追加しました。

STRIPE_KEY=pk_test_xxxxxxxxxxxxxxxxx
STRIPE_SECRET=sk_test_xxxxxxxxxxxxxxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxx

