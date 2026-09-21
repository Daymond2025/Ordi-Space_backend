<?php

namespace App\Http\Requests\Produit;

use Illuminate\Contracts\Validation\Validator;
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
            // Facultative : à défaut, déduite du nom (HP, Dell…) — voir Produit::booted().
            'marque' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'prix' => ['required', 'numeric', 'min:0'],
            // Prix public réel : fixé par l'Admin/le Coordinateur (le fournisseur ne fixe que
            // son prix partenaire `prix`). Sans lui, la boutique affiche "Prix à venir".
            'prix_vente' => ['nullable', 'numeric', 'min:0', Rule::prohibitedIf(fn () => $this->user()?->type_utilisateur === ROLE_FOURNISSEUR)],
            'quantite_stock' => ['required', 'integer', 'min:0'],
            // Numérique = licence/logiciel livré sans passer par un livreur.
            'type_livraison' => ['nullable', Rule::in([TYPE_LIVRAISON_PHYSIQUE, TYPE_LIVRAISON_NUMERIQUE])],
            'duree_garantie_mois' => ['nullable', 'integer', 'min:0', 'max:120'],
            'processeur' => ['nullable', 'string', 'max:150'],
            'memoire_ram' => ['nullable', 'string', 'max:150'],
            'stockage' => ['nullable', 'string', 'max:150'],
            'taille' => ['nullable', 'string', 'max:150'],
            'systeme_exploitation' => ['nullable', 'string', 'max:150'],
            'carte_graphique' => ['nullable', 'string', 'max:150'],
            'couleur' => ['nullable', 'string', 'max:100'],
            'cadeaux' => ['nullable', 'array'],
            'cadeaux.*' => ['string', 'max:100'],
            // "Boutique" — commission qu'un revendeur (Livreur) touche en
            // vendant ce produit via son lien affilié. Renseignée par qui
            // crée le produit (Fournisseur/Coordinateur/Admin), sans rapport
            // avec commission_agent/commission_apporteur (voir Produit::$fillable).
            'commission_revente' => ['nullable', 'numeric', 'min:0'],
            // "Boutique" — état déclaratif, réduction marketing et prix de
            // référence barré. `prix_barre` reste indépendant de `prix`
            // (coût fournisseur, jamais montré au revendeur) et `prix_vente`
            // (prix public réel).
            'etat_produit' => ['nullable', Rule::in(ETATS_PRODUIT)],
            'pourcentage_reduction' => ['nullable', 'integer', 'min:0', 'max:100'],
            'prix_barre' => ['nullable', 'numeric', 'min:0'],
            // Vrais fichiers uploadés (multipart/form-data), stockés localement
            // par ProduitController — voir IMAGE_MAX_POIDS_KO / IMAGE_MIMES_AUTORISES.
            'images' => ['nullable', 'array', 'max:'.IMAGE_PRODUIT_MAX_PAR_ENVOI],
            'images.*' => ['file', 'image', 'mimes:'.IMAGE_MIMES_AUTORISES, 'max:'.IMAGE_MAX_POIDS_KO],
            // Barème de frais de livraison par localité — voir
            // ProduitController::stockerFraisLivraison().
            'frais_livraison' => ['nullable', 'array'],
            'frais_livraison.*.localite_id' => ['required_with:frais_livraison', 'exists:localites,id'],
            'frais_livraison.*.montant' => ['required_with:frais_livraison', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lignes = collect($this->input('frais_livraison', []));
            $localiteIds = $lignes->pluck('localite_id')->filter();

            if ($localiteIds->count() !== $localiteIds->unique()->count()) {
                $validator->errors()->add('frais_livraison', 'Une même localité ne peut apparaître qu\'une seule fois dans le barème.');
            }
        });
    }
}
