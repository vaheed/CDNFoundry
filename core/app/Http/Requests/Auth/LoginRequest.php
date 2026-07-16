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
        return ['email' => ['required', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:1024'], 'device_name' => ['nullable', 'string', 'max:100']];
    }
}
