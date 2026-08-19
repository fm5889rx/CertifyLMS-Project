<?php

declare(strict_types=1);

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class MeetingPackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'meeting_count'    => ['required', 'integer', 'min:1', 'max:100'],
            'price'            => ['required', 'integer', 'min:0'],
            'stripe_price_id'  => ['nullable', 'string', 'max:255'],
            'sort_order'       => ['nullable', 'integer', 'min:0'],
        ];
    }
}
