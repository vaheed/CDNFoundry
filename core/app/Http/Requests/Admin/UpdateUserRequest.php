<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => ['sometimes', 'required', Password::min(12)->letters()->mixedCase()->numbers()],
            'type' => ['sometimes', 'required', Rule::enum(UserType::class)],
        ];
    }
}
