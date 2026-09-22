<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OublierMotDePasseRequest;
use App\Http\Requests\Auth\ReinitialiserMotDePasseRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Commercial;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Auto-inscription par e-mail/mot de passe — réservée au personnel non
     * interne (Fournisseur, Commercial humain, Livreur). Client s'inscrit
     * désormais par téléphone, voir Api\Auth\TelephoneAuthController.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Même normalisation/contrôle d'unicité qu'ailleurs (TelephoneAuthController,
        // MoiController::modifierProfil()) : sans ça, deux formats du même
        // numéro passent la validation puis font échouer l'insertion sur la
        // contrainte unique en base (500 brut au lieu d'une erreur propre).
        if (! empty($data['telephone'])) {
            $data['telephone'] = normaliser_telephone($data['telephone']);

            if (User::where('telephone', $data['telephone'])->exists()) {
                throw ValidationException::withMessages([
                    'telephone' => ['Ce numéro est déjà utilisé par un autre compte.'],
                ]);
            }
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'] ?? null,
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'password' => Hash::make($data['password']),
                'type_utilisateur' => $data['type_utilisateur'],
                'statut_compte' => STATUT_COMPTE_ACTIF,
                // Photo de profil : uniquement fournie (et exigée) pour le
                // Livreur pour l'instant — voir RegisterRequest.
                'photo' => isset($data['photo']) ? $data['photo']->store(PHOTO_PROFIL_DOSSIER, IMAGE_PRODUIT_DISQUE) : null,
            ]);

            match ($data['type_utilisateur']) {
                ROLE_FOURNISSEUR => Fournisseur::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $data['nom_entreprise'],
                ]),
                // Un agent IA n'est jamais créé via l'auto-inscription publique :
                // il reçoit un jeton de service émis directement par un Administrateur.
                ROLE_COMMERCIAL => Commercial::create(['user_id' => $user->id, 'type_commercial' => TYPE_COMMERCIAL_HUMAIN]),
                // Permis/CNI/carte grise obligatoires à l'inscription (décision
                // PDG) — voir RegisterRequest et Livreur::photoPermis() et sœurs.
                ROLE_LIVREUR => Livreur::create([
                    'user_id' => $user->id,
                    'photo_permis' => $data['photo_permis']->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                    'photo_cni' => $data['photo_cni']->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                    'photo_carte_grise' => $data['photo_carte_grise']->store(LIVREUR_DOCUMENT_DOSSIER, IMAGE_PRODUIT_DISQUE),
                ]),
            };

            $user->assignRole($data['type_utilisateur']);

            return $user;
        });

        $token = $user->createToken($request->userAgent() ?? 'inscription')->plainTextToken;

        return $this->success([
            'user' => $this->transformUser($user),
            'token' => $token,
        ], status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || $user->type_utilisateur === ROLE_CLIENT || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides.'],
            ]);
        }

        if ($user->statut_compte !== STATUT_COMPTE_ACTIF) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est '.$user->statut_compte.'. Contactez un administrateur.'],
            ]);
        }

        if ($user->requiresTwoFactor()) {
            $user->notify(new OtpCodeNotification($user->emettreCodeOtp()));

            return $this->success([
                'requires_2fa' => true,
                'user_id' => $user->id,
            ]);
        }

        return $this->success($this->issueSession($user, $request->string('device_name')));
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = User::findOrFail($request->integer('user_id'));

        // Au-delà de OTP_TENTATIVES_MAX échecs, le code est invalidé même
        // s'il n'a pas encore expiré — limite le brute-force distribué sur
        // plusieurs IP (le throttle réseau seul est contournable ainsi).
        if ($user->two_factor_tentatives >= OTP_TENTATIVES_MAX) {
            $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null])->save();

            throw ValidationException::withMessages([
                'code' => ['Trop de tentatives — demandez un nouveau code.'],
            ]);
        }

        $codeValide = $user->two_factor_code
            && $user->two_factor_expires_at?->isFuture()
            && Hash::check($request->string('code'), $user->two_factor_code);

        if (! $codeValide) {
            $user->increment('two_factor_tentatives');

            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null, 'two_factor_tentatives' => 0])->save();

        return $this->success($this->issueSession($user, $request->string('device_name')));
    }

    /**
     * Étape 1 du mot de passe oublié : envoie un code par e-mail si l'adresse
     * correspond à un compte du personnel. Répond TOUJOURS le même message de
     * succès, que l'adresse existe ou non — sinon un attaquant pourrait tester
     * des e-mails un par un pour savoir lesquels ont un compte (énumération).
     */
    public function oublierMotDePasse(OublierMotDePasseRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if ($user && $user->peutReinitialiserMotDePasse() && $user->statut_compte === STATUT_COMPTE_ACTIF) {
            $user->notify(new PasswordResetCodeNotification($user->emettreCodeReinitialisation()));
        }

        return $this->success([
            'message' => "Si un compte existe avec cette adresse, un code de réinitialisation vient de lui être envoyé par e-mail.",
        ]);
    }

    /**
     * Étape 2 : le code reçu par e-mail (30 min, voir
     * PASSWORD_RESET_EXPIRATION_MINUTES) plus le nouveau mot de passe.
     * Révoque toutes les sessions actives — le mot de passe précédent a pu
     * être compromis, mieux vaut forcer une reconnexion partout.
     */
    public function reinitialiserMotDePasse(ReinitialiserMotDePasseRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // Même message qu'un mauvais code, pour ne pas confirmer/infirmer
        // l'existence du compte à cette étape non plus.
        $erreurGenerique = fn () => throw ValidationException::withMessages([
            'code' => ['Code invalide ou expiré.'],
        ]);

        if (! $user || ! $user->peutReinitialiserMotDePasse()) {
            $erreurGenerique();
        }

        // Au-delà de PASSWORD_RESET_TENTATIVES_MAX échecs, le code est
        // invalidé même s'il n'a pas encore expiré — même parade anti-brute-
        // force distribué que verifyOtp().
        if ($user->password_reset_tentatives >= PASSWORD_RESET_TENTATIVES_MAX) {
            $user->forceFill(['password_reset_code' => null, 'password_reset_expires_at' => null])->save();
            $erreurGenerique();
        }

        $codeValide = $user->password_reset_code
            && $user->password_reset_expires_at?->isFuture()
            && Hash::check($request->string('code'), $user->password_reset_code);

        if (! $codeValide) {
            $user->increment('password_reset_tentatives');
            $erreurGenerique();
        }

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'password_reset_code' => null,
            'password_reset_expires_at' => null,
            'password_reset_tentatives' => 0,
        ])->save();

        $user->tokens()->delete();

        return $this->success(['message' => 'Mot de passe mis à jour — connectez-vous avec votre nouveau mot de passe.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(['message' => 'Déconnecté.']);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->transformUser($request->user()));
    }

    private function issueSession(User $user, string $deviceName): array
    {
        $user->forceFill(['derniere_connexion' => now()])->save();

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $this->transformUser($user),
            'token' => $token,
        ];
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'type_utilisateur' => $user->type_utilisateur,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}
