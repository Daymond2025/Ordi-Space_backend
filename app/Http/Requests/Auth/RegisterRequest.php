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

            // Requis uniquement si type_utilisateur = livreur — décision PDG :
            // le livreur manipule l'argent du client, ces pièces permettent de
            // l'identifier formellement en cas de vol/litige (voir
            // AuthController::register() et Livreur::photoPermis() et sœurs).
            'photo' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'photo_permis' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'photo_cni' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'photo_carte_grise' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ];
    }
}
