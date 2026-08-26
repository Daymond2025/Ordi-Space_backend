<?php

use App\Http\Controllers\Api\Admin\AssistantIaController as AdminAssistantIaController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\CommandeController as AdminCommandeController;
use App\Http\Controllers\Api\Admin\FideliteController;
use App\Http\Controllers\Api\Admin\StatistiqueController;
use App\Http\Controllers\Api\Admin\UtilisateurController;
use App\Http\Controllers\Api\AssistantIaController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TelephoneAuthController;
use App\Http\Controllers\Api\CategorieController;
use App\Http\Controllers\Api\ClientRapideController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\Garantix\AbonnementController;
use App\Http\Controllers\Api\Garantix\ExclusionController;
use App\Http\Controllers\Api\Garantix\FormuleController;
use App\Http\Controllers\Api\GarantieController;
use App\Http\Controllers\Api\LivraisonController;
use App\Http\Controllers\Api\MoiController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\PanierController;
use App\Http\Controllers\Api\PrivilegeController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\QuestionFrequenteController;
use App\Http\Controllers\Api\ReclamationController;
use App\Http\Controllers\Api\RetourController;
use App\Http\Controllers\Api\Sav\DemandeSavController;
use App\Http\Controllers\Api\Sav\InterventionController;
use App\Http\Controllers\Api\Sav\RendezVousController;
use App\Http\Controllers\Api\TutorielController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API OrdiSpace — un seul namespace versionné, organisé par ressource
| métier (et non par application cliente) : Client, Fournisseur, Livreur,
| Coordinateur, Commercial et Admin consomment les mêmes endpoints, chacun
| recevant une vue adaptée à son rôle. Voir étude d'architecture technique.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,1');

        // Connexion Client par téléphone (WhatsApp + OTP) — voir
        // TelephoneAuthController. La dernière étape réutilise verify-otp
        // ci-dessus, générique à tout utilisateur.
        Route::prefix('telephone')->middleware('throttle:5,1')->group(function () {
            Route::post('otp', [TelephoneAuthController::class, 'demanderOtp']);
            Route::post('inscription', [TelephoneAuthController::class, 'inscrire']);
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Catalogue public — navigation libre, sans compte (décision produit :
    // "entrée libre", seules les actions comme commander exigent un compte).
    Route::get('categories', [CategorieController::class, 'index']);
    Route::get('produits', [ProduitController::class, 'index']);
    Route::get('produits/{produit}', [ProduitController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('moi')->group(function () {
            Route::get('profil', [MoiController::class, 'profil']);
            Route::patch('profil', [MoiController::class, 'modifierProfil']);
            Route::get('portefeuille', [MoiController::class, 'portefeuille']);
            Route::get('adresses', [MoiController::class, 'adresses']);
            Route::post('adresses', [MoiController::class, 'ajouterAdresse']);
            Route::delete('adresses/{adresse}', [MoiController::class, 'supprimerAdresse']);
            Route::get('achats', [MoiController::class, 'achats']);
            Route::get('achats/{ligne}', [MoiController::class, 'achat']);
            Route::get('notifications', [MoiController::class, 'notifications']);
            Route::patch('notifications/{notification}/lue', [MoiController::class, 'marquerNotificationLue']);
            Route::get('privileges-utilises', [MoiController::class, 'privilegesUtilises']);

            Route::get('panier', [PanierController::class, 'index']);
            Route::post('panier/lignes', [PanierController::class, 'ajouter']);
            Route::put('panier/lignes/{ligne}', [PanierController::class, 'modifier']);
            Route::delete('panier/lignes/{ligne}', [PanierController::class, 'supprimer']);
            Route::delete('panier', [PanierController::class, 'vider']);
        });

        Route::post('categories', [CategorieController::class, 'store']);

        // Catalogue PRODUIT : ordinateurs (fournisseur → coordinateur) ET
        // accessoires/logiciels (Admin, publiés directement) — même endpoint.
        // Lecture (index/show) publique, voir plus haut.
        Route::post('produits', [ProduitController::class, 'store'])->middleware('permission:'.PERMISSION_PRODUITS_CREER);
        Route::put('produits/{produit}', [ProduitController::class, 'update'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);
        Route::delete('produits/{produit}', [ProduitController::class, 'destroy'])->middleware('permission:'.PERMISSION_PRODUITS_SUPPRIMER);
        Route::post('produits/{produit}/valider', [ProduitController::class, 'valider'])->middleware('permission:'.PERMISSION_PRODUITS_VALIDER);
        Route::post('produits/{produit}/images', [ProduitController::class, 'ajouterImages'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);
        Route::delete('produits/{produit}/images/{image}', [ProduitController::class, 'supprimerImage'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);

        // Enregistrement d'une vente pour un client sans compte préalable
        // (canal Commercial/Agent IA) — donne un client_id utilisable
        // immédiatement par POST /commandes ci-dessous. Voir ClientRapideController.
        Route::post('clients/creation-rapide', [ClientRapideController::class, 'store'])
            ->middleware('permission:'.PERMISSION_CLIENTS_CREATION_RAPIDE);

        Route::get('commandes', [CommandeController::class, 'index'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('commandes', [CommandeController::class, 'store'])->middleware('permission:'.PERMISSION_COMMANDES_CREER);
        Route::get('commandes/{commande}', [CommandeController::class, 'show'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('commandes/{commande}/valider', [CommandeController::class, 'valider'])->middleware('permission:'.PERMISSION_COMMANDES_VALIDER);
        Route::post('commandes/{commande}/preparee', [CommandeController::class, 'marquerPreparee'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);
        // Pas de middleware permission ici : livreur (physique) et client
        // (commande 100% numérique) sont tous deux autorisés à encaisser,
        // le contrôleur distingue les deux cas lui-même.
        Route::post('commandes/{commande}/paiement', [PaiementController::class, 'encaisser']);
        Route::get('commandes/{commande}/paiement', [PaiementController::class, 'show'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);

        Route::post('lignes-commande/{ligneCommande}/retour', [RetourController::class, 'store']);
        Route::get('retours', [RetourController::class, 'index']);
        Route::post('retours/{retour}/traiter', [RetourController::class, 'traiter'])->middleware('permission:'.PERMISSION_RETOURS_TRAITER);

        Route::middleware('permission:'.PERMISSION_LIVRAISONS_GERER)->group(function () {
            Route::get('livraisons', [LivraisonController::class, 'index']);
            Route::post('livraisons/{livraison}/affecter', [LivraisonController::class, 'affecter']);
            Route::post('livraisons/{livraison}/livrer', [LivraisonController::class, 'livrer']);
        });
        Route::get('livraisons/{livraison}', [LivraisonController::class, 'show']);

        // Garantie de base (gratuite, incluse à l'achat) — consultation seule,
        // génération automatique à la livraison (cf. Garantie::genererPourCommande).
        Route::get('garanties', [GarantieController::class, 'index']);

        // GarantiX : abonnement annuel payant de maintenance étendue.
        Route::prefix('garantix')->group(function () {
            Route::get('formules', [FormuleController::class, 'index']);
            Route::get('exclusions', [ExclusionController::class, 'index']);
            Route::get('abonnements', [AbonnementController::class, 'index']);
            Route::post('abonnements', [AbonnementController::class, 'store'])->middleware('permission:'.PERMISSION_GARANTIX_SOUSCRIRE);

            Route::middleware('permission:'.PERMISSION_GARANTIX_GERER)->group(function () {
                Route::post('formules', [FormuleController::class, 'store']);
                Route::put('formules/{formule}', [FormuleController::class, 'update']);
                Route::delete('formules/{formule}', [FormuleController::class, 'destroy']);
                Route::post('formules/{formule}/prestations', [FormuleController::class, 'ajouterPrestation']);
                Route::delete('prestations/{prestation}', [FormuleController::class, 'supprimerPrestation']);
                Route::post('exclusions', [ExclusionController::class, 'store']);
                Route::delete('exclusions/{exclusion}', [ExclusionController::class, 'destroy']);
                Route::patch('abonnements/{abonnement}/confirmer-paiement', [AbonnementController::class, 'confirmerPaiement']);
                Route::patch('abonnements/{abonnement}/rejeter-paiement', [AbonnementController::class, 'rejeterPaiement']);
            });
        });

        // Privilège Space : catalogue de promotions/codes, visible par tous.
        Route::get('privileges', [PrivilegeController::class, 'index']);
        Route::middleware('permission:'.PERMISSION_PRIVILEGES_GERER)->group(function () {
            Route::post('privileges', [PrivilegeController::class, 'store']);
            Route::put('privileges/{privilege}', [PrivilegeController::class, 'update']);
            Route::delete('privileges/{privilege}', [PrivilegeController::class, 'destroy']);
        });

        // Tutoriels & astuces : lecture publique (contenu publié), gestion Admin.
        Route::get('tutoriels', [TutorielController::class, 'index']);
        Route::get('tutoriels/{tutoriel}', [TutorielController::class, 'show']);
        Route::post('tutoriels/{tutoriel}/vu', [TutorielController::class, 'marquerVu']);
        Route::middleware('permission:'.PERMISSION_TUTORIELS_GERER)->group(function () {
            Route::post('tutoriels', [TutorielController::class, 'store']);
            Route::put('tutoriels/{tutoriel}', [TutorielController::class, 'update']);
            Route::delete('tutoriels/{tutoriel}', [TutorielController::class, 'destroy']);
        });

        Route::prefix('sav')->group(function () {
            Route::get('demandes', [DemandeSavController::class, 'index']);
            Route::post('demandes', [DemandeSavController::class, 'store'])->middleware('permission:'.PERMISSION_SAV_CREER);
            Route::get('demandes/{demandeSav}', [DemandeSavController::class, 'show']);

            Route::middleware('permission:'.PERMISSION_SAV_TRAITER)->group(function () {
                Route::patch('demandes/{demandeSav}/statut', [DemandeSavController::class, 'changerStatut']);
                Route::get('rendez-vous', [RendezVousController::class, 'index']);
                Route::get('rendez-vous/{rendezVous}', [RendezVousController::class, 'show']);
                Route::post('demandes/{demandeSav}/rendez-vous', [RendezVousController::class, 'store']);
                Route::patch('rendez-vous/{rendezVous}', [RendezVousController::class, 'update']);
                Route::post('rendez-vous/{rendezVous}/intervention', [InterventionController::class, 'store']);
            });
        });

        // Réclamations : litige général (commande, livraison, facturation…),
        // distinct des déclarations de panne SAV ci-dessus.
        Route::prefix('reclamations')->group(function () {
            Route::get('/', [ReclamationController::class, 'index']);
            Route::post('/', [ReclamationController::class, 'store']);
            Route::get('{reclamation}', [ReclamationController::class, 'show']);
            Route::patch('{reclamation}/repondre', [ReclamationController::class, 'repondre'])
                ->middleware('permission:'.PERMISSION_RECLAMATIONS_GERER);
        });

        // Assistance : FAQ + audio (contenu publié par l'Administrateur).
        Route::prefix('assistance')->group(function () {
            Route::get('questions', [QuestionFrequenteController::class, 'index']);
            Route::get('questions/{questionFrequente}', [QuestionFrequenteController::class, 'show']);
            Route::middleware('permission:'.PERMISSION_ASSISTANCE_GERER)->group(function () {
                Route::post('questions', [QuestionFrequenteController::class, 'store']);
                Route::put('questions/{questionFrequente}', [QuestionFrequenteController::class, 'update']);
                Route::delete('questions/{questionFrequente}', [QuestionFrequenteController::class, 'destroy']);
            });
        });

        // Agent IA "Ellah" (page Aide rapide) — limité à 20 messages/minute
        // par client pour contenir le coût et éviter les abus.
        Route::prefix('assistant')->middleware('throttle:20,1')->group(function () {
            Route::get('messages', [AssistantIaController::class, 'index']);
            Route::post('messages', [AssistantIaController::class, 'store']);
        });

        Route::prefix('admin')->middleware('role:'.ROLE_ADMINISTRATEUR)->group(function () {
            Route::get('utilisateurs', [UtilisateurController::class, 'index']);
            Route::post('utilisateurs', [UtilisateurController::class, 'provisionner']);
            Route::patch('utilisateurs/{utilisateur}/statut', [UtilisateurController::class, 'changerStatut']);
            Route::get('statistiques', [StatistiqueController::class, 'globales']);
            Route::get('clients', [ClientController::class, 'index']);
            Route::get('clients/tableau-de-bord', [ClientController::class, 'tableauDeBord']);
            Route::get('clients/{utilisateur}', [ClientController::class, 'show']);
            Route::post('clients/{utilisateur}/notifier', [ClientController::class, 'notifier']);
            Route::get('commandes/{commande}', [AdminCommandeController::class, 'show']);
            Route::patch('commandes/{commande}/statut', [AdminCommandeController::class, 'changerStatut']);
            Route::get('fidelite', [FideliteController::class, 'index']);
            Route::get('assistant-ia/clients', [AdminAssistantIaController::class, 'index']);
            Route::get('assistant-ia/clients/{utilisateur}/messages', [AdminAssistantIaController::class, 'messages']);
        });
    });
});
