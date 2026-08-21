<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * バリデーションルールを定義する
     */
    public function rules(): array
    {
        return [
            'title'                   => ['required', 'string', 'max:100'],
            'body'                    => ['required', 'string', 'max:10000'],
            'target_type'             => ['required', 'string', Rule::in(['all', 'certification', 'user'])],

            'target_certification_id' => [
                'nullable',
                Rule::requiredIf(fn() => $this->input('target_type') === AnnouncementTargetType::Certification->value),
                'string',
                'max:26',
            ],

            'target_user_id'          => [
                'nullable',
                Rule::requiredIf(fn() => $this->input('target_type') === AnnouncementTargetType::User->value),
                'string',
                'max:26',
            ],
        ];
    }
}
