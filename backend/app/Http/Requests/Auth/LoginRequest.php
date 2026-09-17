<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // The browser's IANA timezone (e.g. "America/Bogota"), refreshed
            // on every login so it self-corrects if the user travels.
            'timezone' => ['nullable', 'string', 'timezone'],
        ];
    }
}
