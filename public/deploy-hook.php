<?php

/**
 * Hook de post-déploiement OrdiSpace.
 *
 * Appelé en HTTP par le workflow GitHub Actions (.github/workflows/deploy.yml)
 * juste après l'upload FTP des fichiers, pour effectuer ce qu'un accès SSH
 * ferait normalement : composer install, migrations, mise en cache. C'est le
 * seul contournement possible sur un hébergement mutualisé sans shell.
 *
 * Sécurité :
 *  - Le jeton doit correspondre à DEPLOY_TOKEN, défini uniquement dans le
 *    .env du serveur (jamais commité, jamais régénéré par le déploiement).
 *  - Toute tentative avec un jeton invalide est journalisée puis rejetée
 *    avant même de charger l'application.
 *  - vendor/ n'étant pas envoyé par FTP (cf. workflow), le composer install
 *    d'ici est ce qui installe réellement les dépendances sur le serveur.
 */

header('Content-Type: application/json');

$racine = dirname(__DIR__);
$tokenRecu = $_GET['token'] ?? '';
$tokenAttendu = null;

// Lecture directe du .env, sans bootstrapper Laravel : on doit pouvoir
// rejeter une requête invalide avant de charger tout le framework.
if (is_file($racine.'/.env')) {
    foreach (file($racine.'/.env', FILE_IGNORE_NEW_LINES) as $ligne) {
        if (str_starts_with($ligne, 'DEPLOY_TOKEN=')) {
            $tokenAttendu = trim(substr($ligne, strlen('DEPLOY_TOKEN=')), " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

if (! $tokenAttendu || ! hash_equals($tokenAttendu, (string) $tokenRecu)) {
    error_log(sprintf(
        '[deploy-hook] Tentative refusée depuis %s à %s',
        $_SERVER['REMOTE_ADDR'] ?? 'IP inconnue',
        date('c')
    ));
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Jeton de déploiement invalide.']);
    exit;
}

set_time_limit(300);
$etapes = [];

/**
 * Exécute une commande shell et capture sa sortie sans jamais interrompre
 * les étapes suivantes — on veut un rapport complet même en cas d'échec
 * partiel (ex. artisan indisponible mais composer install réussi).
 */
function executerEtape(array &$etapes, string $label, string $commande): bool
{
    exec($commande.' 2>&1', $sortie, $code);

    $etapes[] = [
        'etape' => $label,
        'code' => $code,
        'sortie' => implode("\n", $sortie),
    ];

    return $code === 0;
}

$php = PHP_BINARY;
$artisan = escapeshellarg($racine.'/artisan');
$cd = 'cd '.escapeshellarg($racine).' && ';

// 1. Dépendances — vendor/ n'arrive jamais par FTP, c'est ici qu'il se
//    construit réellement sur le serveur.
$composerOk = executerEtape($etapes, 'composer install', $cd.'composer install --no-dev --optimize-autoloader --no-interaction');

// 2. Reste des étapes seulement si composer a réussi : artisan a besoin de vendor/.
if ($composerOk) {
    executerEtape($etapes, 'migrate', "{$cd}{$php} {$artisan} migrate --force");
    // Idempotent (firstOrCreate / findOrCreate partout) : provisionne
    // l'administrateur racine depuis ADMIN_INITIAL_EMAIL/PASSWORD, les rôles
    // et permissions, les canaux de vente et l'agent IA système — sans
    // jamais dupliquer ni écraser des données déjà en base.
    executerEtape($etapes, 'db:seed', "{$cd}{$php} {$artisan} db:seed --force");
    executerEtape($etapes, 'config:cache', "{$cd}{$php} {$artisan} config:cache");
    executerEtape($etapes, 'route:cache', "{$cd}{$php} {$artisan} route:cache");
    executerEtape($etapes, 'view:cache', "{$cd}{$php} {$artisan} view:cache");
    executerEtape($etapes, 'storage:link', "{$cd}{$php} {$artisan} storage:link");
}

$succes = array_reduce($etapes, fn ($ok, $e) => $ok && $e['code'] === 0, true);

http_response_code($succes ? 200 : 500);
echo json_encode(['success' => $succes, 'etapes' => $etapes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
