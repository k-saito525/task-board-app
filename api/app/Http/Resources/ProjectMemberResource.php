<?php

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * プロジェクトの参加者1人分。
 *
 * ユーザーの情報は UserResource を使わず、ここで必要な項目だけを出す。UserResource は
 * 本人向け（/me）で two_factor_enabled を含むため、流用すると他のメンバーの MFA の
 * 設定状況まで見えてしまう。
 *
 * URL の {member} にはユーザーの id を入れるので、user_id を識別子として出す。
 * project_members.id は外に出さない。
 *
 * @mixin ProjectMember
 */
class ProjectMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => $this->role,
            'joined_at' => $this->created_at,
        ];
    }
}
