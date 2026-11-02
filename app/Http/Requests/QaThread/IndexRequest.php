<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class IndexRequest extends FormRequest
{
    /**
     * Allows authenticated Q&A board users to filter the listing.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /**
     * Defines the accepted Q&A listing filters.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => ['nullable', 'string'],
            'status' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) && ! is_array($value)) {
                        $fail('ステータスの形式が正しくありません。');
                    }
                },
            ],
            'status.*' => ['string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ];
    }
}
