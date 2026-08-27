<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\ExtractionClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Nouvelle logique d'acquisition (décision PDG) : un Commercial (humain ou
 * l'Agent IA) enregistre la vente d'un client qui n'a encore aucun compte —
 * ce endpoint crée juste l'identité minimale (nom + téléphone, sans mot de
 * passe ni e-mail) pour obtenir un client_id utilisable immédiatement par
 * POST /commandes (inchangé). Le client active ensuite son espace lui-même
 * via le lien WhatsApp envoyé, en validant son numéro par OTP — voir
 * Api\Auth\TelephoneAuthController::demanderOtp().
 */
class ClientRapideController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'telephone' => ['required', 'string', 'max:30'],
        ]);

        $telephone = normaliser_telephone($data['telephone']);
        $existant = User::where('telephone', $telephone)->first();

        if ($existant) {
            if ($existant->type_utilisateur !== ROLE_CLIENT) {
                throw ValidationException::withMessages([
                    'telephone' => ['Ce numéro est déjà utilisé par un compte non-client.'],
                ]);
            }

            // Idempotent : un commercial qui rappelle par erreur récupère le
            // client existant plutôt que de provoquer une erreur d'unicité.
            return $this->success($existant->load('client'));
        }

        $user = Client::creerCompteMinimal($data['nom'], $data['prenom'] ?? null, $telephone);

        return $this->success($user->load('client'), status: 201);
    }

    /**
     * Paste-parse : extrait nom + téléphone d'un texte collé (conversation
     * WhatsApp/Facebook), pour préremplir ce même endpoint store() ci-dessus.
     * Le coordinateur reste libre de tout corriger avant validation — voir
     * ExtractionClientService pour la dégradation en cas d'échec de l'IA.
     */
    public function extraire(Request $request, ExtractionClientService $service): JsonResponse
    {
        $data = $request->validate(['texte' => ['required', 'string', 'max:5000']]);

        return $this->success($service->extraire($data['texte']));
    }
}
