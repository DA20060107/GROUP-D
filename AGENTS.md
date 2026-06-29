# AGENTS.md

## Project Overview

このプロジェクトは、PHP / MySQL / XAMPP を用いた「シフト代勤マッチング支援システム」です。

対象は、従業員15〜20人程度の居酒屋です。

主な目的は、急な休み申請が発生した際に、休みたい従業員と代わりに働ける従業員をシステム上でマッチングし、従業員同士の連絡負担と店長のシフト調整負担を軽減することです。

ローカルの正式なDB名は **`shift_matching_system`** です。古い日本語名「シフト管理システム」は使いません（過去のバージョンで使われていた名残で、現在は無効です）。
さくらインターネットなど他環境へ移行する場合は、`schema.sql` / `seed.sql` の `USE shift_matching_system;` を移行先DB名に合わせて変更、または削除してください。

コミット・プッシュは、ユーザーから明示的な指示があった場合のみ行ってください。指示がない限り、作業内容はコミットせずワーキングツリーに残したまま報告してください。

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

上記の基本フローに加えて、以下も実装済みです（詳細はREADME.mdの該当セクションを参照）。

15. 店長が代勤候補抽出モード（通常／人員確保優先／スキル重視）を設定し、候補者スコア・抽出理由を計算・表示する
16. 休み申請者本人が、店長処理前（`matching`/`no_candidate`）の休み申請をキャンセルする
17. 休み申請者本人が、店長承認後に「やっぱり出勤できる」とキャンセル申請し、店長が承認/却下する（`cancellation_requests.request_type = requester_after_approval`）
18. 代勤者本人が、店長承認後に「やっぱり代勤できない」とキャンセル申請し、店長が承認/却下する（`cancellation_requests.request_type = substitute_after_approval`）
19. 代勤者キャンセルが店長承認された直後、または店長の手動操作により、代勤候補を再抽出する（`retrySubstituteMatching()`）

状態名・状態遷移の正式な一覧は、本ファイルの「State Management」セクションを参照してください。

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

**重要：状態名は実装（`database/schema.sql` のENUM、各 `.php` ファイルでの直接比較）に存在する値が正式名称です。**
このAGENTS.mdやREADME.mdに実装と異なる名称が書かれている場合は、実装を正としてドキュメントを修正してください。
逆に、ここに記載のない状態名・通知typeを新しく作る場合は、機能追加にあたるため、勝手に追加せずユーザーに確認してください
（例: `matching_round` / `proposal_round` / `waiting_response` のような状態は実装に存在しません。使わないでください）。

状態の詳しい意味・遷移先・画面表示例は **README.md の「状態一覧」セクション** に表でまとめています。実装作業の前に必ずそちらも確認してください。ここでは正式な値の一覧と、特に誤解しやすい点だけを記載します。

### leave_requests.status

```text
pending                  : 受付中（休み申請登録直後の一時状態。直後に processSubstituteMatching() が
                            呼ばれ matching/no_candidate に変わるため、通常DBには残らない）
matching                  : 候補者回答待ち（候補者の回答 or 店長承認待ち。"waiting_candidate_response" という
                            別名は実装に存在しない。常に matching を使う）
no_candidate              : 候補者なし（初回 or 再抽出で候補者が見つからなかった。店長が手動再抽出可能）
approved                  : 承認済み（shifts.status = substituted と対応）
rejected                  : 却下済み
cancelled                 : 承認前キャンセル済み（休み申請者本人が店長処理前に取消）
cancelled_after_approval  : 承認後キャンセル済み（休み申請者側の cancellation_requests が承認され、
                            shifts.employee_id が元の休み申請者へ戻った）
replacement_pending       : 代勤者再調整中（代勤者側の cancellation_requests が承認された。元の休み申請者は
                            引き続き休む前提。shifts.employee_id は変更しない。店長が手動再抽出可能。新しい
                            代勤者が承認されると approved に戻る）
```

### shifts.status

```text
scheduled            : 予定（通常の予定シフト、または承認前キャンセル/却下で戻った状態）
leave_requested       : 休み申請中（休み申請登録時に一時的に設定される。承認/却下/キャンセルで scheduled 等に遷移）
substituted           : 代勤反映済み（店長承認により employee_id が代勤者に変更された状態）
cancelled             : シフト自体が無効化された状態（店長によるシフト無効化。候補抽出・一覧から除外）
replacement_pending   : 代勤者再調整中（leave_requests.replacement_pending と対応。employee_id は
                        キャンセルした代勤者のまま変更しない）
```

### substitute_candidates.status

```text
proposed : 候補者として抽出され、依頼中・未回答
accepted : 候補者が「代勤可能」と回答した状態
declined : 候補者が「代勤不可」と回答した状態
expired  : 他候補者が承認された、申請がキャンセルされた、代勤者キャンセルが承認された等で無効化された状態
           （再抽出時、除外対象でなければ proposed に再活性化される場合がある）
```

**重要：`accepted` は「店長承認済み」ではありません。** あくまで「候補者本人が代勤可能と回答した」状態です。
店長の最終承認は `approvals` テーブルと `leave_requests.status = approved` / `shifts.status = substituted` で管理します。
承認時、選ばれなかった他の `proposed`/`accepted` の候補者は `expired` に更新されます（`declined` はそのまま残す）。

### cancellation_requests.request_type

```text
requester_after_approval  : 休み申請者本人による承認後キャンセル。
                             店長承認時: shifts.employee_id を元の休み申請者へ戻す、shifts.status=scheduled、
                             leave_requests.status=cancelled_after_approval
substitute_after_approval : 代勤者本人による承認後キャンセル。
                             店長承認時: shifts.employee_id は変更しない、leave_requests.status/shifts.status
                             を replacement_pending にし、代勤候補を再抽出する
```

この2種別は処理が大きく異なる点に注意してください。前者は「シフト担当者を戻す」処理、後者は「シフト担当者は変えず再調整待ちにする」処理です。

### cancellation_requests.status

```text
pending  : キャンセル申請が提出され、店長判断待ち
approved : 店長がキャンセル申請を承認した
rejected : 店長がキャンセル申請を却下した（代勤・休み申請の状態は維持される）
```

### notifications.type（主なもの）

`notifications.type` は ENUM ではなく VARCHAR です。実装が `INSERT INTO notifications` で実際に使っている値は以下です。

```text
substitute_request               : 代勤候補への代勤依頼（初回抽出・再抽出とも共通で使う）
candidate_available              : 候補者が「代勤可能」と回答 → 店長へ
no_candidate                     : 初回抽出で候補者なし → 店長へ
rematch_no_candidate             : 再抽出しても候補者なし → 店長へ
approval_result                  : 店長の承認/却下結果 → 休み申請者・代勤者へ
leave_request_cancelled          : 承認前キャンセル時の通知 → 候補者・店長へ
after_approval_cancel_requested  : 休み申請者側の承認後キャンセル申請 → 店長へ
after_approval_cancel_approved   : 休み申請者側キャンセルの承認結果 → 休み申請者・代勤者へ
after_approval_cancel_rejected   : 休み申請者側キャンセルの却下結果 → 休み申請者へ
substitute_cancel_requested      : 代勤者側の承認後キャンセル申請 → 店長へ
substitute_cancel_approved       : 代勤者側キャンセルの承認結果 → 代勤者へ
substitute_cancel_rejected       : 代勤者側キャンセルの却下結果 → 代勤者へ
replacement_pending              : 代勤者再調整中の通知 → 休み申請者へ（leave_requests.status と同名だが別物）
```

`leave_request` / `candidate_offer` という type は `database/seed.sql` のサンプル通知データにのみ残っている古い種別で、現在のアプリケーションコードはこれらの type で新規通知を作成しません（`app/includes/status_labels.php` には表示用ラベルのみ残しています）。新規実装でこの2種別を使わないでください。

### 再抽出時の除外条件（`retrySubstituteMatching()`）

通常の必須条件（有効な従業員・勤務可能日時が一致・同時間帯のシフト重複なし）に加えて、以下を必ず除外します。

```text
- 休み申請者本人
- 現在キャンセルした代勤者（自動再抽出時に明示的に除外）
- 同じ leave_request_id で過去に declined と回答した従業員
- 同じ leave_request_id で substitute_after_approval のキャンセル申請が approved になった従業員
  （現在キャンセルした本人だけでなく、過去のキャンセル者も含む）
```

`expired` の候補者はこれらの除外条件に該当しなければ `proposed` へ再活性化されますが、該当する場合は再候補にしません。

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
app/services/substitute_matcher.php          # 候補抽出・スコア計算・再抽出・通知作成の中心的な処理
app/services/cancellation_request_service.php # 承認後キャンセル申請（休み申請者側・代勤者側）の作成・承認・却下
```

### Manager Pages

```text
pages/manager/menu.php
pages/manager/employees.php
pages/manager/shifts.php
pages/manager/matching_settings.php       # 代勤候補抽出モード設定
pages/manager/notifications.php
pages/manager/approvals.php               # 休み申請の承認・却下・代勤候補の手動再抽出ボタン
pages/manager/cancellation_requests.php   # 承認後キャンセル申請（両種別）の確認・承認・却下
pages/manager/rematch_leave_request.php   # 手動再抽出のPOST処理専用（画面表示なし）
```

### Employee Pages

```text
pages/employee/menu.php
pages/employee/shifts.php                 # 自分のシフト確認・代勤者側キャンセル申請フォーム
pages/employee/availability.php
pages/employee/leave_request.php          # 休み申請・承認前キャンセル
pages/employee/notifications.php
pages/employee/candidate_response.php
pages/employee/result.php                 # 承認結果確認・休み申請者側キャンセル申請フォーム
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

## Implemented: 代勤候補抽出モード・スコア計算（完了済み）

候補者抽出は勤務可能日・勤務可能時間に基づく必須条件に加えて、店長が選んだ抽出モードに応じたスコア計算を行います。**この機能は実装済みです**（旧「Current Next Task」はクローズ済み）。詳細・画面・動作確認手順はREADME.mdの「代勤候補抽出モード選択・スコア計算」セクションを参照してください。

抽出モード：

```text
normal             : 通常
staffing_priority  : 人員確保優先
skill_priority     : スキル重視
```

必須条件（モードに関わらず常に同じ。`findSubstituteCandidates()`）：

* 休み申請者本人ではない
* 有効な従業員である
* 勤務可能日が対象シフト日と一致している
* 勤務可能時間が対象シフト時間をカバーしている
* 同時間帯に別シフトが入っていない

スコア条件（必須条件を満たした候補者にのみ計算。`calculateCandidateScore()`）：ポジション一致度・スキルレベル・勤続年数・時間一致度を、モードごとの重みで0〜100点に集計します。スコアは絶対評価ではなく、候補者間の優先順位を決めるための相対的な指標です。重みは店長が個別調整するのではなく、モードごとのプリセット（固定値）です。

| 抽出モード  | ポジション一致 | スキル | 勤続年数 | 時間一致度 |
| ------ | ------: | --: | ---: | ----: |
| 通常     |      30 |  30 |   20 |    20 |
| 人員確保優先 |      10 |  10 |   10 |    70 |
| スキル重視  |      30 |  50 |   15 |     5 |

`employees.skill_level`（1〜5）の意味：

```text
1 : 未経験に近い
2 : 補助可能
3 : 通常業務可能
4 : 安定して対応可能
5 : 熟練
```

店長が `pages/manager/matching_settings.php` でモードを選択・保存し（`matching_settings.current_matching_mode`）、休み申請時にその時点のモードが `leave_requests.matching_mode` に記録されます。`match_score` / `match_reason` は `substitute_candidates` に保存され、`pages/manager/approvals.php` の承認画面に表示されます。

**この機能で実装していないもの**（勝手に追加実装しないこと）：上位3人だけへの通知、全員拒否後の次グループ通知、`proposal_round`/`reserved` 状態の導入、複数スキルテーブル、店長による重みの手動調整。

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
* 抽出モード選択・スコア計算
* 承認前キャンセル
* 休み申請者側の承認後キャンセル（`requester_after_approval`）
* 代勤者側の承認後キャンセル（`substitute_after_approval`）
* 代勤候補の再抽出（自動・手動）
* READMEのデモ手順
* `schema.sql → seed.sql` による空DB再構築

## Completed Phases（参考・履歴）

以下はすべて実装済みです。新しいタスクで「未実装」と誤認しないよう、完了済みであることを明記します（詳細は本ファイルの該当セクション、または README.md を参照）。

* 抽出モード選択・スキルレベル/入社日・モード別スコア計算（`matching_settings` / `employees.skill_level` / `match_score` / `match_reason`）
* 承認前キャンセル（`leave_requests.status = matching / no_candidate` の間のみ、休み申請者本人が取消）
* 休み申請者側の承認後キャンセル（`cancellation_requests.request_type = requester_after_approval`）
* 代勤者側の承認後キャンセル（`cancellation_requests.request_type = substitute_after_approval`、`replacement_pending` 状態）
* 代勤候補の再抽出（自動・手動、`retrySubstituteMatching()`）

## Future Development Flow

今後の開発予定は以下です（着手前に必ずユーザーに方針を確認してください）。

### Manager Account Management

必要に応じて実装します。

方針：

* 既存の店長だけが、別の店長アカウントを作成できる
* 誰でも店長登録できる公開画面は作らない
* 店長一覧・追加・有効/無効切替程度に留める

### UI Polish / Presentation Readiness

* 文言統一
* ボタン配置調整
* デモ手順の簡略化
* エラーメッセージ整理
* 画面遷移の分かりやすさ改善

### Sakura Internet Migration

最終段階で実施予定です。今はまだ実施しません。

移行時に必要になること：

* さくら側MySQLデータベース作成
* `database.php` の接続情報切替（DB名は移行先に合わせる。`shift_matching_system` のままで良いかは要確認）
* `schema.sql → seed.sql` の `USE` 文を移行先DB名に合わせて変更、または削除してから実行
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
