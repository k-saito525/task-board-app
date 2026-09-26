# task-board-app

チーム共有タスクボード。Laravel(API) + Next.js のモノレポ。

- 設計・技術選定・スコープ: [docs/DESIGN.md](docs/DESIGN.md)
- 進捗とステップ一覧: [docs/PROGRESS.md](docs/PROGRESS.md)
- ステップごとの作業記録: [docs/worklog/](docs/worklog/)

## 構成

```
api/   Laravel 13 (PHP 8.4) — API 専用。Blade は使わない
web/   Next.js 16 (App Router, TS, Tailwind)
```

## コマンド

**artisan はすべて Docker 経由で実行する。**

```bash
docker compose up -d                                  # db + api を起動
docker compose exec api php artisan migrate
docker compose exec api php artisan test
docker compose exec db psql -U app -d taskboard
```

**HTTP リクエストはユーザーが実行する。** `curl` / `wget` は `.claude/settings.json` で deny されており、Claude は HTTP リクエストを発行しない。API の動作確認が必要なときは、実行してほしいコマンドを提示して結果を教えてもらう。

```bash
# ユーザーが自分のターミナルで実行する
curl -i localhost:8000/api/health                     # DB 接続込みの生存確認
```

コンテナ経由（`docker compose exec api curl ...`）も使わない。設定のパターンで確実に捕捉できるとは限らないため、**ここは運用ルールとして守る。**

`php artisan serve` は環境変数をホワイトリスト方式でしか子プロセスに渡さず `DB_HOST` が対象外のため、`.env` の `DB_HOST` は `db`（compose のサービス名）で固定している。ホストから直接 `php artisan` を叩くと DB に繋がらない。経緯は [step-01](docs/worklog/step-01-docker-laravel.md)。

例外: DB に触れないコマンド（`boost:mcp` など）はホストから `php api/artisan ...` で実行できる。

## 設計方針

**Laravel 公式の道具を主軸にする。** `Controller → Service → Model` の層構造はコミュニティ発のプラクティスで公式ドキュメントには無いため、本プロジェクトでは採用しない。

| 責務 | 使う道具 |
|---|---|
| ルーティング | `Route::apiResource()->scopeBindings()` |
| バリデーション | `FormRequest` |
| 認可 | `Policy` + `Gate::authorize()`（非メンバーの排除は `{project}` のバインディングで行う） |
| レスポンス整形 | `JsonResource`（API Resource） |
| データ取得 | Eloquent のリレーション経由 |
| 複数ステップの処理 | `app/Actions/` の Action クラス（Fortify / Jetstream と同じ形） |

守ること:

- **クエリでスコープする。** `Project::find($id)` は使わない。`$request->user()->projects()->findOrFail($id)` のようにリレーション経由で辿り、所有権を SQL 側で縛る。取得してから PHP で持ち主を確認する書き方はしない。URL の `{project}` は `AppServiceProvider::configureRouteBindings()` でこの形に差し替えてあり、非メンバーは 404 になる
- **認可判定は Policy に集約する。** ロールで可否を決めるのは Policy だけ（Resource が `pivot->role` を表示用に出すのは可）。Controller は `Gate::authorize(...)` を呼ぶだけにする。`authorizeResource` は Laravel 11 以降の空の基底 Controller では動かないので使わない
- **ネストしたリソースには `scopeBindings()` を付ける。** `/projects/{project}/tasks/{task}` で親子関係が壊れたリクエストを Laravel に 404 させる（IDOR 対策）
- **起こり得ない入力へのガードは書かない。** 型と契約を信じる

## ドキュメントの書き方

- **「学習用」「教材」といった枠付けは書かない。** 設計判断の理由・トレードオフ・トラブルシュートの記録は残す
- 見送った項目は README の「改善余地」に書く

## 作業の進め方

0. **セッションの開始時は [docs/PROGRESS.md](docs/PROGRESS.md) の「現在地」を最初に読む。** どこまで終わっていて次に何をするかはそこに書いてある
1. 作業は小さく区切る。1ステップ完了ごとに [docs/PROGRESS.md](docs/PROGRESS.md) を更新し、短く報告してから次へ進む
2. ステップごとに `docs/worklog/step-NN-*.md` を書く。**詰まった点は「症状 / 調査の手順 / 原因 / 解決 / 技術メモ」に分解して残す**（詰まらなかった場合は「特になし」と明記する）
3. PROGRESS.md 末尾の「詰まった点の索引」にも1行足す

## Laravel Boost について

`laravel/boost` は **`search-docs`（インストール済みバージョンに合わせた Laravel ドキュメント検索）目的でのみ**導入している。

- MCP 設定はリポジトリルートの `.mcp.json`（`php api/artisan boost:mcp`）
- AI ガイドライン / Agent Skills の自動生成は**入れていない**。このファイルが唯一の指示書
- プロジェクトルール機能は `BOOST_RULES_ENABLED=false` で無効化済み（`.ai/rules` は使わない）
- `boost:install` を再実行すると `CLAUDE.md` / `AGENTS.md` を上書き生成し、使っていないエディタ向けの設定（`.cursor/` など）も撒くので**実行しないこと**

仕様を確認するときは `search-docs` に加えて **`api/vendor/` の実装を直接読む**こと。ドキュメントに書かれていない挙動が原因になる場合がある（step-01 の `ServeCommand::$passthroughVariables` が実例）。
