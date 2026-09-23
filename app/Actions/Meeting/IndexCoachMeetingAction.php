<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 【T-A-02：コーチ向け面談一覧データ取得アクション】
 *
 * 担当コーチ(coach)視点における面談履歴一覧の取得クエリ組み立てを単一に司るアクション。
 * 担当受講生(student)のIDや、特定の受講登録(enrollment)IDによる、
 * Eloquentの動的スコープ・絞り込みチェーンの構築責務をControllerから引越し。
 */
final class IndexCoachMeetingAction
{
    public function __invoke(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $filter = $filters['filter'] ?? 'upcoming';
        $studentId = $filters['student'] ?? null;
        $enrollmentId = $filters['enrollment'] ?? null;

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($user)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        return match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate($perPage),
            'all' => $query->orderByDesc('scheduled_at')->paginate($perPage),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate($perPage),
        };
    }
}
