<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbonnementGarantix;
use App\Models\Client;
use App\Models\Commande;
use App\Models\DemandeSav;
use App\Models\Garantie;
use App\Models\Intervention;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\NotificationOrdispace;
use App\Models\ProgressionTutoriel;
use App\Models\Reclamation;
use App\Models\TransactionPortefeuille;
use App\Models\User;
use App\Models\UtilisationPrivilege;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClientController extends Controller
{
    /**
     * Liste enrichie pour l'espace "Gestion clients" de l'admin : chaque
     * ligne agrège en une seule requête (sous-selects, pas de N+1) les
     * indicateurs métier affichés sur la fiche client (produits achetés,
     * montant dépensé, interventions SAV) — jamais chargée en mémoire pour
     * calculer des stats, le volume de clients réels ne le permettrait pas.
     */
    public function index(Request $request): JsonResponse
    {
        $rechercheEnLigne = now()->subMinutes(15);

        $query = Client::query()
            ->join('users', 'users.id', '=', 'clients.user_id')
            ->where('users.type_utilisateur', ROLE_CLIENT)
            ->select([
                'clients.user_id as id',
                'clients.date_inscription',
                'users.nom', 'users.prenom', 'users.email', 'users.telephone',
                'users.statut_compte', 'users.derniere_connexion', 'users.created_at',
            ])
            ->selectSub(
                LigneCommande::query()
                    ->selectRaw('COALESCE(SUM(lignes_commande.quantite), 0)')
                    ->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
                    ->whereColumn('commandes.client_id', 'clients.user_id')
                    ->where('commandes.statut_commande', '!=', STATUT_COMMANDE_ANNULEE),
                'produits_achetes'
            )
            ->selectSub(
                Commande::query()
                    ->selectRaw('COALESCE(SUM(montant_total - montant_remise), 0)')
                    ->whereColumn('client_id', 'clients.user_id')
                    ->where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE),
                'total_depense'
            )
            ->selectSub(
                Intervention::query()
                    ->selectRaw('COUNT(*)')
                    ->join('rendez_vous', 'rendez_vous.id', '=', 'interventions.rendez_vous_id')
                    ->join('demandes_sav', 'demandes_sav.id', '=', 'rendez_vous.demande_sav_id')
                    ->whereColumn('demandes_sav.client_id', 'clients.user_id'),
                'nombre_interventions'
            )
            ->selectSub(
                Commande::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('client_id', 'clients.user_id')
                    ->where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE),
                'nombre_commandes'
            );

        if ($request->filled('recherche')) {
            $terme = '%'.$request->string('recherche').'%';
            $query->where(fn ($q) => $q
                ->where('users.nom', 'like', $terme)
                ->orWhere('users.prenom', 'like', $terme)
                ->orWhere('users.email', 'like', $terme)
                ->orWhere('users.telephone', 'like', $terme));
        }

        if ($request->filled('statut_compte')) {
            $query->where('users.statut_compte', $request->string('statut_compte'));
        }

        match ($request->string('tri')->toString()) {
            'ancien' => $query->orderBy('users.created_at'),
            'depense' => $query->orderByDesc('total_depense'),
            default => $query->orderByDesc('users.created_at'),
        };

        $clients = $query->paginate(paginate_per_page($request));

        $clients->getCollection()->transform(function ($client) use ($rechercheEnLigne) {
            $client->derniere_connexion = $client->derniere_connexion ? \Carbon\Carbon::parse($client->derniere_connexion) : null;
            $client->en_ligne = $client->derniere_connexion !== null && $client->derniere_connexion->greaterThanOrEqualTo($rechercheEnLigne);
            $client->produits_achetes = (int) $client->produits_achetes;
            $client->total_depense = (float) $client->total_depense;
            $client->nombre_interventions = (int) $client->nombre_interventions;
            $client->nombre_commandes = (int) $client->nombre_commandes;
            $client->segment = Client::segmentDepuisCommandes($client->nombre_commandes);

            return $client;
        });

        $statistiques = [
            'total' => $this->baseClientsQuery()->count(),
            'nouveaux_semaine' => $this->baseClientsQuery()->where('created_at', '>=', now()->subWeek())->count(),
            'en_ligne' => $this->baseClientsQuery()->where('derniere_connexion', '>=', $rechercheEnLigne)->count()
        ];

        return $this->success($clients, ['stats' => $statistiques]);
    }

    /**
     * Fiche client complète — un seul appel pour alimenter tous les onglets
     * de la page (Activités, Commandes, Panier, Maintenance, Privilèges,
     * Garanties, Formation, Profil), le volume par client restant faible.
     */
    public function show(User $utilisateur): JsonResponse
    {
        abort_unless($utilisateur->type_utilisateur === ROLE_CLIENT, 404);

        $client = Client::with(['user', 'adresses', 'panier.lignes.produit.images'])->findOrFail($utilisateur->id);

        $commandes = $client->commandes()->with(['lignes.produit.images', 'lignes.produit.categorie', 'lignes.garantie'])->latest('date_commande')->get();

        $garanties = Garantie::whereHas('ligneCommande.commande', fn ($q) => $q->where('client_id', $client->user_id))
            ->with('ligneCommande.produit.images')
            ->get();

        $abonnementsGarantix = AbonnementGarantix::where('client_id', $client->user_id)
            ->with('formule.prestations', 'ligneCommande.produit.images')
            ->latest('date_debut')
            ->get();

        $demandesSav = DemandeSav::where('client_id', $client->user_id)
            ->with(['garantie.ligneCommande.produit', 'rendezVous.technicien.user', 'rendezVous.intervention'])
            ->latest('date_demande')
            ->get();

        $utilisationsPrivilege = UtilisationPrivilege::where('client_id', $client->user_id)
            ->with('privilege', 'commande')
            ->latest('date_utilisation')
            ->get();

        $progressionsTutoriels = ProgressionTutoriel::where('client_id', $client->user_id)
            ->with('tutoriel')
            ->latest('date_vue')
            ->get();

        $commandesValides = $commandes->where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE);

        $produitsAchetes = $commandesValides->flatMap(fn ($c) => $c->lignes)->sum('quantite');

        $totalDepense = $commandesValides->sum(fn ($c) => $c->montant_total - $c->montant_remise);

        $garantiesActives = $garanties->filter(fn ($g) => $g->date_fin >= now())->count()
            + $abonnementsGarantix->where('statut', STATUT_ABONNEMENT_GARANTIX_ACTIF)->count();

        $stats = [
            'total_depense' => (float) $totalDepense,
            'commandes' => $commandes->count(),
            'produits_achetes' => (int) $produitsAchetes,
            'garanties_actives' => $garantiesActives,
            'interventions' => $demandesSav->flatMap(fn ($d) => $d->rendezVous)->filter(fn ($r) => $r->intervention)->count(),
        ];

        $derniereConnexion = $utilisateur->derniere_connexion ? \Carbon\Carbon::parse($utilisateur->derniere_connexion) : null;

        $badges = [
            'garantie_active' => $garantiesActives > 0,
            'segment' => Client::segmentDepuisCommandes($commandesValides->count()),
            'en_ligne' => $derniereConnexion !== null && $derniereConnexion->greaterThanOrEqualTo(now()->subMinutes(15)),
        ];

        return $this->success([
            'profil' => $client,
            'stats' => $stats,
            'badges' => $badges,
            'activites' => $this->construireFilActivites($client->user_id),
            'commandes' => $commandes,
            'maintenance' => ['demandes' => $demandesSav],
            'privileges' => ['utilisations' => $utilisationsPrivilege],
            'garanties' => ['garanties' => $garanties, 'abonnements_garantix' => $abonnementsGarantix],
            'formations' => ['progressions' => $progressionsTutoriels],
        ]);
    }

    /**
     * Notification directe envoyée par l'admin à ce client (réutilise la
     * table notifications_ordispace déjà lue par l'app cliente).
     */
    public function notifier(Request $request, User $utilisateur): JsonResponse
    {
        abort_unless($utilisateur->type_utilisateur === ROLE_CLIENT, 404);

        $data = $request->validate(['contenu' => ['required', 'string', 'max:500']]);

        $notification = NotificationOrdispace::create([
            'user_id' => $utilisateur->id,
            'type_notification' => 'admin',
            'contenu' => $data['contenu'],
            'lu' => false,
            'date_envoi' => now(),
        ]);

        return $this->success($notification, status: 201);
    }

    /**
     * Fil "Activités" : le journal d'audit ne couvre que les actions
     * enregistrées depuis sa mise en service — on le complète avec les
     * commandes/abonnements/pannes plus anciens pour ne pas avoir un fil
     * vide sur les comptes créés avant. Toutes les entrées sont des faits
     * réels, jamais générées.
     */
    private function construireFilActivites(int $clientId): Collection
    {
        $journal = JournalAudit::where('user_id', $clientId)
            ->latest('date_heure')
            ->limit(40)
            ->get()
            ->map(fn ($j) => [
                'categorie' => match (true) {
                    str_starts_with($j->action, 'commande') => 'Boutique',
                    str_starts_with($j->action, 'panier') => 'Boutique',
                    str_starts_with($j->action, 'garantix') => 'GarantiX',
                    str_starts_with($j->action, 'panne') => 'Maintenance',
                    str_starts_with($j->action, 'privilege') => 'Privilèges',
                    str_starts_with($j->action, 'tutoriel') => 'Formation',
                    str_starts_with($j->action, 'reclamation') => 'Réclamations',
                    default => 'Autre',
                },
                'description' => $j->details ?? $j->action,
                'date' => $j->date_heure,
            ]);

        return $journal->sortByDesc('date')->values();
    }

    /**
     * KPIs agrégés pour le "Tableau de bord" de l'espace Gestion Clients :
     * une vue d'ensemble de chaque fonctionnalité (segmentation, commandes,
     * fidélité, réclamations, garanties) sans avoir à ouvrir chaque page.
     */
    public function tableauDeBord(): JsonResponse
    {
        $rechercheEnLigne = now()->subMinutes(15);
        $clients = $this->baseClientsQuery()->get(['id', 'created_at', 'statut_compte', 'derniere_connexion']);

        $commandesParClient = Commande::where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE)
            ->selectRaw('client_id, count(*) as nb')
            ->groupBy('client_id')
            ->pluck('nb', 'client_id');

        $segments = ['nouveau' => 0, 'gros_acheteur' => 0, 'vip' => 0, 'aucun' => 0];
        foreach ($clients as $c) {
            $segment = Client::segmentDepuisCommandes((int) ($commandesParClient[$c->id] ?? 0)) ?? 'aucun';
            $segments[$segment]++;
        }

        $inscriptionsParSemaine = [];
        for ($i = 7; $i >= 0; $i--) {
            $debut = now()->subWeeks($i)->startOfWeek();
            $fin = now()->subWeeks($i)->endOfWeek();
            $inscriptionsParSemaine[] = [
                'semaine' => $debut->format('d/m'),
                'total' => $clients->whereBetween('created_at', [$debut, $fin])->count(),
            ];
        }

        $garantiesActives = Garantie::where('date_fin', '>=', now())->count()
            + AbonnementGarantix::where('statut', STATUT_ABONNEMENT_GARANTIX_ACTIF)->count();

        return $this->success([
            'clients' => [
                'total' => $clients->count(),
                'actifs' => $clients->where('statut_compte', STATUT_COMPTE_ACTIF)->count(),
                'suspendus' => $clients->where('statut_compte', STATUT_COMPTE_SUSPENDU)->count(),
                'desactives' => $clients->where('statut_compte', STATUT_COMPTE_DESACTIVE)->count(),
                'en_ligne' => $clients->filter(fn ($c) => $c->derniere_connexion && $c->derniere_connexion->greaterThanOrEqualTo($rechercheEnLigne))->count(),
                'nouveaux_7j' => $clients->where('created_at', '>=', now()->subDays(7))->count(),
                'nouveaux_30j' => $clients->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'segments' => $segments,
            'inscriptions_par_semaine' => $inscriptionsParSemaine,
            'commandes' => [
                'total' => Commande::where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE)->count(),
                'chiffre_affaires' => (float) Commande::where('statut_commande', '!=', STATUT_COMMANDE_ANNULEE)
                    ->selectRaw('COALESCE(SUM(montant_total - montant_remise), 0) as total')->value('total'),
                'par_statut' => Commande::selectRaw('statut_commande, count(*) as total')->groupBy('statut_commande')->pluck('total', 'statut_commande'),
            ],
            'fidelite' => [
                'solde_total' => (float) Client::sum('solde_portefeuille'),
                'total_credite' => (float) TransactionPortefeuille::where('type', TYPE_TRANSACTION_PORTEFEUILLE_CREDIT)->sum('montant'),
                'clients_avec_filleuls' => Commande::whereNotNull('parrain_id')->pluck('parrain_id')->unique()->count(),
            ],
            'reclamations' => [
                'total' => Reclamation::count(),
                'par_statut' => Reclamation::selectRaw('statut, count(*) as total')->groupBy('statut')->pluck('total', 'statut'),
            ],
            'garanties_actives' => $garantiesActives,
        ]);
    }

    private function baseClientsQuery()
    {
        return User::query()->where('type_utilisateur', ROLE_CLIENT);
    }
}
