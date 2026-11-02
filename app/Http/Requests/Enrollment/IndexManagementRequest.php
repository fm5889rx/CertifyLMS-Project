<?php

declare(strict_types=1);

namespace App\Http\Requests\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexManagementRequest extends FormRequest
{
    /**
     * Allows users permitted to view enrollment listings to apply filters.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Enrollment::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /**
     * Defines the accepted enrollment-list filters.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $enrollmentStatuses = array_map(
            static fn (EnrollmentStatus $status): string => $status->value,
            EnrollmentStatus::cases(),
        );

        return [
            'status' => ['nullable', 'string', Rule::in($enrollmentStatuses)],
            'certification_id' => ['nullable', 'string'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'with_trashed' => ['nullable', 'boolean'],
        ];
    }
}
