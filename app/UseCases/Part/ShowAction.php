<?php

declare(strict_types=1);

namespace App\UseCases\Part;

use App\Models\Part;

/**
 * Part 詳細取得ユースケース。Certification と Chapter を Eager Load する。
 * B-B-02修正版
 */
final class ShowAction
{
    public function __invoke(Part $part): Part
    {
        // B-B-02 ordered()追加
        return $part->load([
            'certification',
            'chapters' => fn ($q) => $q->ordered()->withCount('sections'),
        ]);
    }
}
