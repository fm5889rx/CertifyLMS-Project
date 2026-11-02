<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutRequest extends FormRequest
{
    /**
     * Allows the authenticated route to validate a checkout request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    /**
     * Defines the required meeting-pack identifier.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'meeting_pack_id' => ['required', 'string'],
        ];
    }
}
