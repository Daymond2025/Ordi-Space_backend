<?php

/**
 * Constantes de rôles et permissions OrdiSpace.
 *
 * Reprend exactement les valeurs `type_utilisateur` (migration users) et les
 * noms de rôles/permissions spatie/laravel-permission (RolesAndPermissionsSeeder).
 * Objectif : ne plus jamais écrire "administrateur" ou "produits.valider" en
 * chaîne libre dans le code métier — une seule source de vérité.
 *
 * Chargé automatiquement via composer.json > autoload > files.
 */

// --- Rôles (= valeurs de type_utilisateur ET noms de rôles spatie) -------
defined('ROLE_FOURNISSEUR') || define('ROLE_FOURNISSEUR', 'fournisseur');
defined('ROLE_COMMERCIAL') || define('ROLE_COMMERCIAL', 'commercial');
defined('ROLE_COORDINATEUR') || define('ROLE_COORDINATEUR', 'coordinateur');
defined('ROLE_CLIENT') || define('ROLE_CLIENT', 'client');
defined('ROLE_LIVREUR') || define('ROLE_LIVREUR', 'livreur');
defined('ROLE_TECHNICIEN_MAINTENANCE') || define('ROLE_TECHNICIEN_MAINTENANCE', 'technicien_maintenance');
defined('ROLE_ADMINISTRATEUR') || define('ROLE_ADMINISTRATEUR', 'administrateur');

// --- Sous-type Commercial -------------------------------------------------
defined('TYPE_COMMERCIAL_HUMAIN') || define('TYPE_COMMERCIAL_HUMAIN', 'humain');
defined('TYPE_COMMERCIAL_IA') || define('TYPE_COMMERCIAL_IA', 'ia');

// --- Permissions (cf. RolesAndPermissionsSeeder) -------------------------
defined('PERMISSION_PRODUITS_CREER') || define('PERMISSION_PRODUITS_CREER', 'produits.creer');
defined('PERMISSION_PRODUITS_MODIFIER') || define('PERMISSION_PRODUITS_MODIFIER', 'produits.modifier');
defined('PERMISSION_PRODUITS_VALIDER') || define('PERMISSION_PRODUITS_VALIDER', 'produits.valider');
defined('PERMISSION_PRODUITS_CONSULTER') || define('PERMISSION_PRODUITS_CONSULTER', 'produits.consulter');
defined('PERMISSION_PRODUITS_SUPPRIMER') || define('PERMISSION_PRODUITS_SUPPRIMER', 'produits.supprimer');
defined('PERMISSION_COMMANDES_CREER') || define('PERMISSION_COMMANDES_CREER', 'commandes.creer');
defined('PERMISSION_COMMANDES_VALIDER') || define('PERMISSION_COMMANDES_VALIDER', 'commandes.valider');
defined('PERMISSION_COMMANDES_CONSULTER') || define('PERMISSION_COMMANDES_CONSULTER', 'commandes.consulter');
defined('PERMISSION_LIVRAISONS_GERER') || define('PERMISSION_LIVRAISONS_GERER', 'livraisons.gerer');
defined('PERMISSION_SAV_TRAITER') || define('PERMISSION_SAV_TRAITER', 'sav.traiter');
defined('PERMISSION_SAV_CREER') || define('PERMISSION_SAV_CREER', 'sav.creer');
defined('PERMISSION_RETOURS_TRAITER') || define('PERMISSION_RETOURS_TRAITER', 'retours.traiter');
defined('PERMISSION_UTILISATEURS_GERER') || define('PERMISSION_UTILISATEURS_GERER', 'utilisateurs.gerer');
defined('PERMISSION_ROLES_GERER') || define('PERMISSION_ROLES_GERER', 'roles.gerer');
defined('PERMISSION_STATISTIQUES_GLOBALES') || define('PERMISSION_STATISTIQUES_GLOBALES', 'statistiques.consulter.globales');
defined('PERMISSION_STATISTIQUES_PERIMETRE') || define('PERMISSION_STATISTIQUES_PERIMETRE', 'statistiques.consulter.perimetre');
defined('PERMISSION_GARANTIX_GERER') || define('PERMISSION_GARANTIX_GERER', 'garantix.gerer');
defined('PERMISSION_GARANTIX_SOUSCRIRE') || define('PERMISSION_GARANTIX_SOUSCRIRE', 'garantix.souscrire');
defined('PERMISSION_TUTORIELS_GERER') || define('PERMISSION_TUTORIELS_GERER', 'tutoriels.gerer');
defined('PERMISSION_PRIVILEGES_GERER') || define('PERMISSION_PRIVILEGES_GERER', 'privileges.gerer');
defined('PERMISSION_ASSISTANCE_GERER') || define('PERMISSION_ASSISTANCE_GERER', 'assistance.gerer');
defined('PERMISSION_RECLAMATIONS_GERER') || define('PERMISSION_RECLAMATIONS_GERER', 'reclamations.gerer');

if (! function_exists('roles_auto_inscription')) {
    /**
     * Rôles ouverts à l'auto-inscription publique — Coordinateur, Technicien
     * et Administrateur en sont volontairement exclus (décision d'architecture).
     */
    function roles_auto_inscription(): array
    {
        return [ROLE_CLIENT, ROLE_FOURNISSEUR, ROLE_COMMERCIAL, ROLE_LIVREUR];
    }
}

if (! function_exists('roles_2fa_obligatoire')) {
    /**
     * Rôles à privilèges soumis à la double authentification par OTP.
     */
    function roles_2fa_obligatoire(): array
    {
        return [ROLE_COORDINATEUR, ROLE_ADMINISTRATEUR];
    }
}

if (! function_exists('roles_provisionnes_par_admin')) {
    /**
     * Rôles qu'un Administrateur peut créer manuellement depuis l'espace admin.
     * Coordinateur/Technicien n'ont aucune autre voie de création. Client est
     * normalement auto-inscrit depuis l'appli ; l'admin peut aussi en créer un
     * directement (ex. client accompagné par téléphone) — décision produit.
     */
    function roles_provisionnes_par_admin(): array
    {
        return [ROLE_COORDINATEUR, ROLE_TECHNICIEN_MAINTENANCE, ROLE_CLIENT];
    }
}
