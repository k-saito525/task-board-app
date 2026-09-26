# Step 4: プロジェクト CRUD + Policy

- **日付**: 2026-09-27
- **状態**: ✅ 完了（実機確認済み）
- **完了条件**: 非メンバーからのアクセスが 404

## 作ったもの

| メソッド | パス | 誰ができるか | 応答 |
|---|---|---|---|
| GET | `/api/projects` | ログイン済み（自分が参加しているものだけ） | 200、20件ずつ |
| POST | `/api/projects` | ログイン済み（作成者が owner になる） | 201 |
| GET | `/api/projects/{project}` | メンバー | 200 |
| PATCH | `/api/projects/{project}` | owner | 200 |
| DELETE | `/api/projects/{project}` | owner | 204 |

できない人への応答は2種類に分けた。

| 誰が | 応答 | 理由 |
|---|---|---|
| メンバーではない人 | **404** | 403 だと「その番号のプロジェクトは存在する」ことが伝わる。番号を順に試せば、他のチームのプロジェクト数まで分かる |
| メンバーだが owner ではない人 | **403** | 存在はもう知っているので隠す意味がない |

## 決めたこと

実装前に4点を選んだ。推奨は「最新の主流」を確かめたうえで、本プロジェクトに合うものにした。

### 1. 非メンバーを 404 にする方法 → 取得する段階で絞る

Laravel は URL の `{project}` を見て、自動で DB からプロジェクトを取ってくる（ルートモデルバインディング）。既定では**全員のプロジェクトの中から**探す。

| 案 | 仕組み |
|---|---|
| **採用: 取得を差し替える** | 「自分が参加しているプロジェクトの中から探す」に置き換える。メンバーでなければ SQL の結果が0件になり、自動で 404 |
| 見送り: 取った後で確かめる | 全員の中から取ってきて、Policy で「メンバーか」を確かめ、違えば `Response::denyAsNotFound()` |

**主流は見送った方。** 公式ドキュメントの Policy の章は、既定のバインディング＋Policy での判定（`denyAsNotFound` を含む）で説明している。ただ本プロジェクトは「所有権はクエリで縛る。取ってから PHP で持ち主を確かめない」（CLAUDE.md）を方針にしている。見送った方だと、確認を1か所書き忘れると他人のプロジェクトが見える。採用した方なら SQL の条件そのものが守りになり、書き忘れる場所がない。

### 2. Policy の呼び方 → `Gate::authorize()`

CLAUDE.md には「`authorizeResource` を使う」と書いていたが、**Laravel 11 以降の雛形では動かない。** `authorizeResource()` は内部で `$this->middleware()` を呼ぶ。これは旧来の基底クラス（`Illuminate\Routing\Controller`）のメソッドで、今の `app/Http/Controllers/Controller.php` は何も継承しない空のクラスになっている。

代わりの書き方は3つあった。

| 書き方 | 公式ドキュメント（13.x）での扱い |
|---|---|
| **`Gate::authorize('update', $project)`**（採用） | 「Via the Gate Facade」。コントローラで例外を投げて止める基本形 |
| `#[Authorize('update', 'project')]` 属性 | 「Via Middleware」の中の補足。Laravel 13 の新しい書き方 |
| ルートの `->can('update', 'project')` | 「Via Middleware」。条件がルート定義とコントローラの2か所に散る |

ユーザーの指定は「最近の主流」。ドキュメントがコントローラの基本形として示しているのが `Gate::authorize()` なので、それにした。CLAUDE.md と DESIGN.md の記述も直した。

### 3. 一覧は20件ずつに分ける

`paginate(20)`。応答は `data`（中身）・`links`（前後ページの URL）・`meta`（全件数など）の形になる。3a で `JsonResource::withoutWrapping()` を入れたが、ページネーションは別の仕組みなので `data` のラッパーは残る。

### 4. 応答に「自分のロール」を含める

フロントが編集・削除ボタンを出すかどうかの判断に使う。一覧の SQL で `project_members` を JOIN しているので、`withPivot('role')` で同じ行から取れる（追加の問い合わせ無し）。

**ボタンを隠すのは見た目の話で、可否は必ずサーバーの Policy が判定する。**

## やったこと

### 1. `{project}` の取得を差し替えた

```php
// AppServiceProvider::configureRouteBindings()
Route::pattern('project', '[0-9]+');

Route::bind('project', fn (string $value): Project => request()->user()->projects()->findOrFail($value));
```

実際に流れる SQL:

```sql
SELECT projects.*, project_members.user_id AS pivot_user_id,
       project_members.project_id AS pivot_project_id, project_members.role AS pivot_role
FROM projects
INNER JOIN project_members ON projects.id = project_members.project_id
WHERE project_members.user_id = ?   -- 自分が参加していること
  AND projects.id = ?
LIMIT 1
```

- **ユーザーは解決済み。** バインディングは `SubstituteBindings` ミドルウェアで解決される。`Kernel::$middlewarePriority` では `AuthenticatesRequests` の方が先なので、`request()->user()` はもう入っている
- **id は数字に限った。** 数字以外（`/api/projects/abc`）を渡すと、PostgreSQL が `bigint` への変換に失敗して 500 になる。`Route::pattern` でルート自体に一致させず 404 にした

Step 6 のタスク（`/projects/{project}/tasks/{task}`）でも、親の `{project}` はこの仕組みを通る。

### 2. Policy はロールだけを見る

```php
public function update(User $user, Project $project): bool
{
    return $this->isOwner($user, $project);
}
```

「メンバーかどうか」は Policy では見ない。ここに来た時点で、バインディングが SQL で保証しているため。`view` / `create` のように「メンバーなら誰でも」「ログインしていれば誰でも」の操作にはメソッドを置いていない。

```
非メンバー         → バインディングで 404
メンバー・非 owner → Policy が false → 403
```

ロールは毎回 DB に問い合わせる（`exists()`）。バインディングで取れた `pivot->role` を使えば1回減らせる。ただしそれだと、プロジェクトをどう取得したかで判定が変わってしまう。

Policy は命名規則（`App\Models\Project` → `App\Policies\ProjectPolicy`）で自動的に見つかるので、登録は要らない。

### 3. 作成は Action でトランザクションにした

```php
DB::transaction(function () {
    $project = Project::create($attributes);
    $project->members()->create(['user_id' => $creator->id, 'role' => ProjectRole::Owner]);
});
```

2つの INSERT の途中で失敗したときに、「owner がいないプロジェクト」を残さないため。誰もメンバーではないので、作成者にも見えず、消すこともできない。

名前は `Actions/Project/CreateProject`。DESIGN.md では `CreateProjectAction` としていたが、3b の `Actions/TwoFactor/*`（Fortify と同じく接尾辞なし）に揃えた。

### 4. Resource はバインディングの決まりに寄りかかる

```php
'role' => $this->pivot->role,
```

`pivot` は `$user->projects()` 経由で取ったときにしか付かない。もし誰かが `Project::find()` で取ってきて Resource に渡すと、ここでエラーになる。**決まりが破られたら、テストがすぐ落ちる**形になっている（下の「テストが正しいことの確認」の1行目で実際にそうなった）。

作成直後は `create()` が返したモデルに `pivot` が無いので、`$user->projects()->findOrFail($id)` で取り直している。

### 5. 一覧の並び順は id

```php
$request->user()->projects()->orderByDesc('projects.id')->paginate(20);
```

`created_at` は同じ時刻の行があり得るので、それだけでは順序が一意に決まらない。順序が一意でないと、ページの境目で行が重複したり欠けたりする。

`latest()` を使わなかったのは、既定の列 `created_at` が `projects` と `project_members` の両方にあり、JOIN すると曖昧になるため。

### 6. PATCH は送った項目だけを変える

```php
'name' => ['sometimes', 'required', 'string', 'max:255'],
```

`sometimes` は「キーがあるときだけ検証する」。名前を送らずに説明だけを変えられる。送ったときは空にできない。

`description` の上限は 5000 文字。カラムは `text` で長さの制限がなく、上限を置かないと1件で巨大な本文を送りつけられる。

### 7. テスト

`tests/Feature/Project/ProjectTest.php` に15件。登場人物は owner（作成者）/ member（参加者）/ outsider（部外者）の3人。

| 検証 | 期待 |
|---|---|
| トークン無し | 401 |
| 一覧 | 参加しているものだけ、新しい順、`role` 付き。部外者は0件 |
| 21件あるとき | 1ページ目20件、`meta.total` 21、2ページ目に最古の1件 |
| 作成 | 201、`role: owner`、キーは6つだけ、作成者だけが owner として参加 |
| 名前が空・説明が5001文字 | 422 |
| member が表示 | 200、`role: member` |
| **outsider が表示** | **404。存在しない id と同じメッセージ（id 以外）** |
| 数字以外の id | 404（500 にならない） |
| owner が名前だけ変更 | 200、説明は元のまま |
| owner が説明だけ変更 | 200、名前は元のまま |
| member / outsider が変更 | 403 / 404、DB は変わらない |
| owner が削除 | 204、メンバーとタスクも消える（CASCADE） |
| member / outsider が削除 | 403 / 404、残っている |

**全72件・317アサーションがパス。** Pint も通っている。

## 詰まった点

実装中に詰まった問題は特になし。テストが最初から全件通ったので、壊して確かめる手順で穴を1つ見つけた（下記）。

## テストが正しいことの確認

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| バインディングの差し替えを外す（全体から探す） | 部外者の表示・更新・削除（404 のはず）＋ **member の表示・owner の更新** | 期待どおり（下記） |
| id を数字に限る `pattern` を外す | 数字以外の id のみ | 期待どおり |
| `isOwner` が常に true | member の更新・削除 | 期待どおり |
| ロールを見ず、メンバーなら可 | member の更新・削除 | 期待どおり |
| update の `Gate::authorize` を外す | member の更新のみ | 期待どおり |
| delete の `Gate::authorize` を外す | member の削除のみ | 期待どおり |
| 作成者を owner にしない | 作成のみ | 期待どおり |
| 並び順を指定しない | 一覧・ページネーション | 期待どおり |
| **name の `sometimes` を外す** | **なし → テストを追加** | 下記 |

**1行目:** バインディングを既定に戻すと、部外者の 404 が 200 になるだけでなく、正規のメンバーの応答まで 500 になった。`Project::findOrFail()` で取ったモデルには `pivot` が無く、Resource の `$this->pivot->role` で落ちるため。やり方4. で書いた「決まりが破られたらすぐ落ちる」が実際に働いた。

**最終行:** `sometimes` を外すと `name` が常に必須になり、説明だけを変える PATCH が 422 になる。ところが、テストは名前だけを変える場合しか書いていなかった。「説明だけを変えられる」を1件足した（`test_owner_can_update_only_the_description`）。足したテストは、この壊し方をしたときにだけ落ちる。3b と同じく、**壊して初めて「書いていない仕様」に気づけた**例。

**トランザクションはテストで守れていない。** `CreateProject` の2つ目の INSERT だけを失敗させる手段がテストに無いため。3c-1 の行ロックと同じく、コメントで意図を残すにとどめた。

## 判断メモ

- **404 のメッセージにモデルのクラス名が出る。** 本番設定（`APP_DEBUG=false`）でも `{"message": "No query results for model [App\\Models\\Project] 5"}` になる。部外者と存在しない id で同じ文面なので、存在は漏れない。ただ内部のクラス名を見せる必要はないので、Step 14 のエラー形式の統一で「Not Found.」などに揃える
- **Policy に `view` / `viewAny` / `create` を置かない。** 常に true を返すメソッドを並べても判定の場所が増えるだけで、守りにならない。メンバーかどうかはバインディング、ロールは Policy と、役割を分けた
- **プロジェクトの削除は物理削除。** メンバーとタスクは外部キーの `ON DELETE CASCADE` で消える。論理削除（復元）はスコープ外

## 実機確認（curl）

owner / member / 部外者の3人を毎回新しく登録し、owner がプロジェクトを作った。member の追加は Step 5 の API がまだ無いので、`project_members` に psql で直接 INSERT した。

| 手順 | 期待 | 結果 |
|---|---|---|
| owner が作成 | 201、`role: owner` | ✅ |
| member が表示 | 200 | ✅ |
| 部外者が表示 / 存在しない id / 数字以外の id | 404 | ✅ 3つとも |
| member が更新・削除 | 403 | ✅ |
| 部外者が更新・削除 | 404 | ✅ |
| owner が更新 | 200 | ✅ |
| member の一覧 | 変更後の名前、`role: member`、`meta.total: 1` | ✅ |
| owner が削除 → member が表示 | 204 → 404 | ✅ |

各ユーザーの確認手順をシェル関数（`reg` で登録、`st` で「ラベル → ステータスコード」を1行表示）にしたので、期待値と結果を同じ行で見比べられる。Step 5 以降の確認でも使い回せる。

**気づいたこと:** ページネーションの `meta.links` には `"&laquo; Previous"` のような HTML エンティティ入りのラベルが入る。Blade でページ送りを描画するための情報で、API のクライアントには不要。フロントは `links.prev` / `links.next` と `meta.current_page` / `meta.last_page` だけを使えば足りる。消すかどうかは Step 7（OpenAPI）で応答の形を固めるときに決める。

## 成果物

```
api/
├── app/
│   ├── Actions/Project/CreateProject.php          (新規)
│   ├── Http/
│   │   ├── Controllers/Api/ProjectController.php  (新規)
│   │   ├── Requests/Project/
│   │   │   ├── StoreProjectRequest.php            (新規)
│   │   │   └── UpdateProjectRequest.php           (新規)
│   │   └── Resources/ProjectResource.php          (新規)
│   ├── Models/User.php                            (projects に withPivot('role'))
│   ├── Policies/ProjectPolicy.php                 (新規)
│   └── Providers/AppServiceProvider.php           ({project} のバインディング)
├── routes/api.php                                 (apiResource('projects'))
└── tests/Feature/Project/ProjectTest.php          (新規・15件)
```

コミット: `cc5377f`（実装）→ `8dff014`（テスト）→ この記録

## 次のステップ

Step 5: メンバー管理 + ロール認可
