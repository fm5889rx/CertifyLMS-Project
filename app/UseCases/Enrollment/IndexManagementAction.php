<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Retrieves the staff enrollment listing and its filter options.
 *
 * @return array{
 *     enrollments: LengthAwarePaginator,
 *     status: string,
 *     certification_id: string,
 *     keyword: string,
 *     withTrashed: bool,
 *     certifications: Collection<int, Certification>
 * }
 */
final class IndexManagementAction
{
    /**
     * Returns the staff enrollment listing and available certification filters.
     *
     * @param array{
     *     status?: string|null,
     *     certification_id?: string|null,
     *     keyword?: string|null,
     *     with_trashed?: bool|int|string|null
     * } $filters
     *
     * @return array{
     *     enrollments: LengthAwarePaginator,
     *     status: string,
     *     certification_id: string,
     *     keyword: string,
     *     withTrashed: bool,
     *     certifications: Collection<int, Certification>
     * }
     */
    public function __invoke(User $user, array $filters): array
    {
        $status = $filters['status'] ?? '';
        $certificationId = $filters['certification_id'] ?? '';
        $keyword = trim($filters['keyword'] ?? '');
        $withTrashed = filter_var($filters['with_trashed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = Enrollment::query()
            ->forUser($user)
            ->with(['user', 'certification.category', 'latestStatusLog']);

        if ($user->role === UserRole::Coach) {
            $assignedIds = $user->assignedCertifications()->pluck('certifications.id')->all();
            $query->whereIn('certification_id', $assignedIds);
        }

        if ($user->role === UserRole::Admin && $withTrashed) {
            $query->withTrashed();
        }

        if ($status !== '') {
            $query->where('status', EnrollmentStatus::from($status)->value);
        }

        if ($certificationId !== '') {
            $query->where('certification_id', $certificationId);
        }

        if ($keyword !== '') {
            $query->whereHas(
                'user',
                fn (Builder $userQuery) => $userQuery
                    ->where('name', 'LIKE', "%{$keyword}%")
                    ->orWhere('email', 'LIKE', "%{$keyword}%"),
            );
        }

        $certifications = $user->role === UserRole::Admin
            ? Certification::query()->orderBy('name')->get()
            : $user->assignedCertifications()->orderBy('name')->get();

        return [
            'enrollments' => $query->orderByDesc('created_at')->paginate(20)->withQueryString(),
            'status' => $status,
            'certification_id' => $certificationId,
            'keyword' => $filters['keyword'] ?? '',
            'withTrashed' => $withTrashed,
            'certifications' => $certifications,
        ];
    }
}
