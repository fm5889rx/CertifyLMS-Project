<?php

declare(strict_types=1);

namespace App\UseCases\Part;

use App\Enums\ContentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\Part;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; // ストレージファサードをインポート

/**
 * Part の SoftDelete ユースケース。Draft 状態のみ削除可、Published 状態は削除拒否(先に下書きへ戻す必要がある)。
 * B-B-01での修正忘れ。次のブランチでFix。
 */
final class DestroyAction
{
    /**
     * @throws ContentNotDeletableException
     */
    public function __invoke(Part $part): void
    {
        if ($part->status !== ContentStatus::Draft) {
            throw ContentNotDeletableException::forPart();
        }

        // 💡 ⭕【階層制約の完全適合】：
        // $part->delete() が走ってMySQLの外部キー制約に引っ掛かる前の
        // トランザクションの内部において、紐づいている全階層の子リソース（Chapter ➡ Section ➡ 画像）
        // を、末端の階層から順に物理削除します！
        DB::transaction(function () use ($part) {

            // 1. 配下の全Chapterをループ
            foreach ($part->chapters as $chapter) {

                // 2. さらにその配下の全Sectionをループ
                foreach ($chapter->sections as $section) {

                    // 3. 【最末端】教材内画像のリレーションを引き当てて物理削除
                    $relationName = method_exists($section, 'images') ? 'images' : 'sectionImages';
                    foreach ($section->{$relationName} as $image) {
                        if (Storage::disk('public')->exists($image->path)) {
                            Storage::disk('public')->delete($image->path);
                        }
                        $image->delete();
                    }

                    // 4. 子の Section レコードを物理削除
                    $section->delete();
                }

                // 5. 子の Chapter レコードを物理削除
                $chapter->delete();
            }

            // 6. 配下の子・孫リソースが1件もいなくなった完璧な更地状態のあとで、最上位の Part を削除
            $part->delete();
        });
    }
}
