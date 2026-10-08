<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Commercial;
use App\Models\DemandeRetrait;
use App\Models\LigneCommande;
use App\Models\Produit;
use App\Models\TransactionPortefeuilleCommercial;
use App\Services\NotificationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Accueil" — Espace Commercial (humain) : mêmes compteurs que l'Espace
 * Coordinateur (Api\Coordinateur\EspaceController), mais scopés aux seules
 * commandes DE ce commercial (commercial_id), jamais toutes les commandes
 * de la plateforme. "commission_totale" = COMMISSION_COMMERCIAL_PAR_VENTE ×
 * nombre de commandes livrées (montant fixe, confirmé PDG — jamais
 * Produit::commission_agent) — même calcul que CommercialController::show()
 * (vue Coordinateur sur un commercial précis), ici en self-service.
 * PERMISSION_STATISTIQUES_PERIMETRE était déjà seedée pour ROLE_COMMERCIAL
 * (RolesAndPermissionsSeeder) sans qu'aucune route ne l'utilise encore pour
 * ce rôle — cette route est la première.
 */
class EspaceController extends Controller
{
    public function statistiques(Request $request): JsonResponse
    {
        $commercialId = $request->user()->id;
        [$debut, $fin] = resoudre_periode($request);
        $periodeDefinie = $debut && $fin;

        $base = Commande::query()
            ->where('commercial_id', $commercialId)
            ->when($periodeDefinie, fn ($q) => $q->whereBetween('date_commande', [$debut, $fin]));

        $commandesLivrees = (clone $base)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count();

        return $this->success([
            'periode' => ['debut' => $debut?->toDateTimeString(), 'fin' => $fin?->toDateTimeString()],
            'commandes_total' => (clone $base)->count(),
            'commandes_en_attente' => (clone $base)->where('statut_commande', STATUT_COMMANDE_EN_ATTENTE)->count(),
            // Statut précis (pas un bucket) — carte "Commande Total validée" du mockup accueil.
            'commandes_validees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_VALIDEE)->count(),
            // Large : ni livrée ni annulée — même définition que Produit::statistiquesCommandes()
            // et Api\Coordinateur\EspaceController::statistiques() (commandes_en_cours).
            'commandes_en_cours' => (clone $base)->whereNotIn('statut_commande', [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE])->count(),
            'commandes_livrees' => $commandesLivrees,
            'commandes_annulees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'commission_totale' => (float) ($commandesLivrees * COMMISSION_COMMERCIAL_PAR_VENTE),
        ]);
    }

    /**
     * "Statistiques" (Compte > Statistique) — tableau de bord détaillé, même
     * structure que FournisseurController::statistiques() (vue Coordinateur
     * sur un fournisseur), adaptée : "Commission totale" au lieu de "Chiffre
     * d'affaires" — même calcul que statistiques() ci-dessus (montant fixe ×
     * commandes livrées), pas le ledger du portefeuille, pour que ce chiffre
     * reste cohérent avec l'Accueil et le Compte, qui l'affichent déjà.
     * "Produits les plus vendus" : mêmes lignes de commande que ce commercial,
     * classées par quantité (pas de notion de "produit à moi" ici, contrairement
     * au Fournisseur — il vend ceux des autres).
     */
    public function statistiquesDetail(Request $request): JsonResponse
    {
        $commercialId = $request->user()->id;
        [$debut, $fin] = resoudre_periode($request);
        $periodeDefinie = $debut && $fin;

        $base = Commande::query()
            ->where('commercial_id', $commercialId)
            ->when($periodeDefinie, fn ($q) => $q->whereBetween('date_commande', [$debut, $fin]));

        $commandesLivrees = (clone $base)->where('statut_commande', STATUT_COMMANDE_LIVREE)->count();

        $lignesPeriode = fn () => LigneCommande::whereHas(
            'commande',
            fn ($q) => $q->where('commercial_id', $commercialId)
                ->when($periodeDefinie, fn ($q2) => $q2->whereBetween('date_commande', [$debut, $fin]))
        );

        $produitsPlusVendus = $lignesPeriode()
            ->selectRaw('produit_id, SUM(quantite) as quantite_totale, SUM(quantite * prix_unitaire) as montant_total')
            ->groupBy('produit_id')
            ->orderByDesc('quantite_totale')
            ->with('produit.images')
            ->limit(5)
            ->get()
            ->map(fn ($ligne) => [
                'produit_id' => $ligne->produit_id,
                'nom_produit' => $ligne->produit?->nom_produit,
                'photo' => $ligne->produit?->images->first()?->url_image,
                'quantite_vendue' => (int) $ligne->quantite_totale,
                'montant' => (float) $ligne->montant_total,
            ]);

        return $this->success([
            'commission_totale' => (float) ($commandesLivrees * COMMISSION_COMMERCIAL_PAR_VENTE),
            'croissance_pourcentage' => $this->croissancePourcentageCommercial($commercialId, $request, $commandesLivrees),
            'commandes_total' => (clone $base)->count(),
            'commandes_validees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_VALIDEE)->count(),
            'commandes_livrees' => $commandesLivrees,
            'commandes_annulees' => (clone $base)->where('statut_commande', STATUT_COMMANDE_ANNULEE)->count(),
            'produits_vendus' => (int) $lignesPeriode()->sum('quantite'),
            'produits_distincts_vendus' => (int) $lignesPeriode()->distinct('produit_id')->count('produit_id'),
            'produits_plus_vendus' => $produitsPlusVendus,
        ]);
    }

    /**
     * Compare les commandes livrées (× montant fixe) de la période
     * sélectionnée à la période équivalente précédente — null si "tout" ou
     * si la précédente est à 0 (division impossible). Même structure que
     * FournisseurController::croissancePourcentage().
     */
    private function croissancePourcentageCommercial(int $commercialId, Request $request, int $commandesLivreesActuelles): ?float
    {
        $periode = $request->string('periode')->toString() ?: 'tout';

        [$debutPrecedent, $finPrecedent] = match ($periode) {
            'aujourd_hui' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'semaine' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'semaine_derniere' => [now()->subWeeks(2)->startOfWeek(), now()->subWeeks(2)->endOfWeek()],
            'mois' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            default => [null, null],
        };

        if (! $debutPrecedent || ! $finPrecedent) {
            return null;
        }

        $commandesLivreesPrecedentes = Commande::where('commercial_id', $commercialId)
            ->where('statut_commande', STATUT_COMMANDE_LIVREE)
            ->whereBetween('date_commande', [$debutPrecedent, $finPrecedent])
            ->count();

        if ($commandesLivreesPrecedentes <= 0) {
            return null;
        }

        $commissionActuelle = $commandesLivreesActuelles * COMMISSION_COMMERCIAL_PAR_VENTE;
        $commissionPrecedente = $commandesLivreesPrecedentes * COMMISSION_COMMERCIAL_PAR_VENTE;

        return round((($commissionActuelle - $commissionPrecedente) / $commissionPrecedente) * 100, 1);
    }

    /**
     * "Commandes" (accueil, sous les 3 cartes de stats) — mêmes produits que
     * la liste "Mes commandes" (GET /commandes, scopé commercial_id), mais
     * regroupés PAR PRODUIT avec un compteur par statut, comme l'écran Space
     * du Fournisseur (MessageController::produitsActifs()) — sauf que là-bas
     * c'est scopé "mes PRODUITS" (fournisseur_id) ; ici c'est scopé "MES
     * VENTES" (commercial_id), le Commercial ne possédant aucun produit en
     * propre. Produit::statistiquesCommandes() est global (toute la
     * plateforme) et ne convient donc pas ici — requête dédiée.
     *
     * Le badge vert/le double-check de la carte (mockup) n'a pas d'équivalent
     * "messages non lus" ici (pas de fil de discussion produit dans cette
     * app V1) : interprété comme commandes_en_cours de ce produit (>0 = encore
     * une action à suivre, sinon tout est réglé) — à ajuster si besoin.
     */
    public function produitsActifs(Request $request): JsonResponse
    {
        $commercialId = $request->user()->id;

        $stats = LigneCommande::join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->where('commandes.commercial_id', $commercialId)
            ->selectRaw(
                'lignes_commande.produit_id,
                COUNT(*) as recues,
                SUM(CASE WHEN commandes.statut_commande = ? THEN 1 ELSE 0 END) as livrees,
                SUM(CASE WHEN commandes.statut_commande = ? THEN 1 ELSE 0 END) as annulees,
                MAX(commandes.updated_at) as derniere_activite',
                [STATUT_COMMANDE_LIVREE, STATUT_COMMANDE_ANNULEE]
            )
            ->groupBy('lignes_commande.produit_id')
            ->orderByDesc('derniere_activite')
            ->get();

        if ($stats->isEmpty()) {
            return $this->success([]);
        }

        $produits = Produit::whereIn('id', $stats->pluck('produit_id'))->with('images')->get()->keyBy('id');

        $resultats = $stats
            ->map(function ($ligne) use ($produits) {
                $produit = $produits->get($ligne->produit_id);
                if (! $produit) {
                    return null;
                }

                $enCours = (int) $ligne->recues - (int) $ligne->livrees - (int) $ligne->annulees;

                return [
                    'produit_id' => $produit->id,
                    'nom_produit' => $produit->nom_produit,
                    'photo' => $produit->images->first()?->url_image,
                    'statistiques' => [
                        'recues' => (int) $ligne->recues,
                        'livrees' => (int) $ligne->livrees,
                        'annulees' => (int) $ligne->annulees,
                        'en_cours' => $enCours,
                    ],
                    'derniere_activite' => $ligne->derniere_activite,
                ];
            })
            ->filter()
            ->values();

        return $this->success($resultats);
    }

    /**
     * "Mes paiements" — SOLDES (solde courant, toujours total, indépendant
     * de la période) + historique des transactions (filtrable par période,
     * comme l'accueil). Voir Commercial::crediterPortefeuille()/
     * debiterPortefeuille() et Commande::crediterCommissionCommercialSiEligible().
     */
    public function portefeuille(Request $request): JsonResponse
    {
        $commercialId = $request->user()->id;
        [$debut, $fin] = resoudre_periode($request);
        $periodeDefinie = $debut && $fin;

        $solde = (float) (Commercial::find($commercialId)?->solde_portefeuille ?? 0);

        $transactions = TransactionPortefeuilleCommercial::where('commercial_id', $commercialId)
            ->when($periodeDefinie, fn ($q) => $q->whereBetween('date_transaction', [$debut, $fin]))
            ->latest('date_transaction')
            ->get();

        return $this->success([
            'solde' => $solde,
            // "Demander un retrait" : ce qui reste réellement retirable, déjà
            // amputé des demandes en attente (réservées jusqu'à décision de
            // l'Admin) — évite qu'un commercial cumule plusieurs demandes
            // dépassant ensemble son solde.
            'disponible' => $this->disponiblePourRetrait($commercialId, $solde),
            'retrait_minimum' => RETRAIT_MONTANT_MINIMUM,
            'transactions' => $transactions,
        ]);
    }

    private function disponiblePourRetrait(int $commercialId, float $solde): float
    {
        $enCours = (float) DemandeRetrait::where('user_id', $commercialId)->where('statut', STATUT_RETRAIT_EN_ATTENTE)->sum('montant');

        return max(0.0, $solde - $enCours);
    }

    /**
     * "Mes paiements" > "Demander un retrait" — même mécanique que
     * PortefeuilleController::demander() (Livreur, Boutique) : réutilise
     * DemandeRetrait et sa validation par l'Admin (Admin\RetraitController,
     * 100% générique — aucune modification nécessaire là-bas à part le
     * débit du portefeuille, voir Commercial::debiterPortefeuille()).
     */
    public function demanderRetrait(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'montant' => ['required', 'integer', 'min:'.RETRAIT_MONTANT_MINIMUM],
            'operateur' => ['required', Rule::in(OPERATEURS_RETRAIT)],
            'telephone' => ['required', 'string', 'max:30', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (strlen(preg_replace('/\D/', '', (string) $valeur)) < 8) {
                    $echec('Le numéro doit contenir au moins 8 chiffres.');
                }
            }],
        ], [
            'montant.min' => 'Le retrait minimum est de '.number_format(RETRAIT_MONTANT_MINIMUM, 0, ',', ' ').' FCFA.',
        ]);

        $retrait = DB::transaction(function () use ($user, $data) {
            $commercial = Commercial::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $disponible = $this->disponiblePourRetrait($user->id, (float) $commercial->solde_portefeuille);
            if ($data['montant'] > $disponible) {
                throw ValidationException::withMessages([
                    'montant' => ['Montant supérieur à ta commission disponible ('.number_format($disponible, 0, ',', ' ').' FCFA).'],
                ]);
            }

            return DemandeRetrait::create([
                'user_id' => $user->id,
                'montant' => $data['montant'],
                'operateur' => $data['operateur'],
                'telephone' => normaliser_numero_ci($data['telephone']),
                'statut' => STATUT_RETRAIT_EN_ATTENTE,
            ]);
        });

        NotificationAdminService::notifierTousLesAdmins(
            'retrait_demande',
            'Nouvelle demande de retrait',
            trim(($user->prenom ?? '').' '.$user->nom).' (commercial) demande un retrait de '.number_format($data['montant'], 0, ',', ' ').' FCFA.'
        );

        return $this->success($this->formaterRetrait($retrait), status: 201);
    }

    /** Onglet "Mes retraits" — ses demandes, plus récentes d'abord. */
    public function mesRetraits(Request $request): JsonResponse
    {
        return $this->success(
            DemandeRetrait::where('user_id', $request->user()->id)
                ->latest('id')
                ->paginate(paginate_per_page($request))
                ->through(fn (DemandeRetrait $retrait) => $this->formaterRetrait($retrait))
        );
    }

    /** Annulation par le commercial, tant que l'Admin n'a pas statué — le montant redevient disponible. */
    public function annulerRetrait(Request $request, DemandeRetrait $retrait): JsonResponse
    {
        abort_unless($retrait->user_id === $request->user()->id, 403);

        if (! $retrait->estEnAttente()) {
            throw ValidationException::withMessages(['statut' => ['Cette demande a déjà été traitée.']]);
        }

        $retrait->update(['statut' => STATUT_RETRAIT_ANNULE]);

        return $this->success($this->formaterRetrait($retrait));
    }

    private function formaterRetrait(DemandeRetrait $retrait): array
    {
        return [
            'id' => $retrait->id,
            'montant' => (float) $retrait->montant,
            'operateur' => $retrait->operateur,
            'telephone' => $retrait->telephone,
            'statut' => $retrait->statut,
            'reference' => $retrait->reference,
            'remarque' => $retrait->remarque,
            'created_at' => $retrait->created_at,
            'traite_le' => $retrait->traite_le,
        ];
    }
}
