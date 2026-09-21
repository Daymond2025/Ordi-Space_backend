<?php

/**
 * Adresses des plateformes Ordi'Space affichées sur la page d'accueil
 * publique (LandingController). Une plateforme SANS adresse s'affiche « Bientôt
 * disponible » : renseigner sa variable d'environnement suffit à l'activer, sans
 * toucher au code ni à la page.
 */
return [
    'client' => env('PLATEFORME_CLIENT_URL', 'https://ordispace.client.daymondboutique.com'),
    'livreur' => env('PLATEFORME_LIVREUR_URL'),
    'fournisseur' => env('PLATEFORME_FOURNISSEUR_URL'),
    'commercial' => env('PLATEFORME_COMMERCIAL_URL'),
    'coordinateur' => env('PLATEFORME_COORDINATEUR_URL'),
    'admin' => env('PLATEFORME_ADMIN_URL', 'https://ordispace.admin.daymondboutique.com'),
];
