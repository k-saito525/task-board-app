# 進捗管理

task-board-app の実装進捗。作業は小さく区切り、ステップ完了ごとにこのファイルを更新する。

**各ステップの作業記録（やったこと・詰まった点の症状と原因と解決法・技術メモ）は [`worklog/`](./worklog/) に1ファイルずつ残す。** このファイルは一覧と索引に徹する。

## 現在地

- **完了**: Step 0 / 1 / 2 / 3（基本認証・MFA・レート制限・ログインの2段階化。3b と 3c-1 は**実機確認済み**） / 4（プロジェクト CRUD。**実機確認済み**）
- **次の作業**: **Step 5・6 の実機確認**（curl）と **Step 7 のブラウザ確認**（`http://localhost:8000/docs/api`）。済んだら Step 8（Next.js 雛形 + BFF 認証）へ
- **テスト**: 118 passed / 514 assertions（`docker compose exec api php artisan test`）
- **リポジトリ**: `main` と `origin/main` は同期済み。Step 6 は feat `270bf85` / test `17ad125` / docs、Step 7 は chore(deps) `0c33e9f` → feat `f397c05` / test `1989848` / docs（Step 5〜7 は確認の前にコミット）

まだ存在しないもの: `web/`（Next.js）、`.github/workflows/`、README の本文。

## ステップ一覧

| # | ステップ | 状態 | 完了条件 | 記録 |
|---|---|---|---|---|
| 0 | リポジトリの初期整備 | ✅ 完了 | 環境確認、composer 更新、ドキュメント構成と .gitignore | [step-00](./worklog/step-00-setup.md) |
| 1 | Docker Compose + Postgres + Laravel 雛形 | ✅ 完了 | `docker compose up -d` → `curl localhost:8000/api/health` が 200 | [step-01](./worklog/step-01-docker-laravel.md) |
| 2 | マイグレーションとモデル | ✅ 完了 | 4テーブル作成、Enum とリレーション定義、Factory | [step-02](./worklog/step-02-schema-models.md) |
| 3 | 認証（Sanctum トークン） | ✅ 完了 | 下の 3a / 3b / 3c をすべて満たす | [step-03](./worklog/step-03-authentication.md) |
| 3a | └ 基本認証 | ✅ 完了 | register → login → Bearer 付きで `/me` 200、無しで 401、logout 後に 401 | [step-03](./worklog/step-03-authentication.md#3a-基本認証) |
| 3b | └ MFA の登録・確認・解除 | ✅ 完了 | 認証アプリに登録 → コード確認で有効化 → 解除。シークレットは暗号化して保存 | [step-03](./worklog/step-03-authentication.md#3b-mfa-の登録確認解除) |
| 3c | └ ログインの2段階化 | ✅ 完了 | MFA 有効なユーザーは login でチャレンジを受け取り、コード検証後に本トークンが出る | [step-03](./worklog/step-03-authentication.md#3c-ログインの2段階化) |
| 3c-0 | 　└ レート制限 | ✅ 完了 | 全体 60回/分、認証 10回/分、コード検証 5回/分＋30回/日 | [step-03](./worklog/step-03-authentication.md#3c-0-レート制限完了) |
| 3c-1 | 　└ チャレンジ → 本トークン発行 | ✅ 完了 | TOTP / リカバリコードの検証を通ると本トークンが出る。コードは使い回せない | [step-03](./worklog/step-03-authentication.md#3c-1-引換券と本トークンの発行) |
| 4 | プロジェクト CRUD + Policy | ✅ 完了 | 非メンバーからのアクセスが 404 | [step-04](./worklog/step-04-projects.md) |
| 5 | メンバー管理 + ロール認可 | 🚧 実機確認待ち | member ロールが更新/削除で 403、最後の owner を外せない | [step-05](./worklog/step-05-members.md) |
| 6 | タスク CRUD + scoped | 🚧 実機確認待ち | 他プロジェクトの task id を混ぜると 404、`?status=` が効く | [step-06](./worklog/step-06-tasks.md) |
| 7 | OpenAPI + TS 型生成 | 🚧 ブラウザ確認待ち | `/docs/api` が開ける、`api/openapi.json` を書き出してコミット、そこから TS 型が生成できる（`web/` への配置は Step 8） | [step-07](./worklog/step-07-openapi.md) |
| 8 | Next.js 雛形 + BFF 認証 | ⬜ 未着手 | ブラウザで register → login、リロードで維持、JS からトークンが見えない、`npm run gen:api` で `schema.d.ts` が生成される | — |
| 9 | プロジェクト一覧・作成 UI | ⬜ 未着手 | ブラウザでプロジェクト CRUD が一通り動く | — |
| 10 | ボード UI（3カラム） | ⬜ 未着手 | タスク作成 → ボタンでステータス移動 → 削除が動く | — |
| 11 | メンバー管理 UI | ⬜ 未着手 | 招待した別ユーザーでログインするとボードが見える | — |
| 12 | テスト拡充 | ⬜ 未着手 | `php artisan test` がパス。認可テストを厚めに | — |
| 13 | CI | ⬜ 未着手 | GitHub Actions が緑。OpenAPI 差分チェックも動く | — |
| 14 | 仕上げ | ⬜ 未着手 | エラー形式統一、README 整備 | — |

凡例: ⬜ 未着手 / 🚧 作業中 / ✅ 完了

## 主な決定事項

| 項目 | 決定 |
|---|---|
| リポジトリ | モノレポ `api/` + `web/` |
| DB | PostgreSQL 17 |
| スコープ | チーム共有ボード（`project_members` + owner/member ロール） |
| 認証 | Sanctum API トークン（Bearer）＋ Next.js Route Handler を BFF にして httpOnly Cookie 保管 |
| パスワード | 12文字以上＋漏洩リスト照合（`uncompromised()`）。文字種は強制しない |
| 多要素認証 | TOTP（`pragmarx/google2fa`）+ リカバリコード。Step 3 で実装する |
| レート制限 | 全体 60回/分、パスワード検証 10回/分、コード検証 5回/分＋30回/日。認証済みはユーザー単位、未認証は IP 単位で数える。ログインの2段階目は引換券の持ち主（ユーザー）単位 |
| ログインの2段階化 | 引換券はランダムな ID ＋ キャッシュの控え（5分・1回きり）。コードの空白は取り除く。TOTP は最後に通ったステップを保存して使い回しを塞ぐ |
| 認可 | 非メンバーは `{project}` のバインディングを参加プロジェクトに絞って 404。ロールの判定は Policy。呼び出しは本文ありなら `FormRequest::authorize()`（検証より先）、本文なしなら `Gate::authorize()` |
| タスク | 作成・編集・移動はメンバー全員、削除は owner。担当者はメンバーに限り、メンバーを外すと担当も外す。一覧は全件、ステータスの移動は通常の PATCH |
| 仕様の判断 | Step 6 以降は Claude に一任。最新の実務の主流・本プロジェクトへの適合・標準的なセキュリティで決め、根拠と見送った案を worklog に残す |
| 型共有 | Scramble で OpenAPI 生成 → `openapi-typescript` で TS 型生成。OpenAPI は `composer openapi` で `api/openapi.json` に書き出してコミット。PATCH 優先・サーバー URL は相対・DELETE の項目は本文 |
| API 設計 | Laravel 公式の道具が主軸（`apiResource` + `scopeBindings` / FormRequest / Policy / API Resource）＋ 複数ステップ処理のみ Action クラス |
| ボード UI | 3カラム＋ボタンでステータス移動（D&D は完成後の拡張） |
| テスト | PHPUnit（Feature 中心） |
| 公開範囲 | GitHub Actions で CI まで。デプロイは拡張ステップ |

背景と理由は [DESIGN.md](./DESIGN.md) を参照。

## 詰まった点の索引

過去に踏んだ問題を横断で引けるようにする。詳細は各 worklog を参照。

| ステップ | 問題 | 原因の要約 |
|---|---|---|
| 1 | `install:api` が SQLite エラーで失敗 | `install:api` は migration まで実行する。DB を先に用意する必要があった |
| 1 | Docker デーモンに繋がらない | `docker --version` は CLI の確認にすぎない。疎通は `docker info` で見る |
| 1 | api コンテナだけ DB に繋がらない | `php artisan serve` は環境変数をホワイトリスト方式でしか子プロセスに渡さず、`DB_HOST` が対象外だった |
| 1 | api が Up のまま応答せず停止もできない | コンテナのプロセス状態が壊れていた。Docker Desktop 再起動後、`down`（`-v` なし）→ `up -d` で復旧 |
| 3a | 同じ Resource なのに `data` ラッパーが付いたり付かなかったりする | ラッパーは Resource がレスポンスの最上位にあるときだけ付く。配列に入れ子にすると付かない |
| 3a | ログアウト後のトークンで `/me` が 200 を返す | 実装は正しく、テスト側の問題。1メソッド内でアプリが使い回され、認証ガードが解決済みのユーザーを保持していた |
| 3b | 実装をわざと壊しても1つも落ちない経路があった | テストの穴。「確認待ちの登録はやり直せる」という仕様をテストに書いていなかった |
| 3c-0 | 同じく壊しても落ちなかったが、今回はテストの穴ではなかった | 積んだ上限のキー重複は `RateLimiter` が `fallbackKey()` で自動的に分ける。手で付けた接頭辞が無意味だった（削除） |
| 3b | 実機確認でトークンを付けているのに 401 | Sanctum の平文トークンは `1\|xxxxx` 形式で `\|` を含む。クォート無しで代入して zsh がパイプと解釈していた。`last_used_at` が NULL なのが切り分けの手がかり |
| 3b | 実機確認で正しいコードなのに 422 | 認証アプリは6桁を3桁ずつ区切って表示する。空白込みで送り `digits:6` で落ちていた（コード検証まで到達していない） |
| 3c-1 | わざと壊したら関係ないテストまで10件落ちた | 壊し方が広すぎた。`(int)` を付けたせいで「使い回しを許す」ではなく「どんなコードでも通す」になっていた。壊すのは確かめたい性質1つだけにする |
| 4 | 全件パスしたのに、壊しても落ちない箇所があった | テストの穴。「説明だけを変えられる（name を送らない PATCH）」を書いていなかった |
| 5 | owner でない人が、招待の 422 と 403 の違いでメールアドレスの登録状況を調べられた | FormRequest の検証はコントローラ本体より先に走る。`Gate::authorize()` をコントローラに書くと検証の後になる。`FormRequest::authorize()` へ移した |
| 5 | ページ分けしない一覧だけ `data` で包まれない | `withoutWrapping()` の影響（3a と同じ仕組み）。手で `data` に包み、「一覧は常に data の中」を決まりにした |
| 7 | Scramble がタスクの FormRequest を読めない（警告） | Scramble はリクエストの外で `rules()` を呼ぶので `$this->route('project')` が null。条件をクロージャに包んで検証時に読む |
| 7 | MFA 解除のパスワードが OpenAPI でクエリになっていた | Scramble は DELETE の項目を常にクエリとして書く。そのまま型を作るとパスワードが URL に乗る。ドキュメント変換で本文に移し、テストで固定 |

## 設計ドキュメント

- 全体の設計・技術選定・スコープは [DESIGN.md](./DESIGN.md) を参照
