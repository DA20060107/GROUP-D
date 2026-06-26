# AGENTS.md

## Project Overview

このプロジェクトは、PHP / MySQL / XAMPP を用いた「シフト代勤マッチング支援システム」です。

対象は、従業員15〜20人程度の居酒屋です。

主な目的は、急な休み申請が発生した際に、休みたい従業員と代わりに働ける従業員をシステム上でマッチングし、従業員同士の連絡負担と店長のシフト調整負担を軽減することです。

ローカルでは shift_matching_system を使用。サーバ移行時は schema.sql / seed.sql の USE 文を移行先DB名に合わせる、または削除する。

## Tech Stack

* PHP
* MySQL / MariaDB
* HTML
* CSS
* XAMPP
* PDO
* Git / GitHub

ローカル開発環境では、主に以下のURLで動作確認しています。

```text
http://localhost/shift_matching_system/public/login.php
```

GitHubリポジトリは以下です。

```text
https://github.com/DA20060107/GROUP-D
```

## Current Branch / Git Notes

現在、作業ブランチとして以下を使用している可能性があります。

```text
feature/substitute-matching-notifications
```

作業前に必ず現在のブランチと差分を確認してください。

```bash
git status
git branch -vv
git log --oneline --decorate --graph --all -n 10
```

共有リポジトリのため、`force push` は禁止です。

push前には基本的に以下を行ってください。

```bash
git pull origin main
```

featureブランチで作業している場合は、以下のようにpushしてください。

```bash
git push -u origin feature/substitute-matching-notifications
```

mainへ統合する場合は、事前にユーザーへ確認してください。

## Implemented Core Flow

現在、基本フローは一通り実装済みです。

実装済みの流れは以下です。

1. 店長が従業員情報を登録する
2. 店長が従業員アカウントを作成する
3. 店長がシフトを作成する
4. 従業員が自分の勤務可能日を登録する
5. 従業員が自分のシフトを確認する
6. 従業員が休み申請を行う
7. システムが勤務可能日・勤務可能時間をもとに代勤候補を抽出する
8. 代勤候補へ通知を作成する
9. 候補者が代勤可能・代勤不可を回答する
10. 代勤可能と回答した候補者がいる場合、店長へ通知する
11. 店長が承認画面で代勤候補を承認、または休み申請を却下する
12. 承認時、対象シフトの担当者を代勤者に変更する
13. 休み申請者と代勤者へ結果通知を作成する
14. 従業員が承認結果確認画面で結果を確認する

## Important Existing Features

### Authentication / Authorization

* `manager` と `employee` のrole別ログインを実装済み
* 未ログインユーザーはログイン画面へリダイレクト
* `manager` は店長画面のみ操作可能
* `employee` は従業員画面のみ操作可能
* employeeが店長承認処理を実行できないように制限済み
* managerが従業員専用画面を操作できないように制限済み

既存の権限管理を壊さないでください。

### Manager Features

実装済みの店長機能：

* 店長メニュー
* 従業員情報管理
* 従業員アカウント作成
* 従業員の有効 / 無効切替
* 従業員ごとの勤務可能日確認
* シフト作成・一覧確認
* 通知確認
* 代勤候補承認
* 休み申請却下
* 承認済み・却下済み申請の確認

### Employee Features

実装済みの従業員機能：

* 従業員メニュー
* 自分のシフト確認
* 勤務可能日登録・一覧・削除
* 休み申請
* 休み申請一覧
* 通知確認
* 代勤提案への応答
* 承認結果確認

### Substitute Matching / Notification

実装済み：

* 休み申請後に代勤候補を抽出
* 候補者を `substitute_candidates` に登録
* 候補者へ `notifications` を作成
* 候補者なしの場合、店長へ通知作成
* 代勤可能回答時、店長へ通知作成
* 店長承認時、休み申請者と代勤者へ結果通知作成

現在の候補者抽出は、主に以下の必須条件で行っています。

* 休み申請者本人ではない
* 有効な従業員である
* 勤務可能日が対象シフト日と一致
* 勤務可能時間が対象シフト時間をカバー
* 同時間帯に別シフトが入っていない

## State Management

### leave_requests.status

主な状態：

```text
waiting_candidate_response : 候補者回答待ち
no_candidate               : 候補者なし
approved                   : 承認済み
rejected                   : 却下済み
```

### substitute_candidates.status

主な状態：

```text
proposed : 候補者に提案済み・未回答
accepted : 候補者が「代勤可能」と回答
declined : 候補者が「代勤不可」と回答
expired  : 他候補者が承認された、または無効化された状態
```

重要：

`substitute_candidates.status = accepted` は「店長承認済み」ではありません。
あくまで「候補者が代勤可能と回答した状態」です。

店長の最終承認は、`approvals` や `leave_requests.status` で管理します。

### shifts.status

主な状態：

```text
scheduled   : 予定
substituted : 代勤反映済み
cancelled   : キャンセル
inactive    : 無効
```

承認時には、対象シフトの `employee_id` が代勤者に変更され、`status` が `substituted` になります。

## Status Display

状態表示は、以下の共通ヘルパーで一元管理されています。

```text
app/includes/status_labels.php
```

画面上では、英語のstatusをそのまま表示せず、日本語ラベルとバッジ表示を使ってください。

CSSには状態バッジ用のクラスが追加されています。

```text
public/assets/css/style.css
```

既存のバッジ表示・色設計をなるべく使い回してください。

## Important Files

### Config / Common

```text
app/config/database.php
app/includes/auth.php
app/includes/status_labels.php
app/includes/header.php
app/includes/footer.php
public/assets/css/style.css
```

### Services

```text
app/services/substitute_matcher.php
```

代勤候補抽出・候補者通知・結果通知などの中心的な処理があります。

### Manager Pages

```text
pages/manager/menu.php
pages/manager/employees.php
pages/manager/shifts.php
pages/manager/notifications.php
pages/manager/approvals.php
```

### Employee Pages

```text
pages/employee/menu.php
pages/employee/shifts.php
pages/employee/availability.php
pages/employee/leave_request.php
pages/employee/notifications.php
pages/employee/candidate_response.php
pages/employee/result.php
```

### Database

```text
database/schema.sql
database/seed.sql
```

空DBに対して、以下の順で再構築できることが確認済みです。

```text
schema.sql → seed.sql
```

この再構築可能性を壊さないでください。

## Database Rebuild Requirement

DB変更を行う場合は、必ず以下を満たしてください。

1. 既存DBに対して安全にマイグレーションできること
2. 空DBに対して `schema.sql → seed.sql` の順に実行できること
3. seed後にログイン・基本フローが動くこと

DB変更後は、READMEにも変更内容を記載してください。

## UI Design Policy

既存の画面デザイン方針：

* 白背景
* 青ヘッダー
* 青系ボタン
* 青罫線テーブル
* 薄青テーブルヘッダー
* 状態バッジ
* 余白を広めに取る
* 画面上部に戻るボタン・ホームボタンがある構成

画面ごとにデザインがバラバラにならないようにしてください。

## Demo Flow

READMEには基本フローデモ手順が追加されています。

最短デモフローA：

1. `manager01 / password123` でログインし、シフト一覧を確認
2. `employee01 / password123` でログインし、2026-06-14の対象シフトに休み申請
3. `employee03 / password123` でログインし、通知確認から代勤依頼に「代勤可能」と回答
4. `manager01 / password123` でログインし、承認画面から代勤候補を承認
5. `employee01 / password123` でログインし、承認結果確認画面で結果確認
6. `employee03 / password123` でログインし、承認結果確認画面で結果確認
7. `manager01 / password123` でログインし、シフト一覧で担当者が変更されていることを確認

候補者なしパターン、却下パターンもREADMEに記載済みです。

## Current Next Task

次に実装する予定の作業は、**代勤候補抽出モード選択機能 Step1** です。

目的：

現在の候補者抽出は、主に勤務可能日・勤務可能時間に基づいています。
これを改善し、店長が抽出方針を選択できるようにします。

追加予定の抽出モード：

```text
normal             : 通常
staffing_priority  : 人員確保優先
skill_priority     : スキル重視
```

今回のStep1で実装する範囲：

1. 店長が現在の抽出モードを設定できる
2. 従業員情報にスキルレベルと入社日を追加する
3. 店長が従業員登録・編集時にスキルレベルと入社日を設定できる
4. 休み申請時に、その時点の抽出モードを `leave_requests` に保存する
5. 代勤候補抽出時に、抽出モードに応じて候補者スコアを計算する
6. `substitute_candidates.match_score` にスコアを保存する
7. `substitute_candidates.match_reason` に抽出理由を保存する
8. 店長承認画面で、候補者のスコアと抽出理由を表示する
9. READMEに抽出モード・スコア計算の説明を追記する

## Matching Mode Design

### 必須条件

どの抽出モードでも、以下は必須条件です。

* 休み申請者本人ではない
* 有効な従業員である
* 勤務可能日が対象シフト日と一致している
* 勤務可能時間が対象シフト時間をカバーしている
* 同時間帯に別シフトが入っていない

必須条件を満たした人だけを候補者にしてください。

### スコア条件

必須条件を満たした候補者に対して、以下を使ってスコア計算します。

* ポジション一致度
* スキルレベル
* 勤続年数
* 時間一致度

スコアは100点満点を基本とします。

ただし、スコアは絶対評価ではなく、候補者の優先順位を決めるための相対的な指標です。

### モード別重み

内部計算の初期重みは以下です。

| 抽出モード  | ポジション一致 | スキル | 勤続年数 | 時間一致度 |
| ------ | ------: | --: | ---: | ----: |
| 通常     |      30 |  30 |   20 |    20 |
| 人員確保優先 |      10 |  10 |   10 |    70 |
| スキル重視  |      30 |  50 |   15 |     5 |

説明方針：

* 通常：ポジション・スキル・勤続年数・時間一致度をバランスよく評価
* 人員確保優先：勤務可能時間を重視
* スキル重視：スキルとポジションを重視

店長に細かい点数設定はさせません。
ユーザーには、細かな点数配分ではなく「通常」「人員確保優先」「スキル重視」というプリセットを選ばせる設計です。

## Planned DB Changes for Matching Mode Step1

必要最小限で以下を追加してください。

### employees

```sql
skill_level TINYINT DEFAULT 3
hire_date DATE NULL
```

`skill_level` の意味：

```text
1 : 未経験に近い
2 : 補助可能
3 : 通常業務可能
4 : 安定して対応可能
5 : 熟練
```

### leave_requests

```sql
matching_mode VARCHAR(50) DEFAULT 'normal'
```

使用値：

```text
normal
staffing_priority
skill_priority
```

### matching_settings

設定用テーブルを新規作成してください。

例：

```text
id
setting_key
setting_value
updated_at
```

保存する設定：

```text
current_matching_mode
```

初期値：

```text
normal
```

### substitute_candidates

既存の以下のカラムを活用してください。

```text
match_score
match_reason
matched_at
```

必要な場合のみ最小限のカラム追加を検討してください。

## Planned Page Changes for Matching Mode Step1

### New Manager Page

新規ページ例：

```text
pages/manager/matching_settings.php
```

内容：

* 現在の代勤候補抽出モードを表示
* 「通常」「人員確保優先」「スキル重視」から選択
* 各モードの説明を表示
* 保存ボタン
* 保存後の成功メッセージ

権限：

* managerのみアクセス可能
* employeeアクセス不可
* 未ログインはログイン画面へ

店長メニューに以下のリンクを追加してください。

```text
代勤候補抽出設定
```

### Manager Employees Page

対象：

```text
pages/manager/employees.php
```

追加内容：

* 従業員登録フォームに `skill_level` と `hire_date` を追加
* 従業員編集フォームに `skill_level` と `hire_date` を追加
* 従業員一覧にスキルレベルと入社日を表示
* `skill_level` は1〜5の選択式
* `hire_date` は空欄可

既存の従業員登録・編集・有効/無効切替・アカウント作成を壊さないでください。

### Substitute Matcher

対象：

```text
app/services/substitute_matcher.php
```

追加・整理したい関数例：

```php
getCurrentMatchingMode(PDO $pdo): string
getMatchingModeLabel(string $mode): string
getMatchingWeights(string $mode): array
calculateCandidateScore(array $candidate, array $shift, string $mode): array
buildMatchReason(array $scoreDetails, string $mode): string
```

関数名は既存構成に合わせて変更して構いません。

重要なのは、今後スコア計算や抽出条件を変更しやすいよう、処理を分離することです。

### Manager Approvals Page

対象：

```text
pages/manager/approvals.php
```

候補者一覧に以下を表示してください。

* 抽出モード
* スコア
* 抽出理由
* スキルレベル
* 入社日または勤続期間

古いデータでスコアや理由がNULLでもエラーにならないようにしてください。

## Important Non-Goals for Matching Mode Step1

今回、以下は実装しないでください。

* 上位3人だけに通知する機能
* 全員拒否したら次の3人に通知する機能
* 候補者の段階的通知
* `proposal_round` や `reserved` 状態の導入
* 複数スキルテーブル
* スキル項目ごとの詳細管理
* 店長が細かいスコア配分を手動設定する機能
* 休みキャンセル申請
* 代勤キャンセル申請
* 承認後キャンセル
* メール通知
* LINE通知
* プッシュ通知
* さくらインターネット移行対応

通知対象の範囲は変更しないでください。

現状と同じく、必須条件を満たして候補者登録された従業員には通知を作成してください。

今回はまだ、スコア上位3人だけに通知する制御は入れません。

## Required Safety Checks

変更後は必ず以下を確認してください。

1. PHP構文チェックが通る
2. 店長だけが抽出モード設定画面にアクセスできる
3. 店長メニューから抽出モード設定画面へ移動できる
4. 抽出モードを「通常」に変更できる
5. 抽出モードを「人員確保優先」に変更できる
6. 抽出モードを「スキル重視」に変更できる
7. 従業員登録・編集でスキルレベルと入社日を設定できる
8. 休み申請時に現在の抽出モードが `leave_requests` に保存される
9. 候補者抽出時に `match_score` と `match_reason` が保存される
10. 承認画面でスコアと抽出理由が表示される
11. 候補者がスコア順に表示される
12. 通知作成・代勤回答・店長承認がこれまで通り動く
13. `schema.sql → seed.sql` で空DBから再構築できる
14. READMEが更新されている

## PHP Syntax Check

変更したPHPファイルには、必ず以下を実行してください。

```bash
php -l path/to/file.php
```

可能であれば、変更対象PHPすべてに対して構文チェックを行ってください。

## Do Not Break

以下を壊さないでください。

* ログイン
* ログアウト
* role別アクセス制御
* 従業員登録
* 従業員編集
* 従業員の有効/無効切替
* シフト作成
* 勤務可能日登録
* 休み申請
* 候補者抽出
* 通知作成
* 代勤回答
* 店長承認
* 結果通知
* 承認時のシフト担当者変更
* READMEのデモ手順
* `schema.sql → seed.sql` による空DB再構築

## Future Development Flow

今後の開発予定は以下です。

### Phase 1: Matching Mode Step1

現在の次タスクです。

* 抽出モード選択
* 従業員スキルレベル・入社日
* モード別スコア計算
* `match_score` / `match_reason` 保存
* 承認画面でスコア表示
* README更新

### Phase 2: Pre-Approval Cancellation

次に検討する機能です。

対象：

* 従業員が、自分の休み申請を承認前だけキャンセルできる

対象状態：

```text
waiting_candidate_response
no_candidate
```

実装しないもの：

* 承認後キャンセル
* 代勤者決定後キャンセル
* 代勤者側からのキャンセル

### Phase 3: Manager Account Management

必要に応じて実装します。

方針：

* 既存の店長だけが、別の店長アカウントを作成できる
* 誰でも店長登録できる公開画面は作らない
* 店長一覧・追加・有効/無効切替程度に留める

### Phase 4: UI Polish / Presentation Readiness

* 文言統一
* ボタン配置調整
* デモ手順の簡略化
* エラーメッセージ整理
* 画面遷移の分かりやすさ改善

### Phase 5: Sakura Internet Migration

最終段階で実施予定です。

今はまだ実施しません。

移行時に必要になること：

* さくら側MySQLデータベース作成
* `database.php` の接続情報切替
* `schema.sql → seed.sql` の実行
* ファイルアップロード
* 複数人同時ログインテスト
* 基本フローA/B/Cの確認

## Response / Report Format for Agents

作業完了時は、以下の形式で報告してください。

```text
完了報告

1. 変更したファイル
- path/to/file.php：変更内容
- path/to/another.php：変更内容

2. 実装内容
- 実装した機能
- 仕様上の判断

3. DB変更
- 追加したテーブル
- 追加したカラム
- schema.sql / seed.sql の更新有無

4. 動作確認
- 実行したテスト
- 確認した画面
- php -l の結果
- schema.sql → seed.sql の再構築確認

5. 未実装のまま残したもの
- 今回スコープ外として残した機能

6. 注意点
- ユーザーが次に確認すべきこと
- 既知の懸念
```

作業中に仕様判断が必要になった場合は、勝手に大規模変更せず、ユーザーへ確認してください。
