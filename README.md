# OrdiSpace — API backend

API REST (Laravel) commune à toutes les applications OrdiSpace : client, administration, coordinateur, fournisseur, commercial, livreur, technicien de maintenance (Maintenancier) et partenaire. Elle porte toute la logique métier : catalogue, commandes, livraisons, paiements, garanties & GarantiX, SAV, réclamations, portefeuilles et commissions, boutique d'affiliation, partenaires, notifications et assistant IA.

## Applications qui consomment cette API

| Application | Dépôt | Rôle(s) |
| --- | --- | --- |
| Client | [`Ordi-Space_App_Mobile`](https://github.com/Daymond2025/Ordi-Space_App_Mobile) | `client` |
| Administration | [`Ordi-Space_Admin_Web`](https://github.com/Daymond2025/Ordi-Space_Admin_Web) | `administrateur` |
| Coordinateur | [`ordispace-coordinateur-web`](https://github.com/Daymond2025/ordispace-coordinateur-web) | `coordinateur` |
| Fournisseur | [`ordi-space-fournisseur`](https://github.com/Daymond2025/ordi-space-fournisseur) | `fournisseur` |
| Commercial | [`Ordi-space-commercial`](https://github.com/Daymond2025/Ordi-space-commercial) | `commercial` |
| Livreur | [`ordi-space-livreur`](https://github.com/Daymond2025/ordi-space-livreur) | `livreur` |
| Maintenancier | [`Ordi-space-maintenancier`](https://github.com/Daymond2025/Ordi-space-maintenancier) | `technicien_maintenance` |
| Partenaire | [`Ordi-Space-partenaire`](https://github.com/Daymond2025/Ordi-Space-partenaire) | `partenaire` |
| Page de commande (acheteur, sans compte) | [`client-commande`](https://github.com/Daymond2025/client-commande) | public |

## Stack technique

- PHP 8.2+, [Laravel 12](https://laravel.com)
- MySQL (production et développement), SQLite en mémoire pour les tests
- Authentification par jeton Bearer — [Laravel Sanctum](https://laravel.com/docs/sanctum)
- Rôles et permissions — [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) (matrice dans `database/seeders/RolesAndPermissionsSeeder.php`, constantes dans `app/Helpers/role.php`)
- OTP WhatsApp — Twilio (`twilio/sdk`) ; notifications push — Web Push / VAPID (`minishlink/web-push`)
- Paiement Mobile Money — Wave (Checkout + webhook signé)
- PDF — `barryvdh/laravel-dompdf` ; assistant IA — API Anthropic

## Structure du projet

```text
app/
├── Helpers/              # Constantes (const.php, role.php) et fonctions globales (helper.php)
├── Http/
│   ├── Controllers/Api/  # Un dossier par espace : Admin/, Auth/, Coordinateur/, Commercial/,
│   │                     # Partenaire/, Sav/, Garantix/… + contrôleurs partagés
│   ├── Middleware/       # dont VerifieEspaceAdmin (espaces d'un admin restreint)
│   └── Requests/         # Validation des requêtes (FormRequest)
├── Models/               # Modèles Eloquent
└── Services/             # Logique métier transverse (Partenaire/, Wave/, Whatsapp/, …)
database/
├── migrations/           # Schéma (ordre chronologique)
└── seeders/              # Rôles/permissions, localités, compte admin initial…
routes/api.php            # Toutes les routes, préfixe /api/v1
tests/Feature/            # Tests d'intégration (PHPUnit)
public/deploy-hook.php    # Étapes post-déploiement (migrations, seeders, storage:link)
```

Les réponses suivent toujours la même enveloppe : `{ "success": true, "data": … }` en cas de succès, `{ "success": false, "error": { "code", "message", "fields" } }` en cas d'erreur (voir `bootstrap/app.php`).

## Démarrage en local

Prérequis : PHP 8.2+, Composer, MySQL (ex. XAMPP), Node.js (pour Vite, optionnel).

```bash
composer install
cp .env.example .env          # renseigner au minimum DB_* et CORS_ALLOWED_ORIGINS
php artisan key:generate
php artisan migrate --seed    # schéma + rôles, permissions, localités, admin initial
php artisan storage:link      # images produits et photos servies depuis /storage
php artisan serve             # http://127.0.0.1:8000 — API sur /api/v1
```

En local, si l'envoi WhatsApp n'est pas configuré, le code OTP est renvoyé dans la réponse de l'API (`code_debug`) — **jamais en production**.

### Variables d'environnement principales

| Variable(s) | Rôle |
| --- | --- |
| `APP_URL`, `APP_KEY`, `APP_ENV`, `APP_DEBUG` | Configuration Laravel (`APP_DEBUG=false` en production) |
| `DB_*` | Connexion MySQL |
| `CORS_ALLOWED_ORIGINS` | Origines autorisées, séparées par des virgules (une par application front, ex. `http://localhost:3006`) |
| `ADMIN_INITIAL_EMAIL`, `ADMIN_INITIAL_PASSWORD` | Compte administrateur créé par le seeder |
| `MAIL_*` | Envoi des e-mails (OTP de double authentification admin/coordinateur) |
| `TWILIO_*` | OTP par WhatsApp |
| `WAVE_*` | Paiement Mobile Money (Checkout + secret du webhook) |
| `VAPID_*` | Notifications push web |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` | Assistant IA |
| `LIVREUR_APP_URL`, `PAGE_COMMANDE_URL`, `PLATEFORME_*_URL` | URLs des applications (liens envoyés aux utilisateurs) |

Aucun secret ne doit être commité : le `.env` est ignoré par git.

## Tests

```bash
php artisan test                         # suite complète
php artisan test --filter=PartenaireTest # un fichier
```

Les tests utilisent une base SQLite en mémoire : ils ne touchent jamais à la base locale ni à la production.

## Déploiement

Hébergement mutualisé (`ordisapce.daymondboutique.com`), sans accès SSH. Le workflow `.github/workflows/deploy.yml` s'exécute à chaque push sur `master` :

1. **Tests** — la suite complète doit passer ;
2. **Déploiement FTP** — envoi des fichiers ;
3. **Hook post-déploiement** — `public/deploy-hook.php` (protégé par `DEPLOY_TOKEN`) lance `migrate --force`, `db:seed --force` et `storage:link`.

⚠️ Un push sur `master` déploie donc directement en production et applique les migrations. Le workflow peut aussi être relancé à la main depuis l'onglet *Actions* (`workflow_dispatch`).
