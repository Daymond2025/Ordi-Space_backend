<?php

namespace App\Http\Requests\Produit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValiderProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PERMISSION_PRODUITS_VALIDER);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([DECISION_VALIDATION_VALIDE, DECISION_VALIDATION_REJETE])],
            'motif_rejet' => ['required_if:decision,'.DECISION_VALIDATION_REJETE, 'nullable', 'string', 'max:1000'],
        ];
    }
}
