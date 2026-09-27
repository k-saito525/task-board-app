<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddProjectMemberRequest extends FormRequest
{
    /**
     * 権限の確認はここで行う。FormRequest は authorize() → 検証の順に動くので、
     * 権限の無い人には入力の中身を見る前に 403 を返せる。コントローラで
     * Gate::authorize() を呼ぶと検証の後になり、検証の結果（422 の中身）が権限の無い
     * 人にも見えてしまう。
     *
     * ここでは特に、owner でない人が「そのメールアドレスは登録済みか」を 422 と 403 の
     * 違いで調べられないようにしている。
     */
    public function authorize(): bool
    {
        return $this->user()->can('addMember', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 登録済みのユーザーだけを招待できる（Jetstream の AddTeamMember と同じ）。
            // 未登録のメールアドレスには 422 を返すので、owner には「そのアドレスが
            // 登録済みか」が分かる。登録時の unique 検証と同じ性質で、README の
            // 「改善余地」（ユーザー列挙）にまとめて書く。
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'role' => ['sometimes', Rule::enum(ProjectRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => __('We were unable to find a registered user with this email address.'),
        ];
    }
}
