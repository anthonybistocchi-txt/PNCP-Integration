<?php

namespace App\Http\Requests\Auth;

use App\DTOs\Auth\MeDTO;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ];
    }

    public function toDTO(): MeDTO
    {
        return new MeDTO(
            email:    $this->validated('email'),
            password: $this->validated('password'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required'    => 'E-mail é obrigatório.',
            'email.email'       => 'E-mail deve ser um endereço válido.',
            'password.required' => 'Senha é obrigatória.',
        ];
    }
}
