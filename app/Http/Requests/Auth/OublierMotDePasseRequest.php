<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Optionnel — même rôle de désambiguïsation qu'au login, voir
            // LoginRequest.
            'type_utilisateur' => ['nullable', 'string', Rule::in([
                ROLE_COORDINATEUR, ROLE_FOURNISSEUR, ROLE_LIVREUR, ROLE_COMMERCIAL, ROLE_ADMINISTRATEUR, ROLE_TECHNICIEN_MAINTENANCE,
            ])],
        ];
    }
}
