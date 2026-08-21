<?php

namespace App\Http\Requests\Commande;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('commandes.creer');
    }

    public function rules(): array
    {
        return [
            // Le client concerné : requis si un commercial enregistre pour un
            // client ; ignoré et forcé à l'utilisateur connecté si c'est un
            // client qui commande lui-même — voir CommandeController::store().
            'client_id' => [
                Rule::requiredIf(fn () => $this->user()->type_utilisateur !== ROLE_CLIENT),
                'exists:clients,user_id',
            ],
            // commercial_id n'est plus lu depuis la requête : résolu côté
            // serveur (le commercial connecté, ou l'agent IA pour un client).
            'canal_vente_id' => ['nullable', 'exists:canaux_vente,id'],
            // Optionnelle : une commande 100% numérique (logiciels) n'a rien
            // à livrer. Requise sinon — vérifié dans CommandeController::store.
            'adresse_id' => [
                'nullable',
                Rule::exists('adresses', 'id')->where('client_id', $this->clientIdResolu()),
            ],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            // Code Privilège Space (ex. WELCOME2026), optionnel — vérifié et
            // appliqué dans CommandeController::store.
            'code_promo' => ['nullable', 'string', 'max:50'],
            // Code de parrainage personnel du client "parrain" (ex. ACHAT-KO-2026).
            'code_parrainage' => ['nullable', 'string', 'max:60', 'exists:clients,code_parrainage'],
        ];
    }

    /**
     * Un client connecté commande pour lui-même ; un commercial doit préciser
     * pour quel client il enregistre la commande.
     */
    public function clientIdResolu(): ?int
    {
        return $this->user()->type_utilisateur === ROLE_CLIENT
            ? $this->user()->id
            : $this->integer('client_id');
    }
}
