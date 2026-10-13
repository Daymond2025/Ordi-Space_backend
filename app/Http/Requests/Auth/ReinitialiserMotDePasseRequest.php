<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ReinitialiserMotDePasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:'.OTP_LONGUEUR],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            // Optionnel — même rôle de désambiguïsation qu'au login, voir
            // LoginRequest.
            'type_utilisateur' => ['nullable', 'string', Rule::in([
                ROLE_COORDINATEUR, ROLE_FOURNISSEUR, ROLE_LIVREUR, ROLE_COMMERCIAL, ROLE_ADMINISTRATEUR, ROLE_TECHNICIEN_MAINTENANCE,
            ])],
        ];
    }
}
