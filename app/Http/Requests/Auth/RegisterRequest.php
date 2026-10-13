<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // Unique par rôle, pas globalement : la même adresse peut déjà
            // être utilisée par un compte d'un AUTRE rôle (voir migration
            // unicite_email_telephone_par_role).
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('type_utilisateur', $this->input('type_utilisateur'))],
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
