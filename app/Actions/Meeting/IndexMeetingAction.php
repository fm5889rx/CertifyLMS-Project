<?php

declare(strict_types=1);

namespace App\Actions\Meeting;

use App\Models\User;
use App\Models\Meeting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 【T-A-02：受講生向け面談一覧データ取得アクション】
 *
 * 受講生(student)視点における1on1面談履歴一覧の取得クエリ組み立ておよびページネーション制御を司るアクション。
 * filterクエリ（upcoming/past/all）に応じた時間軸での条件分岐をコントローラーから完全隔離して実行する。
 */
final class IndexMeetingAction
{
    public function __invoke(User $user, ?string $filter = 'upcoming', int $perPage = 20): LengthAwarePaginator
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($user)
            ->orderByDesc('scheduled_at');

        return match ($filter) {
            'past' => $query->past()->paginate($perPage),
            'all'  => $query->paginate($perPage),
            default => $query->upcoming()->paginate($perPage),
        };
    }
}
