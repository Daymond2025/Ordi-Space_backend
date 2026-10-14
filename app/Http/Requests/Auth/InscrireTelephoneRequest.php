<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InscrireTelephoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            // Absent => ROLE_CLIENT (comportement historique) — voir
            // TelephoneAuthController::inscrire().
            'type_utilisateur' => ['nullable', 'string', Rule::in(roles_auto_inscription_telephone())],
            // Exigée pour le Livreur (pièce d'identité du profil), optionnelle sinon — même garde que RegisterRequest.
            'photo' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'nom_entreprise' => ['required_if:type_utilisateur,'.ROLE_FOURNISSEUR, 'string', 'max:150'],
            'photo_permis' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'photo_cni' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            'photo_carte_grise' => ['required_if:type_utilisateur,'.ROLE_LIVREUR, 'file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ];
    }
}
