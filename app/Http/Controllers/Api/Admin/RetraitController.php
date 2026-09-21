<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DemandeRetrait;
use App\Models\NotificationOrdispace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Traitement des demandes de retrait de commissions par l'Administrateur.
 * L'argent est envoyé hors de l'app (Mobile Money) : "valider" enregistre que
 * le transfert est fait (avec sa référence), "refuser" rend le montant au
 * livreur (avec le motif). Dans les deux cas le livreur est notifié.
 */
class RetraitController extends Controller
{
    /**
     * Liste, plus récentes d'abord ; `statut` filtre (toutes si absent). Les
     * totaux par statut (nombre + montant) portent sur l'ensemble des
     * demandes, quel que soit le filtre — ils alimentent l'en-tête de la page.
     */
    public function index(Request $request): JsonResponse
    {
        $statuts = [STATUT_RETRAIT_EN_ATTENTE, STATUT_RETRAIT_VALIDE, STATUT_RETRAIT_REFUSE, STATUT_RETRAIT_ANNULE];
        $data = $request->validate(['statut' => ['nullable', Rule::in($statuts)]]);

        $stats = [];
        foreach ($statuts as $statut) {
            $requete = DemandeRetrait::where('statut', $statut);
            $stats[$statut] = ['nombre' => (clone $requete)->count(), 'montant' => (float) $requete->sum('montant')];
        }

        $retraits = DemandeRetrait::with('user:id,nom,prenom,telephone')
            ->when($data['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->latest('id')
            ->paginate(paginate_per_page($request))
            ->through(fn (DemandeRetrait $retrait) => [
                'id' => $retrait->id,
                'livreur' => trim(($retrait->user->prenom ?? '').' '.($retrait->user->nom ?? '')),
                'livreur_telephone' => $retrait->user->telephone,
                'montant' => (float) $retrait->montant,
                'operateur' => $retrait->operateur,
                'telephone' => $retrait->telephone,
                'statut' => $retrait->statut,
                'reference' => $retrait->reference,
                'remarque' => $retrait->remarque,
                'created_at' => $retrait->created_at,
                'traite_le' => $retrait->traite_le,
            ]);

        return $this->success(['stats' => $stats, 'retraits' => $retraits]);
    }

    public function valider(Request $request, DemandeRetrait $retrait): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);
        $this->verifierEnAttente($retrait);

        $retrait->update([
            'statut' => STATUT_RETRAIT_VALIDE,
            'reference' => $data['reference'],
            'admin_id' => $request->user()->id,
            'traite_le' => now(),
        ]);

        $this->notifier($retrait, 'retrait_valide', 'Retrait effectué',
            'Ton retrait de '.number_format((float) $retrait->montant, 0, ',', ' ')." FCFA a été envoyé sur {$retrait->operateur} {$retrait->telephone} (réf. {$retrait->reference}).");

        return $this->success($retrait->fresh());
    }

    public function refuser(Request $request, DemandeRetrait $retrait): JsonResponse
    {
        $data = $request->validate(['remarque' => ['required', 'string', 'max:255']]);
        $this->verifierEnAttente($retrait);

        $retrait->update([
            'statut' => STATUT_RETRAIT_REFUSE,
            'remarque' => $data['remarque'],
            'admin_id' => $request->user()->id,
            'traite_le' => now(),
        ]);

        $this->notifier($retrait, 'retrait_refuse', 'Retrait refusé',
            'Ton retrait de '.number_format((float) $retrait->montant, 0, ',', ' ')." FCFA a été refusé : {$retrait->remarque}. Le montant est de nouveau disponible.");

        return $this->success($retrait->fresh());
    }

    private function verifierEnAttente(DemandeRetrait $retrait): void
    {
        if (! $retrait->estEnAttente()) {
            throw ValidationException::withMessages(['statut' => ['Cette demande a déjà été traitée.']]);
        }
    }

    private function notifier(DemandeRetrait $retrait, string $type, string $titre, string $contenu): void
    {
        NotificationOrdispace::create([
            'user_id' => $retrait->user_id,
            'type_notification' => $type,
            'titre' => $titre,
            'contenu' => $contenu,
            'lu' => false,
            'date_envoi' => now(),
        ]);
    }
}
