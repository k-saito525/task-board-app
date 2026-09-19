# Step 3: 認証（Sanctum トークン）

- **日付**: 2026-09-19
- **状態**: 🚧 作業中（3a・3b 完了 / 3c 未着手）
- **完了条件**: register → login → Bearer 付きで `/me` 200、無しで 401、logout 後に 401。加えて MFA（TOTP）の登録・確認・解除と、ログインの2段階化

## 分割

パスワードだけの認証は、長さを伸ばしても「漏れたら終わり」という性質が変わらない。所持要素（TOTP）を足す方針にしたため、Step 3 を3つに割った。

| | 内容 | 状態 |
|---|---|---|
| 3a | 基本認証（register / login / me / logout） | ✅ 完了 |
| 3b | MFA の登録・確認・解除（TOTP） | ✅ 完了 |
| 3c | ログインの2段階化（チャレンジ → 本トークン発行） | 🚧 レート制限のみ完了 |

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

### 詰まった点

**特になし。** 実装・テストともに一度で通った。

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

### 3c-1 以降（未着手）

MFA が有効なユーザーのログインを2段階にする。

1. `POST /api/auth/login` は本トークンを返さず、短命のチャレンジ（MFA 待ち）を返す
2. `POST /api/auth/two-factor-challenge` で TOTP コードかリカバリコードを検証し、通ったら本トークンを発行する。`throttle:two-factor-code` をかける
3. リカバリコードは使ったら消し込む（1コード1回）
4. TOTP は `verifyKeyNewer()` で使い回しを塞ぐ（最後に通ったステップを保存する）

MFA が無効なユーザーは従来どおり1回で本トークンを受け取る。

### 成果物（3c-0）

```
api/
├── app/Providers/AppServiceProvider.php   (リミッター3種を定義)
├── bootstrap/app.php                      (throttleApi)
├── routes/api.php                         (throttle:auth / throttle:two-factor-code)
└── tests/Feature/Auth/RateLimitTest.php   (新規・6件)
```

## 次のステップ

Step 3c-1: ログインの2段階化（チャレンジ → 本トークン発行）
