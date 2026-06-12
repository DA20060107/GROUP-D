# シフト代勤マッチング支援システム

## 概要

休みたい従業員と代わりに働ける従業員をマッチングし、従業員間の連絡負担と店長のシフト調整負担を軽減するためのWebシステムです。

対象は店長1人・従業員15〜20人程度の居酒屋を想定しています。

想定する基本フローは以下の通りです。

1. 店長がシフトと勤務可能日を登録する
2. 従業員が自分のシフトを確認する
3. 従業員が休み申請を行う
4. システムが代勤候補を抽出する
5. 代勤候補に通知を作成する
6. 代勤候補が代勤可否を回答する
7. 店長が承認する
8. 関係者が結果通知を確認する

本リポジトリは、上記機能を今後実装していくための**プロジェクト土台**です。

## 開発環境

* XAMPP（Apache + MySQL + PHP）
* PHP, HTML, CSS
* MySQL
* GitHubでのソース管理を想定

## フォルダ構成

```
プロジェクトルート/
├── public/                     # Webサーバの公開フォルダ
│   ├── index.php               # トップページ
│   ├── login.php               # ログイン画面
│   ├── logout.php              # ログアウト処理
│   └── assets/
│       └── css/
│           └── style.css       # 共通CSS
├── app/
│   ├── config/
│   │   └── database.php        # PDOによるDB接続設定
│   └── includes/
│       ├── header.php          # 共通ヘッダー
│       ├── footer.php           # 共通フッター
│       └── auth.php             # 認証・権限チェックの雛形
├── pages/
│   ├── manager/                 # 店長用画面
│   │   ├── menu.php             # 店長メニュー
│   │   ├── employees.php        # 従業員情報管理
│   │   ├── shifts.php           # シフト作成・一覧確認
│   │   ├── notifications.php    # 通知確認
│   │   └── approvals.php        # 承認
│   └── employee/                # 従業員用画面
│       ├── menu.php             # 従業員メニュー
│       ├── shifts.php           # シフト確認
│       ├── leave_request.php    # 休み申請
│       ├── notifications.php    # 通知確認
│       ├── candidate_response.php # 代勤提案への応答
│       └── result.php           # 承認結果確認
├── database/
│   ├── schema.sql               # テーブル定義
│   └── seed.sql                 # サンプルデータ
└── README.md
```

## XAMPPでの起動方法

1. このプロジェクトフォルダ全体を、XAMPPの `htdocs` フォルダ内（例: `htdocs/shift-app/`）に配置します。
2. XAMPP Control Panel から **Apache** と **MySQL** を起動します。
3. ブラウザで以下のURLにアクセスします。

   ```
   http://localhost/(プロジェクトのフォルダ名)/public/index.php
   ```

   （フォルダ名は配置場所に合わせて変更してください）

## DB作成手順

1. phpMyAdmin（`http://localhost/phpmyadmin`）または `mysql` コマンドを開きます。
2. `database/schema.sql` を実行します。
   * `CREATE DATABASE IF NOT EXISTS` でDB「シフト管理システム」が作成されます。
   * 続けて全テーブルが作成されます。
3. 続けて `database/seed.sql` を実行します。
   * サンプルデータ（店長1名、従業員5名、シフト、勤務可能日、通知など）が登録されます。

### schema.sql / seed.sql の実行例（コマンドライン）

```
mysql -u root --default-character-set=utf8mb4 < database/schema.sql
mysql -u root --default-character-set=utf8mb4 < database/seed.sql
```

※ Windows + XAMPPの `mysql` コマンドはデフォルトの文字コードが `cp932` になっている場合があり、
　DB名・データ中の日本語でエラー（`Invalid cp932 character string`）になることがあります。
　その場合は上記のように `--default-character-set=utf8mb4` を付けて実行してください。

phpMyAdminを使う場合は、「SQL」タブに各ファイルの内容を貼り付けて実行してください。
**`schema.sql` → `seed.sql` の順に実行してください。**

## テスト用ログイン情報

`seed.sql` 投入後、以下のユーザーでログインできます。
パスワードは全ユーザー共通で `password123`（開発用、`password_hash()` でハッシュ化して保存済み）です。

| ユーザーID | パスワード | role | 表示名 | ログイン後の遷移先 |
|---|---|---|---|---|
| manager01  | password123 | manager  | 店長 太郎 | `pages/manager/menu.php`（店長メニュー） |
| employee01 | password123 | employee | 山田 太郎 | `pages/employee/menu.php`（従業員メニュー） |
| employee02 | password123 | employee | 佐藤 花子 | `pages/employee/menu.php`（従業員メニュー） |
| employee03 | password123 | employee | 鈴木 次郎 | `pages/employee/menu.php`（従業員メニュー） |
| employee04 | password123 | employee | 高橋 美咲 | `pages/employee/menu.php`（従業員メニュー） |
| employee05 | password123 | employee | 田中 健太 | `pages/employee/menu.php`（従業員メニュー） |

## 現時点で実装済みの内容

* 共通レイアウト（ヘッダー・フッター・共通CSS）
* PDOによるDB接続ファイル（`app/config/database.php`）
* DBスキーマ（`schema.sql`）とサンプルデータ（`seed.sql`）
* ログイン処理（`public/login.php`）
  * `users` テーブルをプリペアドステートメントで照合
  * `password_verify()` によるパスワード検証
  * ログイン成功時は `$_SESSION['user']` にユーザー情報を保存
  * roleに応じて店長メニュー / 従業員メニューへ遷移
  * ログイン失敗時はログイン画面上にエラーメッセージを表示
* ログアウト処理（`public/logout.php`）：セッション破棄してログイン画面へ
* 認証・権限チェック関数（`app/includes/auth.php`）
  * `requireLogin()` / `requireRole($role)` / `currentUser()` / `isManager()` / `isEmployee()`
* 店長側画面（メニュー、従業員情報管理、シフト作成・一覧確認、通知確認、承認）
  * すべて `requireRole('manager')` でアクセス制限
  * メニュー画面にログイン中ユーザー名を表示
* 従業員側画面（メニュー、シフト確認、休み申請、通知確認、代勤提案への応答、承認結果確認）
  * すべて `requireRole('employee')` でアクセス制限
  * メニュー画面にログイン中ユーザー名を表示

## 次に実装する予定の内容

* 店長によるシフト登録・編集機能
* 従業員によるシフト確認のDB連携
* 従業員による休み申請の登録機能
* 代勤候補抽出ロジック
* 代勤候補への通知作成・回答機能
* 店長による承認処理
* 各画面のDB連携（一覧表示・登録・更新）

## 注意点

* DB名は仮で **「シフト管理システム」** としています。文字コードや環境によって扱いにくい場合は、英数字のDB名（例: `shift_management`）へ変更可能です。変更する場合は `database/schema.sql`・`database/seed.sql`・`app/config/database.php` のDB名を合わせて修正してください。
* `seed.sql` のパスワードは開発用の共通パスワード（`password123`）を `password_hash()` でハッシュ化したものです。
  **本番運用では、ユーザーごとに異なるパスワードを設定し、登録・変更時に必ず `password_hash()` でハッシュ化してください。**
* 本番運用では、SQLインジェクション対策（プリペアドステートメントの徹底）に加えて、HTTPS化・セッション管理の強化なども検討してください。

## 動作確認手順

1. XAMPPで **Apache** と **MySQL** を起動する。
2. `database/schema.sql` → `database/seed.sql` の順に実行し、DBとサンプルデータを用意する（上記「DB作成手順」参照）。
3. ブラウザで `http://localhost/<配置フォルダ名>/public/index.php` を開く。
4. 「ログイン画面へ」から `public/login.php` を開く。
5. **店長ログインの確認**
   * ユーザーID `manager01` / パスワード `password123` でログインする。
   * 店長メニュー（`pages/manager/menu.php`）に遷移し、「ログイン中：店長 太郎」と表示されることを確認する。
   * 従業員情報管理・シフト作成・通知確認・承認の各画面に遷移できることを確認する。
6. **従業員ログインの確認**
   * ログアウト後、ユーザーID `employee01` / パスワード `password123` でログインする。
   * 従業員メニュー（`pages/employee/menu.php`）に遷移し、「ログイン中：山田 太郎」と表示されることを確認する。
   * シフト確認・休み申請・通知確認・代勤提案への応答・承認結果確認の各画面に遷移できることを確認する。
7. **ログイン失敗の確認**
   * 誤ったユーザーIDまたはパスワードでログインを試み、ログイン画面上に「ユーザーIDまたはパスワードが正しくありません。」と表示されることを確認する。
8. **権限チェックの確認**
   * 未ログイン状態で `pages/manager/menu.php` や `pages/employee/menu.php` に直接アクセスし、ログイン画面へ転送されることを確認する。
   * 従業員でログイン中に `pages/manager/menu.php` 等の店長画面へアクセスし、従業員メニューへ戻されることを確認する。
   * 店長でログイン中に `pages/employee/menu.php` 等の従業員画面へアクセスし、店長メニューへ戻されることを確認する。
9. **ログアウトの確認**
   * メニュー画面の「ログアウト」リンクからログアウトし、ログイン画面に戻ることを確認する。
   * ログアウト後、再度メニュー画面へ直接アクセスするとログイン画面へ転送されることを確認する。
