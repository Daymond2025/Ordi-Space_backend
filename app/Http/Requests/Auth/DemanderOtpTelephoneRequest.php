<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DemanderOtpTelephoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telephone' => ['required', 'string', 'max:30'],
            // Absent => ROLE_CLIENT (comportement historique, voir
            // TelephoneAuthController::demanderOtp()) — les apps
            // Fournisseur/Commercial/Livreur l'envoient désormais aussi.
            // Technicien de maintenance inclus en plus de
            // roles_auto_inscription_telephone() : connexion seule, pas
            // d'auto-inscription (compte créé par l'Admin) — voir
            // InscrireTelephoneRequest, qui reste fermé à ce rôle.
            'type_utilisateur' => ['nullable', 'string', Rule::in([...roles_auto_inscription_telephone(), ROLE_TECHNICIEN_MAINTENANCE])],
        ];
    }
}
