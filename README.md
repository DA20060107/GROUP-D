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
│   │   ├── matching_settings.php # 代勤候補抽出モード設定
│   │   ├── notifications.php    # 通知確認
│   │   ├── approvals.php        # 休み申請の承認
│   │   └── cancellation_requests.php # 承認後キャンセル申請確認
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
   * `CREATE DATABASE IF NOT EXISTS` でDB `shift_matching_system` が作成されます。
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

## 状態一覧

休み申請・代勤候補・シフト・キャンセル申請・通知には、それぞれ複数の状態（status）があります。
**ここに記載した値が実装上の正式名称です。** 各画面の表示ラベル・バッジ色は `app/includes/status_labels.php` で一元管理しており、`database/schema.sql` のENUM定義とこのセクションは一致しています。新しい状態名・通知typeを増やす場合は、機能追加にあたるため、まずユーザーに確認してください。

### leave_requests.status

| 状態名 | 表示名 | 意味 | 主な遷移先 |
|---|---|---|---|
| `pending` | 受付中 | 休み申請登録直後の一時状態。直後に `processSubstituteMatching()` が呼ばれ、通常はDBに残らない | `matching` / `no_candidate` |
| `matching` | 候補者回答待ち | 代勤候補を抽出し、候補者の回答または店長承認を待っている状態。候補者は `proposed` または `accepted` の可能性がある | `approved` / `rejected` / `cancelled` |
| `no_candidate` | 候補者なし | 初回抽出または再抽出で、条件に合う代勤候補が見つからなかった状態。店長が確認・手動再抽出できる | `matching`（再抽出で候補発見）/ `rejected` |
| `approved` | 承認済み | 店長が代勤候補を承認した状態。`shifts.status = substituted` と対応する | `cancelled_after_approval` / `replacement_pending` |
| `rejected` | 却下済み | 店長が休み申請を却下した状態。対象シフトは元の担当者のまま `scheduled` に戻る | （終端） |
| `cancelled` | キャンセル済み | 店長承認前に、休み申請者本人が休み申請を取り消した状態 | （終端） |
| `cancelled_after_approval` | 承認後キャンセル済み | 店長承認後に、休み申請者本人が「やっぱり出勤できる」とキャンセル申請し、店長が承認した状態。`shifts.employee_id` は元の休み申請者へ戻り、`shifts.status = scheduled` になる | （終端） |
| `replacement_pending` | 代勤者再調整中 | 承認済みだった代勤者が承認後キャンセル申請を出し、店長が承認した状態。**元の休み申請者は引き続き休む前提**で、`shifts.employee_id` は変更しない。自動・手動再抽出の対象で、新しい代勤者が店長承認されると `approved` に戻る | `approved`（新代勤者承認）/ 維持 |

### shifts.status

| 状態名 | 表示名 | 意味 | 主な遷移先 |
|---|---|---|---|
| `scheduled` | 予定 | 通常の予定シフト。代勤確定前、または承認前キャンセル・却下で戻った状態 | `leave_requested` |
| `leave_requested` | 休み申請中 | 休み申請が登録され、店長処理待ちの状態 | `scheduled`（却下/キャンセル）/ `substituted`（承認） |
| `substituted` | 代勤反映済み | 店長承認により、`employee_id` が代勤者へ変更されたシフト。`leave_requests.status = approved` と対応する | `replacement_pending` |
| `cancelled` | キャンセル | 店長によりシフト自体が無効化された状態。通常一覧や候補抽出から除外される | （終端） |
| `replacement_pending` | 代勤者再調整中 | 承認済み代勤者のキャンセルが店長承認され、新しい代勤者を再調整している状態。`leave_requests.status = replacement_pending` と対応する。`employee_id` はキャンセルした代勤者のまま変更しない | `substituted`（新代勤者承認）/ 維持 |

### substitute_candidates.status

| 状態名 | 表示名 | 意味 |
|---|---|---|
| `proposed` | 未回答 | 代勤候補として抽出され、候補者への依頼中・回答待ちの状態 |
| `accepted` | 代勤可能 | 候補者が「代勤可能」と回答した状態 |
| `declined` | 代勤不可 | 候補者が「代勤不可」と回答した状態。同じ休み申請の再抽出では候補から除外される |
| `expired` | 無効 | 他候補者が承認された、申請がキャンセルされた、代勤者キャンセルが承認された等で無効化された状態。再抽出時、除外条件に該当しなければ `proposed` に再活性化される場合がある |

**重要：`accepted` は「店長承認済み」ではありません。** あくまで「候補者本人が代勤可能と回答した」状態です。店長の最終承認は `approvals` テーブルと `leave_requests.status = approved` / `shifts.status = substituted` で管理します。承認時、選ばれなかった他の `proposed`/`accepted` の候補者は `expired` に更新されます（`declined` の候補者はそのまま残ります）。

### cancellation_requests（承認後キャンセル申請）

`request_type`（申請種別）：

| 値 | 表示名 | 意味 |
|---|---|---|
| `requester_after_approval` | 休み申請者による承認後キャンセル | 休み申請者本人が「やっぱり出勤できる」と申請する種別。店長承認時：`shifts.employee_id` を元の休み申請者へ戻し、`shifts.status = scheduled`、`leave_requests.status = cancelled_after_approval` にする |
| `substitute_after_approval` | 代勤者による承認後キャンセル | 承認済み代勤者本人が「やっぱり代勤できない」と申請する種別。店長承認時：`shifts.employee_id` は**変更せず**、`leave_requests.status` / `shifts.status` を `replacement_pending` にし、代勤候補を再抽出する |

**この2種別は処理が大きく異なります。** 休み申請者側は「シフト担当者を元に戻す」処理、代勤者側は「シフト担当者は変えず再調整待ちにする」処理です。実装を変更する際は混同しないよう注意してください。

`status`（申請状態）：

| 値 | 表示名 | 意味 |
|---|---|---|
| `pending` | キャンセル申請中 | キャンセル申請が提出され、店長判断待ち |
| `approved` | キャンセル承認済み | 店長がキャンセル申請を承認した |
| `rejected` | キャンセル却下済み | 店長がキャンセル申請を却下した（代勤・休み申請の状態は維持される） |

### notifications.type（主なもの）

`notifications.type` はENUMではなくVARCHARです。実装が実際に通知作成で使っている値は以下の通りです。

| type | 表示名 | 用途・通知先 |
|---|---|---|
| `substitute_request` | 代勤依頼 | 代勤候補へ依頼通知（初回抽出・再抽出とも共通） |
| `candidate_available` | 代勤可能回答 | 候補者が「代勤可能」と回答 → 店長へ |
| `no_candidate` | 候補者なし | 初回抽出で候補者なし → 店長へ |
| `rematch_no_candidate` | 再抽出候補者なし | 再抽出しても候補者なし → 店長へ |
| `approval_result` | 承認結果 | 店長の承認/却下結果 → 休み申請者・代勤者へ |
| `leave_request_cancelled` | 申請キャンセル | 承認前キャンセル時の通知 → 候補者・店長へ |
| `after_approval_cancel_requested` | 承認後キャンセル申請 | 休み申請者側の承認後キャンセル申請 → 店長へ |
| `after_approval_cancel_approved` | 承認後キャンセル承認 | 休み申請者側キャンセルの承認結果 → 休み申請者・代勤者へ |
| `after_approval_cancel_rejected` | 承認後キャンセル却下 | 休み申請者側キャンセルの却下結果 → 休み申請者へ |
| `substitute_cancel_requested` | 代勤キャンセル申請 | 代勤者側の承認後キャンセル申請 → 店長へ |
| `substitute_cancel_approved` | 代勤キャンセル承認 | 代勤者側キャンセルの承認結果 → 代勤者へ |
| `substitute_cancel_rejected` | 代勤キャンセル却下 | 代勤者側キャンセルの却下結果 → 代勤者へ |
| `replacement_pending` | 代勤者再調整中 | 代勤者再調整中の通知 → 休み申請者へ（`leave_requests.status` と同名だが別の値） |

`leave_request` / `candidate_offer` という type は `database/seed.sql` のサンプル通知データにのみ残っている古い種別で、現在のアプリケーションコードはこれらの type で新規通知を作成しません（`app/includes/status_labels.php` には表示用ラベルのみ残しています）。新規実装でこの2種別を使わないでください。

### 状態遷移の概要

**休み申請（leave_requests.status）：**

```
pending → matching ─┬→ approved → cancelled_after_approval（休み申請者側キャンセル承認）
                     │         └→ replacement_pending ──→ approved（新代勤者を承認）
                     │                                  └→ replacement_pending（維持・再抽出を繰り返す）
                     ├→ no_candidate ──→ matching（手動再抽出で候補発見）
                     │                └→ no_candidate（維持）
                     ├→ rejected
                     └→ cancelled（承認前、本人が取消）
```

**シフト（shifts.status）：**

```
scheduled → leave_requested ─┬→ substituted ──→ replacement_pending ──→ substituted（新代勤者承認）
                              ├→ scheduled（却下・承認前キャンセル・休み申請者側キャンセル承認）
                              └→ cancelled（店長によるシフト無効化）
```

### 再抽出時の除外条件

代勤候補の再抽出（自動・手動）では、通常の必須条件（有効な従業員・勤務可能日時が一致・同時間帯のシフト重複なし）に加えて、以下を必ず除外します。

* 休み申請者本人
* 現在キャンセルした代勤者本人（自動再抽出時に明示的に除外）
* 同じ `leave_request_id` で過去に `declined`（代勤不可）と回答した従業員
* 同じ `leave_request_id` で `substitute_after_approval` のキャンセル申請が `approved` になった従業員（現在キャンセルした本人だけでなく、過去のキャンセル者も含む）

`expired` の候補者は、上記の除外条件に該当しなければ `proposed` に再活性化されて再候補になり得ますが、該当する場合は再候補にしません。詳細は「[今回実装した機能：代勤候補の再抽出（Step1）](#今回実装した機能代勤候補の再抽出step1)」を参照してください。

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
* 従業員側画面（メニュー、シフト確認、勤務可能日登録、休み申請、通知確認、代勤提案への応答、承認結果確認）
  * すべて `requireRole('employee')` でアクセス制限
  * メニュー画面にログイン中ユーザー名を表示
* 店長側：従業員情報管理・アカウント作成（`pages/manager/employees.php`）
  * 従業員情報（氏名・担当業務・備考など）の登録・編集
  * 従業員ごとのログインアカウント（`users` テーブル、role=employee）を同時に作成
  * ログインIDの重複チェック、必須項目チェック（同一画面にエラー表示）
  * 従業員の有効・無効切り替え（`is_active` による論理削除）
  * 従業員ごとの勤務可能日の確認（登録・編集は従業員本人のみ）
* 店長側：シフト作成・一覧確認（`pages/manager/shifts.php`）
  * シフトの新規登録（従業員・勤務日・時間帯・担当業務・備考）
  * 入力チェック（必須項目、開始時刻＜終了時刻、従業員の存在・有効性）
  * 登録済みシフトの一覧確認・無効化（論理削除）
* 従業員側：勤務可能日登録・一覧確認（`pages/employee/availability.php`）
  * ログイン中の従業員本人の勤務可能日の登録・一覧確認・削除
  * 入力チェック（必須項目、開始時刻＜終了時刻、重複時間帯の登録防止）
  * 他の従業員の勤務可能日は操作不可（`employee_id` で本人に限定）
* 従業員側：休み申請登録・一覧確認（`pages/employee/leave_request.php`）
  * ログイン中の従業員本人の「予定」シフトのみを申請対象として選択可能
  * 申請の重複登録防止
  * 申請登録直後に代勤候補抽出・通知作成を実行（`app/services/substitute_matcher.php`）
  * 店長処理前の申請（`matching` / `no_candidate`）を本人がキャンセル可能
  * キャンセル時は関連候補者を無効化し、対象シフトを「予定」に戻す
* 代勤候補抽出・通知作成サービス（`app/services/substitute_matcher.php`）
  * 勤務可能日・時間帯が一致する従業員を代勤候補として `substitute_candidates` に登録
  * 候補者へ「代勤依頼」通知、候補者がいない場合は店長へ「候補者なし」通知を作成
* 従業員側・店長側：通知確認画面（`pages/employee/notifications.php` / `pages/manager/notifications.php`）
  * ログイン中ユーザー宛の通知のみを表示し、既読/未読の確認・既読操作が可能
* 従業員側：代勤提案への応答（`pages/employee/candidate_response.php`）
  * 代勤依頼通知から、自分宛の代勤提案の詳細確認・「代勤可能」「代勤不可」の回答が可能
  * 「代勤可能」と回答した場合、店長へ確認用通知（`candidate_available`）を作成
* 店長側：承認画面（`pages/manager/approvals.php`）
  * 「候補者回答待ち」「候補者なし」の休み申請を一覧表示し、承認・却下を行う
  * 承認時：選択した代勤候補で代勤を確定し、対象シフトの担当者・状態を更新する
  * 却下時：休み申請を却下し、対象シフトを元の担当者の「予定」に戻す
  * 処理結果を `approvals` テーブルに記録し、関係者へ承認結果通知を作成する
* 承認結果通知作成サービス（`app/services/substitute_matcher.php` の `createApprovalResultNotifications()` / `insertNotificationForEmployee()`）
* 従業員側：承認結果確認（`pages/employee/result.php`）
  * 自分の休み申請状態と承認結果通知を一覧表示
  * 承認済み休み申請に対する「承認後キャンセル申請」を作成
* 承認後キャンセル申請（`cancellation_requests` / `app/services/cancellation_request_service.php`）
  * 休み申請者本人が「やっぱり出勤できる」場合に、店長承認が必要なキャンセル申請を作成
  * 店長承認時はシフト担当者を代勤者から元の休み申請者へ戻す
  * 店長却下時は現在の代勤状態を維持
  * 店長メニュー・通知画面から専用確認画面へ移動可能
* 従業員側：シフト確認（`pages/employee/shifts.php`）
  * ログイン中の従業員本人に割り当てられたシフト（無効化済みを除く）を表示
  * 代勤承認により担当者が自分に変更されたシフト（`substituted`）もここに表示される
* 状態表示の整理（`app/includes/status_labels.php`）
  * 休み申請・代勤候補・シフト・通知種別の状態を、各画面で英語のまま表示せず日本語ラベル＋状態バッジで表示
  * ラベルとバッジ色を共通ヘルパーに集約し、画面間で表示がぶれないようにした
* 店長側：代勤候補抽出モード選択・スコア計算（Step1）（`pages/manager/matching_settings.php`、`app/services/substitute_matcher.php`）
  * 「通常」「人員確保優先」「スキル重視」の3モードから抽出モードを選択・保存
  * 休み申請登録時点のモードを `leave_requests.matching_mode` に保存
  * モードごとの重み付けで代勤候補のスコア（`match_score`）・抽出理由（`match_reason`）を計算し保存
  * 承認画面でスコア・抽出理由・スキルレベル・勤続を表示
  * 詳細は「[今回実装した機能：代勤候補抽出モード選択・スコア計算（Step1）](#今回実装した機能代勤候補抽出モード選択スコア計算step1)」を参照
* 代勤候補の再抽出（Step1）（`app/services/substitute_matcher.php` の `retrySubstituteMatching()`、`pages/manager/rematch_leave_request.php`）
  * 代勤者キャンセル承認時に自動で代勤候補を再抽出（キャンセルした代勤者を除外）
  * 店長が `no_candidate` / `replacement_pending` の休み申請を手動で再抽出可能
  * 過去に `declined` と回答した従業員・休み申請者本人を除外し、既存候補（expired）は再活性化
  * 詳細は「[今回実装した機能：代勤候補の再抽出（Step1）](#今回実装した機能代勤候補の再抽出step1)」を参照

## 従業員管理・シフト・勤務可能日機能の使い方

基本フロー「1. 店長が従業員情報とシフトを登録する → 2. 従業員が勤務可能日を登録する → 3. 店長が確認する」に対応する画面を実装しました。

### 1. 店長側：従業員情報管理・アカウント作成（`pages/manager/employees.php`）

* 店長メニューの「従業員情報管理」から開きます。
* 「従業員の新規登録」フォームに以下を入力して登録します。
  * 氏名（必須）
  * ログインID（必須・重複不可）
  * 初期パスワード（必須）
  * 担当可能業務・ポジション
  * 備考
* 登録すると、`employees` テーブルへの従業員情報の登録と、`users` テーブルへのログインアカウント作成（role=employee、パスワードは `password_hash()` でハッシュ化）が同時に行われます（トランザクション）。
* ログインIDが既に使われている場合や、氏名・ログインID・パスワードが未入力の場合は、画面上にエラーメッセージが表示されます（別画面には遷移しません）。
* 一覧の「編集」リンクから、氏名・メールアドレス・電話番号・入社日・担当業務・備考を編集できます。
* 一覧の「無効化／有効化」ボタンで、従業員を論理削除（`is_active` フラグ）できます。退職者などのデータを物理削除せずに非表示扱いにします。

### 2. 店長側：従業員ごとの勤務可能日確認（`pages/manager/employees.php`）

* 「従業員情報管理」画面下部の「勤務可能日の確認」セクションで、従業員をプルダウンから選択すると、その従業員が登録した勤務可能日（日付・開始時刻・終了時刻・備考）の一覧が表示されます。
* 店長はこの画面で勤務可能日を**登録・編集することはできません**（登録は従業員本人が行います）。

### 3. 従業員側：勤務可能日登録・一覧確認（`pages/employee/availability.php`）

* 従業員メニューの「勤務可能日登録」から開きます。
* 「勤務可能日の登録」フォームに、勤務可能日・開始時刻・終了時刻・備考を入力して登録します。
* 入力チェック：
  * 勤務可能日・開始時刻・終了時刻は必須です。
  * 開始時刻は終了時刻より前である必要があります。
  * 同じ日付・時間帯に重複する登録がある場合はエラーになります（重複をある程度防止）。
* エラーがある場合は同じ画面上にメッセージが表示されます。
* 登録した勤務可能日は一覧表示され、「削除」ボタンで削除できます。
* ログイン中の従業員は自分自身の勤務可能日のみ登録・削除でき、他の従業員のデータは操作できません。

### 4. 店長側：シフト作成・一覧確認（`pages/manager/shifts.php`）

* 店長メニューの「シフト作成・一覧確認」から開きます。
* 「シフトの新規作成」フォームに、従業員・勤務日・開始時刻・終了時刻・担当業務・備考を入力して登録します。
* 入力チェック：
  * 従業員・勤務日・開始時刻・終了時刻は必須です。
  * 開始時刻は終了時刻より前である必要があります。
  * 存在しない従業員・無効化された従業員は選択できません。
* エラーがある場合は同じ画面上にメッセージが表示されます。
* 登録済みシフトは一覧表示され（勤務日・開始時刻・終了時刻・従業員名・担当業務・備考・状態）、「無効化」ボタンで論理削除（`status = 'cancelled'`）できます。

## 今回実装した機能：代勤候補抽出・通知作成

休み申請の登録をトリガーとして、代勤候補を自動抽出し、関係者へ通知を作成する機能を実装しました。

### 現在の暫定抽出条件

休み申請されたシフトに対して、以下をすべて満たす従業員を代勤候補とします（`app/services/substitute_matcher.php` の `findSubstituteCandidates()`）。

1. 休み申請を出した本人ではない
2. 従業員が有効状態（`is_active = 1`）である
3. 休み申請対象のシフト日と勤務可能日（`available_date`）が一致している
4. 勤務可能時間（`start_time`〜`end_time`）が対象シフトの勤務時間を覆っている
5. 同じ日に時間帯が重複する別シフトが入っていない
6. 既に同じ休み申請の候補者として登録済みでない

上記1〜6は**必須条件**で、モードに関わらず必ず適用されます（必須条件を1つでも満たさない従業員は、そもそも候補者になりません）。

抽出された候補者は `substitute_candidates` テーブルに、以下の情報とともに登録されます。

* `match_score`：候補者の適合度スコア（現在の抽出モードに応じて計算。詳細は後述の「[今回実装した機能：代勤候補抽出モード選択・スコア計算（Step1）](#今回実装した機能代勤候補抽出モード選択スコア計算step1)」を参照）
* `match_reason`：抽出理由（スコア計算の内訳から自動生成される文言）
* `matched_at`：候補者として抽出された日時

### 候補者ありの場合の処理

1. 該当する従業員を `substitute_candidates` に登録する。
2. 候補者（の `users` アカウント）へ、種別 `substitute_request`・タイトル「代勤依頼が届いています」の通知を作成する。
3. 休み申請（`leave_requests`）の `status` を `matching`（候補者回答待ち）に更新する。

### 候補者なしの場合の処理

1. 店長（`users.role = 'manager'`）へ、種別 `no_candidate`・タイトル「代勤候補が見つかりません」の通知を作成する。
2. 休み申請（`leave_requests`）の `status` を `no_candidate`（候補者なし・店長確認待ち）に更新する。

候補者・通知ともに、同じ休み申請に対して重複して登録・作成されないようにチェックしています。

### 従業員側：休み申請登録の使い方（`pages/employee/leave_request.php`）

* 従業員メニューの「休み申請」から開きます。
* 「休み申請」フォームで、申請可能な自分のシフト（状態が「予定」のもの）を選択し、申請理由を入力して申請します。
* 申請すると、自動で代勤候補抽出が行われ、画面下部の「申請済みの休み申請」一覧に状態（候補者回答待ち／候補者なし（店長確認待ち）など）が表示されます。
* 既に休み申請済みのシフトは選択肢から除外されるため、重複申請はできません。
* 店長が承認・却下する前の申請には「キャンセルする」ボタンが表示されます。詳細は次の「休み申請キャンセル（承認前のみ）」を参照してください。

## 今回実装した機能：休み申請キャンセル（承認前のみ）

休み申請者本人が、店長の承認・却下前に限って自分の休み申請をキャンセルできる機能を実装しました。

### キャンセルできる条件

以下をすべて満たす場合のみキャンセルできます。

* ログイン中の従業員本人が出した休み申請である
* 休み申請の状態が `matching`（候補者回答待ち）または `no_candidate`（候補者なし）である
* 店長がまだ承認・却下していない

本システムでは、設計資料上の「候補者回答待ち」に相当する実際のDB値として `matching` を使用しています。
候補者が既に「代勤可能」（`accepted`）と回答していても、店長承認前であれば休み申請者本人がキャンセルできます。

以下の申請はキャンセルできません。

* `approved`（承認済み）
* `rejected`（却下済み）
* `cancelled`（既にキャンセル済み）
* 他の従業員が出した申請
* 存在しない休み申請

### キャンセル時の処理

「キャンセルする」を押すと、トランザクション内で以下を行います。

1. 休み申請が本人のもので、状態が `matching` / `no_candidate` のどちらかであることを再確認する。
2. `leave_requests.status` を `cancelled` に更新する。
3. 関連する `substitute_candidates` は、未回答・回答済みを問わず**全候補者を `expired`** にして無効化する。
4. 対象シフトの `shifts.status` を `leave_requested` から `scheduled`（予定）へ戻す。
5. 代勤候補者へ「代勤依頼がキャンセルされました」、店長へ「休み申請がキャンセルされました」という `leave_request_cancelled` 通知を作成する。

候補者向けの元の代勤依頼通知には「キャンセル済み・回答不要」と表示されます。
古い通知URLから応答画面を開いても、キャンセル済みであることを表示し、「代勤可能」「代勤不可」の回答は受け付けません。

店長承認画面では、キャンセル済み申請を「未処理の休み申請」には表示せず、「処理済みの休み申請」に「キャンセル済み」として表示します。キャンセル済み申請に対する承認・却下操作はできません。

### 承認前キャンセルで実装していない内容

* 代勤者側からのキャンセル
* キャンセル後の再マッチング
* メール・LINE・プッシュ通知
* 上位候補者だけへの通知や段階通知

## 今回実装した機能：休み申請者による承認後キャンセル申請

店長が休み申請を承認し、対象シフトの担当者が代勤者へ変更された後に、休み申請者が「出勤できるようになった」と申し出るための機能です。
承認前キャンセルとは異なり、申請しただけではシフトを変更せず、店長の承認を必須とします。

### `cancellation_requests` テーブル

承認後キャンセル申請は `leave_requests.status` だけで管理せず、専用の `cancellation_requests` テーブルへ保存します。

主な項目：

* `leave_request_id`：対象の休み申請
* `request_type`：申請種別。今回は `requester_after_approval`
* `requested_by_employee_id`：申請した従業員
* `reason`：キャンセル理由
* `status`：`pending` / `approved` / `rejected`
* `decided_by_user_id` / `decided_at`：判断した店長と処理日時

`request_type = substitute_after_approval` を追加し、代勤者側のキャンセル申請にも対応しました（後述「代勤者による承認後キャンセル申請」を参照）。

### 従業員による申請

承認結果確認画面（`pages/employee/result.php`）で、次の条件をすべて満たす場合に「キャンセル申請を送る」フォームを表示します。

* ログイン中の従業員本人が出した休み申請である
* `leave_requests.status = approved`
* 対象シフトが `substituted` で、現在の担当者が代勤者である
* 同じ休み申請に `pending` のキャンセル申請がない

作成時は休み申請行を `FOR UPDATE` でロックし、pending の重複を再確認してから `cancellation_requests` に保存します。申請中も `leave_requests.status` は `approved` のままで、店長へ `after_approval_cancel_requested` 通知を作成します。

### 店長による承認

店長メニューの「キャンセル申請確認」（`pages/manager/cancellation_requests.php`）から処理します。
承認時はトランザクション内で以下を行います。

1. pending のキャンセル申請と、対象の休み申請・シフトを行ロックする。
2. `request_type = requester_after_approval`、休み申請が `approved`、シフトが `substituted` であることを確認する。
3. `shifts.employee_id` を元の休み申請者へ戻す。
4. `shifts.status` を `scheduled` に戻す。
5. `leave_requests.status` を `cancelled_after_approval` に更新する。
6. `cancellation_requests.status` を `approved` にし、店長ID・処理日時を保存する。
7. 休み申請者へ「休み申請のキャンセルが承認されました」、代勤者へ「代勤予定がキャンセルされました」という通知を作成する。

承認後は元の休み申請者の「シフト確認」に対象シフトが再表示され、代勤者の一覧からは対象シフトが外れます。

### 店長による却下

却下時は `cancellation_requests.status` のみを `rejected` に更新し、店長ID・処理日時を保存します。
`leave_requests.status = approved`、`shifts.employee_id`、`shifts.status = substituted` は変更せず、現在の代勤状態を維持します。休み申請者には `after_approval_cancel_rejected` 通知を作成します。

### 状態表示

* `leave_requests.cancelled_after_approval`：承認後キャンセル済み
* `cancellation_requests.pending`：キャンセル申請中
* `cancellation_requests.approved`：キャンセル承認済み
* `cancellation_requests.rejected`：キャンセル却下済み

### この機能（休み申請者側）で実装していない内容

* キャンセルに伴う再マッチング
* 店長承認なしの即時キャンセル
* メール・LINE・プッシュ通知
* 上位候補者通知・段階通知

## 今回実装した機能：代勤者による承認後キャンセル申請

店長が休み申請を承認し、対象シフトの担当者が代勤者へ変更された後に、**代勤者本人**が「やっぱり代勤できない」と申し出るための機能です。
休み申請者側キャンセルとは処理が異なります。休み申請者側は「やっぱり出勤できる」という意味のため、シフト担当者を元の休み申請者へ戻せば成立します。
一方、代勤者側キャンセルでは**元の休み申請者は引き続き休む前提**のため、店長が承認しても**シフト担当者を元の休み申請者へは戻さず**、対象シフト・休み申請を「代勤者再調整中」（`replacement_pending`）として扱います。
今回は自動再マッチングまでは実装せず、店長が手動で再調整する前提です。

### `cancellation_requests.request_type = substitute_after_approval`

代勤者側キャンセルは、既存の `cancellation_requests` テーブルに `request_type = substitute_after_approval` として保存します。
休み申請者側（`requester_after_approval`）と同じテーブルを使い、申請種別で区別します。承認・却下の処理は種別ごとに分離しています（`app/services/cancellation_request_service.php`）。

### 代勤者による申請（`pages/employee/shifts.php`）

代勤者本人のシフト確認画面で、次の条件をすべて満たす代勤シフトに「代勤キャンセル申請を送る」フォームを表示します。

* `leave_requests.status = approved`
* 対象シフトが `substituted` で、現在の担当者がログイン中の代勤者本人である
* ログイン中の従業員が休み申請者本人ではない
* 対象休み申請に承認済みの `approvals` が存在する
* 同じ休み申請に `substitute_after_approval` かつ `pending` のキャンセル申請がない

作成時は休み申請行を `FOR UPDATE` でロックし、pending の重複を再確認してから保存します。申請中も代勤状態（`leave_requests.status = approved` / `shifts.status = substituted`）は変えず、店長へ `substitute_cancel_requested` 通知を作成します。

### 店長による承認（`pages/manager/cancellation_requests.php`）

店長メニューの「キャンセル申請確認」から処理します。申請種別が `substitute_after_approval` の場合、承認時はトランザクション内で以下を行います。

1. pending のキャンセル申請と、対象の休み申請・シフトを行ロックする。
2. `request_type = substitute_after_approval`、休み申請が `approved`、シフトが `substituted`、シフト担当者がキャンセル申請者本人であることを確認する。
3. 承認済みだった代勤候補（`substitute_candidates`）の状態を `expired` にする。
4. `leave_requests.status` を `replacement_pending`（代勤者再調整中）に更新する。
5. `shifts.status` を `replacement_pending` に更新する。**`shifts.employee_id` は変更しない（元の休み申請者へ戻さない）。**
6. `cancellation_requests.status` を `approved` にし、店長ID・処理日時を保存する。
7. 代勤者へ `substitute_cancel_approved`（代勤キャンセル承認）、休み申請者へ `replacement_pending`（代勤者再調整中）の通知を作成する。

承認後、店長のシフト一覧では対象シフトが「代勤者再調整中」と表示され、代勤者のシフト確認画面では「代勤キャンセル承認済み・担当から外れました」と表示されます。

### 店長による却下

却下時は `cancellation_requests.status` のみを `rejected` に更新し、店長ID・処理日時を保存します。
`leave_requests.status = approved`、`shifts.employee_id`、`shifts.status = substituted` は変更せず、**現在の代勤状態を維持**します。代勤者へ `substitute_cancel_rejected`（代勤キャンセル却下）通知を作成します。

### 状態表示

* `leave_requests.replacement_pending` / `shifts.replacement_pending`：代勤者再調整中
* `cancellation_requests.request_type = substitute_after_approval`：代勤者による承認後キャンセル
* 通知種別 `substitute_cancel_requested` / `substitute_cancel_approved` / `substitute_cancel_rejected` / `replacement_pending`

### この機能（代勤者側）で実装していない内容

* 代勤者キャンセル後の自動再マッチング・新しい代勤候補の自動抽出
* 上位3人通知・段階通知
* 店長承認なしの即時キャンセル
* 承認時にシフト担当者を元の休み申請者へ戻す処理（仕様上、戻しません）
* メール・LINE・プッシュ通知

### 動作確認手順（代勤者による承認後キャンセル）

承認フロー：

1. 従業員が休み申請を行い、代勤候補が「代勤可能」と回答し、店長が承認する（対象シフトが代勤者へ変更され `substituted` になる）。
2. 代勤者が「シフト確認」画面で、対象代勤シフトの「代勤キャンセル申請を送る」から申請する。
3. 店長の「通知確認」に `substitute_cancel_requested`（代勤キャンセル申請）通知が届く。
4. 店長が「キャンセル申請確認」で当該申請を承認する。
5. 対象休み申請が `replacement_pending`、対象シフトが `replacement_pending` になる（シフト担当者は元の休み申請者へ戻らない）。
6. 代勤者へ「代勤キャンセルが承認されました」、休み申請者へ「代勤者が再調整中になりました」通知が届く。
7. 店長のシフト一覧で対象シフトが「代勤者再調整中」と表示される。

却下フロー：

1. 承認済み代勤に対して代勤者がキャンセル申請を出す。
2. 店長が「キャンセル申請確認」で却下する。
3. シフト担当者は代勤者のまま、`leave_requests.status = approved` / `shifts.status = substituted` が維持される。
4. 代勤者へ「代勤キャンセルが却下されました」通知が届く。

不正操作の確認（いずれも実行できないこと）：

* 他人の代勤にキャンセル申請を出す
* 休み申請者本人が代勤者側キャンセル申請を出す
* 未承認の代勤・`replacement_pending` のシフトに申請を出す
* 同じ休み申請に pending の代勤者キャンセル申請を重複作成する
* 従業員が店長側のキャンセル承認・却下処理を実行する（`requireRole('manager')` でブロック）
* 処理済みのキャンセル申請を再承認・再却下する

## 今回実装した機能：代勤候補の再抽出（Step1）

一度抽出した代勤候補が確保できなくなった場合に、**既存の抽出条件・スコア計算を再利用して代勤候補を再抽出**する機能です。

* **代勤者キャンセル承認時の自動再抽出**：代勤者の承認後キャンセルを店長が承認した直後（`leave_requests.status = replacement_pending` になった時点）に、自動で代勤候補を再抽出します。
* **店長による手動再抽出**：`no_candidate`（初回に候補者がいなかった）／`replacement_pending`（代勤者キャンセル後）の休み申請に対して、店長が任意のタイミングで再抽出できます。初回以降に勤務可能日が追加されている可能性があるためです。

実装は `app/services/substitute_matcher.php` の `retrySubstituteMatching()` を中心に、`findSubstituteCandidatesForRetry()` / `getDeclinedEmployeeIdsForLeaveRequest()` / `getExistingCandidateStatus()` / `createOrReactivateCandidate()` / `createRematchNoCandidateNotification()` に分離しています。

### 再抽出時の除外条件

通常の必須条件（有効な従業員・勤務可能日/時間が一致・同時間帯にシフト重複なし）に加えて、以下を必ず除外します。

* 休み申請者本人
* キャンセルした代勤者本人（自動再抽出時に明示的に除外）
* 同じ休み申請で過去に `declined`（代勤不可）と回答した従業員
* 店長による手動再抽出では、対象シフトの現在の担当者（`replacement_pending` ではキャンセルした代勤者、`no_candidate` では休み申請者本人）

### 既存 `substitute_candidates` レコードの扱い

同じ休み申請・従業員の候補レコードが既にある場合は、新規作成せず安全に再利用します。

* `declined`：再抽出対象から除外（再通知しない）
* `proposed`：依頼中のため維持（重複通知を作成しない）
* `accepted`：回答済みのため維持し、店長承認画面に表示（重複通知を作成しない）
* `expired`：除外対象でなければ `proposed` に戻し、`responded_at` を `NULL`、`match_score` / `match_reason` を再計算して再提案する

新たに `proposed` になった（新規作成・再活性化した）候補者にのみ、既存の代勤依頼通知（`substitute_request`）を作成します。同じ候補者に同じ休み申請の代勤依頼通知が既にある場合は、重複作成しません。

### 候補者が見つからなかった場合

候補者が1人も確保できなかった場合は、店長へ `rematch_no_candidate`（再抽出候補者なし）通知を作成し、手動対応を促します。状態は維持します（`no_candidate` は `no_candidate` のまま、`replacement_pending` は `replacement_pending` のまま）。

### 状態遷移

* `no_candidate` で再抽出 → 候補者が見つかれば `matching`（候補者回答待ち）に戻す／見つからなければ `no_candidate` を維持
* `replacement_pending` で再抽出 → 候補者の有無にかかわらず `replacement_pending` を維持（新しい代勤者は店長承認時に確定するため）
* `replacement_pending` の休み申請について、再抽出後の候補者が「代勤可能」と回答し、店長が承認すると、`leave_requests.status = approved` / `shifts.status = substituted` / `shifts.employee_id = 新しい代勤者` に戻ります（承認時に他の候補者は `expired`、関係者へ結果通知）。

### 画面

* 店長の承認画面（`pages/manager/approvals.php`）に、`no_candidate` / `replacement_pending` の休み申請へ「代勤候補を再抽出」ボタンを追加しました（再抽出のPOSTは `pages/manager/rematch_leave_request.php` が処理します）。`replacement_pending` の休み申請も未処理一覧に表示され、再抽出後に `accepted` になった候補者をそのまま承認できます。
* 代勤提案への応答画面（`pages/employee/candidate_response.php`）は、`replacement_pending` の休み申請に紐づく候補者も回答できるようにしました（`approved` / `rejected` / `cancelled` などの完了状態は回答不可のまま）。

### 今回実装していない内容

* 上位3人通知・段階通知
* 全員拒否後に自動で次グループへ通知する機能（`matching_round` / `proposal_round` などのラウンド管理）
* 外部通知（メール・LINE・プッシュ）
* 店長承認なしの自動シフト変更（再抽出で候補者が見つかっても、その時点では確定せず、店長承認で初めてシフト担当者を変更します）

### 動作確認手順（再抽出）

自動再抽出フロー：

1. 従業員Aが休み申請を行い、従業員Bが「代勤可能」と回答、店長がBを承認する（シフトがBへ変更され `substituted`）。
2. BがBの「シフト確認」から代勤キャンセル申請を出し、店長がそれを承認する。
3. `leave_requests.status` / `shifts.status` が `replacement_pending` になり、**直後に自動で再抽出**される。
4. 条件に合う別の従業員Cがいれば、Cへ代勤依頼通知が届く。いなければ店長へ「再抽出候補者なし」通知が届く。
5. Cが「代勤可能」と回答 → 店長承認画面にCが表示 → 店長がCを承認すると `approved` / `substituted` / 担当者=C に戻る。

手動再抽出フロー（`no_candidate`）：

1. 候補者がいない休み申請（`no_candidate`）を用意する。
2. 店長が承認画面の「代勤候補を再抽出」を押す。
3. 候補者がいれば `matching` に戻り候補者へ通知、いなければ `no_candidate` のまま店長へ通知。

手動再抽出フロー（`replacement_pending`）：

1. 代勤者キャンセル承認で `replacement_pending` の休み申請を用意する。
2. 店長が承認画面の「代勤候補を再抽出」を押す。
3. 候補者がいれば候補者へ通知（状態は `replacement_pending` のまま）、いなければ店長へ通知。

### 従業員向け通知確認の使い方（`pages/employee/notifications.php`）

* 従業員メニューの「通知確認」から開きます。
* ログイン中の従業員本人宛の通知のみが、日時の新しい順に一覧表示されます。
* 代勤依頼（`substitute_request`）の通知には「代勤可否の回答画面は次工程で実装予定です」という案内が表示されます（今回は回答処理は実装していません）。
* 未読の通知は「既読にする」ボタンで既読にできます。

### 店長向け通知確認の使い方（`pages/manager/notifications.php`）

* 店長メニューの「通知確認」から開きます。
* ログイン中の店長宛の通知（休み申請、候補者なし通知、代勤可能回答など）が、日時の新しい順に一覧表示されます。
* 未読の通知は「既読にする」ボタンで既読にできます。
* 「承認画面へ」から承認画面（`approvals.php`）へ遷移できますが、承認処理自体は今回実装していません。

## 今回実装した機能：代勤提案への応答

代勤候補となった従業員が、代勤依頼通知から自分宛の代勤提案を確認し、「代勤可能」または「代勤不可」を回答できる機能を実装しました。

### 通知確認画面から応答画面への流れ

1. 従業員向け通知確認画面（`pages/employee/notifications.php`）で、種別「代勤依頼」（`substitute_request`）の通知に、自分自身の代勤提案（`substitute_candidates`）の状態が表示されます。
   * 未回答の場合：「未回答」バッジと「回答する」リンクが表示されます。
   * 回答済みの場合：「代勤可能と回答済み」または「代勤不可と回答済み」バッジと「詳細を見る」リンクが表示されます（履歴として確認可能）。
2. 「回答する」（または「詳細を見る」）リンクから `pages/employee/candidate_response.php?candidate_id=○○` へ遷移します。`candidate_id` は `substitute_candidates.id` です。
3. 応答画面では、以下を表示します。
   * 休み申請者の氏名
   * 勤務日・開始時刻・終了時刻・担当業務・ポジション
   * 申請理由
   * マッチ理由（`match_reason`、登録されている場合のみ）
   * 回答状況（未回答／代勤可能と回答済み／代勤不可と回答済み）
4. 未回答の場合のみ「代勤可能」「代勤不可」ボタンが表示されます。回答済みの場合はボタンを表示せず、「この提案には既に回答済みです（再回答はできません）。」と表示します。

### 「代勤可能」「代勤不可」の意味と保存内容

* 「代勤可能」（`available`）：その代勤に対応できることを回答します。`substitute_candidates.status` を `accepted` に更新します。
* 「代勤不可」（`unavailable`）：その代勤に対応できないことを回答します。`substitute_candidates.status` を `declined` に更新します。
* いずれの場合も、回答日時を `substitute_candidates.responded_at` に保存します。
* `substitute_candidates.status` の値は既存定義（`proposed`/`accepted`/`declined`/`expired`）をそのまま利用しており、本機能では以下のように対応させています。
  * `proposed`：未回答
  * `accepted`：代勤可能と回答済み（回答画面の `response=available` に対応）
  * `declined`：代勤不可と回答済み（回答画面の `response=unavailable` に対応）
  * `expired`：無効（他候補者の承認や、休み申請のキャンセルにより回答不要になった状態）
* `status = 'proposed'` の代勤提案のみ更新対象とし、既に `accepted`/`declined` になっている提案は再回答できません（更新は `id` と `candidate_employee_id`（本人）と `status = 'proposed'` を条件としたUPDATEで、対象行が無い場合はエラーメッセージを表示します）。
* 回答処理が成功すると、画面上に成功メッセージ（「『代勤可能』で回答しました。店長に通知しました。」または「『代勤不可』で回答しました。」）が表示されます。

### 店長への通知作成

* 「代勤可能」と回答された場合、店長（`users.role = 'manager'`）宛に、種別 `candidate_available`・タイトル「代勤可能な候補者が回答しました」の通知を作成します（`createCandidateAvailableNotification()`、`app/services/substitute_matcher.php`）。
  * 通知本文の例：「6月14日のシフトについて、鈴木 次郎さんが代勤可能と回答しました。承認画面で確認してください。」
  * 同一の代勤候補回答に対して、同じ内容の通知が重複して作成されないようにチェックしています。
* 「代勤不可」と回答された場合は、店長への通知は作成しません。`substitute_candidates.status` が `declined` に更新されるのみです（店長側で代勤候補一覧などを確認する機能は今回の実装範囲外です）。

### 入力チェック・安全対策

* 未ログインユーザーがアクセスするとログイン画面へ転送されます。
* `employee` 以外のロール（店長）がアクセスすると、自分のメニューへ転送されます。
* `candidate_id` を他人のものに書き換えてアクセスしても、`candidate_employee_id`（ログイン中の従業員自身）を条件にしているため、他人の代勤提案は閲覧・回答できません（「指定された代勤提案が見つかりません」と表示）。
* 存在しない `candidate_id` を指定した場合も同様に「指定された代勤提案が見つかりません」と表示されます。
* SQLはすべてプリペアドステートメントを使用し、回答処理（ステータス更新＋通知作成）はトランザクションで実行します。

## 今回実装した機能：店長承認・結果通知

代勤候補からの回答内容を踏まえて店長が休み申請を承認・却下し、関係者へ結果を通知する機能を実装しました。

### `leave_requests.status` の状態遷移

| status | 意味 |
|---|---|
| `pending` | 受付中（休み申請登録直後の一時的な状態。直後に `processSubstituteMatching()` が呼ばれ、`matching` または `no_candidate` に変わるため、通常はこの状態でDBに残りません） |
| `matching` | 代勤候補回答待ち（候補者へ代勤依頼を通知済み。店長の承認・却下待ち） |
| `no_candidate` | 候補者なし（条件に合う代勤候補が見つからなかった。店長の確認・却下待ち） |
| `approved` | 承認済み（店長が代勤候補を承認し、代勤が確定した） |
| `rejected` | 却下（店長が休み申請を却下した） |
| `cancelled` | キャンセル済み（休み申請者本人が店長処理前に取り消した） |
| `cancelled_after_approval` | 承認後キャンセル済み（店長が休み申請者側の承認後キャンセル申請を承認し、元の担当者へ戻した） |
| `replacement_pending` | 代勤者再調整中（店長が代勤者側の承認後キャンセル申請を承認した。元の休み申請者は引き続き休む前提で、店長が代勤者を再調整する） |

`approvals.php` の「未処理の休み申請」には `matching` / `no_candidate` の休み申請が、「処理済みの休み申請」には `approved` / `rejected` / `cancelled` / `cancelled_after_approval` の休み申請が表示されます。

### 1. 店長側：承認画面の使い方（`pages/manager/approvals.php`）

* 店長メニューの「通知確認」→「承認画面へ」、または通知確認画面の「代勤可能な候補者が回答しました」通知にある「承認画面で確認する」リンク（該当の休み申請までジャンプします）から開きます。
* **未処理の休み申請**：休み申請者・勤務日・開始時刻・終了時刻・担当業務・ポジション・申請理由・状態（候補者回答待ち／候補者なし）と、代勤候補一覧（候補者名・回答状況・マッチ理由）を表示します。
  * 「代勤可能と回答済み」の候補者には「この候補者で承認」ボタンが表示されます。ボタンを押すと、その候補者で代勤を確定します。
  * 候補者が見つからない場合や、全員が未回答・代勤不可の場合は承認ボタンが表示されません。
  * いずれの場合も「この休み申請を却下」ボタンから休み申請を却下できます。
* **処理済みの休み申請**：処理日時・休み申請者・対象シフト・結果（承認済み／却下）・代勤対応者（承認時のみ）を一覧表示します。

### 2. 承認時の処理

「この候補者で承認」を押すと、以下をトランザクション内で実行します。

1. `approvals` テーブルに承認結果を記録する（`leave_request_id`, `substitute_candidate_id`=選択した候補者のID, `manager_id`, `status='approved'`, `approved_at=NOW()`）。
2. `leave_requests.status` を `approved` に更新する。
3. 対象シフト（`shifts`）の `employee_id` を選択した代勤候補の従業員IDに変更し、`status` を `substituted`（「代勤対応済み」）に更新する。
4. 選ばれなかった他の候補者（`status` が `proposed`/`accepted` のもの）を `expired` にする。「代勤不可」と回答済み（`declined`）の候補者はそのまま残す。
5. 休み申請者と代勤対応者の双方へ、種別 `approval_result` の通知を作成する（`createApprovalResultNotifications()`）。

### 3. 却下時の処理

「この休み申請を却下」を押すと、以下をトランザクション内で実行します。

1. `approvals` テーブルに却下結果を記録する（`substitute_candidate_id=NULL`, `manager_id`, `status='rejected'`, `approved_at=NOW()`）。
2. `leave_requests.status` を `rejected` に更新する。
3. 対象シフト（`shifts`）の `status` を `scheduled`（元の担当者のまま「予定」）に戻す。
4. 候補者（`status` が `proposed`/`accepted` のもの）を `expired` にする。
5. 休み申請者へのみ、種別 `approval_result` の通知を作成する（`createApprovalResultNotifications()`）。代勤対応者は確定しないため通知しません。

### 4. 結果通知作成（`app/services/substitute_matcher.php`）

* `createApprovalResultNotifications($pdo, $leaveRequestId, $result, $approvedCandidateEmployeeId = null)`
  * `$result = 'approved'` の場合：休み申請者へ「休み申請が承認されました」、代勤対応者へ「代勤対応が確定しました」の通知を作成します。
  * `$result = 'rejected'` の場合：休み申請者へ「休み申請が却下されました」の通知のみを作成します。
* `insertNotificationForEmployee($pdo, $employeeId, $type, $title, $message, $leaveRequestId)`
  * 指定した従業員（`employees.id`）に対応する `users` アカウントへ通知を作成する共通ヘルパーです。

### 5. 従業員側：承認結果確認画面の使い方（`pages/employee/result.php`）

* 従業員メニューの「承認結果確認」から開きます。
* ログイン中の従業員本人宛の `approval_result` 通知のみを、日時の新しい順に一覧表示します。
* 各行には、日時・勤務日・開始時刻・終了時刻・担当業務・ポジション・申請状態（承認済み／却下など）・タイトル・内容を表示します。
* `related_leave_request_id` が無い通知（例：サンプルデータの通知）は、勤務日などの項目を「-」で表示します。

### 6. 店長向け通知確認画面の更新（`pages/manager/notifications.php`）

* 種別「代勤可能回答」（`candidate_available`）の通知に、「承認画面で確認する」リンクを追加しました。
* リンク先は `approvals.php#lr-{対象の休み申請ID}` で、対象の休み申請までスクロールします（`related_leave_request_id` が無い場合は `approvals.php` への単純なリンクになります）。

### 安全対策

* `pages/manager/approvals.php` は `requireRole('manager')` でアクセス制限しています。未ログイン・従業員ロールでのアクセスはそれぞれログイン画面・従業員メニューへ転送されます。
* 承認・却下処理は `SELECT ... FOR UPDATE` を用いたトランザクション内で実行し、既に `approved`/`rejected` になっている休み申請を再処理できないようにしています（二重処理防止）。
* 承認時に指定する `candidate_id` は、対象の休み申請に紐づく `status = 'accepted'` の候補者であることを確認してからのみ処理します。条件を満たさない場合は「選択された候補者は『代勤可能』と回答していないか、存在しません。」と表示します。
* SQLはすべてプリペアドステートメントを使用します。

## 今回実装した機能：代勤候補抽出モード選択・スコア計算（Step1）

代勤候補を抽出する際に、店長が抽出モードを選択でき、各候補者に「なぜ選ばれたか」が分かるスコアと抽出理由を表示する機能を実装しました。
**今回はStep1として、モード選択とスコア計算・表示のみを実装しています。** 上位3人だけに通知する／全員断られたら次グループに通知する、といった段階的な通知の仕組みは今回は実装していません（全候補者に同時に通知する既存の挙動はそのまま）。

### 抽出モード（`matching_settings` テーブル）

店長メニューの「代勤候補抽出設定」（`pages/manager/matching_settings.php`）で、以下の3モードから1つを選んで保存できます。保存した内容は `matching_settings` テーブルの `setting_key = 'current_matching_mode'` に1行だけ保持され、以後の休み申請に対する代勤候補抽出に使われます。

| モード | キー | 説明 |
|---|---|---|
| 通常 | `normal` | 通常時は、勤務可能であることに加え、ポジション・スキル・勤続年数・時間一致度をバランスよく評価します。 |
| 人員確保優先 | `staffing_priority` | 人員不足時は、ポジションやスキルよりも、対象時間に出勤できることを重視します。 |
| スキル重視 | `skill_priority` | 業務品質を保ちたい場合は、対象業務に対応できるスキルや経験を持つ従業員を優先します。 |

休み申請が登録されるたびに、その時点の抽出モードが `leave_requests.matching_mode` に保存されます（後から店長がモードを変更しても、過去の申請の表示は変わりません）。

### 必須条件とスコア条件の分離

代勤候補の判定は、2段階に分かれています。

1. **必須条件**（モードに関わらず常に同じ。`findSubstituteCandidates()`）：休み申請を出した本人ではない／従業員が有効（`is_active = 1`）／勤務可能日が一致／勤務可能時間が対象シフトを覆っている／同時間帯の重複シフトがない。これを1つでも満たさない従業員は候補者になりません。
2. **スコア条件**（必須条件を満たした候補者のみが対象。`calculateCandidateScore()`）：ポジション一致度・スキルレベル・勤続年数・時間一致度の4項目を、モードごとの重み付けで合計し、0〜100点のスコアを算出します。

### モードごとの重み付け（100点満点）

スコアの重み付けは、店長が個別に調整するのではなく、3モードぶんをあらかじめ用意したプリセットです（`getMatchingWeights()`）。重み調整のUIを用意すると店長の操作が複雑になるため、Step1では「モードを選ぶだけ」のシンプルな操作性を優先し、重み自体は固定としています。

| モード | ポジション | スキル | 勤続年数 | 時間一致度 |
|---|---:|---:|---:|---:|
| 通常 | 30 | 30 | 20 | 20 |
| 人員確保優先 | 10 | 10 | 10 | 70 |
| スキル重視 | 30 | 50 | 15 | 5 |

**スコアは候補者間の順序づけ・判断材料のための相対的な指標であり、絶対評価ではありません。** 例えば「スコア82点」は「100点満点で82点の働きができる」という意味ではなく、「このモードの重み付けでは、他の候補者と比べてこの程度の優先度になる」という相対的な目安です。

スコア条件の各項目は、おおむね以下のように計算します（`scorePositionMatch()` / `scoreSkillLevel()` / `scoreTenure()` / `scoreTimeMatch()`）。

* **ポジション一致度**：候補者と申請対象シフトのポジションが完全一致なら満点、いずれかが未設定なら中間点、部分一致（例：候補者「ホール・レジ」がシフト「ホール」を含む）ならやや低い点、不一致なら0点。
* **スキルレベル**：`employees.skill_level`（1〜5）に応じて、5→100%、4→80%、3→60%、2→40%、1→20%。
* **勤続年数**：`employees.hire_date` から、1年以上→満点、6か月以上→70%、3か月以上→40%、3か月未満→20%、未登録→中間点（50点扱い）。
* **時間一致度**：候補者の勤務可能時間が対象シフト時間をどれだけ上回っているか（タイトに一致しているほど高評価）。

抽出理由（`match_reason`）は、上記の計算結果から「ポジション一致、スキルレベル4、勤続1年以上、勤務可能時間が対象シフトをカバー」のような文言を自動生成し、`buildMatchReason()` で組み立てます。

### 候補者の並び順・通知の扱い

* 候補者は `createSubstituteCandidates()` 内でスコアの高い順に並べ替えてから `substitute_candidates` に登録するため、承認画面・通知でもスコアの高い候補者から確認できます。
* 通知の作成自体は従来通り**必須条件を満たす候補者全員**に対して行われます（Step1では上位3人のみへの通知や、段階的な追加通知は行いません）。

### 店長側：抽出モードの設定（`pages/manager/matching_settings.php`）

* 店長メニューの「代勤候補抽出設定」から開きます（`requireRole('manager')` でアクセス制限）。
* 現在のモードがラジオボタンで選択された状態で表示され、別のモードを選んで「この内容で保存する」を押すと `matching_settings` を更新します。
* 従業員側からはこの画面にアクセスできません。

### 店長側：従業員のスキルレベル・入社日設定（`pages/manager/employees.php`）

* 従業員の新規登録・編集フォームに「スキルレベル（1〜5）」と「入社日」の入力欄を追加しました。
* スキルレベルは1〜5の範囲外の値が送信された場合エラーになります（「スキルレベルは1〜5の範囲で指定してください。」）。入社日は未入力でも登録できます。
* 従業員一覧にスキルレベルの列を追加し、`skillLevelLabel()`（`app/includes/status_labels.php`）で日本語ラベル表示します。

### 店長側：承認画面でのスコア・抽出理由の表示（`pages/manager/approvals.php`）

* 未処理の休み申請の情報に「抽出モード」の行を追加しました。
* 代勤候補一覧に「スコア」「抽出理由」「スキルレベル」「勤続」の列を追加し、候補者がどのような理由で選ばれたかを確認できるようにしました（例：候補者「鈴木次郎」／スコア「82点」／抽出理由「ポジション一致、スキルレベル4、勤続1年以上、勤務可能時間が対象シフトをカバー」）。
* `match_score` が `NULL` の古いデータ（本機能導入前に登録された候補者など）でもエラーにならず、スコア列に「-」を表示します。

### 安全対策

* `pages/manager/matching_settings.php` は `requireRole('manager')` でアクセス制限しています。未ログイン・従業員ロールでのアクセスはそれぞれログイン画面・従業員メニューへ転送されます。
* 抽出モードの値はサーバー側で `getMatchingModes()` の3値（`normal`/`staffing_priority`/`skill_priority`）以外を拒否します。
* `employees.skill_level` はサーバー側で1〜5の範囲チェックを行います。
* SQLはすべてプリペアドステートメントを使用します。

## 現時点で未実装の内容

休みキャンセル申請（承認前・承認後）、代勤者側キャンセル申請、代勤候補の再抽出は**実装済み**です（詳細は「状態一覧」セクションおよび各「今回実装した機能」セクションを参照）。未実装として残っているのは以下です。

* 上位3人のみへの代勤通知、全員が辞退した場合の次グループへの段階的な通知（`matching_round` / `proposal_round` のようなラウンド管理は導入していません。常に必須条件を満たす候補者全員へ同時通知）
* 店長によるスコア重み付けの手動調整（現在はモードごとのプリセットのみ。重みのカスタムUIは未実装）
* 外部通知（メール・LINE・プッシュ通知等、システム外部への通知連携）
* 店長アカウント管理機能（店長自身による別の店長アカウント作成・一覧・有効/無効切替）
* パスワード変更機能（`seed.sql` の共通パスワードを画面から変更する機能）
* さくらインターネットなど本番環境への移行対応

## 基本フローデモ手順（最短）

最終発表や複数人テストで基本フローを見せるための最短手順です。`schema.sql` → `seed.sql` を投入した初期状態から実行できます。
パスワードはすべて `password123` です。各状態は画面上に日本語の状態バッジで表示されます。

### 状態（ステータス）の日本語表示

各画面では英語の status をそのまま表示せず、日本語ラベル＋状態バッジで表示します（`app/includes/status_labels.php`）。状態名の正式な一覧・意味・遷移先は、本READMEの「[状態一覧](#状態一覧)」セクションにまとめています（`leave_requests.status` / `shifts.status` / `substitute_candidates.status` / `cancellation_requests` / `notifications.type`）。デモを行う際は、そちらを参照してください。

### デモフローA：候補者あり → 承認まで

1. `manager01` でログインし、「シフト作成・一覧確認」で対象シフトを確認する。
2. `employee01`（山田 太郎）でログインし、「休み申請」で「2026-06-14 17:00-22:00（キッチン）」を申請する。状態が「候補者回答待ち」になる。
3. `employee03`（鈴木 次郎）でログインし、「通知確認」の代勤依頼から「回答する」→「代勤可能」を選ぶ。
4. `manager01` でログインし、「通知確認」の「代勤可能な候補者が回答しました」通知の「承認画面で確認する」リンクから承認画面へ移動し、対象申請の「この候補者で承認」を押す。
5. `employee01` でログインし、「承認結果確認」で「承認済み／休み申請が承認されました」を確認する。
6. `employee03` でログインし、「承認結果確認」で「承認済み／代勤対応が確定しました」を確認する。さらに「シフト確認」に 2026-06-14 のシフトが「代勤反映済み」で表示されることを確認する。
7. `manager01` でログインし、「シフト作成・一覧確認」で 2026-06-14 のシフトの担当者が「鈴木 次郎」、状態が「代勤反映済み」に変わっていることを確認する。

> `seed.sql` には「承認待ちのサンプル」として、佐藤 花子さんの 2026-06-15 のシフトに対する休み申請（鈴木 次郎さんが「代勤可能」と回答済み）が初めから入っています。承認画面を開けば、すぐに承認操作だけを試すこともできます。

### デモフローB：候補者なし

1. `employee04`（高橋 美咲）でログインし、「休み申請」で「2026-06-16 17:00-22:00（キッチン）」を申請する。その日に勤務可能な他の従業員がいないため、状態が「候補者なし」になる。
2. `manager01` でログインし、「通知確認」に「代勤候補が見つかりません」通知（「手動対応が必要」バッジと「承認画面で確認する」リンク付き）が表示されることを確認する。
3. 承認画面の「未処理の休み申請」に当該申請が「候補者なし」で表示され、手動での調整・却下が必要なことが分かる。

### デモフローC：却下

1. （デモフローB に続けて）`manager01` で承認画面を開き、当該申請の「この休み申請を却下」を押す。
2. `employee04` でログインし、「承認結果確認」で「却下済み／休み申請が却下されました」を確認する。

### デモフローD：抽出モード変更とスコア確認

1. `manager01` でログインし、店長メニューから「代勤候補抽出設定」を開く。現在のモードが「通常」であることを確認する。
2. 「人員確保優先」を選んで保存し、「代勤候補抽出モードを更新しました。」と表示されることを確認する。
3. 「従業員情報管理」で、いずれかの従業員のスキルレベル・入社日を編集して保存する。
4. 別の従業員（休み申請を出す側）でログインし、「休み申請」で対象シフトを申請する。
5. `manager01` でログインし、承認画面（`approvals.php`）を開く。対象の休み申請に「抽出モード：人員確保優先」と表示され、代勤候補一覧にスコア・抽出理由・スキルレベル・勤続が表示され、候補者がスコアの高い順に並んでいることを確認する。

### デモ用にDBを初期状態へ戻す

デモを何度も行う場合は、`schema.sql` → `seed.sql` を再投入すれば初期状態に戻せます。
既存データを完全に消してから作り直す場合は、DBを一度削除してから再構築します（コマンドライン例）。

```
mysql -u root --default-character-set=utf8mb4 -e "DROP DATABASE IF EXISTS \`shift_matching_system\`;"
mysql -u root --default-character-set=utf8mb4 < database/schema.sql
mysql -u root --default-character-set=utf8mb4 < database/seed.sql
```

`schema.sql` は `CREATE DATABASE / TABLE IF NOT EXISTS` と `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`（MariaDB対応）で構成されているため、空のDB・既存DBのどちらに対して実行しても安全です。空のDBから `schema.sql` → `seed.sql` を実行すれば、上記デモが動作する初期状態になります。

## 注意点

* ローカル開発用の正式なDB名は **`shift_matching_system`** です。古い日本語名「シフト管理システム」は過去のバージョンで使われていましたが、現在は使用していません（コード内に残っている場合はドキュメント整理漏れです）。
* 他環境（例：さくらインターネットなどへの移行）では、移行先のDB名に合わせて `database/schema.sql`・`database/seed.sql` の `USE shift_matching_system;` 文と `app/config/database.php` の `$dbName` を変更または削除してください。ローカル開発では変更不要です。
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
10. **従業員情報管理・アカウント作成の確認**
    * 店長でログインし、店長メニューから「従業員情報管理」を開く。
    * 「従業員の新規登録」フォームに氏名・ログインID・初期パスワード・担当業務・備考を入力して登録する。
    * 一覧に新しい従業員が追加され、ログインIDが表示されることを確認する。
    * 既存のログインIDと同じIDで登録しようとすると、画面上にエラーメッセージが表示されることを確認する。
    * 氏名・ログインID・パスワードのいずれかを空欄で登録しようとすると、画面上にエラーメッセージが表示されることを確認する。
    * 一覧の「無効化」ボタンを押すと、該当従業員のバッジが「無効」になることを確認する（再度押すと「有効」に戻る）。
11. **新規作成した従業員アカウントでのログイン確認**
    * ログアウトし、手順10で登録したログインIDと初期パスワードでログインする。
    * 従業員メニューに遷移し、「ログイン中：（登録した氏名）」と表示されることを確認する。
12. **従業員による勤務可能日登録・一覧確認**
    * 従業員メニューから「勤務可能日登録」を開く。
    * 勤務可能日・開始時刻・終了時刻・備考を入力して登録し、一覧に追加されることを確認する。
    * 開始時刻を終了時刻より後にして登録しようとすると、画面上にエラーメッセージが表示されることを確認する。
    * 同じ日付・時間帯と重複する内容を登録しようとすると、画面上にエラーメッセージが表示されることを確認する。
    * 登録した勤務可能日を「削除」ボタンで削除できることを確認する。
13. **店長による勤務可能日確認**
    * 店長でログインし、「従業員情報管理」画面下部の「勤務可能日の確認」で、手順12で操作した従業員を選択する。
    * その従業員が登録した勤務可能日の一覧が表示されることを確認する（登録・編集フォームが無いことも確認する）。
14. **シフト作成・一覧確認**
    * 店長メニューから「シフト作成・一覧確認」を開く（画面タイトルが「シフト作成・一覧確認」であることを確認する）。
    * 「シフトの新規作成」フォームに従業員・勤務日・開始時刻・終了時刻・担当業務・備考を入力して登録し、一覧に追加されることを確認する。
    * 開始時刻を終了時刻より後にして登録しようとすると、画面上にエラーメッセージが表示されることを確認する。
    * 「無効化」ボタンを押すと、該当シフトが一覧から表示されなくなることを確認する。
15. **権限チェックの確認（追加分）**
    * 従業員でログイン中に `pages/manager/employees.php` や `pages/manager/shifts.php` に直接アクセスし、従業員メニューへ転送されることを確認する。
    * 店長でログイン中に `pages/employee/availability.php` に直接アクセスし、店長メニューへ転送されることを確認する。
    * 従業員でログイン中に、他の従業員の勤務可能日のレコードIDを指定して削除リクエストを送っても削除されないことを確認する（`employee_id` で本人のレコードに限定されているため）。
16. **休み申請登録・代勤候補抽出（候補者あり）の確認**
    * ユーザーID `employee01`（山田 太郎）でログインし、従業員メニューから「休み申請」を開く。
    * 対象シフトとして「2026-06-14 17:00-22:00（キッチン）」を選択し、申請理由を入力して申請する。
    * 「休み申請を登録しました。代勤候補の抽出を行いました。」と表示され、画面下部の一覧に状態「候補者回答待ち」が表示されることを確認する。
    * ログアウトし、ユーザーID `employee03`（鈴木 次郎）でログインして「通知確認」を開き、種別「代勤依頼」・タイトル「代勤依頼が届いています」の通知（未読）が表示されることを確認する。
    * 「既読にする」ボタンを押すと、状態が「既読」に変わることを確認する。
17. **休み申請登録・代勤候補抽出（候補者なし）の確認**
    * ユーザーID `employee04`（高橋 美咲）でログインし、「休み申請」で自分のシフト「2026-06-16 17:00-22:00（キッチン）」を申請する。
    * 一覧の状態が「候補者なし（店長確認待ち）」と表示されることを確認する。
    * ログアウトし、ユーザーID `manager01` でログインして「通知確認」を開き、種別「候補者なし」・タイトル「代勤候補が見つかりません」の通知が表示されることを確認する。
18. **休み申請の重複防止の確認**
    * 手順16で申請したシフトに対して、再度同じシフトを選んで休み申請しようとすると、画面上にエラーメッセージが表示され、選択肢からも除外されることを確認する（対象シフトの状態が「予定」から変わっているため）。
19. **代勤提案への応答（代勤可能）の確認**
    * 手順16に続けて、ユーザーID `employee03`（鈴木 次郎）でログインする。
    * 「通知確認」を開き、種別「代勤依頼」の通知に「未回答」バッジと「回答する」リンクが表示されることを確認する。
    * 「回答する」リンクから代勤提案への応答画面（`candidate_response.php`）を開き、休み申請者「山田 太郎」、勤務日「2026-06-14」、開始時刻「17:00」、終了時刻「22:00」、担当業務・ポジション「キッチン」、申請理由、マッチ理由（スコア計算に基づく抽出理由が表示される）、回答状況「未回答」が表示され、「代勤可能」「代勤不可」ボタンが表示されることを確認する。
    * 「代勤可能」ボタンを押すと、「『代勤可能』で回答しました。店長に通知しました。」と表示され、回答状況が「代勤可能と回答済み」に変わり、ボタンが表示されなくなることを確認する。
    * 「通知確認」に戻ると、代勤依頼通知の表示が「代勤可能と回答済み」バッジに変わり、リンクが「詳細を見る」になっていることを確認する。
20. **再回答防止・他人の代勤提案へのアクセス防止の確認**
    * 手順19に続けて、同じ代勤提案の応答画面（`candidate_response.php?candidate_id=...`）を再度開くと、ボタンが表示されず「この提案には既に回答済みです（再回答はできません）。」と表示されることを確認する。
    * ログアウトし、ユーザーID `employee01`（山田 太郎）でログインする。手順19で使用した `candidate_id` をURLに指定して `candidate_response.php` を開くと、「指定された代勤提案が見つかりません」と表示され、他人の代勤提案を閲覧・回答できないことを確認する。
    * 存在しない `candidate_id`（例: `candidate_id=9999`）を指定した場合も同様に「指定された代勤提案が見つかりません」と表示されることを確認する。
21. **「代勤可能」回答に対する店長通知の確認**
    * ログアウトし、ユーザーID `manager01` でログインして「通知確認」を開く。
    * 種別「代勤可能回答」・タイトル「代勤可能な候補者が回答しました」の通知が表示され、内容に「6月14日のシフトについて、鈴木 次郎さんが代勤可能と回答しました。承認画面で確認してください。」のように表示されることを確認する。
22. **「代勤不可」回答の確認（参考）**
    * 代勤候補が複数いる場合、未回答の代勤候補が応答画面で「代勤不可」ボタンを押すと、回答状況が「代勤不可と回答済み」に変わり、再回答できなくなることを確認する。
    * この場合は店長への通知（種別 `candidate_available`）は作成されないため、店長の「通知確認」に新しい通知が増えないことを確認する。
23. **承認画面の表示・「承認画面で確認する」リンクの確認**
    * 手順21に続けて、ユーザーID `manager01` でログイン中の「通知確認」画面で、「代勤可能な候補者が回答しました」通知の「承認画面で確認する」リンクを押す。
    * `approvals.php` に遷移し、対象の休み申請（「未処理の休み申請」セクション）まで表示位置がジャンプすることを確認する。
    * 「未処理の休み申請」に、休み申請者「佐藤 花子」、勤務日「2026-06-15」、開始時刻「17:00」、終了時刻「22:00」、状態「候補者回答待ち」と、代勤候補「鈴木 次郎」に「代勤可能と回答済み」バッジと「この候補者で承認」ボタンが表示されることを確認する。
24. **休み申請の承認（代勤確定）の確認**
    * 手順23に続けて、「この候補者で承認」ボタンを押す。
    * 「休み申請を承認し、代勤を確定しました。」と表示され、対象の休み申請が「未処理の休み申請」から消え、「処理済みの休み申請」に結果「承認済み」・代勤対応者「鈴木 次郎」として表示されることを確認する。
    * ログアウトし、ユーザーID `employee02`（佐藤 花子）でログインして「承認結果確認」を開き、勤務日「2026-06-15」・申請状態「承認済み」・タイトル「休み申請が承認されました」の行が表示されることを確認する。
    * ログアウトし、ユーザーID `employee03`（鈴木 次郎）でログインして「承認結果確認」を開き、勤務日「2026-06-15」・申請状態「承認済み」・タイトル「代勤対応が確定しました」の行が表示されることを確認する（佐藤さん宛の通知は表示されないことも確認する）。
25. **候補者なしの休み申請の却下の確認**
    * ユーザーID `employee04`（高橋 美咲）でログインし、「休み申請」で自分のシフト「2026-06-16 17:00-22:00（キッチン）」を申請し、状態「候補者なし（店長確認待ち）」になることを確認する（手順17と同様）。
    * ログアウトし、ユーザーID `manager01` でログインして「承認画面」を開き、「未処理の休み申請」に当該休み申請（状態「候補者なし」）が表示され、代わりに「代勤候補が見つかりませんでした。手動で調整するか、休み申請を却下してください。」と表示され、承認ボタンが無いことを確認する。
    * 「この休み申請を却下」ボタンを押すと、「休み申請を却下しました。」と表示され、「処理済みの休み申請」に結果「却下」として表示されることを確認する。
    * ログアウトし、ユーザーID `employee04`（高橋 美咲）でログインして「承認結果確認」を開き、勤務日「2026-06-16」・申請状態「却下」・タイトル「休み申請が却下されました」の行が表示されることを確認する。
26. **承認画面の権限チェック・二重処理防止の確認**
    * 未ログイン状態で `pages/manager/approvals.php` に直接アクセスし、ログイン画面へ転送されることを確認する。
    * 従業員でログイン中に `pages/manager/approvals.php` に直接アクセスし、従業員メニューへ転送されることを確認する（POSTで `action=approve`/`action=reject` を送信した場合も同様）。
    * 手順24・25で処理済みになった休み申請に対して、同じ `leave_request_id` で再度承認・却下のリクエストを送っても「指定された休み申請が見つからないか、既に処理済みです。」と表示され、二重に処理されないことを確認する。
    * 「代勤可能と回答済み」以外（未回答・代勤不可・存在しない）の `candidate_id` を指定して承認しようとすると、「選択された候補者は『代勤可能』と回答していないか、存在しません。」と表示されることを確認する。
27. **代勤候補抽出モード設定の権限チェックの確認**
    * 未ログイン状態で `pages/manager/matching_settings.php` に直接アクセスし、ログイン画面へ転送されることを確認する。
    * 従業員でログイン中に `pages/manager/matching_settings.php` に直接アクセスし、従業員メニューへ転送されることを確認する。
    * 店長でログイン中に店長メニューから「代勤候補抽出設定」を開けることを確認する。
28. **抽出モードの切り替えの確認**
    * 店長でログインし、「代勤候補抽出設定」で「通常」→「人員確保優先」→「スキル重視」の順にそれぞれ選択して保存し、そのたびに「現在の抽出モード」の表示と、保存後に再読み込みしたときの選択状態（ラジオボタンのチェック位置）が一致することを確認する。
29. **従業員のスキルレベル・入社日設定の確認**
    * 「従業員情報管理」の新規登録フォームでスキルレベル・入社日を指定して登録し、一覧にスキルレベルが表示されることを確認する。
    * スキルレベルに範囲外の値（例えば不正な値）を指定して登録・編集しようとすると、「スキルレベルは1〜5の範囲で指定してください。」と表示されることを確認する。
    * 入社日を空欄のまま登録・編集してもエラーにならないことを確認する。
30. **休み申請時のモード保存・スコア計算・承認画面表示の確認**
    * 店長で「代勤候補抽出設定」のモードを「人員確保優先」に変更する。
    * 従業員で休み申請を行い、代勤候補が抽出されることを確認する。
    * 店長で承認画面を開き、対象の休み申請に「抽出モード：人員確保優先」と表示されることを確認する。
    * 代勤候補一覧にスコア・抽出理由・スキルレベル・勤続が表示され、候補者がスコアの高い順（降順）に並んでいることを確認する。
    * `match_score`/`match_reason` が `NULL` の古いデータ（例：本機能導入前のレコード）があっても、承認画面がエラーにならず、スコア列に「-」が表示されることを確認する。
31. **候補者ありの休み申請キャンセル**
    * `employee01` で 2026-06-14 のシフトに休み申請し、状態が「候補者回答待ち」になることを確認する。
    * 申請一覧の「キャンセルする」を押し、状態が「キャンセル済み」になり、キャンセルボタンが消えることを確認する。
    * `employee03` の通知確認で、元の代勤依頼に「キャンセル済み・回答不要」と表示され、キャンセル通知も届いていることを確認する。
    * 古い回答リンクを直接開いても「休み申請がキャンセルされたため回答できません」と表示され、回答ボタンが出ないことを確認する。
32. **代勤可能回答済みの休み申請キャンセル**
    * 代勤候補が「代勤可能」と回答した後、店長が承認する前に休み申請者がキャンセルする。
    * 店長の承認画面で当該申請が未処理一覧から消え、処理済み一覧に「キャンセル済み」と表示されることを確認する。
    * 以前の店長通知に「キャンセル済み・対応不要」と表示され、承認画面への操作リンクが出ないことを確認する。
33. **候補者なしの休み申請キャンセル**
    * `employee04` で候補者なしになる休み申請を作成し、状態が「候補者なし」になることを確認する。
    * 本人がキャンセルし、状態が「キャンセル済み」になることを確認する。
    * 店長の承認画面で未処理として表示されず、元の候補者なし通知に「キャンセル済み・対応不要」と表示されることを確認する。
34. **キャンセル不可・権限チェック**
    * `approved` / `rejected` / `cancelled` の申請にはキャンセルボタンが表示されず、POSTを直接送っても更新されないことを確認する。
    * 他人の `leave_request_id`、存在しないIDを送ってもキャンセルされないことを確認する。
    * 未ログイン状態ではログイン画面へ、店長ロールでは店長メニューへ転送されることを確認する。
35. **承認後キャンセル申請の承認**
    * 通常フローで休み申請を承認し、シフト担当者が代勤者、シフト状態が「代勤反映済み」になっていることを確認する。
    * 休み申請者で「承認結果確認」を開き、キャンセル理由を入力して「キャンセル申請を送る」を押す。
    * 状態が「キャンセル申請中」になり、同じ申請を重複送信できないことを確認する。
    * 店長の通知確認に「承認後キャンセル申請が届いています」が表示され、「キャンセル申請を確認する」から専用画面へ移動する。
    * 店長が「キャンセルを承認」を押し、シフト担当者が元の休み申請者、シフト状態が「予定」に戻ることを確認する。
    * `leave_requests.status` が `cancelled_after_approval`、`cancellation_requests.status` が `approved` になることを確認する。
    * 休み申請者へ承認通知、元の代勤者へ代勤予定キャンセル通知が届くことを確認する。
36. **承認後キャンセル申請の却下**
    * 別の承認済み休み申請でキャンセル申請を作成し、店長が「キャンセルを却下」を押す。
    * `cancellation_requests.status` が `rejected` になり、休み申請は `approved`、シフト担当者は代勤者、シフト状態は `substituted` のままであることを確認する。
    * 休み申請者へキャンセル却下通知が届くことを確認する。
37. **承認後キャンセル申請の不正操作防止**
    * 他人の休み申請、未承認・却下済み・承認前キャンセル済み・承認後キャンセル済みの申請には作成できないことを確認する。
    * 同じ休み申請に pending のキャンセル申請を複数作成できないことを確認する。
    * 未ログイン・従業員ロールで店長用 `cancellation_requests.php` を開くと、ログイン画面または従業員メニューへ転送されることを確認する。
    * 処理済みのキャンセル申請を再承認・再却下できないことを確認する。
