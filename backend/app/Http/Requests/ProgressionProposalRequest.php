<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The only thing a client may submit is the opaque proposal the server issued. Anything
 * else (a load, an exercise, a plan, a date) is refused outright rather than ignored, so a
 * client that tries to supply its own numbers fails loudly instead of appearing to work.
 */
class ProgressionProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['proposal' => ['required', 'string', 'max:4000']];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (array_diff(array_keys($this->all()), ['proposal']) !== []) {
                    $validator->errors()->add('proposal', 'Only the proposal can be submitted.');
                }
            },
        ];
    }
}
