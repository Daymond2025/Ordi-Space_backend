<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Traduit en code la matrice de permissions validée avec le PDG
     * (cf. étude d'architecture technique — section Autorisation).
     * Constantes définies dans app/Helpers/role.php.
     */
    private const PERMISSIONS = [
        PERMISSION_PRODUITS_CREER,
        PERMISSION_PRODUITS_MODIFIER,
        PERMISSION_PRODUITS_VALIDER,
        PERMISSION_PRODUITS_CONSULTER,
        PERMISSION_PRODUITS_SUPPRIMER,
        PERMISSION_COMMANDES_CREER,
        PERMISSION_COMMANDES_VALIDER,
        PERMISSION_COMMANDES_CONSULTER,
        PERMISSION_LIVRAISONS_GERER,
        PERMISSION_SAV_TRAITER,
        PERMISSION_SAV_CREER,
        PERMISSION_RETOURS_TRAITER,
        PERMISSION_UTILISATEURS_GERER,
        PERMISSION_ROLES_GERER,
        PERMISSION_STATISTIQUES_GLOBALES,
        PERMISSION_STATISTIQUES_PERIMETRE,
        PERMISSION_GARANTIX_GERER,
        PERMISSION_GARANTIX_SOUSCRIRE,
        PERMISSION_TUTORIELS_GERER,
        PERMISSION_PRIVILEGES_GERER,
        PERMISSION_ASSISTANCE_GERER,
        PERMISSION_RECLAMATIONS_GERER,
    ];

    private const ROLES = [
        ROLE_FOURNISSEUR => [
            PERMISSION_PRODUITS_CREER, PERMISSION_PRODUITS_MODIFIER, PERMISSION_PRODUITS_CONSULTER,
            PERMISSION_RETOURS_TRAITER, PERMISSION_STATISTIQUES_PERIMETRE,
        ],
        ROLE_COMMERCIAL => [
            PERMISSION_PRODUITS_CONSULTER, PERMISSION_COMMANDES_CREER, PERMISSION_COMMANDES_CONSULTER,
            PERMISSION_STATISTIQUES_PERIMETRE,
        ],
        ROLE_COORDINATEUR => [
            PERMISSION_PRODUITS_VALIDER, PERMISSION_PRODUITS_CONSULTER, PERMISSION_COMMANDES_VALIDER,
            PERMISSION_COMMANDES_CONSULTER, PERMISSION_STATISTIQUES_PERIMETRE,
        ],
        ROLE_CLIENT => [
            PERMISSION_PRODUITS_CONSULTER, PERMISSION_COMMANDES_CREER, PERMISSION_COMMANDES_CONSULTER, PERMISSION_SAV_CREER,
            PERMISSION_GARANTIX_SOUSCRIRE,
        ],
        ROLE_LIVREUR => [
            PERMISSION_COMMANDES_CONSULTER, PERMISSION_LIVRAISONS_GERER, PERMISSION_STATISTIQUES_PERIMETRE,
        ],
        ROLE_TECHNICIEN_MAINTENANCE => [
            PERMISSION_SAV_TRAITER,
        ],
        ROLE_ADMINISTRATEUR => null, // reçoit toutes les permissions, voir plus bas.
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions ?? self::PERMISSIONS);
        }
    }
}
