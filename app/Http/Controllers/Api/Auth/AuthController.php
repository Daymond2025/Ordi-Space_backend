<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Commercial;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
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

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'] ?? null,
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'password' => Hash::make($data['password']),
                'type_utilisateur' => $data['type_utilisateur'],
                'statut_compte' => STATUT_COMPTE_ACTIF,
            ]);

            match ($data['type_utilisateur']) {
                ROLE_FOURNISSEUR => Fournisseur::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $data['nom_entreprise'],
                ]),
                // Un agent IA n'est jamais créé via l'auto-inscription publique :
                // il reçoit un jeton de service émis directement par un Administrateur.
                ROLE_COMMERCIAL => Commercial::create(['user_id' => $user->id, 'type_commercial' => TYPE_COMMERCIAL_HUMAIN]),
                ROLE_LIVREUR => Livreur::create(['user_id' => $user->id]),
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
