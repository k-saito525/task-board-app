# Step 1: Docker Compose + Postgres + Laravel 雛形

- **日付**: 2026-09-12
- **状態**: ✅ 完了
- **完了条件**: `docker compose up -d` → `curl localhost:8000/api/health` が 200

## ゴール

PostgreSQL 17 と Laravel 13 が Docker 上で動き、DB 接続まで含めて生きていることを1本の URL で確認できる状態にする。

## やったこと

### 1. Laravel のインストール

```bash
composer create-project laravel/laravel api --no-interaction
php artisan --version   # Laravel Framework 13.31.0
```

`create-project` は **SQLite をデフォルトの DB として作り、マイグレーションまで自動で走らせる**。今回は PostgreSQL を使うので `database/database.sqlite` は削除した。

### 2. Laravel 13 で気づいた変更点

`app/Models/User.php` がプロパティではなく **PHP アトリビュート記法**になっていた。

```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

Laravel 12 までは `protected $fillable = [...]` と書いていた部分。Laravel 13 の新機能で、`$table` / `$fillable` / `$hidden` / `$primaryKey` などをアトリビュートで宣言できる（従来の書き方も引き続き有効）。

また、`create-project` した時点で `CLAUDE.md` と `AGENTS.md`（同一内容）が同梱されていた。中身は `<laravel-boost-guidelines>` で囲まれた仮置きのブートストラップ指示で、「Laravel Boost を入れろ」と書かれている。扱いは後述（セクション6）。

### 3. Docker 環境の構築

`api/Dockerfile`（PHP 8.4 CLI + pdo_pgsql）と、ルートの `docker-compose.yml`（db + api）を作成。

```yaml
db:
  image: postgres:17
  healthcheck:
    test: ["CMD-SHELL", "pg_isready -U app -d taskboard"]
api:
  build: ./api
  depends_on:
    db:
      condition: service_healthy
```

**healthcheck が必要な理由**: Postgres には「コンテナは起動したが、まだ接続を受け付ける準備ができていない」という数秒間がある。api が先に起動すると DB 接続エラーで落ちるため、`pg_isready` が通るまで待たせる。

### 4. Sanctum の導入

```bash
php artisan install:api --no-interaction
```

このコマンドがやること:

- `laravel/sanctum` を composer で追加
- `routes/api.php` を作成（`/api` プレフィックスで登録される）
- Sanctum の config と migration を publish
- **migration を実行**（`personal_access_tokens` テーブル作成）

最後に `Please add the [Laravel\Sanctum\HasApiTokens] trait to your User model.` と出るので、手動で追加する。

```php
use Laravel\Sanctum\HasApiTokens;   // ファイル冒頭: import

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;   // クラス内: トレイトの取り込み
}
```

このトレイトが生やすメソッド（`vendor/laravel/sanctum/src/HasApiTokens.php` で確認）:

```
tokens()  tokenCan()  tokenCant()  createToken()
generateTokenString()  currentAccessToken()  withAccessToken()
```

Sanctum は `Contracts/HasApiTokens.php`（インターフェース）と `HasApiTokens.php`（トレイト）の**両方**を提供している。型として要求するのはインターフェース、既定の実装を配るのはトレイト、という Laravel の定石。

### 5. ヘルスチェックの実装

```php
// routes/api.php
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        return response()->json(['status' => 'error', 'database' => 'down'], 503);
    }

    return ['status' => 'ok', 'database' => 'up'];
});
```

Laravel には標準で `/up` があるが（`bootstrap/app.php` の `health: '/up'`）、**これはアプリがブートできたかしか見ておらず、DB が落ちていても 200 を返す**。DB 接続まで含めた生死を返したいので自前で用意した。

### 6. Laravel Boost をドキュメント検索専用で導入

同梱の `CLAUDE.md` / `AGENTS.md` が要求していた `laravel/boost` を、**機能を絞って**導入した。

Boost は4つの機能を持つが、`boost:install` はフラグで選択できる。

| 機能 | 採否 | 理由 |
|---|---|---|
| MCP サーバー（`search-docs` 他） | **採用** | インストール済みバージョンに合わせた Laravel ドキュメント検索が得られる |
| AI ガイドライン生成 | 不採用 | `CLAUDE.md` は本プロジェクトの方針を自分で書く |
| Agent Skills | 不採用 | 同上 |
| プロジェクトルール（`.ai/rules`） | 不採用 | 仕組みを二重に持たない |

```bash
composer require laravel/boost --dev
php artisan boost:install --mcp --no-interaction   # --mcp だけを指定
```

`--mcp` のみを渡したことで `CLAUDE.md` / `AGENTS.md` は上書きされなかった。ただし `boost:install` は**検出したエディタすべてに設定を配る**ため、Claude Code 用の `api/.mcp.json` に加えて Cursor 用の `api/.cursor/mcp.json` も生成された。使っていないエディタの設定なので削除した（ユーザーのグローバル設定には何も書き込まれていないことも確認済み）。

その後:

- 雛形の `api/CLAUDE.md` `api/AGENTS.md` を削除し、リポジトリルートに自前の `CLAUDE.md` を作成
- `.mcp.json` はルートに移し、args を `["api/artisan", "boost:mcp"]` に変更（モノレポのルートから起動するため）
- `.env` に `BOOST_RULES_ENABLED=false` を追加してプロジェクトルール機能を無効化
- `boost.json` は再生成されるため `.gitignore` に追加

MCP サーバーの疎通は JSON-RPC を直接流し込んで確認した。

```bash
printf '%s\n%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | php artisan boost:mcp
```

`BOOST_RULES_ENABLED=false` の前後でツール数が **10 → 9** に減り、`record-rule` が消えたことで設定が効いていることを確認できた。残る9ツールは `application-info` / `browser-logs` / `database-connections` / `database-query` / `database-schema` / `get-absolute-url` / `last-error` / `read-log-entries` / `search-docs`。

> **注意**: `boost:install` を引数なしで再実行すると `CLAUDE.md` / `AGENTS.md` を上書き生成する。実行しないこと。

### 7. Claude Code の権限設定

`.claude/settings.json` を作成し、AI エージェントに許す操作を明示的に定義した。方針は**「明示的に許可したもの以外はすべて確認を挟む」**。

```json
{
  "cleanupPeriodDays": 7,
  "permissions": { "defaultMode": "default", ... }
}
```

| 分類 | 内容 |
|---|---|
| allow（9件） | `git status` / `diff` / `log` / `show` / `blame` など**読み取り専用の git のみ** |
| ask（15件） | `CLAUDE.md` と `.claude/**` の編集、`api/.env` の読み書き、`git push` / `commit`、`migrate:fresh` 系 |
| deny（25件） | 認証情報の読み取り、`curl` / `wget`、破壊的コマンド |

判断のポイント:

- **`ask` は `allow` より優先される。** `CLAUDE.md` と `.claude/**` を `ask` に置いたのは、将来 `Edit(**)` のような広い許可を足しても、この2つだけは必ず確認が出るようにするため
- **`deny` は `allow` より優先される。** そのため「`curl` は全部 deny、ただし localhost だけ許可」という書き方はできない。全面 deny を選び、HTTP リクエストはユーザーが実行する運用にした
- **`curl` の deny は通信の遮断ではない。** php / node / python が入っている以上、URL の取得は他の手段でも可能。これは「指示されて実行してしまう最も一般的な経路を塞ぐ」防御層であり、境界ではない
- `docker compose down -v` と `docker volume rm` を deny に入れた。`-v` はボリュームごと削除するため **DB のデータが消える**
- `migrate:fresh` 系は deny ではなく ask。マイグレーションの書き直しで実際に使うため

`.mcp.json` については `enableAllProjectMcpServers: false` ＋ `enabledMcpjsonServers: ["laravel-boost"]` とし、**ファイルに別のサーバーが追加されても、名前を明示しない限り起動しない**ようにした。

## 詰まった点

### 問題 1: `install:api` が SQLite のエラーで失敗

**症状**

```
Illuminate\Database\QueryException
Database file at path [.../api/database/database.sqlite] does not exist.
(Connection: sqlite, ...)
```

**原因**

`composer create-project` がデフォルトで作った `database/database.sqlite` を「PostgreSQL を使うから不要」と削除した。しかし `.env` は `DB_CONNECTION=sqlite` のままだった。

`install:api` は Sanctum を入れるだけでなく**最後に migration を実行する**ため、DB への接続が必要になる。接続先が存在しない SQLite ファイルを指していたので落ちた。

**解決**

順序を入れ替えた。

1. `.env` を `DB_CONNECTION=pgsql` に書き換え
2. `docker compose up -d db` で Postgres を起動し、healthy を待つ
3. その後 `install:api` を実行

**技術メモ**

`install:api` は「パッケージを入れるだけ」のコマンドではなく DB を触る。**DB を先に用意してから実行する**のが正しい順序。

---

### 問題 2: Docker デーモンが起動していなかった

**症状**

```
Cannot connect to the Docker daemon at unix:///Users/cheek/.docker/run/docker.sock.
Is the docker daemon running?
```

**原因**

`docker --version` は通っていたので油断したが、それは **CLI のバージョンを表示しているだけ**。Docker Desktop アプリ（＝デーモン本体）が起動していなかった。

**解決**

```bash
open -a Docker
# docker info が通るまで待つ
for i in $(seq 1 60); do docker info >/dev/null 2>&1 && break; sleep 3; done
```

**技術メモ**

`docker --version`（CLI が入っているか）と `docker info`（デーモンに繋がるか）は別物。**疎通確認には `docker info` を使う。**

---

### 問題 3: api コンテナだけ DB に繋がらない ★本命

**症状**

DB は healthy、テーブルも10個できている。それなのに:

```
$ curl localhost:8000/api/health
HTTP/1.1 503 Service Unavailable
{"status":"error","database":"down"}
```

ところが同じコンテナの中で直接実行すると**成功する**。

```
$ docker compose exec api php artisan tinker --execute='DB::connection()->getPdo(); echo "OK";'
OK
```

**調査の手順**

1. コンテナの環境変数を確認 → `docker compose exec api printenv DB_HOST` は `db`。compose の `environment` は効いている
2. Laravel の設定解決を確認 → `config("database.connections.pgsql.host")` も `db`。正しい
3. `pdo_pgsql` の有無を確認 → あり
4. **api コンテナのログで応答時間を見た** → `/api/health ... ~ 0.26ms`

ここが決め手だった。**0.26ms は「接続タイムアウト」の速さではない。** 存在しないホストへの接続なら数秒かかるはずで、この速さは「即座に接続拒否された」＝ **`127.0.0.1`（コンテナ自身）に繋ぎにいっている**サイン。

つまり HTTP 経由のリクエストだけ `DB_HOST` が `db` ではなく `.env` の `127.0.0.1` を見ている。

**原因**

`php artisan serve` は PHP ビルトインサーバーを**子プロセスとして起動する**。このとき **環境変数をホワイトリスト方式でしか渡さない。**

`vendor/laravel/framework/src/Illuminate/Foundation/Console/ServeCommand.php`:

```php
public static $passthroughVariables = [
    'APP_ENV',
    'HERD_PHP_81_INI_SCAN_DIR',
    ... (Herd 関連)
    'IGNITION_LOCAL_SITES_PATH',
    'LARAVEL_SAIL',
    'PATH',
    'PHP_IDE_CONFIG',
    'SYSTEMROOT',
    'XDEBUG_CONFIG',
    'XDEBUG_MODE',
    'XDEBUG_SESSION',
];
```

**`DB_HOST` はこのリストに無い。** そのため docker-compose の `environment: DB_HOST: db` は子プロセスに届かず、`.env` に書いた `DB_HOST=127.0.0.1` が使われていた。コンテナ内の `127.0.0.1` は自分自身なので、Postgres は居らず即座に connection refused になる。

`docker compose exec` で成功していたのは、**exec が子プロセスではなくコンテナの環境変数を直接受け取る別プロセスだから**。この非対称性が混乱の元だった。

**解決**

設定を2か所に分けていたこと自体が原因なので、一元化した。

- `api/.env` と `.env.example` を `DB_HOST=db` に統一
- `docker-compose.yml` の `environment: DB_HOST: db` を削除
- artisan は `docker compose exec api php artisan ...` で実行する運用に統一

```bash
# 検証: 正常時 → DB停止 → 復旧
curl localhost:8000/api/health   # 200 {"status":"ok","database":"up"}
docker compose stop db
curl localhost:8000/api/health   # 503 {"status":"error","database":"down"}
curl localhost:8000/up           # 200 ← 標準の /up は DB が死んでても 200
docker compose start db
curl localhost:8000/api/health   # 200 {"status":"ok","database":"up"}
```

**技術メモ**

- **「exec では動くのに HTTP では動かない」は、環境変数の受け渡し経路を疑う。** 同じコンテナでもプロセスの起動経路が違えば環境が違う
- **失敗が異常に速いのは、タイムアウトではなく即時拒否のサイン。** 応答時間は原因切り分けの手がかりになる
- **同じ設定を2か所（`.env` と compose）に書くと不整合が起きる。** どちらか一方に寄せる
- `php artisan serve` はあくまで開発用。本番では php-fpm + nginx 等を使うのでこの制約は出ない

---

### 問題 4: api コンテナが Up のまま応答せず、停止もできなくなった

**症状**

長時間放置したあと（ホストのスリープを挟んだ可能性が高い）、`docker compose ps` では `api: Up 19 hours` と表示されるのに HTTP が返らない。ログには正常起動時のメッセージが残ったままだった。

```
api-1  |    INFO  Server running on [http://0.0.0.0:8000].
```

`docker compose exec api curl ...` もハングして返ってこない。

**原因**

停止を試みたところ、デーモンがコンテナを kill できない状態だった。

```
Error response from daemon: cannot stop container b29fe067fc71...:
tried to kill container, but did not receive an exit event
```

`docker info` 自体は即座に応答したため、デーモン全体ではなく**このコンテナのプロセス状態が壊れていた**。`restart` も `up --force-recreate` も同じエラーで失敗した。

**解決**

Docker Desktop を再起動した。その後 `docker compose up -d` で復帰したが、コンテナ名に `b29fe067fc71_task-board-app-api-1` という接頭辞が付いていた。**固まった古いコンテナが名前を握ったまま残っていたため、Docker が新しい方をリネームして起動していた。**

```bash
docker compose down     # -v は付けない（ボリューム＝DBデータを消さないため）
docker compose up -d
```

これで `task-board-app-api-1` という正規の名前に戻り、テーブル10個も残ったままだった。

**技術メモ**

- **`Up` はプロセスが健全であることを意味しない。** コンテナが存在するだけで `Up` と表示される。アプリの生死は別途ヘルスチェックで見る必要がある（`api` サービスに healthcheck を付けていなかったのは改善余地）
- **`docker compose down` に `-v` を付けるかどうかは全く意味が違う。** 付けなければボリュームは残りDBデータは保持される。付けると消える。だから `.claude/settings.json` では `docker compose down -v` だけを deny 対象にした
- 復旧後に名前へ接頭辞が付いていたら、古いコンテナが残っているサイン。`down` → `up -d` で作り直す

## 検証結果

| 確認項目 | `/api/health` | `/up`（Laravel標準） |
|---|---|---|
| DB 正常時 | **200** `{"status":"ok","database":"up"}` | 200 |
| DB 停止時 | **503** `{"status":"error","database":"down"}` | **200**（異常に気づけない） |

作成されたテーブル（`docker compose exec db psql -U app -d taskboard -c '\dt'`）:

```
cache / cache_locks / failed_jobs / job_batches / jobs
migrations / password_reset_tokens / personal_access_tokens
sessions / users
```

## 成果物

```
task-board-app/
├── docker-compose.yml      (新規: db + api)
├── CLAUDE.md               (新規: 作業方針)
├── .mcp.json               (新規: laravel-boost MCP)
└── api/
    ├── Dockerfile          (新規: php:8.4-cli + pdo_pgsql)
    ├── .env / .env.example (pgsql、BOOST_RULES_ENABLED を追加)
    ├── routes/api.php      (/api/health を追加)
    └── app/Models/User.php (HasApiTokens トレイトを追加)
```

## 次のステップ

Step 2: マイグレーションとモデル（projects / project_members / tasks の作成、Enum とリレーション定義）
