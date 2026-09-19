# Step 2: マイグレーションとモデル

- **日付**: 2026-09-19
- **状態**: ✅ 完了
- **完了条件**: 4テーブル作成、Enum とリレーション定義、Factory

## ゴール

`projects` / `project_members` / `tasks` を作り、所有権とロールをデータ構造として表現する。

## やったこと

### 1. backed enum の定義

`role` と `status` は取りうる値が固定なので、PHP の列挙型で表現した。

```php
enum ProjectRole: string {
    case Owner = 'owner';
    case Member = 'member';
}
```

生の文字列で扱うと `'onwer'` のようなタイプミスが実行時まで表面化しない。**role は認可の判定に使う値**であり、ここの誤りは「本来 403 にすべきリクエストが通る」という形で現れるため、型で守る。

PostgreSQL の `CREATE TYPE ... AS ENUM` は使わない。値の追加・変更に `ALTER TYPE` が必要でマイグレーションの巻き戻しが面倒になるため、**文字列カラム＋CHECK 制約**を採った。

### 2. マイグレーション

| テーブル | 要点 |
|---|---|
| `projects` | **`owner_id` を持たせない。** 所有者は `project_members.role = 'owner'` で表現し、所有権の判定先を1か所に絞る |
| `project_members` | `UNIQUE(project_id, user_id)` で二重登録を防ぐ。このインデックスは「参加しているか」の判定にもそのまま効く |
| `tasks` | `index(project_id, status)` でボードのカラム取得を賄う。`assignee_id` のみ `nullOnDelete` |

CHECK 制約は enum の定義から組み立てているため、ケースを追加してもマイグレーション側の記述は変わらない。

```php
$roles = collect(ProjectRole::cases())
    ->map(fn (ProjectRole $role) => "'{$role->value}'")
    ->implode(', ');

DB::statement("ALTER TABLE project_members ADD CONSTRAINT ... CHECK (role IN ({$roles}))");
```

**外部キーの削除挙動を使い分けている。**

- `project_id` → `cascadeOnDelete`：プロジェクトを消したら参加者行もタスクも消える
- `assignee_id` → `nullOnDelete`：担当者が退会してもタスクは履歴として残し、担当だけ外す

### 3. 制約が実際に効くことを DB へ直接確認

アプリを経由せず `psql` から不正なデータを投入して、DB 単体で防げることを確かめた。

| 投入した値 | 結果 |
|---|---|
| `role = 'admin'` | `ERROR: violates check constraint "project_members_role_check"` |
| `role = 'owner'` | 成功 |
| 同じ `(project_id, user_id)` を再投入 | `ERROR: duplicate key value violates unique constraint` |
| `status = 'blocked'` | `ERROR: violates check constraint "tasks_status_check"` |
| `status` 省略 | `todo` が入る |

### 4. モデルとリレーション

リレーション定義には**生成される SQL をコメントで併記**した。ORM の記述だけでは何がどう絞られるか読み取れず、このプロジェクトでは **JOIN の条件が認可の実装そのもの**になるため。

```php
/**
 * $user->projects()->findOrFail($id)
 *   SELECT projects.* FROM projects
 *   INNER JOIN project_members ON projects.id = project_members.project_id
 *   WHERE project_members.user_id = ?   -- 自分が参加していること
 *     AND projects.id = ?
 *   LIMIT 1
 *   → 非メンバーなら 0 件が返り findOrFail が 404 を投げる
 */
public function projects(): BelongsToMany
```

`Project` には `members()`（中間テーブル行）だけを置き、`users()`（ユーザー本体への多対多）は定義していない。同じ関係を2通りで辿れる状態を避けるため。ユーザー情報は `members()->with('user')` で取る。

`User::projects()` に `withPivot('role')` は付けていない。中間テーブルの値は enum にキャストされず生の文字列で返るという落とし穴があり、必要になるのはプロジェクト一覧 API（Step 4）なので、そこで必要性が確定してから入れる。

### 5. Factory

認可テストでは「オーナー」「メンバー」「部外者」を毎回用意する。素で書くと1テストあたり20行の準備が必要になるため、state を切った。

```php
$owner = User::factory()->create();
$project = Project::factory()->ownedBy($owner)->create();
```

**既定値はランダムにしない。** `fake()->randomElement(TaskStatus::cases())` のように振ると、「todo だけ絞り込めるか」を検証するテストが実行ごとに結果の変わるフレーキーテストになる。既定は固定し、変えたい値だけ state か `create()` の引数で明示する。

### 6. テスト

`tests/Feature/Models/ProjectRelationsTest.php` で6件。enum キャスト、`$user->projects()` のスコープ、外部キーの連鎖削除と SET NULL を固定した。

## 詰まった点

このステップでは特になし。Step 1 で発生していた api コンテナの不調は、healthcheck と `restart: unless-stopped` を入れたことで再発しなかった。

## 判断メモ

- **`projects.owner_id` を持たせるかは設計上の分岐点だった。** 持たせると「オーナーは必ず1人」を DB で表現できるが、`project_members` にも owner 行が要るため二重管理になる。判定先を1か所に絞る方を優先し、「最後の owner は外せない」制約はアプリ側（Policy / FormRequest）で担保することにした
- **テストを PostgreSQL に向けておいた判断がここで効いた。** CHECK 制約と `ON DELETE SET NULL` の挙動は SQLite では再現しない

## 成果物

```
api/
├── app/
│   ├── Enums/{ProjectRole,TaskStatus}.php          (新規)
│   └── Models/{Project,ProjectMember,Task}.php     (新規)
│       └── User.php                                (リレーション追加)
├── database/
│   ├── factories/{ProjectFactory,TaskFactory}.php  (新規)
│   └── migrations/                                 (3ファイル新規)
└── tests/Feature/Models/ProjectRelationsTest.php   (新規)
```

## 次のステップ

Step 3: 認証（Sanctum トークンによる register / login / me / logout）
