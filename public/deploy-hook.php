<?php

/**
 * Hook de post-déploiement OrdiSpace.
 *
 * Appelé en HTTP par le workflow GitHub Actions (.github/workflows/deploy.yml)
 * juste après l'upload FTP des fichiers, pour effectuer ce qu'un accès SSH
 * ferait normalement : migrations, seeding, mise en cache. C'est le seul
 * contournement possible sur un hébergement mutualisé sans shell.
 *
 * exec()/shell_exec() sont désactivés sur cet hébergement (hébergement
 * mutualisé Infomaniak) : les commandes artisan sont donc appelées
 * directement en PHP via le Console Kernel de Laravel, dans le même
 * processus — jamais par un appel shell. Conséquence : vendor/ doit être
 * livré par FTP (composer install tourne en CI, pas ici, cf. workflow).
 *
 * Sécurité :
 *  - Le jeton doit correspondre à DEPLOY_TOKEN, défini uniquement dans le
 *    .env du serveur (jamais commité, jamais régénéré par le déploiement).
 *  - Toute tentative avec un jeton invalide est journalisée puis rejetée
 *    avant même de charger l'application.
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
 * Exécute une commande artisan directement dans ce processus PHP (Console
 * Kernel de Laravel), sans jamais capturer/interrompre les étapes
 * suivantes — on veut un rapport complet même en cas d'échec partiel.
 */
function executerEtape(array &$etapes, $kernel, string $label, string $commande, array $parametres = []): bool
{
    try {
        $code = $kernel->call($commande, $parametres);
        $sortie = $kernel->output();
    } catch (\Throwable $e) {
        $code = 1;
        $sortie = get_class($e).': '.$e->getMessage();
    }

    $etapes[] = ['etape' => $label, 'code' => $code, 'sortie' => $sortie];

    return $code === 0;
}

if (! is_file($racine.'/vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => "vendor/autoload.php introuvable — vérifier que vendor/ est bien transféré par FTP (voir workflow).",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

require $racine.'/vendor/autoload.php';

try {
    $app = require $racine.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Échec du bootstrap Laravel : '.get_class($e).' — '.$e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// config:clear d'abord : garantit que migrate/seed lisent le .env réel du
// serveur, jamais un cache de config figé par un déploiement précédent.
executerEtape($etapes, $kernel, 'config:clear', 'config:clear');

executerEtape($etapes, $kernel, 'migrate', 'migrate', ['--force' => true]);

// Idempotent (firstOrCreate / findOrCreate partout) : provisionne
// l'administrateur racine depuis ADMIN_INITIAL_EMAIL/PASSWORD, les rôles
// et permissions, les canaux de vente et l'agent IA système — sans jamais
// dupliquer ni écraser des données déjà en base.
executerEtape($etapes, $kernel, 'db:seed', 'db:seed', ['--force' => true]);

executerEtape($etapes, $kernel, 'config:cache', 'config:cache');
executerEtape($etapes, $kernel, 'route:cache', 'route:cache');
executerEtape($etapes, $kernel, 'view:cache', 'view:cache');
executerEtape($etapes, $kernel, 'storage:link', 'storage:link');

$succes = array_reduce($etapes, fn ($ok, $e) => $ok && $e['code'] === 0, true);

http_response_code($succes ? 200 : 500);
echo json_encode(['success' => $succes, 'etapes' => $etapes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
