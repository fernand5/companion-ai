<?php

namespace App\Http\Requests;

use App\Services\Fitness\ActivityService;
use Illuminate\Foundation\Http\FormRequest;

class StoreActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', array_diff(ActivityService::TYPES, ['weight']))],
            'logged_date' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'intensity' => ['nullable', 'string', 'in:low,moderate,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
