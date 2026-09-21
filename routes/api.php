<?php
use App\Http\Controllers\Api\Admin\AssistantIaController as AdminAssistantIaController;
use App\Http\Controllers\Api\Admin\ClientController;
use App\Http\Controllers\Api\Admin\CommandeController as AdminCommandeController;
use App\Http\Controllers\Api\Admin\CoordinateurController as AdminCoordinateurController;
use App\Http\Controllers\Api\Admin\FideliteController;
use App\Http\Controllers\Api\Admin\LivreurController as AdminLivreurController;
use App\Http\Controllers\Api\Admin\StatistiqueController;
use App\Http\Controllers\Api\Admin\UtilisateurController;
use App\Http\Controllers\Api\AssistantIaController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BoutiqueController;
use App\Http\Controllers\Api\BoutiquePubliqueController;
use App\Http\Controllers\Api\Admin\BoutiqueController as AdminBoutiqueController;
use App\Http\Controllers\Api\Admin\RetraitController as AdminRetraitController;
use App\Http\Controllers\Api\ParametreController;
use App\Http\Controllers\Api\PortefeuilleController;
use App\Http\Controllers\Api\Auth\TelephoneAuthController;
use App\Http\Controllers\Api\CategorieController;
use App\Http\Controllers\Api\ClientRapideController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\CommercialController;
use App\Http\Controllers\Api\LivreurController;
use App\Http\Controllers\Api\Coordinateur\EspaceController as CoordinateurEspaceController;
use App\Http\Controllers\Api\Coordinateur\PortefeuilleController as CoordinateurPortefeuilleController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\Garantix\AbonnementController;
use App\Http\Controllers\Api\Garantix\ExclusionController;
use App\Http\Controllers\Api\Garantix\FormuleController;
use App\Http\Controllers\Api\GarantieController;
use App\Http\Controllers\Api\LivraisonController;
use App\Http\Controllers\Api\LocaliteController;
use App\Http\Controllers\Api\MessageController;
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
use App\Http\Controllers\Api\WaveWebhookController;
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
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware(['throttle:5,1', 'throttle:otp-verify']);

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

    // Webhook Wave (encaissement Mobile Money à la livraison) — public par
    // nature (appelé par Wave, pas par un utilisateur connecté), sécurisé par
    // la signature "Wave-Signature" plutôt que par auth:sanctum. Voir
    // WaveWebhookController et App\Services\Wave\WaveCheckoutService.
    Route::post('webhooks/wave', [WaveWebhookController::class, 'handle']);

    // Catalogue public — navigation libre, sans compte (décision produit :
    // "entrée libre", seules les actions comme commander exigent un compte).
    Route::get('categories', [CategorieController::class, 'index']);
    Route::get('categories/filtres', [CategorieController::class, 'filtres']);
    Route::get('produits', [ProduitController::class, 'index']);
    // Doit être déclarée avant produits/{produit} ci-dessous : sinon la
    // liaison de modèle de route absorbe "activite-recente" comme un ID.
    Route::get('produits/activite-recente', [MessageController::class, 'produitsActifs'])
        ->middleware(['auth:sanctum', 'permission:'.PERMISSION_MESSAGES_PRODUIT_GERER]);
    // Même précaution d'ordre que activite-recente ci-dessus.
    Route::get('produits/statistiques', [ProduitController::class, 'statistiques'])
        ->middleware(['auth:sanctum', 'permission:'.PERMISSION_PRODUITS_CONSULTER]);
    Route::get('produits/{produit}', [ProduitController::class, 'show']);
    // Référentiel des localités (communes d'Abidjan + villes de CI) — lu par
    // fournisseur/admin (barème produit) et client/commercial (adresse).
    Route::get('localites', [LocaliteController::class, 'index']);

    // Page d'arrivée de l'acheteur (dossier page_commande) — sans compte.
    // Les compteurs de visite et la commande sont limités par IP.
    Route::prefix('public')->group(function () {
        Route::get('liens/{code}', [BoutiquePubliqueController::class, 'lien']);
        Route::get('vitrines/{code}', [BoutiquePubliqueController::class, 'vitrine']);
        Route::get('vitrines/{code}/produits/{produit}', [BoutiquePubliqueController::class, 'produitVitrine']);
        Route::post('liens/{code}/vue', [BoutiquePubliqueController::class, 'vueLien'])->middleware('throttle:30,1');
        Route::post('vitrines/{code}/vue', [BoutiquePubliqueController::class, 'vueVitrine'])->middleware('throttle:30,1');
        Route::post('commandes', [BoutiquePubliqueController::class, 'commander'])->middleware('throttle:10,1');
    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('moi')->group(function () {
            Route::get('profil', [MoiController::class, 'profil']);
            Route::patch('profil', [MoiController::class, 'modifierProfil']);
            Route::post('profil/photo', [MoiController::class, 'modifierPhoto']);
            Route::get('portefeuille', [MoiController::class, 'portefeuille']);
            Route::get('adresses', [MoiController::class, 'adresses']);
            Route::post('adresses', [MoiController::class, 'ajouterAdresse']);
            Route::delete('adresses/{adresse}', [MoiController::class, 'supprimerAdresse']);
            Route::get('achats', [MoiController::class, 'achats']);
            Route::get('achats/{ligne}', [MoiController::class, 'achat']);
            Route::get('activites', [MoiController::class, 'activites']);
            Route::get('notifications', [MoiController::class, 'notifications']);
            Route::patch('notifications/{notification}/lue', [MoiController::class, 'marquerNotificationLue']);
            Route::get('privileges-utilises', [MoiController::class, 'privilegesUtilises']);
            Route::get('paiements', [MoiController::class, 'paiements']);
            Route::post('paiements/reverser', [MoiController::class, 'reverserPaiements']);
            Route::get('recapitulatif-jour', [MoiController::class, 'recapitulatifJour']);
            Route::patch('disponibilite', [MoiController::class, 'basculerDisponibilite']);
            Route::patch('vehicule', [MoiController::class, 'modifierVehicule']);

            Route::get('panier', [PanierController::class, 'index']);
            Route::post('panier/lignes', [PanierController::class, 'ajouter']);
            Route::put('panier/lignes/{ligne}', [PanierController::class, 'modifier']);
            Route::delete('panier/lignes/{ligne}', [PanierController::class, 'supprimer']);
            Route::delete('panier', [PanierController::class, 'vider']);
        });

        // "Boutique" — le Livreur revend des produits publiés par
        // Fournisseur/Coordinateur/Admin (commission par vente, lien
        // affilié partageable). Voir BoutiqueController.
        // Support Ordi'Space (numéro fixé par l'Admin) — lisible par tout compte connecté.
        Route::get('support', [ParametreController::class, 'support']);

        Route::prefix('boutique')->group(function () {
            Route::post('produits/{produit}/lien', [BoutiqueController::class, 'genererLien']);
            Route::get('produits/{produit}/fournisseur', [BoutiqueController::class, 'fournisseurProduit']);
            Route::get('fournisseurs', [BoutiqueController::class, 'fournisseurs']);
            Route::post('commandes', [BoutiqueController::class, 'commander'])->middleware('throttle:20,1');
            Route::get('ventes', [BoutiqueController::class, 'ventes']);
            Route::get('profil', [BoutiqueController::class, 'profil']);
            Route::get('portefeuille', [PortefeuilleController::class, 'resume']);
            Route::get('portefeuille/commissions', [PortefeuilleController::class, 'commissions']);
            Route::get('retraits', [PortefeuilleController::class, 'retraits']);
            Route::post('retraits', [PortefeuilleController::class, 'demander']);
            Route::put('retraits/{retrait}/annuler', [PortefeuilleController::class, 'annuler']);
            Route::get('liens', [BoutiqueController::class, 'liens']);
            Route::get('liens/{lien}', [BoutiqueController::class, 'lien']);
        });

        Route::post('categories', [CategorieController::class, 'store']);
        Route::put('categories/{categorie}', [CategorieController::class, 'update']);

        // Catalogue PRODUIT : ordinateurs (fournisseur → coordinateur) ET
        // accessoires/logiciels (Admin, publiés directement) — même endpoint.
        // Lecture (index/show) publique, voir plus haut.
        Route::post('produits', [ProduitController::class, 'store'])->middleware('permission:'.PERMISSION_PRODUITS_CREER);
        Route::put('produits/{produit}', [ProduitController::class, 'update'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);
        Route::delete('produits/{produit}', [ProduitController::class, 'destroy'])->middleware('permission:'.PERMISSION_PRODUITS_SUPPRIMER);
        Route::post('produits/{produit}/valider', [ProduitController::class, 'valider'])->middleware('permission:'.PERMISSION_PRODUITS_VALIDER);
        Route::post('produits/{produit}/publier', [ProduitController::class, 'publier'])->middleware('permission:'.PERMISSION_PRODUITS_VALIDER);
        Route::patch('produits/{produit}/prix', [ProduitController::class, 'modifierPrix'])->middleware('permission:'.PERMISSION_PRODUITS_VALIDER);
        Route::patch('produits/{produit}/fiche', [ProduitController::class, 'modifierFiche'])->middleware('permission:'.PERMISSION_PRODUITS_VALIDER);
        Route::post('produits/{produit}/images', [ProduitController::class, 'ajouterImages'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER.'|'.PERMISSION_PRODUITS_VALIDER);
        Route::delete('produits/{produit}/images/{image}', [ProduitController::class, 'supprimerImage'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER.'|'.PERMISSION_PRODUITS_VALIDER);
        Route::patch('produits/{produit}/booster', [ProduitController::class, 'basculerBoost'])->middleware('permission:'.PERMISSION_PRODUITS_BOOSTER);
        Route::patch('produits/{produit}/stock', [ProduitController::class, 'modifierStock'])->middleware('permission:'.PERMISSION_PRODUITS_GERER_STOCK);
        Route::get('produits/{produit}/frais-livraison', [ProduitController::class, 'previsualiserFraisLivraison'])->middleware('permission:'.PERMISSION_COMMANDES_CREER);

        // Discussion produit façon WhatsApp — Espace Coordinateur (Phase 2).
        Route::middleware('permission:'.PERMISSION_MESSAGES_PRODUIT_GERER)->group(function () {
            Route::get('produits/{produit}/messages', [MessageController::class, 'indexProduit']);
            Route::post('produits/{produit}/messages', [MessageController::class, 'storeProduit']);
            Route::get('produits/{produit}/conversation', [MessageController::class, 'conversationProduit']);
            Route::post('produits/{produit}/negociation-prix', [MessageController::class, 'demarrerNegociationPrix']);
            Route::get('produits/{produit}/negociation-prix', [MessageController::class, 'negociationPrix']);
            Route::post('produits/{produit}/negociation-prix/messages', [MessageController::class, 'repondreNegociationPrix']);
        });

        // Fournisseurs — Espace Coordinateur (Centre des opérations).
        Route::prefix('fournisseurs')->group(function () {
            Route::middleware('permission:'.PERMISSION_FOURNISSEURS_CONSULTER)->group(function () {
                Route::get('/', [FournisseurController::class, 'index']);
                Route::get('{fournisseur}', [FournisseurController::class, 'show']);
                Route::get('{fournisseur}/produits', [FournisseurController::class, 'produits']);
                Route::get('{fournisseur}/commandes', [FournisseurController::class, 'commandes']);
                Route::get('{fournisseur}/portefeuille', [FournisseurController::class, 'portefeuille']);
                Route::get('{fournisseur}/statistiques', [FournisseurController::class, 'statistiques']);
            });
            Route::post('{fournisseur}/portefeuille/paiement', [FournisseurController::class, 'enregistrerPaiement'])
                ->middleware('permission:'.PERMISSION_FOURNISSEURS_PORTEFEUILLE_GERER);
            Route::post('{fournisseur}/portefeuille/payer-tout', [FournisseurController::class, 'payerTout'])
                ->middleware('permission:'.PERMISSION_FOURNISSEURS_PORTEFEUILLE_GERER);
            Route::patch('{fournisseur}/commission', [FournisseurController::class, 'modifierCommission'])
                ->middleware('permission:'.PERMISSION_FOURNISSEURS_COMMISSION_GERER);
        });

        // Enregistrement d'une vente pour un client sans compte préalable
        // (canal Commercial/Agent IA) — donne un client_id utilisable
        // immédiatement par POST /commandes ci-dessous. Voir ClientRapideController.
        Route::post('clients/creation-rapide', [ClientRapideController::class, 'store'])
            ->middleware('permission:'.PERMISSION_CLIENTS_CREATION_RAPIDE);
        // Paste-parse (Ajout d'une commande depuis une discussion produit) —
        // extrait nom+téléphone d'un texte collé, voir ExtractionClientService.
        Route::post('clients/extraction', [ClientRapideController::class, 'extraire'])
            ->middleware('permission:'.PERMISSION_CLIENTS_CREATION_RAPIDE);
        // Adresse créée pour le client au nom duquel le Commercial/Coordinateur
        // saisit une commande — distinct du self-service MoiController::ajouterAdresse().
        Route::post('clients/{client}/adresses', [ClientRapideController::class, 'creerAdresse'])
            ->middleware('permission:'.PERMISSION_CLIENTS_CREATION_RAPIDE);

        Route::get('commandes', [CommandeController::class, 'index'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('commandes', [CommandeController::class, 'store'])->middleware('permission:'.PERMISSION_COMMANDES_CREER);
        Route::get('commandes/{commande}', [CommandeController::class, 'show'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('commandes/{commande}/valider', [CommandeController::class, 'valider'])->middleware('permission:'.PERMISSION_COMMANDES_VALIDER);
        Route::post('commandes/{commande}/traiter-probleme', [CommandeController::class, 'traiterProbleme'])->middleware('permission:'.PERMISSION_COMMANDES_TRAITER);
        Route::post('commandes/{commande}/preparee', [CommandeController::class, 'marquerPreparee'])->middleware('permission:'.PERMISSION_PRODUITS_MODIFIER);
        Route::get('commandes/{commande}/suivi', [CommandeController::class, 'suivi'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('commandes/{commande}/assigner-livreur', [CommandeController::class, 'assignerLivreur'])->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
        Route::post('commandes/{commande}/statut', [CommandeController::class, 'changerStatut'])->middleware('permission:'.PERMISSION_COMMANDES_CHANGER_STATUT);
        Route::get('livreurs', [LivreurController::class, 'index'])->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
        Route::middleware('permission:'.PERMISSION_MESSAGES_COMMANDE_GERER)->group(function () {
            Route::get('commandes/{commande}/messages', [MessageController::class, 'indexCommande']);
            Route::post('commandes/{commande}/messages', [MessageController::class, 'storeCommande']);
        });
        // Pas de middleware permission ici : livreur (physique) et client
        // (commande 100% numérique) sont tous deux autorisés à encaisser,
        // le contrôleur distingue les deux cas lui-même.
        Route::post('commandes/{commande}/paiement', [PaiementController::class, 'encaisser']);
        Route::post('commandes/{commande}/paiement/wave', [PaiementController::class, 'initierPaiementWave']);
        Route::get('commandes/{commande}/paiement', [PaiementController::class, 'show'])->middleware('permission:'.PERMISSION_COMMANDES_CONSULTER);
        Route::post('paiements/{paiement}/deposer', [PaiementController::class, 'deposer']);
        Route::post('paiements/{paiement}/confirmer-manuellement', [PaiementController::class, 'confirmerManuellement']);

        Route::post('lignes-commande/{ligneCommande}/retour', [RetourController::class, 'store']);
        Route::get('retours', [RetourController::class, 'index']);
        Route::post('retours/{retour}/traiter', [RetourController::class, 'traiter'])->middleware('permission:'.PERMISSION_RETOURS_TRAITER);

        Route::middleware('permission:'.PERMISSION_LIVRAISONS_GERER)->group(function () {
            Route::get('livraisons', [LivraisonController::class, 'index']);
            Route::post('livraisons/{livraison}/affecter', [LivraisonController::class, 'affecter']);
            Route::post('livraisons/{livraison}/accepter', [LivraisonController::class, 'accepter']);
            Route::post('livraisons/{livraison}/recuperer', [LivraisonController::class, 'recuperer']);
            Route::post('livraisons/{livraison}/demarrer-livraison', [LivraisonController::class, 'demarrerLivraison']);
            Route::post('livraisons/{livraison}/arriver', [LivraisonController::class, 'arriver']);
            Route::post('livraisons/{livraison}/livrer', [LivraisonController::class, 'livrer']);
            Route::post('livraisons/{livraison}/annuler', [LivraisonController::class, 'annuler']);
            Route::post('livraisons/{livraison}/retourner', [LivraisonController::class, 'retourner']);
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
            Route::post('{reclamation}/preuves', [ReclamationController::class, 'ajouterPreuve']);
            Route::delete('{reclamation}/preuves/{preuve}', [ReclamationController::class, 'supprimerPreuve']);
        });

        // Espace Coordinateur — préfixe étendu par les phases suivantes
        // (chat produit/commande, ledger fournisseur, rapport quotidien).
        // L'Administrateur (superviseur global) doit pouvoir accéder à ce
        // groupe comme un coordinateur — il a déjà toutes les permissions
        // (RolesAndPermissionsSeeder), seul le rôle Spatie manquait ici.
        Route::prefix('coordinateur')->middleware('role:'.ROLE_COORDINATEUR.'|'.ROLE_ADMINISTRATEUR)->group(function () {
            Route::get('espace/statistiques', [CoordinateurEspaceController::class, 'statistiques'])
                ->middleware('permission:'.PERMISSION_STATISTIQUES_PERIMETRE);
            Route::middleware('permission:'.PERMISSION_FOURNISSEURS_CONSULTER)->group(function () {
                Route::get('portefeuille', [CoordinateurPortefeuilleController::class, 'index']);
                Route::get('portefeuille/transactions/{transaction}', [CoordinateurPortefeuilleController::class, 'show']);
                Route::get('portefeuille/transactions/{transaction}/recu', [CoordinateurPortefeuilleController::class, 'recu']);
            });
            Route::get('livreurs', [LivreurController::class, 'liste'])
                ->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
            Route::get('livraisons-disponibles', [LivreurController::class, 'livraisonsDisponibles'])
                ->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
            Route::get('livreurs/{livreur}/missions', [LivreurController::class, 'missions'])
                ->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
            Route::get('livreurs/{livreur}', [LivreurController::class, 'show'])
                ->middleware('permission:'.PERMISSION_LIVRAISONS_ASSIGNER);
            Route::get('commerciaux', [CommercialController::class, 'liste'])
                ->middleware('permission:'.PERMISSION_COMMERCIAUX_CONSULTER);
            Route::get('commerciaux/{commercial}', [CommercialController::class, 'show'])
                ->middleware('permission:'.PERMISSION_COMMERCIAUX_CONSULTER);
            Route::patch('commerciaux/{commercial}/statut', [CommercialController::class, 'changerStatut'])
                ->middleware('permission:'.PERMISSION_COMMERCIAUX_GERER);
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
            Route::get('commandes', [AdminCommandeController::class, 'index']);
            Route::get('commandes/{commande}', [AdminCommandeController::class, 'show']);
            Route::patch('commandes/{commande}/statut', [AdminCommandeController::class, 'changerStatut']);
            Route::get('fidelite', [FideliteController::class, 'index']);
            Route::get('livreurs/tableau-de-bord', [AdminLivreurController::class, 'tableauDeBord']);
            Route::put('parametres/support', [ParametreController::class, 'modifierSupport']);
            Route::get('boutique/commandes', [AdminBoutiqueController::class, 'commandes']);
            Route::get('retraits', [AdminRetraitController::class, 'index']);
            Route::post('retraits/{retrait}/valider', [AdminRetraitController::class, 'valider']);
            Route::post('retraits/{retrait}/refuser', [AdminRetraitController::class, 'refuser']);
            Route::get('assistant-ia/clients', [AdminAssistantIaController::class, 'index']);
            Route::get('assistant-ia/clients/{utilisateur}/messages', [AdminAssistantIaController::class, 'messages']);
            Route::get('coordinateurs/{coordinateur}/activites', [AdminCoordinateurController::class, 'activites']);
        });
    });
});
