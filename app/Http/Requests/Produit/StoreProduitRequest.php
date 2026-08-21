<?php

namespace App\Http\Requests\Produit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitRequest extends FormRequest
{
    /**
     * L'autorisation fine (permission + propriété du produit) est déjà
     * gérée par le middleware de route (création) et ProduitPolicy (mise à
     * jour) — cf. routes/api.php et app/Policies/ProduitPolicy.php.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categorie_id' => ['required', 'exists:categories,id'],
            'nom_produit' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'prix' => ['required', 'numeric', 'min:0'],
            'quantite_stock' => ['required', 'integer', 'min:0'],
            // Numérique = licence/logiciel livré sans passer par un livreur.
            'type_livraison' => ['nullable', Rule::in([TYPE_LIVRAISON_PHYSIQUE, TYPE_LIVRAISON_NUMERIQUE])],
            'duree_garantie_mois' => ['nullable', 'integer', 'min:0', 'max:120'],
            // Vrais fichiers uploadés (multipart/form-data), stockés localement
            // par ProduitController — voir IMAGE_MAX_POIDS_KO / IMAGE_MIMES_AUTORISES.
            'images' => ['nullable', 'array', 'max:'.IMAGE_PRODUIT_MAX_PAR_ENVOI],
            'images.*' => ['file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
        ];
    }
}
