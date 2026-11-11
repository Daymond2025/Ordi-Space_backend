<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Models\ApprovisionnementPartenaire;
use App\Models\CommandePartenaire;
use App\Services\Partenaire\CommandeParPartenaireService;
use App\Models\Produit;
use App\Services\NotificationAdminService;
use App\Services\Partenaire\StockPartenaireService;
use App\Services\Partenaire\TarificationPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Approvisionnement côté partenaire : il commande à Ordi'Space au prix de
 * cession (figé à la création), déclare son paiement avec une preuve,
 * puis confirme la réception — seul moment où son stock augmente.
 */
class ApprovisionnementController extends ControleurPartenaire
{
    public function index(Request $request): JsonResponse
    {
        $query = ApprovisionnementPartenaire::withCount('lignes')
            ->where('partenaire_id', $this->partenaire($request)->user_id);

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return $this->success($query->latest('id')->paginate(paginate_per_page($request)));
    }

    public function show(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $this->autoriser($request, $approvisionnement);

        return $this->success($this->detail($approvisionnement));
    }

    public function store(Request $request): JsonResponse
    {
        $partenaire = $this->partenaire($request);

        $data = $request->validate([
            'lignes' => ['required', 'array', 'min:1', 'max:50'],
            'lignes.*.produit_id' => ['required', 'integer', 'distinct', 'exists:produits,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $produits = Produit::whereIn('id', array_column($data['lignes'], 'produit_id'))->get()->keyBy('id');

        foreach ($data['lignes'] as $i => $ligne) {
            if (! $produits[$ligne['produit_id']]->estVisibleALaVente()) {
                throw ValidationException::withMessages(["lignes.{$i}.produit_id" => ["Ce produit n'est plus disponible."]]);
            }
        }

        $prix = TarificationPartenaire::prixCessionPour($partenaire, $produits->values());

        $approvisionnement = DB::transaction(function () use ($partenaire, $data, $prix) {
            $approvisionnement = ApprovisionnementPartenaire::create([
                'reference' => ApprovisionnementPartenaire::genererReference(),
                'partenaire_id' => $partenaire->user_id,
                'statut' => STATUT_APPRO_EN_ATTENTE_PAIEMENT,
                'montant_total' => collect($data['lignes'])->sum(fn ($l) => $prix[$l['produit_id']] * $l['quantite']),
            ]);

            foreach ($data['lignes'] as $ligne) {
                $approvisionnement->lignes()->create([
                    'produit_id' => $ligne['produit_id'],
                    'quantite' => $ligne['quantite'],
                    'prix_cession_unitaire' => $prix[$ligne['produit_id']],
                ]);
            }

            return $approvisionnement;
        });

        return $this->success($this->detail($approvisionnement), status: 201);
    }

    /** Paiement d'avance : référence de transaction + capture/photo du reçu. */
    public function declarerPaiement(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $this->autoriser($request, $approvisionnement);
        $this->exigerStatut($approvisionnement, [STATUT_APPRO_EN_ATTENTE_PAIEMENT]);

        $data = $request->validate([
            'mode_paiement' => ['required', Rule::in([MODE_PAIEMENT_MOBILE_MONEY, MODE_PAIEMENT_ESPECES, 'virement'])],
            'reference_paiement' => ['required', 'string', 'max:100'],
            'preuve' => ['required', 'file', 'mimes:'.IMAGE_MIMES_AUTORISES.',pdf', 'max:'.IMAGE_MAX_POIDS_KO],
        ]);

        $ancienne = $approvisionnement->preuve_paiement;
        $chemin = $request->file('preuve')->store(PARTENAIRE_PREUVE_DOSSIER, PARTENAIRE_PREUVE_DISQUE);

        $approvisionnement->update([
            'statut' => STATUT_APPRO_PAIEMENT_DECLARE,
            'mode_paiement' => $data['mode_paiement'],
            'reference_paiement' => $data['reference_paiement'],
            'preuve_paiement' => $chemin,
            'motif_refus' => null,
            'paiement_declare_le' => now(),
        ]);

        if ($ancienne) {
            Storage::disk(PARTENAIRE_PREUVE_DISQUE)->delete($ancienne);
        }

        NotificationAdminService::notifierTousLesAdmins(
            'approvisionnement_partenaire',
            'Paiement partenaire à vérifier',
            "{$this->partenaire($request)->nom_boutique} a déclaré le paiement de l'approvisionnement {$approvisionnement->reference} (".number_format((float) $approvisionnement->montant_total, 0, ',', ' ').' F).'
        );

        return $this->success($this->detail($approvisionnement));
    }

    public function annuler(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $this->autoriser($request, $approvisionnement);
        $this->exigerStatut($approvisionnement, [STATUT_APPRO_EN_ATTENTE_PAIEMENT]);

        $approvisionnement->update(['statut' => STATUT_APPRO_ANNULE, 'annule_le' => now()]);

        return $this->success($this->detail($approvisionnement));
    }

    public function confirmerReception(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $this->autoriser($request, $approvisionnement);

        DB::transaction(function () use ($request, $approvisionnement) {
            // Verrou : deux confirmations simultanées ne doublent pas le stock.
            $verrouille = ApprovisionnementPartenaire::whereKey($approvisionnement->id)->lockForUpdate()->first();
            $this->exigerStatut($verrouille, [STATUT_APPRO_EXPEDIE]);

            foreach ($verrouille->lignes as $ligne) {
                StockPartenaireService::mouvement($verrouille->partenaire_id, $ligne->produit_id, $ligne->quantite, MOUVEMENT_STOCK_APPROVISIONNEMENT, [
                    'approvisionnement_id' => $verrouille->id,
                    'auteur_id' => $request->user()->id,
                ]);
            }

            $verrouille->update(['statut' => STATUT_APPRO_RECU, 'recu_le' => now()]);

            // Approvisionnement d'une commande passée depuis le catalogue : le stock
            // est arrivé, la commande du client est validée automatiquement.
            $commande = $verrouille->commande_partenaire_id
                ? CommandePartenaire::with('lignes')->whereKey($verrouille->commande_partenaire_id)->lockForUpdate()->first()
                : null;
            if ($commande && $commande->statut === STATUT_COMMANDE_EN_ATTENTE && ! $commande->stock_sorti
                && StockPartenaireService::suffisant($commande->partenaire_id, $commande->lignes)) {
                CommandeParPartenaireService::valider($commande, $request->user()->id);
            }
        });

        return $this->success($this->detail($approvisionnement->fresh()));
    }

    /** "Payer" de l'écran Confirmation : paiement fait hors application, à valider par l'Admin. */
    public function confirmerPaiement(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $this->autoriser($request, $approvisionnement);

        return $this->success($this->detail(CommandeParPartenaireService::confirmerPaiementHorsApplication($approvisionnement)));
    }

    public function preuve(Request $request, ApprovisionnementPartenaire $approvisionnement): StreamedResponse
    {
        $this->autoriser($request, $approvisionnement);

        return self::servirPreuve($approvisionnement);
    }

    public static function servirPreuve(ApprovisionnementPartenaire $approvisionnement): StreamedResponse
    {
        abort_unless($approvisionnement->preuve_paiement && Storage::disk(PARTENAIRE_PREUVE_DISQUE)->exists($approvisionnement->preuve_paiement), 404);

        return Storage::disk(PARTENAIRE_PREUVE_DISQUE)->response($approvisionnement->preuve_paiement);
    }

    public static function detail(ApprovisionnementPartenaire $approvisionnement): array
    {
        $approvisionnement->load(['lignes.produit.images', 'lignes.produit.categorie']);

        return [
            ...$approvisionnement->toArray(),
            'lignes' => $approvisionnement->lignes->map(fn ($l) => [
                'id' => $l->id,
                'produit' => self::presenterProduit($l->produit),
                'quantite' => $l->quantite,
                'prix_cession_unitaire' => (float) $l->prix_cession_unitaire,
                'sous_total' => (float) $l->prix_cession_unitaire * $l->quantite,
            ]),
        ];
    }

    private function autoriser(Request $request, ApprovisionnementPartenaire $approvisionnement): void
    {
        abort_unless($approvisionnement->partenaire_id === $this->partenaire($request)->user_id, 404);
    }

    private function exigerStatut(ApprovisionnementPartenaire $approvisionnement, array $statuts): void
    {
        if (! in_array($approvisionnement->statut, $statuts, true)) {
            throw ValidationException::withMessages(['statut' => ["Action impossible : l'approvisionnement est « {$approvisionnement->statut} »."]]);
        }
    }
}
