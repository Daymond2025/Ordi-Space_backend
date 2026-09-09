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
defined('PERMISSION_CLIENTS_CREATION_RAPIDE') || define('PERMISSION_CLIENTS_CREATION_RAPIDE', 'clients.creation_rapide');
// --- Espace Coordinateur (Phase 1 : fondations) --------------------------
defined('PERMISSION_COMMANDES_TRAITER') || define('PERMISSION_COMMANDES_TRAITER', 'commandes.traiter');
defined('PERMISSION_FOURNISSEURS_CONSULTER') || define('PERMISSION_FOURNISSEURS_CONSULTER', 'fournisseurs.consulter');
defined('PERMISSION_PRODUITS_BOOSTER') || define('PERMISSION_PRODUITS_BOOSTER', 'produits.booster');
// --- Espace Coordinateur (Phase 2 : chat produit/commande) ---------------
defined('PERMISSION_MESSAGES_PRODUIT_GERER') || define('PERMISSION_MESSAGES_PRODUIT_GERER', 'messages.produit.gerer');
defined('PERMISSION_MESSAGES_COMMANDE_GERER') || define('PERMISSION_MESSAGES_COMMANDE_GERER', 'messages.commande.gerer');
defined('PERMISSION_LIVRAISONS_ASSIGNER') || define('PERMISSION_LIVRAISONS_ASSIGNER', 'livraisons.assigner');
// --- Espace Coordinateur (comblement cahier des charges) -----------------
defined('PERMISSION_FOURNISSEURS_PORTEFEUILLE_GERER') || define('PERMISSION_FOURNISSEURS_PORTEFEUILLE_GERER', 'fournisseurs.portefeuille.gerer');
// Réservée à l'Administrateur : jamais accordée explicitement à un rôle,
// il la reçoit de fait via RolesAndPermissionsSeeder (liste complète).
defined('PERMISSION_FOURNISSEURS_COMMISSION_GERER') || define('PERMISSION_FOURNISSEURS_COMMISSION_GERER', 'fournisseurs.commission.gerer');
defined('PERMISSION_PRODUITS_GERER_STOCK') || define('PERMISSION_PRODUITS_GERER_STOCK', 'produits.stock.gerer');
// Écran détail commande (Espace Coordinateur) : transition libre de statut,
// sans passer par la machine à états stricte de commandes.traiter.
defined('PERMISSION_COMMANDES_CHANGER_STATUT') || define('PERMISSION_COMMANDES_CHANGER_STATUT', 'commandes.changer_statut');
// Espace Agent (liste des commerciaux, bottombar).
defined('PERMISSION_COMMERCIAUX_CONSULTER') || define('PERMISSION_COMMERCIAUX_CONSULTER', 'commerciaux.consulter');
// Activer/suspendre un compte commercial (écran profil commercial).
defined('PERMISSION_COMMERCIAUX_GERER') || define('PERMISSION_COMMERCIAUX_GERER', 'commerciaux.gerer');

if (! function_exists('roles_auto_inscription')) {
    /**
     * Rôles ouverts à l'auto-inscription publique par e-mail/mot de passe.
     * Client en est exclu depuis le passage à la connexion par téléphone
     * (WhatsApp + OTP) — voir Api\Auth\TelephoneAuthController. Coordinateur,
     * Technicien et Administrateur restent exclus (décision d'architecture).
     */
    function roles_auto_inscription(): array
    {
        return [ROLE_FOURNISSEUR, ROLE_COMMERCIAL, ROLE_LIVREUR];
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
