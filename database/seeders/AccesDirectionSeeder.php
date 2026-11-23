<?php

namespace Database\Seeders;

use App\Models\Administrateur;
use App\Models\Coordinateur;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Accès de la direction : un compte Super-admin (app Admin) et un compte
 * Coordinateur (app Coordinateur), avec la même identité, le même e-mail et
 * le même mot de passe (l'e-mail n'est unique que par rôle).
 *
 * Le dépôt est public : les identifiants viennent uniquement de
 * l'environnement (DIRECTION_*), rien n'est codé en dur. Sans DIRECTION_EMAIL
 * et DIRECTION_PASSWORD, le seeder ne fait rien.
 *
 * Idempotent : appelé à chaque déploiement (deploy-hook → db:seed), il ne
 * crée les comptes qu'une fois et ne réécrit jamais le mot de passe d'un
 * compte actif ; un compte supprimé/suspendu est réactivé.
 */
class AccesDirectionSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('DIRECTION_EMAIL');
        $password = env('DIRECTION_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                'Accès direction non créés : renseignez DIRECTION_EMAIL et '
                .'DIRECTION_PASSWORD (et DIRECTION_NOM / DIRECTION_PRENOM) dans .env.'
            );

            return;
        }

        $identite = [
            'nom' => env('DIRECTION_NOM', 'Direction'),
            'prenom' => env('DIRECTION_PRENOM'),
            'password' => Hash::make($password),
            'statut_compte' => STATUT_COMPTE_ACTIF,
        ];

        // Super-admin : accès à tous les espaces de l'app Admin.
        $admin = $this->compte($email, ROLE_ADMINISTRATEUR, $identite);
        Administrateur::updateOrCreate(['user_id' => $admin->id], ['est_super_admin' => true, 'espaces_autorises' => null]);
        $admin->syncRoles([ROLE_ADMINISTRATEUR]);

        // Coordinateur : connexion e-mail + mot de passe + code reçu par e-mail.
        $coordinateur = $this->compte($email, ROLE_COORDINATEUR, $identite);
        Coordinateur::firstOrCreate(['user_id' => $coordinateur->id]);
        $coordinateur->syncRoles([ROLE_COORDINATEUR]);

        $this->command?->info('Accès direction prêts : super-admin et coordinateur.');
    }

    /**
     * Compte de ce rôle pour l'e-mail de la direction : créé s'il n'existe
     * pas ; s'il a été supprimé, suspendu ou désactivé (ex. supprimé depuis
     * l'Admin faute de mot de passe), il est réactivé avec le mot de passe
     * DIRECTION_PASSWORD. Un compte actif n'est jamais modifié (son mot de
     * passe a pu être changé depuis l'app).
     */
    private function compte(string $email, string $role, array $identite): User
    {
        $user = User::where('type_utilisateur', $role)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if (! $user) {
            return User::create(['email' => $email, 'type_utilisateur' => $role, ...$identite]);
        }

        if ($user->statut_compte !== STATUT_COMPTE_ACTIF) {
            $user->update($identite);
            $this->command?->info("Accès {$role} de la direction réactivé.");
        }

        return $user;
    }
}
