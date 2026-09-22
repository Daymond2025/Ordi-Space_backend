<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OublierMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Volontairement pas de règle "exists:users,email" — la réponse ne doit
        // jamais révéler si l'adresse est connue (voir AuthController).
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
