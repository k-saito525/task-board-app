# Step 3: 認証（Sanctum トークン）

- **日付**: 2026-09-19
- **状態**: ✅ 完了
- **完了条件**: register → login → Bearer 付きで `/me` 200、無しで 401、logout 後に 401。加えて MFA（TOTP）の登録・確認・解除と、ログインの2段階化

## 分割

パスワードだけの認証は、長さを伸ばしても「漏れたら終わり」という性質が変わらない。所持要素（TOTP）を足す方針にしたため、Step 3 を3つに割った。

| | 内容 | 状態 |
|---|---|---|
| 3a | 基本認証（register / login / me / logout） | ✅ 完了 |
| 3b | MFA の登録・確認・解除（TOTP） | ✅ 完了 |
| 3c | ログインの2段階化（チャレンジ → 本トークン発行） | ✅ 完了 |

---

## 3a: 基本認証

### やったこと

#### 1. パスワードポリシーを1か所に集約

```php
Password::defaults(fn () => $this->app->runningUnitTests()
    ? Password::min(12)
    : Password::min(12)->uncompromised());
```

各 FormRequest に `min:12` と直書きすると、登録・リセット・変更で条件がずれたときに気づけない。`Password::defaults()` を `AppServiceProvider` に置き、各所はそれを参照するだけにした。

**文字種は強制しない。** NIST SP 800-63B は文字種の強制がかえって予測可能なパターン（`Password1!`）を生むとして推奨を取り下げ、長さと漏洩リストとの照合を推奨している。`uncompromised()` は Have I Been Pwned の API を k-匿名性の方式で使う（SHA-1 ハッシュの先頭5文字だけを送り、照合はローカル）ため、パスワードそのものは外部に出ない。

テスト時は外部通信を切る。ネットワーク障害で CI が落ちる要因を持ち込まないため。

#### 2. トークンの有効期限

`config/sanctum.php` の `expiration` を1週間（`60 * 24 * 7`）に設定した。Sanctum のトークンは JWT と違い実体が `personal_access_tokens` にあるため、**期限前でも個別に失効させられる**。この差が Sanctum を選んだ理由そのものなので、設定箇所にコメントで残した。

#### 3. 出力は許可リストで固定する

`UserResource` に出すフィールドだけを列挙した。

モデルを直接返すと DB のカラム構成がそのまま API の仕様になる。モデルの `#[Hidden]` は「隠すものを挙げる」拒否リストで、カラム追加のたびに書き足す必要があるのに対し、Resource は「出すものを挙げる」許可リスト。**3b で `two_factor_secret` を `users` に足すため、この方向の違いがそのまま安全性の差になる。**

#### 4. ログイン失敗時の応答をそろえる

```php
if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
    throw ValidationException::withMessages(['email' => __('auth.failed')]);
}
```

メールアドレスが存在しない場合とパスワードが誤っている場合で、同じ 422 と同じメッセージを返す。どちらが誤りかを攻撃者に伝えないため。

ただし**完全には塞げていない**。存在しないメールアドレスでは `! $user ||` が短絡して bcrypt の計算が走らず、応答時間に差が残る（ユーザー列挙）。さらに登録時の `unique:users,email` 検証が 422 で「登録済み」をそのまま伝えるため、ログインだけ塞いでも意味がない。塞ぐなら登録導線ごと変える必要がある。README の「改善余地」に記載する。

#### 5. ログアウトは現在のトークンだけを消す

```php
$request->user()->currentAccessToken()->delete();
```

`tokens()->delete()` だと全端末からログアウトさせてしまう。他端末のトークンが残ることが DB でトークンを管理する方式の利点なので、そちらを固定した。

#### 6. PHPUnit を厳格化

```xml
failOnRisky="true" failOnWarning="true" failOnNotice="true"
failOnDeprecation="true" beStrictAboutOutputDuringTests="true"
```

既定では警告・非推奨・アサーションのないテストが「通った」ことになる。CI を緑にする意味を保つため、これらを失敗として扱う。

#### 7. テスト

`tests/Feature/Auth/AuthenticationTest.php` に11件。**成功する経路より、失敗すべき経路（誤ったパスワード、トークンなし、失効後）が本体。** ここが緩むと以降のすべての認可テストが土台から無意味になる。

| 検証 | 期待 |
|---|---|
| 登録 → ユーザー作成とトークン発行 | 201、平文のパスワードが保存されていない |
| 9文字のパスワード | 422 / ユーザーが作られない |
| 確認用パスワードの不一致 | 422 |
| メールアドレスの重複 | 422 / 2件目が作られない |
| 正しい資格情報でログイン | 200、トークン1件 |
| 誤ったパスワード | 422、**トークンが1件も作られない** |
| 存在しないメールアドレス | 誤ったパスワードと同じ応答 |
| トークン無しで `/me` | 401 |
| `/me` が返すキー | `id, name, email, created_at` に固定 |
| ログアウト後、そのトークンで `/me` | 401 |
| ログアウト後、別端末のトークンで `/me` | 200 |

`/me` のキーを固定するテストは、3b で `users` にカラムを足したときに応答へ漏れ出したらその場で落ちるようにするためのもの。

**全17件・52アサーションがパス。**

### 詰まった点 1: `data` ラッパーが場所によって付いたり付かなかったりする

**症状**

同じ `UserResource` を使っているのに、応答の形が違った。

```
GET  /api/auth/me       → {"data": {"id": 1, ...}}
POST /api/auth/register → {"token": "...", "user": {"id": 1, ...}}
```

**調査の手順**

実際に両方のエンドポイントを叩いて応答を見比べた。この食い違いは、当初こちらが「ラッパーを付けた方が単数・複数で一貫する」と説明していた内容と噛み合わなかったため、説明ではなく実物で確認した。

**原因**

`JsonResource` のラッパーは、**Resource がレスポンスの最上位にあるときだけ**付く。配列に入れ子にして返すと付かない。`register` は `['token' => ..., 'user' => new UserResource(...)]` という配列を返しているため、`user` の中身にはラッパーが付かなかった。

「単数・複数で一貫する」という当初の説明が誤りで、実際には**同じオブジェクトの形が場所によってずれる**という結果になっていた。

**解決**

`AppServiceProvider::boot()` で無効化した。

```php
JsonResource::withoutWrapping();
```

これでユーザーオブジェクトはどこに現れても同じ形になる。一覧のページネーションは別の仕組みなので `data` / `links` / `meta` を返し続ける。フロント側で「最上位かどうか」で分岐する必要がなくなる。

### 詰まった点 2: ログアウトのテストだけ 401 にならない

**症状**

ログアウト後に同じトークンで `/api/auth/me` を叩くテストが、401 ではなく 200 を返して落ちた。

**調査の手順**

実装とテストのどちらが誤っているかを切り分けるため、使い捨てのテストを書いてトークンの件数を直接数えた。

- ログアウト前: 2件（`phone` / `laptop`）
- ログアウト後: 1件、残っているのは `phone`

**削除は正しく行われていた。** つまり実装は意図どおりで、テストの読み取り方に問題があった。

**原因**

本番では1リクエスト＝1プロセスだが、テストでは1メソッド内でアプリのインスタンスが使い回される。認証ガードが解決済みのユーザーを保持したままなので、DB からトークンが消えていても2回目のリクエストが通ってしまっていた。

**解決**

リクエストの間でガードを破棄し、本番と同じ条件に戻す。

```php
$this->app['auth']->forgetGuards();
```

**技術メモ**

「テストが落ちた＝実装が間違っている」とは限らない。ここで実装を書き換えていたら、正しい `currentAccessToken()->delete()` を `tokens()->delete()`（全端末ログアウト）に変えてしまい、テストは緑になったうえで仕様が壊れていた。**落ちた原因を確かめる前に実装へ手を入れない。**

### テストが正しいことの確認

テスト自体が正しく検知できるかを、実装をわざと壊して確かめた（ミューテーションテストを手で行う形）。

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| `auth:sanctum` ミドルウェアを外す | `me requires a token` / `logout revokes only the current token` | 期待どおり |
| `Password::min(12)` → `min(1)` | パスワードポリシーのテストのみ | 期待どおり |
| `currentAccessToken()->delete()` → `tokens()->delete()` | ログアウトのテストのみ | 期待どおり |

いずれも**壊した箇所に対応するテストだけ**が落ちた。すべて元に戻して17件パスを再確認した。

### 判断メモ

- **ログインに `Password::defaults()` を適用しない。** 適用すると、ポリシー強化前に登録した既存ユーザーが正しいパスワードを入力しても弾かれる。ログイン時は形式だけを見る
- **`register` の 201 と `login` の 200 を区別した。** 登録はリソースの新規作成なので 201 が正しい
- **`plainTextToken` は発行直後の一度しか取得できない。** DB には SHA-256 ハッシュが保存され、平文は残らない。トークンを見失ったら再発行するしかない

### 成果物

```
api/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/AuthController.php      (新規)
│   │   ├── Requests/Auth/{Register,Login}Request.php (新規)
│   │   └── Resources/UserResource.php              (新規)
│   └── Providers/AppServiceProvider.php            (パスワードポリシー / ラッパー無効化)
├── config/sanctum.php                              (expiration)
├── phpunit.xml                                     (厳格化)
├── routes/api.php                                  (auth グループ)
└── tests/Feature/Auth/AuthenticationTest.php       (新規・11件)
```

コミット: `7847be2` → `c4a0fb7` → `de44045`

---

## 3b: MFA の登録・確認・解除

### やったこと

#### 1. 状態を3カラムで持つ

```php
$table->text('two_factor_secret')->nullable();          // 認証アプリと共有する鍵
$table->text('two_factor_recovery_codes')->nullable();  // 使い捨てコード
$table->timestamp('two_factor_confirmed_at')->nullable();
```

`two_factor_confirmed_at` を分けたのが設計の中心。**シークレットを保存した時点ではまだ有効化しない。** 保存＝有効化にすると、認証アプリへの登録に失敗したユーザー（QR を読む前に画面を閉じた、別端末に入れたつもりで入っていない）が次のログインで自分のアカウントから締め出される。実際にコードを1回通せたことを確認してから有効にする。

これで状態が3つになる。

| 状態 | `two_factor_secret` | `two_factor_confirmed_at` | MFA |
|---|---|---|---|
| 未登録 | `null` | `null` | 無効 |
| 確認待ち | あり | `null` | **無効** |
| 有効 | あり | あり | 有効 |

`User::hasTwoFactorEnabled()` は `confirmed_at` だけを見る。シークレットの有無で判定すると「確認待ち」が有効側に転ぶ。

型を `string` ではなく `text` にしたのは暗号化のため。`encrypted` キャストの保存値は `iv` / `value` / `mac` を持つ JSON を Base64 化したもので、元の長さの数倍になる。リカバリコード8件の配列は 255 文字に収まらない。

#### 2. シークレットは暗号化して保存する

```php
'two_factor_secret' => 'encrypted',
'two_factor_recovery_codes' => 'encrypted:array',
```

パスワードの `hashed` とは別物で、`encrypted` は `APP_KEY` による**可逆な**暗号化。TOTP の検証には鍵の平文が必要なのでハッシュ化はできない。狙いは「DB だけが漏れた場合に読めないこと」で、`APP_KEY` ごと漏れれば読める。

`#[Hidden]` にも両カラムを足した。API が返す項目は `UserResource`（許可リスト）が正で、`#[Hidden]` は API を通らない経路（ログ出力、`dd`、キューのペイロード）でモデルがそのまま配列化されるときの保険。**守る対象が「応答の仕様」と「不注意な直列化」で違う**ので二重に書いている。

#### 3. TOTP の生成・検証は `pragmarx/google2fa`

QR 画像は作らない。`otpauth://` URI を返して画像化はフロントの責務にする（Step 8）。表示の都合で解像度や配色は変わるが URI は変わらないため、バックエンドが持つ理由がない。手入力したいユーザー向けにシークレット自体も返す。どちらも同じ鍵を運ぶ。

鍵は 32 文字の Base32 = 160 ビットで生成した。RFC 6238 が HMAC-SHA1 の鍵長として推奨する値。

#### 4. エンドポイントは4本

| メソッド | パス | 本文 | 応答 |
|---|---|---|---|
| POST | `/api/auth/two-factor` | `password` | 201 `{ secret, otpauth_uri }` |
| POST | `/api/auth/two-factor/confirm` | `code` | 200 `{ recovery_codes }` |
| POST | `/api/auth/two-factor/recovery-codes` | `password` | 200 `{ recovery_codes }` |
| DELETE | `/api/auth/two-factor` | `password` | 204 |

`apiResource` にしない。ユーザーごとに1つしかなく id で指すものがない（コレクションではない）ため。

状態の遷移を伴う処理は Action クラスに置いた（`app/Actions/TwoFactor/`）。Controller は状態の検査と応答の組み立てだけを行う。

#### 5. 設定変更に現在のパスワードを要求する

```php
'password' => ['required', 'string', 'current_password:sanctum'],
```

有効化・解除・リカバリコードの再発行はトークンだけでは通さない。**トークンを盗んだ相手が MFA を解除できるなら、MFA を足す意味がなくなる。** 所持要素を外す操作は知識要素で再確認する。

ガードを `:sanctum` と明示した。`auth:sanctum` ミドルウェアは認証が通ったときに `shouldUse('sanctum')` を呼ぶので省略しても動くが、それに頼ると認証の設定を変えたときに照合先が静かにずれる。

`Password::defaults()` は適用しない。ログインと同じ理由で、既存ユーザーの正しいパスワードを形式で弾いてはならない。

#### 6. リカバリコードは確認が済んでから発行する

有効化とコード発行を同じ1回の保存にまとめた。**「有効だがリカバリコードが無い」状態を作らないため。** 認証アプリを失えばその状態は復旧不能になる。逆に確認前に発行しても、MFA が無効なうちはコードの使い道がない。

1コードは英数20文字（`Str::random(10).'-'.Str::random(10)`）。`Str::random()` は `random_bytes()` 由来なので予測できない。62種類から20文字で約119ビット。ハイフンで割るのは読み上げ・書き写しのしやすさのためで、検証では区切りも含めて1つのコードとして扱う。

#### 7. 手順を飛ばした呼び出しは 409、入力の誤りは 422

| 呼び出し | 応答 |
|---|---|
| 有効なのに再度 有効化 | 409（黙って別の鍵に差し替えない） |
| 確認待ちのまま 有効化 | 201（**やり直せる**。QR を読む前に閉じたユーザーが詰まる） |
| 登録を始めずに 確認 | 409 |
| MFA 無効で コード再発行 | 409 |
| 未登録で 解除 | 204（結果の状態は同じで、409 にしても呼び出し側にできることがない） |
| コードが6桁でない / 一致しない | 422 |

409 と 422 を分ける基準は「同じリクエストを送り直せば解決するか」。状態の不一致は送り直しても解決しないので 409、入力の誤りは直せるので 422。

#### 8. `/me` は状態だけを返す

```php
'two_factor_enabled' => $this->hasTwoFactorEnabled(),
```

設定画面の表示と「解除ボタンを出すか」の判断に必要な情報はこれで足りる。シークレットは出さない。

3a で書いた「`/me` のキーを固定するテスト」がここで効いた。カラムを足した時点でテストが落ち、**応答に何を足すかを明示的に決めさせられた**（`two_factor_enabled` を1件足して期待値を更新）。テストが黙って通っていたら、決めずに済ませていた。

#### 9. テスト

`tests/Feature/Auth/TwoFactorAuthenticationTest.php` に16件。見ているのは次の3点。

- シークレットが平文で DB に残らないこと
- 確認が済むまで MFA が有効にならないこと
- 現在のパスワード無しで設定を変えられないこと

暗号化の検証は `DB::table('users')->value('two_factor_secret')` でカラムを直接読む。Eloquent 経由では `encrypted` キャストが復号してしまい、平文との違いが見えない。

正しいコードは `Google2FA::getCurrentOtp($user->two_factor_secret)` で作る。`encrypted` キャストは読み出し時に復号するので、Factory で入れた鍵をテスト側からそのまま使える（`twoFactorPending()` / `twoFactorConfirmed()` の2状態を追加した）。

**全33件・110アサーションがパス。** Pint も通っている。

#### 10. 実機確認（curl ＋ oathtool）

テストは HTTP カーネルを通しているが、**実時刻・開発用 `APP_KEY`・本番設定**（`uncompromised()`、`cache.default = database`）は通らない。開発環境で一通り動かした。

| 手順 | 結果 |
|---|---|
| 登録 → ログイン → `/me` | 200 |
| 登録開始 | 201（`secret` + `otpauth_uri`） |
| コード確認 | 200（`recovery_codes` 8件） |
| `/me` | 200 / `two_factor_enabled: true`、`secret` は含まれない |
| 有効なのに再度 有効化 | 409 |
| 誤ったパスワードで解除 | 422（解除されない） |
| 解除 | 204 |
| 解除後の DB | 3カラムすべて `NULL`。トークンは残る |

**コードの生成には `oathtool`（oath-toolkit）を使った。** 当初こちらで `pragmarx/google2fa` を使って正解コードを計算して突き合わせようとしたが、これでは**ライブラリの使い方を間違えていた場合に気づけない**（同じ実装なので自己整合するだけ）。RFC 6238 の別実装で通ることを確認して初めて「規格どおり」と言える。

実測で分かったこと: **暗号化後のシークレットは 256 文字。** `varchar(255)` では1文字入らない。マイグレーションで `text` を選んだ判断が数字で裏付けられた。

解除でトークンは削除されない。MFA の解除は所持要素を外す操作であって、セッションの破棄ではないため。

### 詰まった点 1: トークンを付けているのに 401

実装ではなく実機確認での話。

**症状**

`Authorization: Bearer $TOKEN` を付けているのに `/api/auth/two-factor` が 401 `Unauthenticated.` を返した。

**調査の手順**

`personal_access_tokens` を見ると、行は存在するが `last_used_at` が `NULL` だった。**つまりサーバーがそのトークンで認証に成功したことが一度もない。** `auth:sanctum` はアプリのコードより前で止めるので、実装側ではなくリクエスト側の問題だと切り分けられた。

**原因**

Sanctum の平文トークンは `1|xxxxx…` という形式で **`|` を含む**。`TOKEN=1|xxxxx` とクォート無しで代入したため、zsh が `|` をパイプとして解釈し、`TOKEN=1` だけが入っていた。

**解決**

応答から `sed` で切り出して直接代入し、貼り付けを介さない形にした。手で貼る場合はシングルクォートで囲む。

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/auth/login ... \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
```

**技術メモ**

`last_used_at` が `NULL` であることは「そのトークンで一度も認証が通っていない」証拠になる。実装を疑うかクライアントを疑うかの切り分けに使える。

### 詰まった点 2: 正しいコードなのに 422

**症状**

認証アプリの表示どおりに送ったのに `422` と `The code field must be 6 digits.` が返った。

**原因**

認証アプリは6桁を3桁ずつ区切って表示する（`751 790`）。空白込みで送ったため `digits:6` で落ちていた。**コードの検証まで到達していなかった。**

**解決**

空白を詰めて送る。

**技術メモ**

- エラーメッセージがどの層のものか（`code` のバリデーション文言か、`two factor ... invalid` か）で、リクエストがどこまで到達したか分かる
- **レート制限はミドルウェアなのでバリデーションより先に走る。** バリデーションで落ちた分も枠を消費する

### 決定済み（3c-1）: コードの空白を正規化するか

→ **正規化する**ことにした。実装は [3c-1 の 5.](#5-コードの空白を取り除く) を参照。以下は決める前の整理。

認証アプリが区切って表示する以上、手入力するユーザーは空白を含めがちである。サーバー側で正規化するかどうかを決めていない。

- 入れるなら `ConfirmTwoFactorRequest::prepareForValidation()` で**空白だけ**を除く。これは「起こり得ない入力へのガード」ではなく**実際に来る入力の正規化**なので、本プロジェクトの方針とは矛盾しない
- ただし**リカバリコードはハイフンが値の一部**なので、正規化は `code` フィールド限定にする必要がある
- 3c-1 のチャレンジも同じ入力を受けるため、入れるならそこと揃える
- Fortify はこの正規化をしていない

### テストが正しいことの確認

3a と同じく、実装をわざと壊して検知できるかを確かめた。

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| `current_password:sanctum` を外す | パスワード確認の3件のみ | 期待どおり |
| 有効化で `confirmed_at` も立てる（保存＝有効化） | 確認前は無効であることのテストのみ | 期待どおり |
| `two_factor_secret` の `encrypted` キャストを外す | 暗号化のテストのみ | 期待どおり |
| 解除で `confirmed_at` だけを消す | 解除のテストのみ | 期待どおり |
| `hasTwoFactorEnabled()` を `secret !== null` に変える | 確認前は無効 / 誤ったコード / `/me` の3件 | 期待どおり |

最後の1つで**テストの穴が1つ見つかった。** この破壊を入れると「確認待ちのユーザーが有効化をやり直す」経路が 409 になるのに、どのテストも落ちなかった。やり直せることは意図した仕様（QR を読む前に画面を閉じたユーザーが先へ進めなくなる）なので、テストを1件追加した（`enabling again while pending replaces the secret`）。**壊して初めて「書いていない仕様」に気づけた**例。

### 判断メモ

- **リカバリコードはハッシュ化せず暗号化で保存した。** ハッシュ化すれば DB から読めなくなる分強いが、再表示ができなくなる。今回は発行直後の応答でしか平文を渡さない設計なので再表示は元から無く、ハッシュ化の余地はある。Fortify と同じ「暗号化」に合わせたうえで、将来 UI で控えを見せたくなったときに選択肢を残した
- **コードの使い回し（リプレイ）は塞いでいない。** `verifyKey` は前後1ステップ（±30秒）を許容するため、同じコードは最大90秒間通る。`google2fa` には `verifyKeyNewer($secret, $code, $oldTimestamp)` があり、一致したステップの timestamp を返すので、最後に使ったステップを保存すれば「同じコードは二度通らない」を実現できる。**3b では確認（1回きり）にしか検証を使わないので影響がなく、3c のログイン検証で入れる**
- **レート制限は未実装。** `bootstrap/app.php` で `throttleApi()` を呼んでいないため、この API には現在レート制限が一切かかっていない（`Middleware::$apiLimiter` が null だと `throttle:` ミドルウェアがグループに入らない）。6桁 = 100万通りの TOTP を無制限に試せる状態は 3c のログイン検証で致命的になる。**3c の最初に入れる**
- **`code` の検証は `digits:6`。** `integer` や `numeric` を付けると先頭が 0 のコードが落ちる。`digits` は文字列として「数字以外を含まない」かつ「桁数が一致」を見る
- **コードの比較は `hash_equals`**（`google2fa` の `findValidOTP` 内）。総当たりの手がかりになる時間差が出ない

### 成果物

```
api/
├── app/
│   ├── Actions/TwoFactor/
│   │   ├── EnableTwoFactorAuthentication.php    (新規・登録開始)
│   │   ├── ConfirmTwoFactorAuthentication.php   (新規・確認して有効化)
│   │   ├── DisableTwoFactorAuthentication.php   (新規・解除)
│   │   └── RegenerateRecoveryCodes.php          (新規・コード再発行)
│   ├── Http/
│   │   ├── Controllers/Api/TwoFactorAuthenticationController.php (新規)
│   │   ├── Requests/Auth/PasswordConfirmationRequest.php         (新規)
│   │   ├── Requests/Auth/ConfirmTwoFactorRequest.php             (新規)
│   │   └── Resources/UserResource.php           (two_factor_enabled)
│   ├── Models/User.php                          (キャスト / Hidden / hasTwoFactorEnabled)
│   └── Support/RecoveryCode.php                 (新規)
├── database/
│   ├── factories/UserFactory.php                (twoFactorPending / twoFactorConfirmed)
│   └── migrations/2026_09_19_140739_add_two_factor_columns_to_users_table.php (新規)
├── routes/api.php                               (two-factor グループ)
└── tests/Feature/Auth/
    ├── TwoFactorAuthenticationTest.php          (新規・16件)
    └── AuthenticationTest.php                   (/me の期待キーを更新)
```

依存の追加: `pragmarx/google2fa` ^9.1

コミット: `ac79589`（実装）→ `712a79c`（テスト）→ この記録

## 3c: ログインの2段階化

### 3c-0: レート制限（完了）

3b を書き終えた時点で、この API にはレート制限が**一切かかっていなかった**。6桁のコードを無制限に試せる状態でログインチャレンジを公開できないため、2段階化の前に入れた。

#### Laravel 11 以降は既定で何もかかっていない

```php
// bootstrap/app.php
$middleware->throttleApi();
```

`Middleware::$apiLimiter` が null だと `throttle:` ミドルウェアが api グループに入らない（`Middleware::getMiddlewareGroups()` が `array_filter` で落とす）。10 以前の `RouteServiceProvider` にあった既定の 60回/分は、11 の骨組みからは消えている。**呼ばなければ制限は無い**が、外からは見えない。

`throttleApi()` と名前付きリミッター `api` の定義は**対で置く必要がある**。片方だけだと `resolveMaxAttempts()` が `'api'` を数値に変換できず `MissingRateLimiterException` を投げ、**全リクエストが 500 になる**（無制限に緩むのではなく全面停止する方向に倒れる）。

#### 3段構えにした

守る対象が違うので上限値も分ける。

| リミッター | 上限 | かける場所 |
|---|---|---|
| `api` | 60回/分 | API 全体（`throttleApi()`） |
| `auth` | 10回/分 | パスワードを検証する場所（register / login / MFA の設定変更3本） |
| `two-factor-code` | 5回/分 ＋ 30回/日 | 6桁のコードを検証する場所（confirm、3c-1 のチャレンジ） |

数え方は「認証済みならユーザー単位、未認証なら IP 単位」。IP 単位だけにすると、同じ IP を共有する利用者（NAT・社内網）が互いの枠を食い合う。

**コードの検証だけ日単位の上限を重ねた。** パスワードは鍵空間の広さ（12文字以上＋漏洩リスト照合）が主な防御で、毎分の制限は速度を落として気づく時間を稼ぐ層にすぎない。一方コードは100万通りしかなく、毎分5回でも1日7,200回試せる。前後1ステップの許容を含めると当たる確率は1日あたり約2%で、放置できない。30回/日にすると約10万分の1に下がる。

#### 未認証のリクエストは枠を消費しない

Laravel のミドルウェア優先順位（`Kernel::$middlewarePriority`）では `AuthenticatesRequests` が `ThrottleRequests` より**先**にある。そのため認証が必要なルートでは、

- トークン無しのリクエストは 401 で止まり、レート制限のカウンターを増やさない
- リミッターが呼ばれる時点でユーザーが解決済みなので、ユーザー単位のキーが確実に取れる

逆順だと、第三者がトークン無しで連打して**正規の利用者の枠を潰せる**。テストで固定した。

#### テスト

`tests/Feature/Auth/RateLimitTest.php` に6件。

| 検証 | 期待 |
|---|---|
| ルートごとの上限が `X-RateLimit-Limit` に出る | health 60 / login 10 / confirm 5 |
| ログイン11回目 | 429 ＋ `Retry-After` |
| コード検証6回目 | 429 |
| 5回×6分＝30回の後の31回目 | 429（分の枠は空いているのに通らない） |
| 同じ IP の別ユーザー | 影響を受けない |
| 未認証で10回叩いた後の正規ユーザー | `X-RateLimit-Remaining: 4`（枠が減っていない） |

日単位の上限は `$this->travel(1)->minutes()` で時間を進めて検証した。分の枠だけ回復させれば、日の枠に当たるまで進められる。

「設定したつもりで何もかかっていない」は外から見えないため、応答ヘッダと 429 の両方で確かめている。

#### カウンターの置き場

レート制限の回数は**キャッシュストア**に入る。この環境の `cache.default` は `database` で、`cache` テーブル（骨組みのマイグレーション）に書かれる。つまり全リクエストが DB への読み書きを1往復増やす。動作としては正しいが、実運用でトラフィックが増えたら Redis に移す場所。`throttleApi(redis: true)` で Redis 用の実装（`ThrottleRequestsWithRedis`）に切り替えられる。README の「改善余地」に書く。

テストは `CACHE_STORE=array`（`phpunit.xml`）なので、カウンターはテストメソッドごとに作り直されるアプリのメモリ上にあり、メソッド間に漏れない。

### 技術メモ: 上限を重ねてもカウンターは混ざらない

積んだ上限に同じ `by()` を渡すとカウンターを共有してしまうと考え、`'minute:'` / `'day:'` の接頭辞を付けていた。**不要だった。**

わざと接頭辞を外して確かめたところ、挙動は完全に同じだった。理由は `RateLimiter::limiter()` にある。

```php
$duplicates = (new Collection($result))->duplicates('key');
...
foreach ($result as $limit) {
    if ($duplicates->contains($limit->key)) {
        $limit->key = $limit->fallbackKey();   // "<key>:attempts:5:decay:60"
    }
}
```

キーの重複を検出して、上限値と期間を含むキーに差し替える。手で分ける必要はない。接頭辞は消した（**起こり得ないことへのガードを残すと、フレームワークが何を保証しているかが読めなくなる**）。

調べ方は、壊しても落ちないテストの原因を追ったこと。「テストの穴では？」と考えて確かめたら、**穴ではなく差が無かった**。リミッターの実体を `RateLimiter::limiter('two-factor-code')(request())` で取り出してキーを直接見たら答えが出た。

### 成果物（3c-0）

```
api/
├── app/Providers/AppServiceProvider.php   (リミッター3種を定義)
├── bootstrap/app.php                      (throttleApi)
├── routes/api.php                         (throttle:auth / throttle:two-factor-code)
└── tests/Feature/Auth/RateLimitTest.php   (新規・6件)
```

### 3c-1: 引換券と本トークンの発行

- **日付**: 2026-09-27
- **状態**: ✅ 完了（実機確認済み）

MFA が有効なユーザーのログインを2回に分けた。

```
① POST /api/auth/login                 メール＋パスワード
   → { "two_factor": true, "challenge": "<40文字>" }   本トークンはまだ出ない

② POST /api/auth/two-factor-challenge  challenge ＋ code（または recovery_code）
   → { "token": "...", "user": {...} }
```

`challenge` は「パスワードは合っていた。あとはコードだけ」という途中の状態を表す**引換券**で、これだけでは API を使えない。MFA が無効なユーザー（確認待ちを含む）は、従来どおり①で本トークンを受け取る。

#### 決めたこと

実装前に3点を選んだ。

**1. 引換券の作り方 → 控えをサーバーのキャッシュに置く**

推測できないランダムな ID（40文字）を渡し、「この ID = このユーザー」という控えを5分で消えるキャッシュに置く。使ったらその場で消す。Fortify がブラウザ版でセッションに `login.id` を置くのと同じ考え方で、Cookie を使わない API 向けにしたもの。

| 見送った案 | 理由 |
|---|---|
| 専用テーブル | 仕組みはキャッシュ案と同じ。試行回数などを記録しやすいが、今は要らない |
| Sanctum トークンに「引換専用」の ability を付ける | 既存の `auth:sanctum` ルートは ability を見ていない。全ルートに確認を足す必要があり、**1か所でも足し忘れると引換券で API が使えてしまう**（既定が開いている） |
| 暗号化した自己完結の券 | サーバーに控えが無いので、使った後も期限までは無効にできない |

**2. コードの試行回数 → 引換券の持ち主（ユーザー）単位で数える**

2段階目はまだトークンが無いので、3c-0 の数え方のままだと IP 単位になる。②に来ている相手はすでにパスワードを知っており、IP を次々に変えられるなら IP ごとに30回/日ずつ試せてしまう。引換券から持ち主を引いて、そのユーザーで数えるようにした。

confirm（認証済み・ユーザー単位）と同じキーになるので、**両方を合わせて1ユーザー30回/日**になる。

代償として、パスワードを知る相手がわざと間違え続けると、本人もその日はコードを入力できなくなる。ただしその時点でパスワードは漏れているので、本人が変更すべき状況ではある。

**3. コードの空白 → 取り除く**（3b の未決事項）

#### 1. 最後に通ったステップを保存する

```php
$table->bigInteger('two_factor_last_used_step')->nullable();
```

TOTP は30秒ごとに切り替わる。その通し番号（ステップ）のうち、最後に通ったものを保存し、それ以前のコードを拒否する。`verifyKey` は時刻ずれを許して前後1ステップを受け入れるので、塞がないと**同じコードが最大90秒間通る**。盗み見たコードや、中継型のフィッシングで横取りしたコードをその間に使われうる。

MFA の解除でこのカラムも `null` に戻す（解除は「鍵を捨てる」操作なので、鍵に紐づく記録も捨てる）。

#### 2. コードの消費を Action に分けた

| クラス | 役割 |
|---|---|
| `ConsumeTotpCode` | `verifyKeyNewer()` で検証し、通ったステップを保存 |
| `ConsumeRecoveryCode` | 一致したリカバリコードを配列から消す（`hash_equals` で照合） |
| `CompleteTwoFactorChallenge` | 券を確認 → 上のどちらかで検証 → 券を消す |

**confirm も `ConsumeTotpCode` を通すように変えた。** 有効化の確認に使ったコードを、その直後のログインでもう一度通させないため。

リカバリコードは暗号化して保存しているので、SQL で探せない。復号した配列を PHP 側で照合する。

#### 3. 同時に届いたリクエストに備えて行をロックする

```php
DB::transaction(function () {
    $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
    // SELECT * FROM users WHERE id = ? LIMIT 1 FOR UPDATE
    ...
});
```

ロックが無いと、同じコードを載せたリクエストが2本同時に届いたとき、両方が「まだ使われていない」を読んでから保存し、**2本ともトークンを受け取れる**。使い回し防止に穴が空く。ロックで2本を順番に並べれば、後の1本は先の1本が保存した結果を読む。

券の確認はロックの**内側でもう一度**行う。ロックを待つ間に、同じ券が別のリクエストで使われたかもしれないため。同じ場所で「券の発行後に別の端末から MFA を解除された」場合も弾く。

#### 4. 引換券のキーはハッシュにする

```php
'two-factor-challenge:'.hash('sha256', $challenge)
```

この環境のキャッシュは `cache` テーブルにあり、キーが平文のまま残る。DB を読めるだけの相手に、使える券を渡さないためにハッシュにした。Sanctum がトークンを SHA-256 で保存するのと同じ理由。

#### 5. コードの空白を取り除く

```php
preg_replace('/\s+/u', '', $this->input('code'))
```

`NormalizesTotpCode` トレイトの `prepareForValidation()` に置き、confirm とチャレンジの両方で使う。

- **`u` 修飾子を付ける。** 無いと `\s` は ASCII の空白しか拾わず、日本語入力のまま打った全角空白（U+3000）が残る
- **対象は `code` だけ。** リカバリコードはハイフンを含めて1つの値なので手を付けない

#### 6. code と recovery_code はどちらか片方だけ

```php
'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'digits:6'],
'recovery_code' => ['nullable', 'required_without:code', 'string'],
```

両方来たときにどちらを優先するかを決めずに済むよう、組み合わせごと 422 にする。

#### 技術メモ: `verifyKeyNewer()` に `null` を渡すとステップが返らない

実装前に vendor を読んで見つけた。

```php
// Google2FA::findValidOTP()
return is_null($oldTimestamp)
    ? true
    : $startingTimestamp;
```

第3引数（前回のステップ）が `null` だと、一致したときに**ステップ番号ではなく `true`** を返す。まだ一度もコードを使っていないユーザーでは保存値が `null` なので、そのまま渡すと `true` を保存してしまう（`integer` キャストで `1` になる）。`?? 0` で必ず数値を渡している。

テストで確かめた（下の「テストが正しいことの確認」の2行目）。`?? 0` を外すと、使い回しを塞ぐテストが2件落ちる。

#### 技術メモ: `$this->travel()` では TOTP の時刻は動かない

`google2fa` は PHP の `time()` を直接見ている。`travel()` が動かすのは Carbon の `now()` だけなので、コードの計算には効かない。一方、引換券の有効期限はキャッシュ経由で Carbon を見るので、`travel()` で期限切れを再現できる。

次のステップのコードが必要なテストでは、ステップを指定して作った。

```php
$google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1);
```

前後1ステップの許容に入るので、今の時刻でも通る。

#### テスト

`tests/Feature/Auth/TwoFactorChallengeTest.php` に15件。見ているのは次の2点。

- パスワードだけでは本トークンが出ないこと（引換券では API を使えないこと）
- 券もコードも1回しか使えないこと

| 検証 | 期待 |
|---|---|
| MFA 有効で login | `two_factor: true` と `challenge`、`token` なし、トークン0件 |
| 確認待ちで login | 従来どおり `token` |
| 引換券を Bearer にして `/me` | 401 |
| 正しいコード | 200、発行されたトークンで `/me` が 200 |
| 半角空白入りのコード | 200 |
| 誤ったコード | 422（`code`）、トークン0件 |
| 発行していない券 | 422（`challenge`） |
| 使用済みの券（コードは新しい） | 422（`challenge`）、トークンは1件のまま |
| 期限切れの券（6分後） | 422（`challenge`） |
| 券の発行後に MFA 解除 | 422（`challenge`） |
| 同じコードを券を取り直して再送 | 422（`code`） |
| 使った直後に次のステップのコード | 200（塞ぎすぎていない） |
| confirm に使ったコードでログイン | 422（`code`） |
| リカバリコード | 1回目 200、残り7件、2回目 422 |
| code と recovery_code の両方 / どちらも無し | 422 |

ほかに次の3件を足した。

- `RateLimitTest`: チャレンジの上限ヘッダが 5。**IP を変えても同じユーザーなら6回目は 429**
- `TwoFactorAuthenticationTest`: confirm で全角空白入りのコードが通る

**全57件・255アサーションがパス。** Pint も通っている。

### 詰まった点 1: 壊し方が乱暴で、関係ないテストまで落ちた

実装ではなく、テストを検証する手順での話。

**症状**

「使い回し防止を外す」つもりで `verifyKeyNewer` を `(int) verifyKey` に置き換えたところ、使い回しのテスト以外に、レート制限や誤ったコードのテストまで10件落ちた。

**原因**

`verifyKey` は一致すると `true`、しないと `false` を返す。`(int)` を付けたため `false` が `0` になり、`$step === false` の判定が**一度も成立しなくなった**。つまり「使い回しを許す」ではなく「どんなコードでも通す」に壊していた。誤ったコードが 200 になり、422 を前提にしたテストが軒並み落ちた。

**解決**

保存したステップを無視するだけの最小の壊し方（第3引数を常に `0` にする）でやり直した。落ちたのは使い回しのテスト2件だけで、期待どおり。

**技術メモ**

わざと壊すときは、**確かめたい性質1つだけを壊す**。壊し方が広いと、たくさん落ちても「どのテストがその性質を守っているか」が分からない。

### テストが正しいことの確認

| 壊した箇所 | 落ちたテスト | 判定 |
|---|---|---|
| login の MFA 分岐を外す | チャレンジ関連の15件 | 期待どおり |
| `verifyKeyNewer` に `null` を渡す（`?? 0` を外す） | 同じコードの再送 / confirm のコードでログイン | 期待どおり |
| 保存したステップを無視する（常に `0`） | 同上の2件 | 期待どおり |
| 成功後に券を消さない | 使用済みの券のみ | 期待どおり |
| リカバリコードを消し込まない | リカバリコードのみ | 期待どおり |
| コード検証の上限を IP 単位に戻す | IP を変えても同じユーザーのみ | 期待どおり |
| 空白の除去から `u` を外す相当（半角空白だけ除く） | confirm の全角空白のみ | 期待どおり |
| MFA 解除後の券を弾かない | 解除後の券のみ | 期待どおり |
| **行ロックを外す** | **なし** | 下記 |

**行ロックはテストで守れていない。** PHPUnit の Feature テストはリクエストを1本ずつ順に処理するので、「2本が同時に届く」状況を作れない。ロックが無くても逐次なら正しく動くため、外しても落ちない。並行リクエストを投げる負荷テストか、2つの DB 接続でロックを奪い合わせるテストが必要になる。ここはコメントで意図を残すにとどめた。

### 判断メモ

- **①の応答は 200。** 「MFA が必要」はエラーではなくログインの途中なので 4xx にしない。フロントは `two_factor` の有無で分岐する
- **コードを間違えても券は消さない。** 打ち間違えのたびにパスワードからやり直させることになる。試行回数はレート制限（ユーザー単位 5回/分・30回/日）が抑える
- **券の有効期限は5分。** 認証アプリを開いてコードを打つには足り、放置された券が長く残らない長さ
- **リカバリコードを使い切っても MFA は有効のまま。** 再発行（`POST /two-factor/recovery-codes`）で補充する。残数の通知は UI の段階で考える

### 実機確認（curl ＋ oathtool）

テストは HTTP カーネルを通しているが、実時刻・`cache.default = database`・PostgreSQL の行ロックを実際に通すのは開発環境だけ。3b と同じく curl ＋ oathtool で確かめた。

確認専用のユーザーを毎回新しく作る手順にした（メールアドレスは `check-<UNIX時刻>@example.com`、パスワードは `openssl rand -hex 12`）。既存アカウントの状態に左右されず、書き換える箇所もない。

| 手順 | 結果 |
|---|---|
| 登録 → MFA 登録開始 → oathtool のコードで確認 | 200（リカバリコード8件） |
| login | `{"two_factor":true,"challenge":"…"}`。**`token` なし** |
| 確認に使ったコードでチャレンジ | 422（`code`）。使い回しが弾かれた |
| 30秒待って新しいコードで同じ券 | 200、`token` と `user`（`two_factor_enabled: true`） |

終わった後の DB:

| 見たもの | 値 | 読み取れること |
|---|---|---|
| `two_factor_last_used_step` | `59681536` | ×30 = `1790446080` で、確認時の UNIX 時刻 `1790446117` とほぼ一致。**`true`（= 1）ではなくステップ番号が入っている**（`?? 0` が効いている） |
| `cache` の `two-factor-challenge:*` | 0件 | 使った券は消えている |
| `personal_access_tokens` | 登録時の1本＋チャレンジで発行された1本 | 本トークンは2段階目でだけ出ている |

### 成果物（3c-1）

```
api/
├── app/
│   ├── Actions/TwoFactor/
│   │   ├── CompleteTwoFactorChallenge.php       (新規・券とコードの検証)
│   │   ├── ConsumeTotpCode.php                  (新規・使い回し防止つきの検証)
│   │   ├── ConsumeRecoveryCode.php              (新規・リカバリコードの消し込み)
│   │   ├── ConfirmTwoFactorAuthentication.php   (ConsumeTotpCode を使う)
│   │   └── DisableTwoFactorAuthentication.php   (last_used_step も消す)
│   ├── Http/
│   │   ├── Controllers/Api/AuthController.php   (login の分岐 / twoFactorChallenge)
│   │   └── Requests/Auth/
│   │       ├── Concerns/NormalizesTotpCode.php  (新規・空白の除去)
│   │       ├── ConfirmTwoFactorRequest.php      (トレイトを使う)
│   │       └── TwoFactorChallengeRequest.php    (新規)
│   ├── Models/User.php                          (last_used_step のキャスト)
│   ├── Providers/AppServiceProvider.php         (コード検証の数え方)
│   └── Support/TwoFactorChallenge.php           (新規・引換券)
├── database/migrations/2026_09_26_175731_add_two_factor_last_used_step_to_users_table.php (新規)
├── routes/api.php                               (two-factor-challenge)
└── tests/Feature/Auth/
    ├── TwoFactorChallengeTest.php               (新規・15件)
    ├── RateLimitTest.php                        (+1件、上限ヘッダの確認に1行)
    └── TwoFactorAuthenticationTest.php          (+1件)
```

コミット: `ff89b4e`（実装）→ `910735d`（テスト）→ この記録

## 次のステップ

Step 4: プロジェクト CRUD + Policy
