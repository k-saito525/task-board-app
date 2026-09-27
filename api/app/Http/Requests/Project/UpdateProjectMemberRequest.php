<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectMemberRequest extends FormRequest
{
    /**
     * 権限の確認はここで行う。FormRequest は authorize() → 検証の順に動くので、
     * 権限の無い人には入力の中身を見る前に 403 を返せる。コントローラで
     * Gate::authorize() を呼ぶと検証の後になり、検証の結果（422 の中身）が権限の無い
     * 人にも見えてしまう。
     */
    public function authorize(): bool
    {
        return $this->user()->can('updateMember', $this->route('project'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }
}
