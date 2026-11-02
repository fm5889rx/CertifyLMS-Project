<?php

declare(strict_types=1);

namespace App\UseCases\QaBoard;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\Question;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the role-scoped Q&A listing and its filter options.
 *
 * @return array{
 *     threads: LengthAwarePaginator,
 *     certifications: Collection<int, Certification>,
 *     filters: array{status: string, certification_id: string, keyword: string},
 *     indexRoute: string,
 *     publishedStatus: CertificationStatus
 * }
 */
final class IndexAction
{
    /**
     * Builds the role-scoped, paginated thread listing and its filter options.
     *
     * @param array{
     *     status?: string|array<int, string>|null,
     *     certification_id?: string|null,
     *     keyword?: string|null
     * } $filters
     *
     * @return array{
     *     threads: LengthAwarePaginator,
     *     certifications: Collection<int, Certification>,
     *     filters: array{status: string, certification_id: string, keyword: string},
     *     indexRoute: string,
     *     publishedStatus: CertificationStatus
     * }
     */
    public function __invoke(User $user, array $filters): array
    {
        $query = Question::query()
            ->with(['user', 'certification'])
            ->withCount('replies');

        if ($user->isCoach()) {
            $assignedIds = $user->assignedCertifications()
                ->where('status', CertificationStatus::Published->value)
                ->pluck('certifications.id')
                ->all();
            $query->whereIn('certification_id', $assignedIds);
        } elseif (! $user->isAdmin()) {
            $publishedIds = Certification::query()
                ->where('status', CertificationStatus::Published->value)
                ->pluck('id')
                ->all();
            $query->whereIn('certification_id', $publishedIds);
        }

        if ($this->isFilled($filters['certification_id'] ?? null)) {
            $query->where('certification_id', $filters['certification_id']);
        }

        $status = $filters['status'] ?? null;
        if ($this->isFilled($status)) {
            $statusString = strtolower(is_array($status) ? implode(' ', $status) : (string) $status);

            if (str_contains($statusString, 'unresolved') || str_contains($statusString, 'open')) {
                $query->where('status', QaThreadStatus::Open->value);
            } elseif (str_contains($statusString, 'resolved')) {
                $query->where('status', QaThreadStatus::Resolved->value);
            }
        }

        $keyword = $filters['keyword'] ?? null;
        if ($this->isFilled($keyword)) {
            $pattern = '%'.$keyword.'%';
            $query->where(function (Builder $threadQuery) use ($pattern): void {
                $threadQuery->where('title', 'like', $pattern)
                    ->orWhere('body', 'like', $pattern)
                    ->orWhereHas('replies', fn (Builder $replyQuery) => $replyQuery->where('body', 'like', $pattern));
            });
        }

        $certifications = match (true) {
            $user->isAdmin() => Certification::all(),
            $user->isCoach() => $user->assignedCertifications()
                ->where('status', CertificationStatus::Published->value)
                ->get(),
            default => Certification::query()
                ->where('status', CertificationStatus::Published->value)
                ->get(),
        };

        $viewStatus = is_array($status) ? '' : ($status ?? '');

        return [
            'threads' => $query->latest('created_at')->paginate(20)->withQueryString(),
            'certifications' => $certifications,
            'filters' => [
                'status' => $viewStatus,
                'certification_id' => $filters['certification_id'] ?? '',
                'keyword' => $keyword ?? '',
            ],
            'indexRoute' => $user->isAdmin() ? 'admin.qa-board.index' : 'qa-board.index',
            'publishedStatus' => CertificationStatus::Published,
        ];
    }

    /**
     * Determines whether an optional filter has a meaningful value.
     */
    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
