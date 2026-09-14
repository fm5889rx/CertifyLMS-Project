<?php

declare(strict_types=1);

namespace App\UseCases\MockExam;

use App\Enums\UserRole;
use App\Models\MockExam;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin / coach 用の模試マスタ一覧をフィルタ付きで取得するユースケース。
 *
 * 【T-A-01：模試マスタ一覧 N+1 ＆ カウントクエリ最適化】
 *
 * - admin: 全資格配下の MockExam
 * - coach: 担当資格(certification.coaches)配下の MockExam のみ
 * フィルタ: keyword(部分一致) / certification_id / is_published
 */
final class IndexAction
{
    public function __invoke(
        User $auth,
        ?string $keyword = null,
        ?string $certificationId = null,
        ?bool $isPublished = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = MockExam::query();

        // T-A-01で追加
        // 1. with() によって各行に必要な「所属資格、作成者、更新者」の関連レコードをあらかじめ一括ロード
        // 2. withCount() によって、問題数を MySQL 側のサブクエリ結合で 1 発で超高速集計して内部保持
        // これにより、どれほどデータ件数が増えようとも、クエリ発行数を最小の定数回へ激減させる。
        $query->with(['certification', 'createdBy', 'updatedBy'])
            ->withCount('mockExamQuestions');

        if ($auth->role === UserRole::Coach) {
            $query->whereHas(
                'certification.coaches',
                fn ($q) => $q->where('users.id', $auth->id),
            );
        }

        if ($keyword !== null && $keyword !== '') {
            $query->where('title', 'LIKE', '%'.$keyword.'%');
        }

        if ($certificationId !== null && $certificationId !== '') {
            $query->where('certification_id', $certificationId);
        }

        if ($isPublished !== null) {
            $query->where('is_published', $isPublished);
        }

        return $query
            ->orderBy('certification_id')
            ->orderBy('order')
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
