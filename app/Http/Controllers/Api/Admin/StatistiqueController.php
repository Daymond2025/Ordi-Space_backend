<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatistiqueController extends Controller
{
    public function globales(): JsonResponse
    {
        return $this->success([
            'utilisateurs_par_type' => User::selectRaw('type_utilisateur, count(*) as total')
                ->groupBy('type_utilisateur')->pluck('total', 'type_utilisateur'),
            'commandes_par_statut' => Commande::selectRaw('statut_commande, count(*) as total')
                ->groupBy('statut_commande')->pluck('total', 'statut_commande'),
            'produits_par_statut' => Produit::selectRaw('statut_produit, count(*) as total')
                ->groupBy('statut_produit')->pluck('total', 'statut_produit'),
            'chiffre_affaires_encaisse' => Paiement::where('statut_paiement', STATUT_PAIEMENT_CONFIRME)->sum('montant'),
        ]);
    }
}
