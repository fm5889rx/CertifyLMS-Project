<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\v1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class NotificationIndexRequest extends FormRequest
{
    /**
     * Allows authenticated users to request their notification listing.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    /**
     * Defines the accepted notification-list query parameters.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        if ($this->user()?->role === UserRole::Admin) {
            return [];
        }

        return [
            'tab' => ['nullable', 'string', 'in:all,unread'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    /**
     * Returns localized messages for invalid notification-list parameters.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tab.in' => 'タブ識別子は「all」または「unread」のいずれかを指定してください。',
            'per_page.between' => '1ページあたりの件数は1〜50の整数で指定してください。',
        ];
    }

    /**
     * Returns validation failures using the API's JSON error structure.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors(),
        ], 422));
    }
}
