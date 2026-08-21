<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Client;
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
     * Auto-inscription — réservée aux rôles validés avec le PDG
     * (Client, Fournisseur, Commercial humain, Livreur).
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
                ROLE_CLIENT => Client::create([
                    'user_id' => $user->id,
                    'code_parrainage' => Client::genererCodeParrainage($data['nom']),
                ]),
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

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
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
            $code = generate_otp_code();

            $user->forceFill([
                'two_factor_code' => Hash::make($code),
                'two_factor_expires_at' => now()->addMinutes(OTP_EXPIRATION_MINUTES),
            ])->save();

            $user->notify(new OtpCodeNotification($code));

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

        $codeValide = $user->two_factor_code
            && $user->two_factor_expires_at?->isFuture()
            && Hash::check($request->string('code'), $user->two_factor_code);

        if (! $codeValide) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null])->save();

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
