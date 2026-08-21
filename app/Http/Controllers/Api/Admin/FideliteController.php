<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Commande;
use App\Models\TransactionPortefeuille;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FideliteController extends Controller
{
    /**
     * Vue admin du programme de fidélité déjà réellement en place (portefeuille
     * crédité + parrainage "Carte invitation") — aucun nouveau système de points
     * n'est inventé ici, on rend simplement visible ce qui est déjà suivi par
     * Client::solde_portefeuille / code_parrainage / TransactionPortefeuille.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()
            ->join('users', 'users.id', '=', 'clients.user_id')
            ->where('users.type_utilisateur', ROLE_CLIENT)
            ->select([
                'clients.user_id as id', 'clients.code_parrainage', 'clients.solde_portefeuille',
                'users.nom', 'users.prenom', 'users.email', 'users.telephone',
            ])
            ->selectSub(
                Commande::query()->selectRaw('COUNT(DISTINCT client_id)')->whereColumn('parrain_id', 'clients.user_id'),
                'nombre_filleuls'
            )
            ->selectSub(
                TransactionPortefeuille::query()->selectRaw('MAX(date_transaction)')->whereColumn('client_id', 'clients.user_id'),
                'derniere_transaction'
            );

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(fn ($q) => $q
                ->where('users.nom', 'like', $terme)
                ->orWhere('users.prenom', 'like', $terme)
                ->orWhere('users.email', 'like', $terme)
                ->orWhere('clients.code_parrainage', 'like', $terme));
        }

        // Par défaut, seuls les clients actifs dans le programme (solde > 0 ou
        // au moins un filleul) sont montrés, pour ne pas noyer la liste sous
        // des centaines de comptes jamais crédités.
        if (! $request->boolean('tous')) {
            $query->where(fn ($q) => $q
                ->where('clients.solde_portefeuille', '>', 0)
                ->orWhereExists(fn ($sub) => $sub->selectRaw(1)->from('commandes')->whereColumn('commandes.parrain_id', 'clients.user_id')));
        }

        $clients = $query->orderByDesc('clients.solde_portefeuille')->paginate(paginate_per_page($request));

        $clients->getCollection()->transform(function ($c) {
            $c->solde_portefeuille = (float) $c->solde_portefeuille;
            $c->nombre_filleuls = (int) $c->nombre_filleuls;

            return $c;
        });

        $statistiques = [
            'solde_total' => (float) Client::sum('solde_portefeuille'),
            'total_credite' => (float) TransactionPortefeuille::where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->sum('montant'),
            'clients_avec_filleuls' => Commande::whereNotNull('parrain_id')->pluck('parrain_id')->unique()->count(),
        ];

        return $this->success($clients, ['stats' => $statistiques]);
    }
}
