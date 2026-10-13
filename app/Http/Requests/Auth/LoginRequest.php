<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            // Optionnel : une app à rôle unique l'envoie pour distinguer sans
            // ambiguïté un compte quand le même e-mail sert aussi à un compte
            // d'un AUTRE rôle (voir AuthController::login()). Absent, le
            // comportement historique est conservé (premier compte non-Client
            // trouvé) — cas de l'Admin Web (Coordinateur + Administrateur).
            'type_utilisateur' => ['nullable', 'string', Rule::in([
                ROLE_COORDINATEUR, ROLE_FOURNISSEUR, ROLE_LIVREUR, ROLE_COMMERCIAL, ROLE_ADMINISTRATEUR, ROLE_TECHNICIEN_MAINTENANCE,
            ])],
        ];
    }
}
