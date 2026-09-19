<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return $this->tokenResponse($user, status: Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // 成否にかかわらず同じメッセージを返し、どちらが誤りかを伝えない。
        //
        // ただし応答時間には差が残る。存在しないメールアドレスでは Hash::check が
        // 短絡されて bcrypt の計算が走らないため、登録済みかどうかを推測されうる
        // （ユーザー列挙）。登録時の unique 検証も 422 で同じ情報を返すので、
        // ここだけ塞いでも意味がない。塞ぐなら登録導線ごと変える必要がある。
        // README の「改善余地」に記載する。
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->tokenResponse($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        // 現在のリクエストで使われたトークンだけを削除する。
        // 他の端末で発行したトークンは有効なまま残る。
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function tokenResponse(User $user, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            // plainTextToken は発行直後のこの一度しか取得できない。
            // DB には SHA-256 ハッシュが保存され、平文は残らない。
            'token' => $user->createToken('api')->plainTextToken,
            'user' => new UserResource($user),
        ], $status);
    }
}
