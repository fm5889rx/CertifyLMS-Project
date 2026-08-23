<?php

declare(strict_types=1);

namespace App\UseCases\Section;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;         // 追加：B-B-01

/**
 * Section の SoftDelete ユースケース。Draft 状態のみ削除可、Published は削除拒否。
 */
final class DestroyAction
{
    /**
     * @throws ContentNotDeletableException
     */
    public function __invoke(Section $section): void
    {
        if ($section->status !== ContentStatus::Draft) {
            throw ContentNotDeletableException::forSection();
        }

        // B-B-01での修正
        // 修正前：DB::transaction(fn () => $section->delete());
        // トランザクションの内部において、紐づいている子画像を先に物理掃除する
        DB::transaction(function () use ($section) {

            // 提供済みBladeとモデルの双方からリレーション名を動的に引き当てて全件物理削除
            $relationName = method_exists($section, 'images') ? 'images' : 'sectionImages';

            foreach ($section->{$relationName} as $image) {
                // 1. サーバー内の物理画像ファイルを綺麗に消去
                if (Storage::disk('public')->exists($image->path)) {
                    Storage::disk('public')->delete($image->path);
                }
                // 2. 子の画像レコードをデータベースから安全に物理削除
                $image->delete();
            }

            // 3. 子画像が1件もいなくなった完璧な更地状態のあとで、親のSectionを安全に削除！
            $section->delete();
        });
    }
}
