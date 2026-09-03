<?php

namespace Database\Seeders;

use App\Models\Administrateur;
use App\Models\CanalVente;
use App\Models\Categorie;
use App\Models\Commercial;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        foreach (['Facebook', 'Instagram', 'TikTok', 'WhatsApp', CANAL_VENTE_BOUTIQUE_APPLICATION] as $canal) {
            CanalVente::firstOrCreate(['nom_canal' => $canal]);
        }

        $this->call(LocaliteSeeder::class);

        $this->seedAdministrateurRacine();
        $this->seedAgentIa();
        $this->seedCategoriesAjoutProduitCoordinateur();
    }

    /**
     * Catégories des tuiles de l'écran "Ajout d'un produit" (Espace
     * Coordinateur) — "Ordinateur portable" réutilise la catégorie
     * "Ordinateurs portables" déjà existante, les 5 autres sont nouvelles.
     */
    private function seedCategoriesAjoutProduitCoordinateur(): void
    {
        foreach (['Ordinateur bureau', 'Chargeur', 'Souris', 'Sacs pc', 'Autre'] as $nom) {
            Categorie::firstOrCreate(['nom_categorie' => $nom]);
        }
    }

    /**
     * Compte Administrateur "racine", provisionné une seule fois au déploiement
     * (l'auto-inscription est exclue pour ce rôle — cf. décision d'architecture).
     * Les identifiants viennent de l'environnement : rien n'est codé en dur.
     */
    private function seedAdministrateurRacine(): void
    {
        $email = env('ADMIN_INITIAL_EMAIL');
        $password = env('ADMIN_INITIAL_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn(
                'Aucun administrateur racine créé : renseignez ADMIN_INITIAL_EMAIL '
                .'et ADMIN_INITIAL_PASSWORD dans .env puis relancez ce seeder.'
            );

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'nom' => 'Administrateur',
                'prenom' => 'Racine',
                'password' => Hash::make($password),
                'type_utilisateur' => ROLE_ADMINISTRATEUR,
                'statut_compte' => STATUT_COMPTE_ACTIF,
            ]
        );

        Administrateur::firstOrCreate(['user_id' => $user->id]);
        $user->syncRoles([ROLE_ADMINISTRATEUR]);

        $this->command?->info("Administrateur racine prêt : {$email}");
    }

    /**
     * Commercial "de service" (type_commercial = ia) utilisé pour enregistrer
     * les commandes que les clients passent eux-mêmes depuis l'appli, sans
     * intervention d'un commercial humain — voir CommandeController::store().
     * Compte technique : mot de passe aléatoire, jamais utilisé pour se
     * connecter (l'agent IA reçoit un jeton de service, pas des identifiants).
     */
    private function seedAgentIa(): void
    {
        $user = User::firstOrCreate(
            ['email' => AGENT_IA_EMAIL],
            [
                'nom' => 'Agent',
                'prenom' => 'IA',
                'password' => Hash::make(Str::random(40)),
                'type_utilisateur' => ROLE_COMMERCIAL,
                'statut_compte' => STATUT_COMPTE_ACTIF,
            ]
        );

        Commercial::firstOrCreate(
            ['user_id' => $user->id],
            ['type_commercial' => TYPE_COMMERCIAL_IA, 'nom_modele_ia' => AGENT_IA_NOM_MODELE]
        );

        $user->syncRoles([ROLE_COMMERCIAL]);

        $this->command?->info("Agent IA système prêt : {$user->email}");
    }
}
