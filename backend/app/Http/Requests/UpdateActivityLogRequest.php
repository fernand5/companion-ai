<?php

namespace App\Http\Requests;

use App\Models\ActivityLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logged_date' => ['nullable', 'date', 'before_or_equal:'.$this->user()->localToday()],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            // Distance only applies to treadmill and sport logs (type cannot change on update).
            'distance_km' => [
                'nullable', 'numeric', 'min:0', 'max:1000',
                Rule::prohibitedIf(fn () => ! in_array($this->route('activity')?->type, [ActivityLog::TYPE_TREADMILL, ActivityLog::TYPE_SPORT], true)),
            ],
            'intensity' => ['nullable', 'string', 'in:low,moderate,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
