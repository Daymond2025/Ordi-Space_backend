<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Liste blanche explicite — jamais de wildcard (ligne de base sécurité).
    // Les 3 apps Next.js (coordinateur, commercial, admin) sont concernées ;
    // les apps Flutter appellent l'API hors navigateur et ne sont pas soumises à CORS.
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:3001,http://localhost:3002,http://localhost:3010'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // "Content-Disposition" n'est pas exposé par défaut en cross-origin :
    // sans ça, le JS du front (fetch + blob, télécharger une image produit —
    // voir ProduitController::telechargerImage()) ne peut pas lire le nom de
    // fichier suggéré par le serveur et retombe sur un nom généré par le
    // navigateur.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
