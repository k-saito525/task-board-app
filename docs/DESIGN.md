# task-board-app 設計

## 概要

チーム共有タスクボード。Laravel を API 専用サーバーとし、Next.js(TS) から REST API 経由で利用する、フロント／バックエンド分離構成のアプリケーション。

ユーザーはプロジェクトを作成し、メンバーを招待して、タスクを todo / in_progress / done の3状態で管理する。プロジェクトへのアクセスは所属メンバーに限定され、更新・削除・メンバー管理は owner ロールのみが行える。

設計の主眼は次の4点に置く。

- **ロールベース認可** — 所有権とロールを SQL とポリシーの二層で担保し、他者のデータに触れられない状態を作る
- **フロント／バックエンドの契約管理** — OpenAPI を自動生成し、TypeScript の型として配布することで契約のずれを防ぐ
- **トークン認証の安全な受け渡し** — API トークンをブラウザの JavaScript に一切露出させない
- **CI による品質の自動検証** — テスト・静的解析・整形・型チェック・契約ずれ検出を自動実行する

## 技術選定

| 項目 | 採用 | 理由 |
|---|---|---|
| リポジトリ | モノレポ `api/` + `web/` | API とフロントの変更を1つの差分として追える |
| Backend | PHP 8.4 / Laravel 13 | 認可（Policy）・バリデーション（FormRequest）・レスポンス整形（API Resource）が標準で揃っており、API 専用構成に追加ライブラリをほぼ必要としない |
| DB | PostgreSQL 17 | CHECK 制約・部分インデックス・JSONB など制約をDB側に寄せる手段が豊富。マネージドサービスの選択肢も多い |
| 認証 | Sanctum API トークン（Bearer） | Laravel 標準。トークンを DB で管理するため**個別に失効できる**（JWT の自己完結トークンとの最大の差） |
| Frontend | Next.js 16 (App Router) / TypeScript / Tailwind | Server Components からの取得を主軸にし、トークンをサーバー側に閉じ込められる |
| 型共有 | Scramble で OpenAPI 3.1 生成 → `openapi-typescript` | コードから生成するため、アノテーションの書き忘れによる乖離が起きない |
| Testing | PHPUnit（Feature 中心） | Laravel 同梱。HTTP から DB までを通して認可の振る舞いを検証できる |
| 静的解析 / 整形 | Larastan / Laravel Pint | CI で自動実行する |
| CI | GitHub Actions | — |
| ローカル環境 | 手書き `docker-compose.yml` | 何が動いているかが README から追える |

## フォルダ構造

```
task-board-app/
├── README.md
├── CLAUDE.md
├── docker-compose.yml          # db(postgres:17) + api
├── .mcp.json
├── docs/{DESIGN.md, PROGRESS.md, worklog/}
├── .github/workflows/ci.yml
├── api/                        # Laravel 13
│   ├── Dockerfile
│   ├── app/
│   │   ├── Actions/            # CreateProjectAction, InviteMemberAction
│   │   ├── Enums/              # TaskStatus, ProjectRole
│   │   ├── Http/{Controllers/Api, Requests, Resources}/
│   │   ├── Models/             # User, Project, ProjectMember, Task
│   │   └── Policies/           # ProjectPolicy, TaskPolicy
│   ├── database/migrations/
│   ├── routes/api.php
│   └── tests/Feature/
└── web/                        # Next.js 16
    └── src/
        ├── app/
        │   ├── (auth)/{login,register}/
        │   ├── api/auth/[...]/route.ts   # BFF: トークンを httpOnly Cookie に保管
        │   └── projects/[projectId]/
        ├── components/
        └── lib/api/{schema.d.ts, client.ts}
```

フロントは HMR の速さを優先してホストの `npm run dev` で動かす。DB と API は Docker 上で動かす。

## DB スキーマ

| テーブル | カラム |
|---|---|
| `users` | id, name, email UNIQUE, password, timestamps |
| `projects` | id, name, description, timestamps |
| `project_members` | id, project_id, user_id, role, timestamps。**UNIQUE(project_id, user_id)** |
| `tasks` | id, project_id, assignee_id（NULL可）, title, description, status, due_date, timestamps |

- **`projects` に `owner_id` は持たせない。** オーナーは `project_members.role = 'owner'` で表現し、所有権の判定先を1か所に絞る。プロジェクト作成時に作成者を owner として登録する2ステップが `CreateProjectAction`。「最後の owner は脱退・降格できない」は Policy / FormRequest で守る
- `role` / `status` は文字列カラム + PHP の backed enum（`ProjectRole` = owner/member、`TaskStatus` = todo/in_progress/done）+ モデルの `casts`。DB 側にも CHECK 制約を付けて二重に守る
- 外部キーは `ON DELETE CASCADE`
- インデックス: `project_members(project_id, user_id)` UNIQUE、`tasks(project_id, status)`、`tasks(assignee_id)`

## REST API

すべて `/api` 配下。`GET /api/health` 以外は `auth:sanctum`。

```
POST   /api/auth/register
POST   /api/auth/login                             → { token, user }
POST   /api/auth/logout                            → currentAccessToken()->delete()
GET    /api/auth/me

GET    /api/projects                               自分がメンバーのもののみ
POST   /api/projects
GET    /api/projects/{project}
PATCH  /api/projects/{project}                     owner のみ
DELETE /api/projects/{project}                     owner のみ

GET    /api/projects/{project}/members
POST   /api/projects/{project}/members             owner のみ（email 指定で招待）
PATCH  /api/projects/{project}/members/{member}    owner のみ
DELETE /api/projects/{project}/members/{member}    owner のみ

GET    /api/projects/{project}/tasks?status=todo
POST   /api/projects/{project}/tasks
GET    /api/projects/{project}/tasks/{task}
PATCH  /api/projects/{project}/tasks/{task}
DELETE /api/projects/{project}/tasks/{task}
PATCH  /api/projects/{project}/tasks/{task}/status
```

## 設計方針

Laravel 公式が章立てで提供している専用クラスを主軸に据え、複数ステップの処理だけ Action クラスに切り出す（公式スターターキットの Fortify / Jetstream が `app/Actions/` を使っているのと同じ形）。`Controller → Service → Model` の層構造は採用しない。Service が何でも入る箱になりやすく、責務の境界が曖昧になるため。

| 責務 | 使う道具 |
|---|---|
| ルーティング | `Route::apiResource()->scopeBindings()` |
| バリデーション | `FormRequest` |
| 認可 | `Policy` + `authorizeResource` |
| レスポンス整形 | `JsonResource`（API Resource） |
| データ取得 | Eloquent のリレーション経由 |
| 複数ステップの処理 | Action クラス |

### 設計上の要点

1. **`scopeBindings()` による親子整合の検証** — `/projects/1/tasks/99` で task 99 が project 1 のものでなければ Laravel が自動で 404 を返す。IDOR 対策をルーティング層で担保する
2. **所有権はクエリで縛る** — `Project::find($id)` ではなく `$request->user()->projects()->findOrFail($id)`。取得してから PHP で持ち主を判定する実装は、条件の書き漏れがそのまま情報漏洩になるため採らない
3. **認可判定は Policy に集約** — `project_members.role` を読むのは `ProjectPolicy` / `TaskPolicy` のみ。Controller は `$this->authorize(...)` を呼ぶだけ
4. **API Resource + `whenLoaded()`** — リレーションが読み込み済みのときだけ出力し N+1 を防ぐ。`paginate()` と組めばページネーションのメタが自動で付く
5. **トークンの有効期限** — `config/sanctum.php` の `expiration` を設定する。トークンは DB にあるため個別失効が可能

## Next.js 側の構成

App Router の Server Components を主軸にデータを取得し、変更は Server Actions + `revalidatePath` で行う。

**BFF による認証フロー**（トークンをブラウザの JavaScript に一切触らせない）:

1. ブラウザ → `web` の Route Handler `POST /api/auth/login`
2. Route Handler → Laravel `POST /api/auth/login`
3. Laravel が `$user->createToken('web')->plainTextToken` を返す
4. Route Handler が **httpOnly + SameSite=Lax + Secure** Cookie にトークンをセットして返す
5. 以降 Server Component / Server Action が `cookies()` からトークンを読み、`Authorization: Bearer` を付けて Laravel を呼ぶ

トークンが `document.cookie` からも `localStorage` からも読めないため、XSS が起きてもトークンを持ち出せない。

型は `npm run gen:api` で `web/src/lib/api/schema.d.ts` を再生成する。生成物はコミットし、API の変更がフロントの型にどう波及したかを差分でレビューできるようにする。fetch は `openapi-fetch` で型を効かせる。

## CI

1ワークフロー2ジョブ。

- **api**: `services: postgres:17` を立てて `php artisan test` / `composer analyse`（Larastan）/ `pint --test`
- **web**: `npm ci` / `eslint` / `tsc --noEmit` / `next build`
- **契約ずれ検出**: OpenAPI を再生成し、コミット済みのものと `git diff --exit-code` で比較。ずれていたら fail

## 画面

タスクは todo / in_progress / done の3カラムで表示し、カード上のボタンでステータスを移動する（`PATCH .../status` 1本）。ドラッグ&ドロップは並び順を保存する `position` カラムの採番設計が必要になるため、MVP 完成後の拡張とする。

## スコープ外（README の「改善余地」に明記する）

- D&D による並び替え（`position` の採番設計・楽観的更新）
- タスクのコメント、ラベル、添付ファイル、活動ログ
- リフレッシュトークンのライフサイクル、Sanctum の abilities によるトークンスコープ
- メール通知（招待メール等）、パスワードリセット
- WebSocket / SSE によるリアルタイム更新
- 本番デプロイ（Vercel + Fly.io/Render + マネージド Postgres）
- E2E テスト（Playwright）

## 実装ステップと検証方法

ステップ一覧と各ステップの完了条件は [PROGRESS.md](./PROGRESS.md) を参照。

- **Step 1**: `docker compose up -d` → `curl localhost:8000/api/health`、`docker compose exec db psql -U app -d taskboard -c '\dt'`
- **Step 3〜6**: curl で一連の流れを確認。特に**他ユーザー・非メンバーからのアクセスが 403/404 になること**を毎回確認する
- **Step 8〜11**: ブラウザで「登録 → プロジェクト作成 → タスク作成 → ステータス移動 → メンバー招待 → 別アカウントで確認」を手動で通す
- **Step 12**: `docker compose exec api php artisan test`
- **Step 13**: GitHub に push して Actions が緑になること
