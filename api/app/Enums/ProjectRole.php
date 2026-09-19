<?php

namespace App\Enums;

enum ProjectRole: string
{
    /** プロジェクトの管理者。更新・削除・メンバー管理ができる */
    case Owner = 'owner';

    /** 参加者。タスクは操作できるが、プロジェクト自体は変更できない */
    case Member = 'member';
}
