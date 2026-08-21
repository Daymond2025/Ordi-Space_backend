<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            // Coordinateur, Technicien et Administrateur sont exclus — voir
            // roles_auto_inscription() dans app/Helpers/role.php.
            'type_utilisateur' => ['required', 'in:'.implode(',', roles_auto_inscription())],

            // Requis uniquement si type_utilisateur = fournisseur
            'nom_entreprise' => ['required_if:type_utilisateur,'.ROLE_FOURNISSEUR, 'string', 'max:150'],
        ];
    }
}
