# Step 6: タスク CRUD + scoped

- **日付**: 2026-10-03
- **状態**: 🚧 実装・テスト完了 / 実機確認待ち
- **完了条件**: 他プロジェクトの task id を混ぜると 404、`?status=` が効く

## 作ったもの

| メソッド | パス | 誰ができるか | 応答 |
|---|---|---|---|
| GET | `/api/projects/{project}/tasks?status=` | メンバー全員 | 200、全件を `{ data: [...] }` |
| POST | `/api/projects/{project}/tasks` | メンバー全員 | 201 |
| GET | `/api/projects/{project}/tasks/{task}` | メンバー全員 | 200 |
| PATCH | `/api/projects/{project}/tasks/{task}` | メンバー全員 | 200 |
| DELETE | `/api/projects/{project}/tasks/{task}` | owner | 204 |

- 部外者は、どれを呼んでも 404（`{project}` のバインディング。Step 4 と同じ）
- 別のプロジェクトのタスクの id を URL に混ぜても 404（`scoped()`）

## 決めたこと

このステップから、仕様の判断はこちらに任された。基準は「最新の実務で主流か」「本プロジェクトの方針に合うか」「標準的なセキュリティの水準を満たすか」。主流の例として、タスクボードの代表である Trello と、GitHub の Issue の仕様を確かめた。

| 論点 | 決定 | 根拠 | 見送った案 |
|---|---|---|---|
| タスクの削除 | **owner だけ** | 物理削除で取り消せない。GitHub の Issue も削除は管理者に限る（[GitHub Docs](https://docs.github.com/en/issues/tracking-your-work-with-issues/administering-issues/deleting-an-issue)）。不要なタスクは member でも done にできる | メンバー全員（Trello。ただし「管理者だけが削除できる設定がほしい」という要望が出ている：[TRELLO-72](https://jira.atlassian.com/browse/TRELLO-72)） |
| 外したメンバーの担当 | **担当を外す** | Trello も、ボードから外した人を全カードの担当から外す（[Trello 公式ヘルプ](https://support.atlassian.com/trello/docs/removing-a-member-from-a-board/)）。「担当者は必ずメンバー」を崩さない | そのまま残す（もういない人の名前がカードに出続ける） |
| 一覧の分割 | **全件を返す** | ボードは3列すべてのカードを一度に描く。Trello もボードを開くと全カードを読み込む | 20件ずつ（ボードを描くのに全ページを読むことになる） |
| ステータスの移動 | **通常の PATCH に `status` だけを送る** | GitHub の Issue 更新（`state`）、Trello のカード更新（移動先のリスト）と同じ。移り方の規則（Jira のワークフロー）が無いので入口を分ける理由がない | 専用の `PATCH .../status`（DESIGN.md の当初案。同じことができる場所が2つになる） |

これまでの決まりに沿って決めたもの:

- **作成・編集・移動はメンバー全員**（Step 2 の `ProjectRole::Member` の定義「タスクは操作できる」のとおり）
- **担当者はそのプロジェクトのメンバーに限る**（部外者は 422）
- **`?status=` の不正な値は 422**（黙って無視すると0件が返り、書き間違いに気づけない）
- **応答に担当者の名前を埋め込む**（GitHub の Issue も `assignee` を埋め込む）

## やったこと

### 1. `{task}` は親のプロジェクトのタスクの中からだけ探す

```php
Route::apiResource('projects.tasks', TaskController::class)->scoped();
```

```sql
SELECT * FROM tasks
WHERE tasks.project_id = ?   -- 親のプロジェクトに属すること
  AND id = ?
LIMIT 1
```

CLAUDE.md には「`->scopeBindings()` を付ける」と書いていたが、**リソースルート（`PendingResourceRegistration`）に `scopeBindings()` は無い。** 代わりに `scoped()` を使う。

`scoped()` に何も渡さないと、`ResourceRegistrar::setResourceBindingFields()` が URI の全引数を「列の指定なし（null）」で登録する。`ImplicitRouteBinding` は、引数が `bindingFields` に含まれていれば親のリレーション経由で探すので、`{task}` は `$project->tasks()` の中から id で探される。Step 5 の `scoped(['member' => 'user_id'])` は、列を指定した形。

`route:list` では `{project:}/tasks/{task:}` と表示される（`:` の後ろが空 = 既定の列で照合）。

CLAUDE.md と DESIGN.md の記述を `->scoped()` に直した。

### 2. 削除の判定は ProjectPolicy に置いた

```php
// ProjectPolicy
public function deleteTask(User $user, Project $project): bool
{
    return $this->isOwner($user, $project);
}
```

DESIGN.md では `TaskPolicy` を予定していた。しかし可否は「親のプロジェクトでのロール」で決まり、タスクそのものの属性では決まらない。Step 5 のメンバー管理と同じく、親の Policy に置いた（Jetstream の `TeamPolicy` と同じ置き方）。ロールを読む場所が `ProjectPolicy` 1つに収まる。

作成・編集には Policy のメソッドを置いていない。メンバーであることは `{project}` のバインディングが SQL で保証している。

### 3. 担当者はこのプロジェクトのメンバーに限る

```php
'assignee_id' => [
    'nullable',
    'integer',
    Rule::exists('project_members', 'user_id')->where('project_id', $project->id),
],
```

```sql
SELECT count(*) FROM project_members WHERE user_id = ? AND project_id = ?
```

`exists:users,id` だと「どこかに存在するユーザー」なら通ってしまい、部外者を担当にできる。作成と更新で同じルールを使うので、`TaskRules` トレイトにまとめた。更新側は各項目の先頭に `sometimes` を足す（送った項目だけを検証・更新する）。

### 4. メンバーを外したら担当も外す

```php
// RemoveProjectMember（Step 5）の同じトランザクションの中
$member->project->tasks()
    ->where('assignee_id', $member->user_id)
    ->update(['assignee_id' => null]);
```

```sql
UPDATE tasks SET assignee_id = NULL, updated_at = ?
WHERE project_id = ? AND assignee_id = ?
```

`project_id` で絞るのが大事。同じ人が別のプロジェクトで担当しているタスクには触れない（テストで固定した）。

### 5. 一覧と絞り込み

```php
$project->tasks()
    ->with('assignee')
    ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
    ->orderBy('id')
    ->get();
```

```sql
SELECT * FROM tasks WHERE project_id = ? [AND status = ?] ORDER BY id
SELECT * FROM users WHERE id IN (?, ...)   -- with('assignee')
```

Step 2 で作った複合インデックス `tasks(project_id, status)` がそのまま効く。一覧は Step 5 で決めた「常に `data` の中」に合わせて手で包んだ。

### 6. 応答の形

```json
{
  "id": 12,
  "title": "Write the README",
  "description": null,
  "status": "todo",
  "due_date": "2026-10-31",
  "assignee": { "user_id": 3, "name": "..." },
  "created_at": "...",
  "updated_at": "..."
}
```

- **`due_date` は日付だけの文字列にした。** `date` キャストの値をそのまま出すと `"2026-10-31T00:00:00.000000Z"` になり、受け取った側がタイムゾーンを当てると前日にずれうる
- **担当者はメールアドレスを出さない。** カードに要るのは名前だけで、メールアドレスはメンバー一覧にある
- **作成直後は読み直してから返す。** `status` を省いたときの値（todo）は DB の既定値で決まり、`create()` が返すモデルには入っていない

### 7. `project_id` はリクエストから入れない

```php
$project->tasks()->create($request->validated());
```

`Task` の `#[Fillable]` に `project_id` は無い。リレーション経由の `create()` が親から入れる。リクエストの値でタスクの所属先を変えられる余地を作らない（Step 2 で決めた形がそのまま効いた）。

### 8. テスト

`tests/Feature/Task/TaskTest.php` に19件。登場人物は owner / member / outsider（別のプロジェクトの owner）。

| 検証 | 期待 |
|---|---|
| member が一覧 | 作成順、キーは8つだけ、担当者の埋め込み、`due_date` は日付だけ。別のプロジェクトのタスクは含まない |
| `?status=done` | done だけ |
| `?status=archived` | 422 |
| member が作成（status 省略） | 201、`status: todo` |
| 空のタイトル・不正な status・`2026/10/31` 形式の日付 | 422 |
| **部外者を担当にする（作成・更新）** | **422** |
| **別のプロジェクトのタスクの id（表示・更新・削除）** | **404。元のタスクは変わらない** |
| 数字以外の id | 404 |
| member が `status` だけ送る | 200、他の項目は変わらない |
| member が編集・担当を外す | 200 |
| owner が削除 | 204 |
| **member が削除** | **403、残っている** |
| 部外者の一覧・作成・表示・更新・削除 | 404 |

`ProjectMemberTest` にも1件足した（外した人の担当が外れ、別のプロジェクトの担当はそのまま）。

**全113件・485アサーションがパス。** Pint も通っている。

## 詰まった点

実装中に詰まった問題は特になし。テストも1回目から全件通ったので、壊して確かめる手順で確認した（下記）。

## テストが正しいことの確認

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| `scoped()` を外す（`{task}` を全体から探す） | 別のプロジェクトのタスクの id のみ | 期待どおり（完了条件のテスト） |
| 担当者を「users に存在するか」だけで検証 | 部外者を担当にする（作成・更新）の2件 | 期待どおり |
| `deleteTask` が常に true | member の削除のみ | 期待どおり |
| destroy の `Gate::authorize` を外す | member の削除のみ | 期待どおり |
| `?status=` を検証しない | 不正な status のみ | 期待どおり |
| `?status=` で絞らない | 絞り込みのみ | 期待どおり |
| `due_date` を日時のまま出す | 一覧・作成の2件 | 期待どおり |
| 作成後に読み直さない | 作成のみ（`status` が null） | 期待どおり |
| 外した人の担当を外さない | 担当の解除のみ | 期待どおり |
| 担当の解除をプロジェクトで絞らない | 担当の解除のみ（別のプロジェクトの担当まで消える） | 期待どおり |
| 更新の `sometimes` を外す | `status` だけ送る更新のみ | 期待どおり |
| `{task}` の数字 pattern を外す | 数字以外の id のみ | 期待どおり |
| **並び順を指定しない** | **なし** | Step 5 と同じ。小さな表では PostgreSQL が追加順に返すので差が出ない |

Step 5 の反省（壊した状態のテストが本当に走ったか）を踏まえ、確認用のスクリプトで「`Tests:` の行が出なかったら警告する」ようにした。今回は全件で実行を確認できた。

## 判断メモ

- **担当の割り当てとメンバーの削除が同時に起きた場合は防いでいない。** 「担当者はメンバーか」の検証（タスクの作成・更新）と、メンバーの削除（担当の解除）が同時に走ると、外れた人が担当のまま残りうる。防ぐにはタスクの作成・更新でもプロジェクトの行をロックする必要があり、ふつうの CRUD に対して重い。起きても権限の問題にはならない（外れた人はプロジェクトを見られない）ので、受け入れた
- **期日の過去日は許す。** 過去の作業を記録として登録する使い方があり、Trello・GitHub も過去日を受け付ける
- **タスクの並び替え（D&D）はスコープ外のまま。** 並び順は id（作成順）で、`position` 列は持たない（DESIGN.md のとおり）

## 実機確認（未実施）

## 成果物

```
api/
├── app/
│   ├── Actions/Project/RemoveProjectMember.php    (外した人の担当を外す)
│   ├── Http/
│   │   ├── Controllers/Api/TaskController.php     (新規)
│   │   ├── Requests/Task/
│   │   │   ├── Concerns/TaskRules.php             (新規・作成と更新の共通ルール)
│   │   │   ├── ListTasksRequest.php               (新規)
│   │   │   ├── StoreTaskRequest.php               (新規)
│   │   │   └── UpdateTaskRequest.php              (新規)
│   │   └── Resources/TaskResource.php             (新規)
│   ├── Policies/ProjectPolicy.php                 (deleteTask)
│   └── Providers/AppServiceProvider.php           ({task} を数字に限る)
├── routes/api.php                                 (projects.tasks)
└── tests/Feature/
    ├── Task/TaskTest.php                          (新規・19件)
    └── Project/ProjectMemberTest.php              (+1件)
```

コミット: `270bf85`（実装）→ `17ad125`（テスト）→ この記録。実機確認の前にコミットした

## 次のステップ

Step 7: OpenAPI + TS 型生成
