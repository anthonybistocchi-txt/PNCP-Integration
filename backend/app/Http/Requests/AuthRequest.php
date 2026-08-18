<?php

namespace App\Http\Requests;

use App\DTOs\AuthDTO;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'email'        => 'required|string|email',
            'company_name' => 'required_with:cnpj|nullable|string|max:255',
            'cpf'          => 'prohibits:cnpj|required_without:cnpj|nullable|string|min:11|max:11|unique:users,cpf',
            'cnpj'         => 'prohibits:cpf|required_without:cpf|nullable|string|min:14|max:14|unique:users,cnpj',
            'password'     => 'required|string|min:8',
        ];
    }

    public function toDTO(): AuthDTO
    {
        return new AuthDTO(
            name: $this->name,
            email: $this->email,
            companyName: $this->company_name,
            cpf: $this->cpf,
            cnpj: $this->cnpj,
            password: $this->password,
        );
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'              => 'Nome é obrigatório.',
            'email.required'             => 'E-mail é obrigatório.',
            'company_name.required_with' => 'Nome da empresa é obrigatório.',
            'cpf.required_without'       => 'CPF ou CNPJ é obrigatório.',
            'cpf.prohibits'              => 'CPF e CNPJ não podem ser informados juntos.',
            'cpf.unique'                 => 'CPF já cadastrado.',
            'cpf.min'                    => 'CPF deve ter pelo menos 11 dígitos.',
            'cpf.max'                    => 'CPF deve ter no máximo 11 dígitos.',
            'cnpj.prohibits'             => 'CPF e CNPJ não podem ser informados juntos.',
            'cnpj.min'                   => 'CNPJ deve ter pelo menos 14 dígitos.',
            'cnpj.max'                   => 'CNPJ deve ter no máximo 14 dígitos.',
            'password.required'          => 'Senha é obrigatória.',
            'password.min'               => 'Senha deve ter pelo menos 8 dígitos.',
        ];
    }
}
