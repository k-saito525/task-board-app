<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 認証の振る舞いを固定する。
 *
 * 成功する経路より、失敗すべき経路（誤ったパスワード、トークンなし、失効後）が本体。
 * ここが緩むと、以降のすべての認可テストが土台から無意味になる。
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** ポリシー（12文字以上）を満たす値 */
    private const VALID_PASSWORD = 'correct-horse-battery';

    private const EMAIL = 'test-user@example.com';

    public function test_registration_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'テストユーザー',
            'email' => self::EMAIL,
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', ['email' => self::EMAIL]);

        // User モデルの 'password' => 'hashed' キャストにより平文では保存されない
        $this->assertNotSame(self::VALID_PASSWORD, User::sole()->password);
    }

    public function test_registration_rejects_a_password_shorter_than_the_policy(): void
    {
        $short = 'short1234';   // 9文字。ポリシーは12文字以上

        $this->postJson('/api/auth/register', [
            'name' => 'テストユーザー',
            'email' => self::EMAIL,
            'password' => $short,
            'password_confirmation' => $short,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseEmpty('users');
    }

    public function test_registration_rejects_a_mismatched_confirmation(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'テストユーザー',
            'email' => self::EMAIL,
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => 'different-password-xx',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseEmpty('users');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson('/api/auth/register', [
            'name' => 'テストユーザー2',
            'email' => self::EMAIL,
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::VALID_PASSWORD)]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_a_wrong_password_without_issuing_a_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::VALID_PASSWORD)]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password-here',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // 認証に失敗した以上、トークンは1件も作られてはならない
        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    /**
     * 存在しないメールアドレスでも、パスワード誤りと同じ応答を返す。
     * どちらが誤りかを攻撃者に伝えないため。
     */
    public function test_login_gives_the_same_response_for_an_unknown_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => self::VALID_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    /**
     * UserResource を許可リスト方式にしてある効果を固定する。
     * Step 3b で two_factor_secret を users に追加するが、このテストがあれば
     * 誤って応答に混ざった時点で落ちる。
     */
    public function test_me_returns_only_the_allowlisted_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');

        $this->assertSame(
            ['id', 'name', 'email', 'created_at'],
            array_keys($response->json()),
        );
    }

    /**
     * ログアウトは「今使っているトークンだけ」を消す。
     * 他端末のトークンが残るのが、DB でトークンを管理する方式の利点。
     */
    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();

        $phone = $user->createToken('phone')->plainTextToken;
        $laptop = $user->createToken('laptop')->plainTextToken;

        $this->withToken($laptop)->postJson('/api/auth/logout')->assertNoContent();

        // 本番では1リクエスト＝1プロセスだが、テストでは1メソッド内でアプリの
        // インスタンスが使い回される。認証ガードが解決済みのユーザーを保持したままだと
        // 削除後のトークンでも通ってしまうため、リクエスト間でガードを破棄して
        // 本番と同じ条件に戻す。
        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/auth/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/auth/me')->assertOk();
    }
}
