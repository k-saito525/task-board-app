# Step 7: OpenAPI + TS 型生成

- **日付**: 2026-10-03
- **状態**: 🚧 実装・テスト完了 / ブラウザでの確認待ち
- **完了条件**: `/docs/api` が開ける、`schema.d.ts` が生成される

## 範囲の調整

`schema.d.ts` の置き場所は `web/src/lib/api/`（DESIGN.md）だが、`web/`（Next.js）は Step 8 で作る。先にフォルダを作ると、Next.js の雛形を作るコマンド（`create-next-app`）が「既存のファイルと衝突する」と止まる。

そこで Step 7 は次の3点までにした。`web/` への組み込み（`npm run gen:api`）は Step 8 で行う。

1. `/docs/api` を開けるようにする
2. OpenAPI を `api/openapi.json` に書き出してコミットする
3. そこから TypeScript の型を生成できることを確かめる（作業用の一時フォルダに出力）

## やったこと

### 1. 先に既存の脆弱性を直した

`composer require dedoc/scramble` の最後に、Composer が既存のパッケージの注意報を出した。

| パッケージ | 注意報 | 重大度 |
|---|---|---|
| `league/commonmark` 2.10.1 | GHSA-3q6v-r5mr-hxv8: GFM の表拡張で二次時間の DoS | high |
| 同上 | GHSA-97jj-33gv-5xf9: 許可しない HTML タグの除去をすり抜けられる | medium |

Laravel 本体の依存（`laravel/framework` → `^2.8.1`）で、9月30日に公表されたもの。この API は Markdown を描画しないが、修正版（2.10.2・2.10.3）が出ている既知の脆弱性は残さない。

Scramble の追加と混ざらないよう、いったん `composer.json` / `composer.lock` を退避し、`composer update league/commonmark` でこのパッケージだけを 2.10.3 に上げて、別のコミット（`0c33e9f`）にした。その後に Scramble を入れ直した。`composer audit` は0件。

### 2. Scramble を入れた

```bash
docker compose exec api composer require dedoc/scramble:^0.13
docker compose exec api php artisan vendor:publish --tag=scramble-config
```

v0.13.47。Laravel 13 に対応している（`illuminate/contracts ^10|^11|^12|^13`）。公式の手順どおり `require`（`--dev` ではない）で入れた。ドキュメントの公開範囲は下の 4. で制限している。

何も設定しない状態で書き出し、Scramble がこの API をどう読み取ったかを確かめた。ルート・FormRequest・Resource から24本の操作をほぼ読み取れたが、次の6点を直す必要があった。

| # | 問題 | 対処 |
|---|---|---|
| 1 | 題名が `Laravel`（`APP_NAME`） | `config/scramble.php` の `ui.title` に `Task Board API` |
| 2 | 認証方式（Bearer）が書かれていない | `MiddlewareAuthSecurityStrategy`（下記） |
| 3 | タスクの作成・更新に本文の定義が無い（警告2件） | 下の「詰まった点 1」 |
| 4 | 更新が `PUT` | `preferPatchMethod()` |
| 5 | メンバーの降格・脱退の 409 が無い | `ConflictHttpException` と `@throws`（下記） |
| 6 | **MFA 解除のパスワードがクエリになっている** | 下の「詰まった点 2」 |

### 3. 書き出し方の設定

```php
// AppServiceProvider::configureApiDocs()
Scramble::configure()
    ->preferPatchMethod()
    ->withDocumentTransformers([
        function (OpenApi $openApi): void {
            $openApi->servers = [Server::make('/api')];
        },
        DeleteParametersAsBody::class,
    ]);
```

- **`PATCH` で書く。** `apiResource` の update は `PUT|PATCH` の両方を受ける。Scramble は既定で先頭の `PUT` を書くが、実装は「送った項目だけを変える」（`sometimes`）なので意味は `PATCH`。Scramble に `preferPatchMethod()` が用意されていた
- **サーバー URL は相対パスの `/api`。** 既定では `url()` で `APP_URL` から作られる（`Generator::makeOpenApi()`）。開発環境（`http://localhost:8000`）と CI（`.env.example` の `http://localhost`）で `APP_URL` が違うと、同じコードでも書き出し結果が変わり、Step 13 の「コミット済みの OpenAPI との差分チェック」が誤って落ちる。ドキュメント変換は生成の最後に走るので、そこで置き換えた
- **題名も設定ファイルで固定した**（同じ理由。`APP_NAME` に依存させない）

認証方式は、`config/scramble.php` の `security_strategy` を `MiddlewareAuthSecurityStrategy` にした。`auth` / `auth:*` ミドルウェアの付いたルートにだけ Bearer を必須と書き、ログインなどの公開ルートは `security: []`（認証不要）と書き分ける。全体に一律で付ける書き方（`$openApi->secure(...)`）だと、ログインまで「トークンが必要」と書かれてしまう。

### 4. ドキュメントの公開範囲

Scramble の既定のミドルウェア（`RestrictedDocsAccess`）は、`APP_ENV=local` のときだけ通し、それ以外は `viewApiDocs` ゲートが許可しない限り 403 にする。本番で公開しない既定のままにした。テストで固定している（下記）。

### 5. 409 を型として読み取れるようにした

Step 5 のメンバーの降格・脱退は、Action の中で `abort_if(..., 409)` を返していた。Scramble は、コントローラに直接書いた `abort_if` は読み取るが、注入した Action の中までは辿らない。

- Action の `abort_if` を `throw new ConflictHttpException(...)` に変えた（応答は同じ `{"message": ...}` の 409）
- コントローラの `update` / `destroy` に `@throws ConflictHttpException` を書いた。Scramble は PHPDoc の `@throws` を読む（`FunctionLikeDeclarationPhpDocDefinitionBuilder`）

`abort_if(..., 409)` が投げるのは汎用の `HttpException` で、型からは状態コードが分からない。`ConflictHttpException` なら「409 を投げる」ことが型で表せる。

### 6. プロジェクトの `role` を列挙として書く

メンバー一覧の `role` は `"owner" | "member"` の列挙として読み取られたが、プロジェクトの `role` は `string` になった。中間テーブル（pivot）の値はキャストされない生の文字列だから。

最初は `ProjectRole::from($this->pivot->role)` で Enum に変換したが、Scramble は `from()` の戻り値を元の文字列として読み取り、`string` のままだった。Scramble が対応している PHPDoc の `@var` で型を明示した。

```php
/** @var ProjectRole */
'role' => $this->pivot->role,
```

### 7. 書き出しのコマンドと置き場所

```bash
docker compose exec api composer openapi   # = php artisan scramble:export --path=openapi.json
```

`api/openapi.json` に書き出してコミットする（15パス・24操作・17スキーマ、約86KB）。CLAUDE.md に「API の入出力を変えたら `composer openapi` を実行して一緒にコミットする」を足した。

### 8. TypeScript の型が生成できることを確かめた

```bash
npx openapi-typescript@7 api/openapi.json -o <一時フォルダ>/schema.d.ts   # 7.13.0
npx -p typescript@5 tsc --noEmit --strict <一時フォルダ>/schema.d.ts
```

1,328行の型が生成され、TypeScript の厳格モードでエラーなく読み込めた。列挙は `ProjectRole: "owner" | "member"`、`TaskStatus: "todo" | "in_progress" | "done"` のような文字列リテラルの合併型になる。ログインの応答は「トークン」と「MFA の引換券」の `anyOf` で、フロントは `two_factor` の有無で分岐できる。

### 9. テスト

`tests/Feature/ApiDocumentationTest.php` に5件。テストの中で OpenAPI を一時ファイルに書き出して読む。Scramble の版を上げたときなどに、気づかないうちに崩れるのを防ぐ。

| 検証 | 期待 |
|---|---|
| `/docs/api` と `/docs/api.json` | 開発環境以外（テストは `testing`）では 403 |
| どの操作でも | パスワードをクエリに取らない。MFA 解除のパスワードは本文 |
| 更新 | `PATCH` で書かれ、`PUT` は無い |
| サーバー | `[{"url": "/api"}]` |
| 認証方式 | Bearer。register / login / two-factor-challenge は `security: []`、`/auth/me` は必須のまま |

**全118件・514アサーションがパス。** Pint も通っている。

## 詰まった点 1: Scramble がタスクの FormRequest を読めなかった

**症状**

書き出しで警告が2件出て、タスクの作成・更新に本文の定義が付かなかった。

```
WARN [VR001] StoreTaskRequest::rules() call failed
Source at app/Http/Requests/Task/Concerns/TaskRules.php:34
Message   Attempt to read property "id" on null
```

**原因**

Scramble は OpenAPI を作るときに、本物のリクエストの外で `rules()` を呼び出して検証ルールを読む。ルートが無いので `$this->route('project')` が `null` になり、`$project->id` で止まっていた。

**解決**

プロジェクトの条件をクロージャに包み、検証の時点で初めて読むようにした。

```php
Rule::exists('project_members', 'user_id')->where(
    fn (Builder $query) => $query->where('project_id', $this->project()->id),
),
```

クロージャの中身は本物の検証でしか実行されないので、`rules()` 自体はどこからでも呼べる。発行される SQL は同じ。

Scramble の提案（`$this->route('param')?->id` のように `null` を許す）は採らなかった。ドキュメント生成のためだけのガードで、本物のリクエストでは起こり得ない `null` を許すことになる（「起こり得ない入力へのガードは書かない」に反する）。

## 詰まった点 2: MFA 解除のパスワードが URL のクエリとして書かれていた

**症状**

`DELETE /auth/two-factor`（現在のパスワードを送って MFA を解除する）の `password` が、`"in": "query"` で書かれていた。

**原因**

Scramble は DELETE を「本文を持たないメソッド」として扱い、FormRequest の項目をすべてクエリとして書く（`RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY = ['get', 'delete', 'head']`）。`#[BodyParameter]` 属性を付けても、DELETE ではこの分岐が先に来るので効かない。

この OpenAPI から型を作ると、フロントは型どおりにパスワードを URL に乗せて送る。URL はアクセスログ・プロキシのログ・ブラウザの履歴に残るので、**パスワードが平文で記録される。**

**解決**

API の形（DELETE に本文でパスワードを送る）は変えなかった。Laravel のスターターキットと同じ形だから（Breeze のアカウント削除 `DELETE /profile`、Jetstream の同機能は、本文で現在のパスワードを送る）。RFC 9110 は DELETE の本文を「サーバーが受け付けると示した場合」に限って認めており、この OpenAPI がその表明になる。

直したのはドキュメントの書き方で、DELETE のクエリ項目を JSON の本文に移すドキュメント変換（`App\Support\OpenApi\DeleteParametersAsBody`）を足した。この API の DELETE はクエリパラメータを取らないので、DELETE のクエリはすべて移す。Scramble の `Schema::createFromParameters()` が、パラメータの一覧から本文のスキーマを組み立ててくれる。

**技術メモ**

- 自動生成されたドキュメントは、**セキュリティの観点でも読む**。ドキュメントがそのまま型になり、型がそのままリクエストになるので、ドキュメントの誤りが実装の誤りに直結する
- 「どの操作もパスワードをクエリに取らない」をテストで固定した。特定の1本ではなく全操作を見るので、今後 DELETE を足しても守られる

## テストが正しいことの確認

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| `preferPatchMethod()` を外す | PATCH のみ | 期待どおり |
| サーバー URL の置き換えを外す | サーバー URL のみ | 期待どおり |
| `DeleteParametersAsBody` を外す | パスワードのクエリのみ | 期待どおり |
| `security_strategy` を `null` に戻す | 認証方式のみ | 期待どおり |
| `viewApiDocs` ゲートで誰にでも開放する | ドキュメントの公開範囲のみ | 期待どおり（下記） |

最後の行は、最初に `Gate::define('viewApiDocs', fn () => true)` で壊したところ、テストが落ちなかった。Laravel のゲートは、ログインしていない相手には、クロージャが `?User` のように「ユーザー無し」を受け付けると明示しない限り、常に false を返す。つまり**壊したつもりで、実際には誰にも開放されていなかった。** `fn (?User $user = null) => true` で壊し直すと、期待どおり落ちた。Step 5 と同じく、壊し方そのものが正しいかを確かめる必要があった。

## 判断メモ

- **コードのコメントが API ドキュメントの説明文になる。** Scramble は、配列の要素や引数の直前のコメントを説明文として拾う。この API では約20件が、実装上の理由や発行される SQL だった（例：`password` の説明が「ガードを明示するのは…」）。内容はリポジトリのコードにすでに書かれているもので、ドキュメントを読むのも同じリポジトリのフロントの開発者なので、秘密が漏れるわけではない。コメントを該当行から離すと「SQL をコードの横に残す」書き方が崩れるので、今は受け入れた。利用者向けに API ドキュメントを整える必要が出たら、Step 14 で見直す
- **`meta.links` の HTML ラベル**（Step 4 で気づいた `&laquo; Previous`）は、OpenAPI にも `links` の配列として載っている。フロントは使わない想定のまま。消すなら Step 14 のエラー形式・応答形式の統一で扱う
- **OpenAPI と実装のずれの検出は Step 13（CI）で行う。** PHPUnit のテストにすることもできるが、DESIGN.md の計画どおり CI で「書き出し直して `git diff --exit-code`」にする

## ブラウザでの確認（未実施）

`APP_ENV=local` の開発環境で、ブラウザから `http://localhost:8000/docs/api` を開く。

## 成果物

```
api/
├── app/
│   ├── Actions/Project/
│   │   ├── ChangeProjectMemberRole.php          (409 を ConflictHttpException に)
│   │   └── RemoveProjectMember.php              (同上)
│   ├── Http/
│   │   ├── Controllers/Api/ProjectMemberController.php  (@throws)
│   │   ├── Requests/Task/Concerns/TaskRules.php         (プロジェクトの条件をクロージャに)
│   │   └── Resources/ProjectResource.php                (role を @var で列挙に)
│   ├── Providers/AppServiceProvider.php         (configureApiDocs)
│   └── Support/OpenApi/DeleteParametersAsBody.php  (新規)
├── composer.json / composer.lock                (dedoc/scramble、composer openapi)
├── config/scramble.php                          (新規・題名、説明、認証方式)
├── openapi.json                                 (新規・書き出した OpenAPI)
└── tests/Feature/ApiDocumentationTest.php       (新規・5件)
```

コミット: `0c33e9f`（`league/commonmark` を 2.10.3 に）→ `f397c05`（実装）→ `1989848`（テスト）→ この記録。ブラウザでの確認の前にコミットした

## 次のステップ

Step 8: Next.js 雛形 + BFF 認証（`npm run gen:api` で `web/src/lib/api/schema.d.ts` を生成する）
