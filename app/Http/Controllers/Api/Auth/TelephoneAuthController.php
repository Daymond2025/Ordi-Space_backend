<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DemanderOtpTelephoneRequest;
use App\Http\Requests\Auth\InscrireTelephoneRequest;
use App\Models\Client;
use App\Models\Commercial;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\User;
use App\Services\Whatsapp\WhatsappOtpSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connexion par numéro WhatsApp + OTP — Client, et depuis l'uniformisation
 * de l'auth, Fournisseur/Commercial/Livreur aussi (plus de mot de passe
 * pour les nouvelles inscriptions de ces rôles). Coordinateur et
 * Administrateur restent sur AuthController (email/mot de passe,
 * inchangé) — comptes internes. Flux en 3 étapes :
 *   1. demanderOtp()  — saisie du numéro (+ rôle, sauf pour le Client).
 *   2. inscrire()     — appelé seulement si demanderOtp() a répondu
 *                        "compte_existant: false" (numéro inconnu pour ce rôle).
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
        $role = $request->filled('type_utilisateur') ? $request->string('type_utilisateur')->toString() : ROLE_CLIENT;

        $user = User::where('telephone', $telephone)->where('type_utilisateur', $role)->first();

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
        $role = $request->filled('type_utilisateur') ? $request->string('type_utilisateur')->toString() : ROLE_CLIENT;

        if (User::where('telephone', $telephone)->where('type_utilisateur', $role)->exists()) {
            throw ValidationException::withMessages([
                'telephone' => ['Ce numéro est déjà associé à un compte — connectez-vous plutôt.'],
            ]);
        }

        if ($role === ROLE_CLIENT) {
            $photoPath = $request->hasFile('photo') ? $request->file('photo')->store(PHOTO_PROFIL_DOSSIER, IMAGE_PRODUIT_DISQUE) : null;
            $user = Client::creerCompteMinimal($request->string('nom'), $request->string('prenom') ?: null, $telephone, $photoPath);
        } else {
            $user = $this->creerComptePersonnel($request, $role, $telephone);
        }

        return $this->success($this->emettreEtEnvoyerOtp($user), status: 201);
    }

    /**
     * Fournisseur/Commercial/Livreur par téléphone — même création de
     * sous-profil que AuthController::register() (volontairement dupliquée,
     * pas extraite : register() reste intact pour les comptes existants),
     * mais sans mot de passe choisi ni e-mail, comme Client::creerCompteMinimal().
     */
    private function creerComptePersonnel(InscrireTelephoneRequest $request, string $role, string $telephone): User
    {
        return DB::transaction(function () use ($request, $role, $telephone) {
            $user = User::create([
                'nom' => $request->string('nom'),
                'prenom' => $request->string('prenom') ?: null,
                'email' => null,
                'telephone' => $telephone,
                'password' => Hash::make(Str::random(40)),
                'type_utilisateur' => $role,
                'statut_compte' => STATUT_COMPTE_ACTIF,
                'photo' => $request->hasFile('photo') ? $request->file('photo')->store(PHOTO_PROFIL_DOSSIER, IMAGE_PRODUIT_DISQUE) : null,
            ]);

            match ($role) {
                ROLE_FOURNISSEUR => Fournisseur::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $request->string('nom_entreprise'),
                ]),
                ROLE_COMMERCIAL => Commercial::create(['user_id' => $user->id, 'type_commercial' => TYPE_COMMERCIAL_HUMAIN]),
                ROLE_LIVREUR => Livreur::create([
                    'user_id' => $user->id,
                    'photo_permis' => $request->file('photo_permis')->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                    'photo_cni' => $request->file('photo_cni')->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                    'photo_carte_grise' => $request->file('photo_carte_grise')->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                ]),
            };

            $user->assignRole($role);

            return $user;
        });
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
