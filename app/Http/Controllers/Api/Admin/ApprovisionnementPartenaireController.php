<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Partenaire\ApprovisionnementController as ApprovisionnementPartenaireCote;
use App\Http\Controllers\Controller;
use App\Models\ApprovisionnementPartenaire;
use App\Models\NotificationOrdispace;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Validation des approvisionnements partenaires par l'Admin : vérifier le
 * paiement d'avance (preuve), puis expédier. À l'expédition, le stock
 * Ordi'Space des produits diminue d'autant.
 */
class ApprovisionnementPartenaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApprovisionnementPartenaire::with('partenaire:user_id,nom_boutique')->withCount('lignes');

        if ($request->filled('statut')) {
            $query->whereIn('statut', explode(',', $request->string('statut')));
        }
        if ($request->filled('partenaire_id')) {
            $query->where('partenaire_id', $request->integer('partenaire_id'));
        }

        return $this->success($query->latest('id')->paginate(paginate_per_page($request)));
    }

    public function show(ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        return $this->success([
            ...ApprovisionnementPartenaireCote::detail($approvisionnement),
            'partenaire' => $approvisionnement->partenaire()->with('user:id,nom,prenom,telephone')->first(),
        ]);
    }

    public function preuve(ApprovisionnementPartenaire $approvisionnement): StreamedResponse
    {
        return ApprovisionnementPartenaireCote::servirPreuve($approvisionnement);
    }

    public function validerPaiement(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $a = $this->transition($approvisionnement, [STATUT_APPRO_PAIEMENT_DECLARE], [
            'statut' => STATUT_APPRO_PAIEMENT_VALIDE,
            'paiement_valide_le' => now(),
            'valide_par' => $request->user()->id,
        ]);

        $this->notifier($a, 'Paiement validé', "Le paiement de votre approvisionnement {$a->reference} est validé. Expédition en préparation.");

        return $this->success(ApprovisionnementPartenaireCote::detail($a));
    }

    /** Paiement non retrouvé / montant incorrect : le partenaire peut redéclarer. */
    public function refuserPaiement(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $a = $this->transition($approvisionnement, [STATUT_APPRO_PAIEMENT_DECLARE], [
            'statut' => STATUT_APPRO_EN_ATTENTE_PAIEMENT,
            'motif_refus' => $data['motif'],
        ]);

        $this->notifier($a, 'Paiement refusé', "Le paiement de l'approvisionnement {$a->reference} n'a pas été validé : {$data['motif']}");

        return $this->success(ApprovisionnementPartenaireCote::detail($a));
    }

    public function expedier(ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $a = DB::transaction(function () use ($approvisionnement) {
            $a = $this->verrouiller($approvisionnement, [STATUT_APPRO_PAIEMENT_VALIDE]);

            foreach ($a->lignes as $ligne) {
                $produit = Produit::whereKey($ligne->produit_id)->lockForUpdate()->first();
                if ($produit->quantite_stock < $ligne->quantite) {
                    throw ValidationException::withMessages(['stock' => ["Stock Ordi'Space insuffisant pour « {$produit->nom_produit} » ({$produit->quantite_stock} disponible(s))."]]);
                }
                $produit->decrement('quantite_stock', $ligne->quantite);
            }

            return tap($a)->update(['statut' => STATUT_APPRO_EXPEDIE, 'expedie_le' => now()]);
        });

        $this->notifier($a, 'Approvisionnement expédié', "Votre approvisionnement {$a->reference} est en route. Confirmez la réception pour l'ajouter à votre stock.");

        return $this->success(ApprovisionnementPartenaireCote::detail($a));
    }

    /** Avant expédition uniquement (le remboursement éventuel se fait hors plateforme). */
    public function annuler(Request $request, ApprovisionnementPartenaire $approvisionnement): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $a = $this->transition($approvisionnement, [STATUT_APPRO_EN_ATTENTE_PAIEMENT, STATUT_APPRO_PAIEMENT_DECLARE, STATUT_APPRO_PAIEMENT_VALIDE], [
            'statut' => STATUT_APPRO_ANNULE,
            'motif_refus' => $data['motif'],
            'annule_le' => now(),
        ]);

        $this->notifier($a, 'Approvisionnement annulé', "L'approvisionnement {$a->reference} a été annulé : {$data['motif']}");

        return $this->success(ApprovisionnementPartenaireCote::detail($a));
    }

    private function transition(ApprovisionnementPartenaire $approvisionnement, array $depuis, array $attributs): ApprovisionnementPartenaire
    {
        return DB::transaction(fn () => tap($this->verrouiller($approvisionnement, $depuis))->update($attributs));
    }

    private function verrouiller(ApprovisionnementPartenaire $approvisionnement, array $depuis): ApprovisionnementPartenaire
    {
        $a = ApprovisionnementPartenaire::with('lignes')->whereKey($approvisionnement->id)->lockForUpdate()->firstOrFail();

        if (! in_array($a->statut, $depuis, true)) {
            throw ValidationException::withMessages(['statut' => ["Action impossible : l'approvisionnement est « {$a->statut} »."]]);
        }

        return $a;
    }

    private function notifier(ApprovisionnementPartenaire $a, string $titre, string $contenu): void
    {
        NotificationOrdispace::create([
            'user_id' => $a->partenaire_id,
            'type_notification' => 'approvisionnement_'.$a->statut,
            'titre' => $titre,
            'contenu' => $contenu,
            'lu' => false,
            'date_envoi' => now(),
        ]);
    }
}
