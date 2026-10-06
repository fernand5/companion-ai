<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class RecordExercisePerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sets' => ['required', 'array', 'min:1', 'max:50'],
            'sets.*.reps' => ['nullable', 'integer', 'min:0', 'max:200'],
            'sets.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'sets.*.duration_seconds' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'sets.*.completed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('sets', []) as $i => $set) {
                    $set = (array) $set;

                    if (($set['reps'] ?? null) === null && ($set['weight_kg'] ?? null) === null && ($set['duration_seconds'] ?? null) === null) {
                        $validator->errors()->add("sets.$i", 'Each set needs reps, weight or duration.');
                    }
                }
            },
        ];
    }
}
