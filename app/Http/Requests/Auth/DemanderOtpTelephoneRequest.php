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
            'type_utilisateur' => ['nullable', 'string', Rule::in(roles_auto_inscription_telephone())],
        ];
    }
}
