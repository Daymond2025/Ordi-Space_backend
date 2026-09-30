<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Administrateur;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * CRUD des comptes Admin eux-mêmes — distinct de UtilisateurController qui
 * provisionne les AUTRES rôles (coordinateur/fournisseur/livreur/client).
 * Réservé au super-admin (Administrateur::est_super_admin) : un admin
 * restreint ne peut jamais créer/modifier un autre admin, quel que soit son
 * espace. Le rôle Spatie reste "administrateur" pour les deux paliers (pour
 * ne toucher à aucun des `role:administrateur` déjà en place ailleurs dans
 * l'app) — la distinction super-admin/admin et les espaces autorisés vivent
 * uniquement sur la table administrateurs, appliqués par VerifieEspaceAdmin.
 */
class AdministrateurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->autoriserSuperAdmin($request);

        $admins = User::where('type_utilisateur', ROLE_ADMINISTRATEUR)
            ->with('administrateur')
            ->orderBy('nom')
            ->get();

        return $this->success($admins);
    }

    public function show(Request $request, User $utilisateur): JsonResponse
    {
        $this->autoriserSuperAdmin($request);
        abort_unless($utilisateur->type_utilisateur === ROLE_ADMINISTRATEUR, 404);

        return $this->success($utilisateur->load('administrateur'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->autoriserSuperAdmin($request);

        $data = $this->validerDonnees($request, creation: true);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'] ?? null,
                'email' => $data['email'],
                'telephone' => $data['telephone'] ?? null,
                'password' => Hash::make($data['password']),
                'type_utilisateur' => ROLE_ADMINISTRATEUR,
                'statut_compte' => STATUT_COMPTE_ACTIF,
            ]);

            Administrateur::create([
                'user_id' => $user->id,
                'est_super_admin' => $data['role'] === 'super_admin',
                'espaces_autorises' => $data['role'] === 'super_admin' ? null : ($data['espaces'] ?? []),
            ]);

            $user->assignRole(ROLE_ADMINISTRATEUR);

            return $user;
        });

        return $this->success($user->load('administrateur'), status: 201);
    }

    public function update(Request $request, User $utilisateur): JsonResponse
    {
        $this->autoriserSuperAdmin($request);
        abort_unless($utilisateur->type_utilisateur === ROLE_ADMINISTRATEUR, 404);

        $data = $this->validerDonnees($request, creation: false, utilisateurId: $utilisateur->id);

        abort_if(
            $utilisateur->id === $request->user()->id && $data['role'] !== 'super_admin',
            422,
            'Vous ne pouvez pas retirer vos propres droits de super-admin.'
        );

        DB::transaction(function () use ($data, $utilisateur) {
            $champsIdentite = array_intersect_key($data, array_flip(['nom', 'prenom', 'email', 'telephone']));
            if ($champsIdentite !== []) {
                $utilisateur->update($champsIdentite);
            }
            if (! empty($data['password'])) {
                $utilisateur->update(['password' => Hash::make($data['password'])]);
            }

            $utilisateur->administrateur()->updateOrCreate([], [
                'est_super_admin' => $data['role'] === 'super_admin',
                'espaces_autorises' => $data['role'] === 'super_admin' ? null : ($data['espaces'] ?? []),
            ]);
        });

        return $this->success($utilisateur->fresh()->load('administrateur'));
    }

    private function validerDonnees(Request $request, bool $creation, ?int $utilisateurId = null): array
    {
        return $request->validate([
            'nom' => [$creation ? 'required' : 'sometimes', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => [$creation ? 'required' : 'sometimes', 'email', Rule::unique('users', 'email')->ignore($utilisateurId)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'password' => [$creation ? 'required' : 'nullable', Password::min(8)->mixedCase()->numbers()],
            'role' => ['required', Rule::in(['super_admin', 'admin'])],
            'espaces' => ['nullable', 'array'],
            'espaces.*' => [Rule::in(ESPACES_ADMIN)],
        ]);
    }

    private function autoriserSuperAdmin(Request $request): void
    {
        abort_unless($request->user()->administrateur?->est_super_admin ?? false, 403, 'Réservé au super-admin.');
    }
}
