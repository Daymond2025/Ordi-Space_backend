<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DemanderOtpTelephoneRequest;
use App\Http\Requests\Auth\InscrireTelephoneRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Whatsapp\WhatsappOtpSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Connexion Client par numéro WhatsApp + OTP — remplace l'auto-inscription
 * et la connexion par e-mail/mot de passe pour ce rôle (voir AuthController,
 * qui reste inchangé pour le personnel). Flux en 3 étapes :
 *   1. demanderOtp()  — le client saisit son numéro.
 *   2. inscrire()     — appelé seulement si demanderOtp() a répondu
 *                        "compte_existant: false" (numéro inconnu).
 *   3. AuthController::verifyOtp() — inchangé, générique à tout utilisateur.
 */
class TelephoneAuthController extends Controller
{
    public function __construct(private readonly WhatsappOtpSender $whatsapp)
    {
    }

    public function demanderOtp(DemanderOtpTelephoneRequest $request): JsonResponse
    {
        $telephone = normaliser_telephone($request->string('telephone'));

        $user = User::where('telephone', $telephone)->where('type_utilisateur', ROLE_CLIENT)->first();

        if (! $user) {
            return $this->success(['compte_existant' => false]);
        }

        if ($user->statut_compte !== STATUT_COMPTE_ACTIF) {
            throw ValidationException::withMessages([
                'telephone' => ['Ce compte est '.$user->statut_compte.'. Contactez un administrateur.'],
            ]);
        }

        return $this->success(array_merge(['compte_existant' => true], $this->emettreEtEnvoyerOtp($user)));
    }

    public function inscrire(InscrireTelephoneRequest $request): JsonResponse
    {
        $telephone = normaliser_telephone($request->string('telephone'));

        if (User::where('telephone', $telephone)->exists()) {
            throw ValidationException::withMessages([
                'telephone' => ['Ce numéro est déjà associé à un compte — connectez-vous plutôt.'],
            ]);
        }

        $user = Client::creerCompteMinimal($request->string('nom'), $request->string('prenom') ?: null, $telephone);

        return $this->success($this->emettreEtEnvoyerOtp($user), status: 201);
    }

    private function emettreEtEnvoyerOtp(User $user): array
    {
        $code = $user->emettreCodeOtp();
        $envoyeParWhatsapp = $this->whatsapp->envoyer($user->telephone, $code);

        $reponse = ['user_id' => $user->id];

        // Mode de secours hors production uniquement : Twilio indisponible ou
        // non configuré. Le code est déjà connu du backend (haché en base) —
        // l'exposer en production reviendrait à contourner l'authentification.
        if (! $envoyeParWhatsapp && ! app()->environment('production')) {
            $reponse['code_debug'] = $code;
        }

        return $reponse;
    }
}
