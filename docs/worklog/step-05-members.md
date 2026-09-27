# Step 5: メンバー管理 + ロール認可

- **日付**: 2026-09-28
- **状態**: 🚧 実装・テスト完了 / 実機確認待ち
- **完了条件**: member ロールが更新/削除で 403、最後の owner を外せない

## 作ったもの

| メソッド | パス | 誰ができるか | 応答 |
|---|---|---|---|
| GET | `/api/projects/{project}/members` | メンバー全員 | 200、全員を `{ data: [...] }` |
| POST | `/api/projects/{project}/members` | owner | 201 |
| PATCH | `/api/projects/{project}/members/{user}` | owner | 200 |
| DELETE | `/api/projects/{project}/members/{user}` | owner、または本人（脱退） | 204 |

- 部外者は、どれを呼んでも 404（`{project}` のバインディング。Step 4 と同じ）
- owner は複数いてよい。ただし **owner が0人になる操作**（最後の owner の降格・脱退）は 409

## 決めたこと

推奨は「最新の主流」を確かめたうえで、本プロジェクトに合うものにした。主流の基準には、Laravel 公式のスターターキット Jetstream のチーム機能（`AddTeamMember` / `RemoveTeamMember` / `TeamPolicy`）を使った。

| 質問 | 決定 | 根拠 |
|---|---|---|
| 招待したメールアドレスが未登録なら？ | **422** | Jetstream と同じ（`exists:users`）。招待の保留にはメール送信が要り、スコープ外 |
| member が自分で抜けられるか？ | **抜けられる**（最後の owner は除く） | Jetstream・GitHub・Slack と同じ。DESIGN.md の当初案（owner しか外せない）から変更 |
| メンバー一覧を分割するか？ | **全員を一度に返す** | 人数は限られ、担当者の選択（Step 10）でも全員が要る。Jetstream も全員表示 |
| URL の `{member}` に入れる番号 | **ユーザーの id** | 下記 |

### URL の `{member}` はユーザーの id

最初は「参加記録（`project_members`）の id」を推奨したが、ユーザーから「ユーザーの id の方が一般的では」と指摘があった。調べ直したところ、そのとおりだった。

| 例 | URL |
|---|---|
| Jetstream | `/teams/{team}/members/{user}` |
| GitHub | `/orgs/{org}/members/{username}` |
| GitLab | `/projects/:id/members/:user_id` |

最初の推奨は、Jetstream の処理本体（Action）だけを見て、URL の形まで確かめていなかった。本プロジェクトにとってもユーザーの id の方が合う。

- 「自分が抜ける」ときに、`/me` で分かる自分の id をそのまま使える
- Step 6 のタスクの担当者（`assignee_id`）もユーザーの id で、番号の種類が揃う

```php
Route::apiResource('projects.members', ProjectMemberController::class)
    ->except('show')
    ->scoped(['member' => 'user_id']);
```

`scoped()` を付けると、`{member}` は親のプロジェクトの参加者の中からだけ探される。

```sql
SELECT * FROM project_members
WHERE project_members.project_id = ?   -- 親のプロジェクトに属すること
  AND user_id = ?
LIMIT 1
```

親の `{project}` は Step 4 で差し替えたバインディングで先に解決される（`ImplicitRouteBinding::resolveForRoute()` は、解決済みの引数を飛ばす）。子はその `$project->members()` から探される（`Model::resolveChildRouteBinding()` が、引数名 `member` の複数形 `members` をリレーション名として使う）。

## やったこと

### 1. Policy は親のプロジェクトに置いた

```php
// ProjectPolicy
public function addMember(User $user, Project $project): bool       // owner
public function updateMember(User $user, Project $project): bool    // owner
public function removeMember(User $user, Project $project, ProjectMember $member): bool
{
    return $member->user_id === $user->id || $this->isOwner($user, $project);
}
```

Jetstream の `TeamPolicy`（`addTeamMember` など）と同じ形。可否は親のロールで決まり、参加記録（ProjectMember）単体では決まらないため。

### 2. 「owner を0人にしない」は Action がロックしたうえで守る

| Action | 役割 |
|---|---|
| `AddProjectMember` | すでに参加していれば 422。それ以外は参加させる |
| `ChangeProjectMemberRole` | 最後の owner を member に下げるなら 409 |
| `RemoveProjectMember` | 最後の owner を外す（本人が抜ける）なら 409 |

3つとも、最初にプロジェクトの行をロックする。

```php
DB::transaction(function () use ($member) {
    Project::whereKey($member->project_id)->lockForUpdate()->first();
    // SELECT * FROM projects WHERE id = ? LIMIT 1 FOR UPDATE
    $member->refresh();
    ...
});
```

同じプロジェクトへの変更が1本ずつ順番に処理されるので、確認が同時に届いた別のリクエストとずれない。

- **ロックが無い場合:** owner が2人いて互いを同時に下げると、どちらも「owner はまだ2人いる」と読んでから保存し、0人になる
- **二度押しの場合:** 招待ボタンを二度押しすると、2本目が「まだ参加していない」を読んで INSERT し、`UNIQUE(project_id, user_id)` 違反で 500 になる

`$member->refresh()` は、ロックを待つ間に別のリクエストがこの人のロールを変えた場合に備えるもの。

**判定を Policy ではなく Action に置いた理由:** 「owner は何人いるか」はロールの可否ではなく、データの状態の話。同時に届いたリクエストと合わせて数える必要があるので、ロックを取るトランザクションの中でしか正しく判定できない。DESIGN.md には当初「Policy / FormRequest で守る」と書いていたので、直した。

人数の数え方は `ProjectMember::isLastOwner()` にまとめ、2つの Action から使う。

### 3. 409 と 422 の使い分け

3b で決めた基準（同じリクエストを送り直せば解決するか）に合わせた。

| 状況 | 応答 | 理由 |
|---|---|---|
| 最後の owner の降格・脱退 | 409 | 状態の食い違い。送り直しても解決しない（先に誰かを owner にする必要がある） |
| 未登録のメールアドレス / 参加済みの人 | 422 | 入力の誤り。別のアドレスを送れば通る |
| 存在しないロール（`admin` など） | 422 | 入力の誤り |

### 4. 一覧のユーザー情報は UserResource を使わない

```php
// ProjectMemberResource
'user_id' => $this->user_id,
'name' => $this->user->name,
'email' => $this->user->email,
'role' => $this->role,
'joined_at' => $this->created_at,
```

`UserResource` は本人向け（`/me`）で、`two_factor_enabled` を含む。流用すると、**他のメンバーの MFA の設定状況まで見えてしまう。** 3a で書いた「出力は許可リストで固定する」を、相手ごとに分けて適用した形。

`project_members.id` は外に出さない。URL にはユーザーの id を使うので、要らない。

一覧は `with('user')` で、ユーザーをまとめて1回で引く（N+1 を避ける）。

```sql
SELECT * FROM project_members WHERE project_id = ? ORDER BY id
SELECT * FROM users WHERE id IN (?, ?, ...)
```

### 5. テスト

`tests/Feature/Project/ProjectMemberTest.php` に20件。登場人物は owner / member / invitee（登録済みだが未参加）/ outsider（部外者）の4人。

| 検証 | 期待 |
|---|---|
| member が一覧 | 200、参加順、キーは5つだけ（`two_factor_enabled` なし） |
| 部外者が一覧・招待・外す | 404 |
| owner が招待（ロール指定なし / owner 指定） | 201、member / owner |
| 未登録のメールアドレス / 参加済みの人を招待 | 422 |
| **member が招待（未登録・登録済みのどちらも）** | **403**（登録状況を調べられない） |
| owner が member を昇格 | 200 |
| owner が2人いるときに自分を降格 | 200 |
| **最後の owner が自分を降格** | **409**、owner のまま |
| member がロールを変更（不正な値でも） | 403 |
| owner が存在しないロールを指定 | 422 |
| **このプロジェクトの参加者でないユーザーの id** | **404**（別のプロジェクトの参加記録に届かない） |
| owner が member を外す | 204 |
| member が自分で抜ける | 204、その後プロジェクトが 404 |
| member が他の人を外す | 403 |
| **最後の owner が抜ける** | **409** |
| owner が2人いるときに片方が抜ける | 204 |

`ProjectTest` にも1件足した（member が不正な値で更新しても、422 ではなく 403）。

**全93件・396アサーションがパス。** Pint も通っている。

## 詰まった点 1: 権限の確認が、入力の検証より後に走っていた

**症状**

実装を書いている途中で気づいた。owner でない member が招待 API を呼ぶと、次のように応答が分かれる作りになっていた。

- 未登録のメールアドレス → 422「見つかりません」
- 登録済みのメールアドレス → 403

owner でなくても、**そのアドレスが登録済みかどうかを調べられる。**

**原因**

FormRequest の検証は、コントローラの本体より**先に**走る。コントローラの引数を用意する段階で、FormRequest が解決されるため。Step 4 で決めた「コントローラの1行目で `Gate::authorize()`」は、検証が済んだ後に走っていた。

vendor で順序を確認した。

```php
// ValidatesWhenResolvedTrait::validateResolved()
$this->prepareForValidation();

if (! $this->passesAuthorization()) {    // ← FormRequest::authorize()
    $this->failedAuthorization();         //    403
}

$instance = $this->getValidatorInstance();
if ($instance->fails()) {                 // ← 検証。422
    $this->failedValidation($instance);
}
```

**解決**

本文を受け取るエンドポイントでは、Policy を `FormRequest::authorize()` から呼ぶようにした。公式ドキュメントの「Authorizing Form Requests」の形。

```php
public function authorize(): bool
{
    return $this->user()->can('addMember', $this->route('project'));
}
```

`$this->route('project')` は、この時点ですでにプロジェクトのモデルになっている。ルートのバインディングはミドルウェア（`SubstituteBindings`）で解決され、FormRequest はその後に作られるため。部外者はバインディングの段階で 404 になる。

本文の無いエンドポイント（削除・脱退）は、検証が無いので `Gate::authorize()` のまま。Step 4 のプロジェクト更新（`UpdateProjectRequest`）も同じ順序の問題を抱えていたので、同じ形に直した。CLAUDE.md と DESIGN.md の記述も更新した。

**技術メモ**

- 「権限の無い人に何が見えるか」は、判定の中身だけでなく**判定の順序**でも決まる
- Step 4 のテストは「member が正しい入力で更新すると 403」しか見ていなかったので、この順序の問題を捕まえられなかった。「member が**不正な入力**で更新しても 403」を足した

## 詰まった点 2: ページ分けしない一覧だけ `data` で包まれなかった

**症状**

メンバー一覧のテストで `assertJsonCount(2, 'data')` が「`data` が null」で落ちた。

**原因**

3a の詰まった点1と同じ仕組み。`JsonResource::withoutWrapping()` を入れているので、`ProjectMemberResource::collection()` をそのまま返すと、**最上位が裸の配列**になる。一方、ページ分けした一覧（プロジェクト一覧）は別の仕組みで、`data` / `links` / `meta` の形のまま。

```
GET /api/projects                    → {"data": [...], "links": {...}, "meta": {...}}
GET /api/projects/1/members（修正前）→ [...]
```

**解決**

手で `data` に包んだ。

```php
return response()->json([
    'data' => ProjectMemberResource::collection($members),
]);
```

「一覧は常に `data` の中」を CLAUDE.md の守ることに足した。フロントは一覧の種類ごとに形を分けて扱う必要がなくなる。最上位が配列だと、後から件数などを足すときに形を変えるしかない、という理由もある。

**技術メモ**

`assertExactJsonStructure` で配列の各要素を表すには `['*' => [...]]` と書く。最初に `[[...]]` と書いて、「要素0だけを期待したのに要素1もある」という失敗になった。

## テストが正しいことの確認

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| `scoped()` を外す（`{member}` を参加記録の id で探す） | ロール変更・削除の10件 | 期待どおり |
| `{member}` を全プロジェクトの参加記録から user_id で探す | このプロジェクトの参加者でない id のみ | 期待どおり（下記） |
| 本人の脱退を許さない | 自分で抜けるのみ | 期待どおり |
| `removeMember` が常に true | 他の人を外すのみ | 期待どおり |
| `addMember` / `updateMember` が常に true | それぞれ member の招待 / ロール変更のみ | 期待どおり |
| 招待の `authorize()` を外す | member の招待のみ | 期待どおり |
| 最後の owner の判定を無効化 | 降格・脱退の2件 | 期待どおり |
| 参加済みの確認を外す | 参加済みの招待のみ | 期待どおり |
| 未登録メールの検証を外す | 未登録の招待のみ | 期待どおり |
| Resource に `two_factor_enabled` を足す | 一覧のキーのみ | 期待どおり |
| **並び順を指定しない** | **なし** | 下記 |

**2行目:** 1行目の壊し方では、「このプロジェクトの参加者でない id → 404」のテストは落ちなかった。調べると、壊した状態で参加記録の id として探したとき、たまたま一致する行が無く 404 になっていただけだった。より現実的な壊し方（プロジェクトで絞らず、ユーザーの id で全体から探す）に変えたところ、このテストだけが落ちた。**別のプロジェクトの参加記録を書き換えられる（IDOR）ケースを、このテストが捕まえられる**ことを確かめられた。

なお、最初に試した壊し方（ルートに `withoutScopedBindings()` を付ける）は、リソースルートに無いメソッドでルートファイルごとエラーになっていた。テストの出力が空だったのを、元に戻した後の「93 passed」と見間違えかけた。**壊した状態のテストが本当に走ったかを確かめる**。

**並び順:** `orderBy('id')` を外しても、PostgreSQL が追加した順に返すので、テストでは差が出ない。Step 4 のプロジェクト一覧（テストで差が出た）と違い、こちらは主キー順の小さな表なので、実際に順序が崩れる状況を作れなかった。コードで明示しておくにとどめた。

**トランザクションと行ロックもテストで守れていない。** 3c-1・Step 4 と同じく、Feature テストはリクエストを1本ずつ処理するので、同時に届く状況を作れない。

## 判断メモ

- **外れた人が担当していたタスクは、そのまま残る。** `tasks.assignee_id` は外れた人を指し続ける。Step 6 で「担当者はメンバーに限る」を入れるときに扱いを決める
- **owner が owner を外せる。** 複数の owner は対等にした。「作成者だけは外せない」のような特別扱いは、`projects` に作成者の列を持たない設計（所有者は `project_members.role` だけで表す）と合わない
- **招待の応答で登録状況が分かることは、README の「改善余地」（ユーザー列挙）にまとめる。** 調べられるのはログイン済みの owner だけで、レート制限（60回/分）もかかっている

## 実機確認（未実施）

## 成果物

```
api/
├── app/
│   ├── Actions/Project/
│   │   ├── AddProjectMember.php                   (新規)
│   │   ├── ChangeProjectMemberRole.php            (新規)
│   │   └── RemoveProjectMember.php                (新規)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── ProjectMemberController.php        (新規)
│   │   │   └── ProjectController.php              (update の認可を FormRequest へ)
│   │   ├── Requests/Project/
│   │   │   ├── AddProjectMemberRequest.php        (新規)
│   │   │   ├── UpdateProjectMemberRequest.php     (新規)
│   │   │   └── UpdateProjectRequest.php           (authorize() を追加)
│   │   └── Resources/ProjectMemberResource.php    (新規)
│   ├── Models/ProjectMember.php                   (isLastOwner)
│   ├── Policies/ProjectPolicy.php                 (addMember / updateMember / removeMember)
│   └── Providers/AppServiceProvider.php           ({member} を数字に限る)
├── routes/api.php                                 (projects.members)
└── tests/Feature/Project/
    ├── ProjectMemberTest.php                      (新規・20件)
    └── ProjectTest.php                            (+1件)
```

コミット: `20d7995`（実装）→ `13cc45f`（テスト）→ この記録。実機確認の前にコミットした

## 次のステップ

Step 6: タスク CRUD + scopeBindings
