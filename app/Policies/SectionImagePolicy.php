<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Section;
use App\Models\SectionImage;
use App\Models\User;

/**
 * 教材内画像(SectionImage) の認可ポリシー。(B-B-01修正版)
 */
class SectionImagePolicy
{
    public function create(User $auth, Section $section): bool
    {
        return $this->canManage($auth, $section->chapter->part->certification);
    }

    public function delete(User $auth, SectionImage $image): bool
    {
        // 【階層構造の適合】：調査ログで実証された単数形チェーンで親の資格を安全に牽引
        return $image->section && $image->section->chapter && $image->section->chapter->part && $image->section->chapter->part->certification
            ? $this->canManage($auth, $image->section->chapter->part->certification)
            : false;
    }

    private function canManage(User $auth, Certification $certification): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            // 修正前：UserRole::Coach => false,
            // 修正後：担当資格であれば、画像アップロード・削除特権を与える
            UserRole::Coach => $certification->coaches()->where('users.id', $auth->id)->exists(),
            default => false,
        };
    }
}
