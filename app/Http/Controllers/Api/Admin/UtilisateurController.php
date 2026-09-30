<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Coordinateur;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\TechnicienMaintenance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UtilisateurController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('type_utilisateur')) {
            $query->where('type_utilisateur', $request->string('type_utilisateur'));
        }

        return $this->success($query->latest()->paginate(paginate_per_page($request)));
    }

    public function show(User $utilisateur): JsonResponse
    {
        return $this->success($utilisateur);
    }

    /**
     * Création manuelle d'un utilisateur par l'admin : Coordinateur et
     * Technicien n'ont aucune autre voie de création (rôles internes exclus
     * de l'auto-inscription). Client/Fournisseur/Livreur peuvent aussi être
     * créés ici directement (ex. accompagné par téléphone, partenaire onboardé
     * en personne), en plus de leur auto-inscription classique depuis l'appli.
     */
    public function provisionner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
            'type_utilisateur' => ['required', Rule::in(roles_provisionnes_par_admin())],
            'specialite' => ['required_if:type_utilisateur,'.ROLE_TECHNICIEN_MAINTENANCE, 'nullable', 'string', 'max:150'],
            'nom_entreprise' => ['required_if:type_utilisateur,'.ROLE_FOURNISSEUR, 'nullable', 'string', 'max:150'],
            'type_vehicule' => ['nullable', 'string', Rule::in(TYPES_VEHICULE_LIVREUR)],
            'zone_couverture' => ['nullable', 'string', 'max:255'],
        ]);

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
                ROLE_COORDINATEUR => Coordinateur::create(['user_id' => $user->id]),
                ROLE_TECHNICIEN_MAINTENANCE => TechnicienMaintenance::create([
                    'user_id' => $user->id,
                    'specialite' => $data['specialite'] ?? null,
                ]),
                ROLE_CLIENT => Client::create([
                    'user_id' => $user->id,
                    'code_parrainage' => Client::genererCodeParrainage($data['nom']),
                ]),
                ROLE_FOURNISSEUR => Fournisseur::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $data['nom_entreprise'],
                ]),
                ROLE_LIVREUR => Livreur::create([
                    'user_id' => $user->id,
                    'type_vehicule' => $data['type_vehicule'] ?? null,
                    'zone_couverture' => $data['zone_couverture'] ?? null,
                ]),
            };

            $user->assignRole($data['type_utilisateur']);

            return $user;
        });

        return $this->success($user, status: 201);
    }

    /**
     * Identité de base (nom/prénom/email/téléphone) — commune à tous les
     * rôles. Les champs "métier" (nom_entreprise, type_vehicule…) se modifient
     * via l'endpoint dédié à chaque rôle (FournisseurController::modifierProfilAdmin(),
     * LivreurController::modifierProfil(), CoordinateurController::modifierProfil()).
     */
    public function modifier(Request $request, User $utilisateur): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($utilisateur->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
        ]);

        $utilisateur->update($data);

        return $this->success($utilisateur->fresh());
    }

    public function changerStatut(Request $request, User $utilisateur): JsonResponse
    {
        $data = $request->validate([
            'statut_compte' => ['required', Rule::in([STATUT_COMPTE_ACTIF, STATUT_COMPTE_SUSPENDU, STATUT_COMPTE_DESACTIVE])],
        ]);

        $utilisateur->update($data);

        // Revoque toutes les sessions actives si le compte est suspendu/désactivé.
        if ($data['statut_compte'] !== STATUT_COMPTE_ACTIF) {
            $utilisateur->tokens()->delete();
        }

        return $this->success($utilisateur->fresh());
    }
}
