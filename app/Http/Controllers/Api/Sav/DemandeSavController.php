<?php

namespace App\Http\Controllers\Api\Sav;

use App\Http\Controllers\Controller;
use App\Models\DemandeSav;
use App\Models\Garantie;
use App\Models\JournalAudit;
use App\Models\LigneCommandePartenaire;
use App\Models\NotificationOrdispace;
use App\Models\RendezVous;
use App\Services\NotificationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DemandeSavController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // produit.categorie : l'accueil Maintenancier choisit l'illustration
        // (portable / ordinateur de bureau) d'après la catégorie du produit ;
        // rendezVous.intervention : problèmes identifiés sur l'onglet "Intervention".
        $query = DemandeSav::with([
            'client.user',
            'garantie.ligneCommande.produit.images',
            'garantie.ligneCommande.produit.categorie',
            'rendezVous.technicien.user',
            'rendezVous.intervention',
        ]);

        if ($user->type_utilisateur === ROLE_CLIENT) {
            $query->where('client_id', $user->id);
        } elseif ($user->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE) {
            // Même logique que RendezVousController::index().
            $query->visiblesPourTechnicien($user->id)->coteOrdispace();
        } else {
            // Livreur, fournisseur, commercial… : aucune demande SAV (données client).
            abort_unless($user->can(PERMISSION_SAV_TRAITER), 403);
            $query->coteOrdispace();
        }

        if ($request->filled('statut_demande')) {
            $query->where('statut_demande', $request->string('statut_demande'));
        }

        return $this->success($query->latest('date_demande')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        abort_unless($demandeSav->estAccessiblePar($request->user()), 403);

        return $this->success($demandeSav->load([
            'client.user',
            // Écran détail du rendez-vous (app Maintenancier) : "Coordonnées client".
            'client.adresses.localite',
            'garantie.ligneCommande.produit.images',
            'garantie.ligneCommande.produit.categorie',
            'rendezVous.technicien.user',
            'rendezVous.intervention',
        ]));
    }

    /**
     * Accepter / Refuser / Reporter — boutons de l'écran détail du
     * rendez-vous (app Maintenancier). Agit sur le dernier rendez-vous de la
     * demande ; s'il n'y en a pas encore, la première décision le crée,
     * affecté au technicien qui décide (une demande refusée sort donc de la
     * file des autres techniciens, le coordinateur la voit toujours).
     */
    public function decider(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->type_utilisateur === ROLE_TECHNICIEN_MAINTENANCE && $demandeSav->estAccessiblePar($user), 403);

        $rdv =$demandeSav->rendezVous()->latest('id')->first();

        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepter', 'refuser', 'reporter'])],
            'date_rdv' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'reporter' || ($request->input('decision') === 'accepter' && ! $rdv)),
                'nullable', 'date', 'after:now',
            ],
            'lieu' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if($rdv && $rdv->technicien_id && $rdv->technicien_id !== $user->id, 403, 'Ce rendez-vous est affecté à un autre technicien.');

        if (in_array($demandeSav->statut_demande, [STATUT_DEMANDE_SAV_RESOLUE, STATUT_DEMANDE_SAV_CLOTUREE], true)
            || in_array($rdv?->statut_rdv, [STATUT_RDV_TERMINE, STATUT_RDV_REFUSE], true)) {
            throw ValidationException::withMessages(['decision' => ['Ce rendez-vous ne peut plus être modifié.']]);
        }

        $statut = match ($data['decision']) {
            'accepter' => STATUT_RDV_CONFIRME,
            'refuser' => STATUT_RDV_REFUSE,
            'reporter' => STATUT_RDV_REPORTE,
        };

        $attributs = array_filter([
            'statut_rdv' => $statut,
            'technicien_id' => $user->id,
            'date_rdv' => $data['date_rdv'] ?? null,
            'lieu' => $data['lieu'] ?? null,
        ], fn ($v) => $v !== null);

        $rdv = DB::transaction(function () use ($rdv, $demandeSav, $attributs, $statut) {
            $rdv = $rdv
                ? tap($rdv)->update($attributs)
                // date_rdv est obligatoire en base : un refus sans rendez-vous préalable est daté du moment du refus.
                : RendezVous::create(['date_rdv' => now(), ...$attributs, 'demande_sav_id' => $demandeSav->id]);

            if ($statut !== STATUT_RDV_REFUSE && $demandeSav->statut_demande === STATUT_DEMANDE_SAV_EN_ATTENTE) {
                $demandeSav->update(['statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE]);
            }

            return $rdv;
        });

        $date = $rdv->date_rdv->format('d/m/Y à H:i');
        [$titre, $contenu] = match ($statut) {
            STATUT_RDV_CONFIRME => ['Rendez-vous confirmé', "Votre rendez-vous de maintenance est confirmé pour le {$date}."],
            STATUT_RDV_REPORTE => ['Rendez-vous reporté', "Votre rendez-vous de maintenance a été reporté au {$date}."],
            STATUT_RDV_REFUSE => ['Rendez-vous refusé', 'Votre demande de rendez-vous de maintenance n\'a pas pu être acceptée. Notre équipe vous recontacte.'],
        };

        NotificationOrdispace::create([
            'user_id' => $demandeSav->client_id,
            'type_notification' => 'rendez_vous_'.$statut,
            'titre' => $titre,
            'contenu' => $contenu,
            'lu' => false,
            'date_envoi' => now(),
        ]);

        return $this->success($rdv->fresh());
    }

    public function changerStatut(Request $request, DemandeSav $demandeSav): JsonResponse
    {
        abort_unless($demandeSav->estAccessiblePar($request->user()), 403);

        $data =$request->validate([
            'statut_demande' => ['required', Rule::in([
                STATUT_DEMANDE_SAV_EN_ATTENTE,
                STATUT_DEMANDE_SAV_PLANIFIEE,
                STATUT_DEMANDE_SAV_EN_COURS,
                STATUT_DEMANDE_SAV_RESOLUE,
                STATUT_DEMANDE_SAV_CLOTUREE,
            ])],
        ]);

        $demandeSav->update($data);

        return $this->success($demandeSav->fresh());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->type_utilisateur === ROLE_CLIENT, 403);

        $data = $request->validate([
            'garantie_id' => ['nullable', 'exists:garanties,id', 'prohibits:ligne_commande_partenaire_id'],
            // Produit acheté chez un partenaire : la panne lui est adressée d'abord.
            'ligne_commande_partenaire_id' => ['nullable', 'integer', 'exists:lignes_commande_partenaire,id'],
            'description_probleme' => ['required', 'string', 'max:2000'],
        ]);

        if (! empty($data['garantie_id'])) {
            $garantie = Garantie::findOrFail($data['garantie_id']);
            abort_unless(
                $garantie->ligneCommande->commande->client_id === $request->user()->id,
                403,
                'Cette garantie ne vous appartient pas.'
            );
        }

        $partenaireId = null;
        if (! empty($data['ligne_commande_partenaire_id'])) {
            $commandePartenaire = LigneCommandePartenaire::findOrFail($data['ligne_commande_partenaire_id'])->commande;
            abort_unless($commandePartenaire->client_id === $request->user()->id, 403, 'Cet achat ne vous appartient pas.');
            if ($commandePartenaire->statut !== STATUT_COMMANDE_LIVREE) {
                throw ValidationException::withMessages(['ligne_commande_partenaire_id' => ["Cet achat n'a pas encore été livré."]]);
            }
            $partenaireId = $commandePartenaire->partenaire_id;
        }

        $demande = DemandeSav::create([
            ...$data,
            'partenaire_id' => $partenaireId,
            'client_id' => $request->user()->id,
            'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
            'date_demande' => now(),
        ]);

        if ($partenaireId !== null) {
            NotificationOrdispace::create([
                'user_id' => $partenaireId,
                'type_notification' => 'panne_declaree',
                'titre' => 'Nouvelle déclaration de panne',
                'contenu' => "{$request->user()->nom} a déclaré une panne : « ".mb_substr($data['description_probleme'], 0, 120).' »',
                'lu' => false,
                'date_envoi' => now(),
            ]);

            return $this->success($demande, status: 201);
        }

        JournalAudit::enregistrer(
            $request->user()->id,
            ACTION_PANNE_DECLAREE,
            'demande_sav',
            "A déclaré une panne : « ".substr($data['description_probleme'], 0, 120)." »"
        );

        NotificationAdminService::notifierTousLesAdmins(
            'panne_declaree',
            'Nouvelle déclaration de panne',
            "{$request->user()->nom} a déclaré une panne : « ".substr($data['description_probleme'], 0, 120)." »"
        );

        return $this->success($demande, status: 201);
    }
}
