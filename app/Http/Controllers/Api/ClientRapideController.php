<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adresse;
use App\Models\Client;
use App\Models\Localite;
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

    /**
     * Crée une adresse pour un client au nom duquel un Commercial/Coordinateur
     * saisit une commande (flux "création par copier-coller") — distinct de
     * MoiController::ajouterAdresse(), self-service réservé au client
     * lui-même. La localité est toujours confirmée manuellement côté front
     * (jamais déduite automatiquement d'un texte collé) : c'est elle qui
     * conditionne les frais de livraison facturés. "rue" n'a pas de saisie
     * dédiée dans ce flux — repli sur le nom de la localité.
     */
    public function creerAdresse(Request $request, int $client): JsonResponse
    {
        Client::where('user_id', $client)->firstOrFail();

        $data = $request->validate([
            'localite_id' => ['required', 'exists:localites,id'],
        ]);

        $localite = Localite::findOrFail($data['localite_id']);
        $ville = $localite->type === TYPE_LOCALITE_COMMUNE_ABIDJAN ? 'Abidjan' : $localite->nom;

        $adresse = Adresse::create([
            'client_id' => $client,
            'ville' => $ville,
            'rue' => $localite->nom,
            'pays' => "Côte d'Ivoire",
            'localite_id' => $localite->id,
        ]);

        return $this->success($adresse, status: 201);
    }
}
