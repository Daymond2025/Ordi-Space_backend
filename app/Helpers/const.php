<?php

/**
 * Constantes techniques globales d'OrdiSpace.
 *
 * Valeurs identiques à celles déjà utilisées dans les migrations (colonnes
 * enum) et les seeders — on nomme ici ce qui existait déjà en chaîne de
 * caractères libre, sans changer aucune valeur stockée en base.
 *
 * Chargé automatiquement via composer.json > autoload > files.
 */

// --- Pagination ---------------------------------------------------------
defined('PAGINATION_PAR_DEFAUT') || define('PAGINATION_PAR_DEFAUT', 20);
defined('PAGINATION_MAX') || define('PAGINATION_MAX', 100);

// --- Authentification / 2FA ---------------------------------------------
defined('OTP_LONGUEUR') || define('OTP_LONGUEUR', 6);
defined('OTP_EXPIRATION_MINUTES') || define('OTP_EXPIRATION_MINUTES', 5);
// Au-delà de ce nombre d'échecs, le code est invalidé (il faut en redemander
// un) — limite le brute-force même si l'attaquant répartit ses tentatives
// sur plusieurs IP pour contourner le throttle réseau.
defined('OTP_TENTATIVES_MAX') || define('OTP_TENTATIVES_MAX', 5);

// --- Téléphone (connexion Client par WhatsApp + OTP) ---------------------
// Indicatif appliqué aux numéros locaux (ex. "07 79 36 38 09") saisis sans
// indicatif — l'activité d'OrdiSpace est basée en Côte d'Ivoire.
defined('TELEPHONE_INDICATIF_PAYS_DEFAUT') || define('TELEPHONE_INDICATIF_PAYS_DEFAUT', '+225');

// --- Agent commercial IA système ------------------------------------------
// Compte "commercial" de service utilisé pour enregistrer les commandes
// passées en self-service par un client depuis l'appli (aucun commercial
// humain n'intervient) — voir CommandeController::store().
defined('AGENT_IA_EMAIL') || define('AGENT_IA_EMAIL', 'agent-ia@ordispace.local');
defined('AGENT_IA_NOM_MODELE') || define('AGENT_IA_NOM_MODELE', 'OrdiSpace Assistant');
defined('CANAL_VENTE_BOUTIQUE_APPLICATION') || define('CANAL_VENTE_BOUTIQUE_APPLICATION', 'Boutique application');

// --- Upload d'images produit (stockage local, disque "public") ----------
defined('IMAGE_PRODUIT_MAX_PAR_ENVOI') || define('IMAGE_PRODUIT_MAX_PAR_ENVOI', 8);
defined('IMAGE_PRODUIT_MAX_TOTAL') || define('IMAGE_PRODUIT_MAX_TOTAL', 8);
defined('IMAGE_MIMES_AUTORISES') || define('IMAGE_MIMES_AUTORISES', 'jpg,jpeg,png,webp');
defined('IMAGE_MAX_POIDS_KO') || define('IMAGE_MAX_POIDS_KO', 5120); // 5 Mo, en Ko (unité attendue par la règle "max" de Laravel)
defined('IMAGE_PRODUIT_DISQUE') || define('IMAGE_PRODUIT_DISQUE', 'public');
defined('IMAGE_PRODUIT_DOSSIER') || define('IMAGE_PRODUIT_DOSSIER', 'produits');

// --- Statuts PRODUIT (cf. migration create_produits_table) ---------------
defined('STATUT_PRODUIT_EN_ATTENTE') || define('STATUT_PRODUIT_EN_ATTENTE', 'en_attente');
defined('STATUT_PRODUIT_VALIDE') || define('STATUT_PRODUIT_VALIDE', 'valide');
defined('STATUT_PRODUIT_REJETE') || define('STATUT_PRODUIT_REJETE', 'rejete');
defined('STATUT_PRODUIT_CORRIGE') || define('STATUT_PRODUIT_CORRIGE', 'corrige');

// --- Décision de validation (cf. migration validations_produits) ---------
defined('DECISION_VALIDATION_VALIDE') || define('DECISION_VALIDATION_VALIDE', 'valide');
defined('DECISION_VALIDATION_REJETE') || define('DECISION_VALIDATION_REJETE', 'rejete');

// --- Statuts COMMANDE (cf. migration create_commandes_table) -------------
defined('STATUT_COMMANDE_EN_ATTENTE') || define('STATUT_COMMANDE_EN_ATTENTE', 'en_attente');
defined('STATUT_COMMANDE_VALIDEE') || define('STATUT_COMMANDE_VALIDEE', 'validee');
defined('STATUT_COMMANDE_EN_PREPARATION') || define('STATUT_COMMANDE_EN_PREPARATION', 'en_preparation');
defined('STATUT_COMMANDE_EN_LIVRAISON') || define('STATUT_COMMANDE_EN_LIVRAISON', 'en_livraison');
defined('STATUT_COMMANDE_LIVREE') || define('STATUT_COMMANDE_LIVREE', 'livree');
defined('STATUT_COMMANDE_ANNULEE') || define('STATUT_COMMANDE_ANNULEE', 'annulee');
// Statuts "problème" — posés par un coordinateur avant validation, cf.
// CommandeController::traiterProbleme() (Espace Coordinateur).
defined('STATUT_COMMANDE_REPORTEE') || define('STATUT_COMMANDE_REPORTEE', 'reportee');
defined('STATUT_COMMANDE_CLIENT_INJOIGNABLE') || define('STATUT_COMMANDE_CLIENT_INJOIGNABLE', 'client_injoignable');
defined('STATUT_COMMANDE_NUMERO_INCORRECT') || define('STATUT_COMMANDE_NUMERO_INCORRECT', 'numero_incorrect');

// ----- Statuts LIVRAISON (cf. migration create_livraisons_table) -----------
defined('STATUT_LIVRAISON_EN_PREPARATION') || define('STATUT_LIVRAISON_EN_PREPARATION', 'en_preparation');
defined('STATUT_LIVRAISON_EN_ATTENTE_LIVREUR') || define('STATUT_LIVRAISON_EN_ATTENTE_LIVREUR', 'en_attente_livreur');
// Assignée par le coordinateur, en attente d'acceptation par le livreur —
// distinct de EN_COURS, qui ne s'applique qu'une fois la mission acceptée.
defined('STATUT_LIVRAISON_ASSIGNEE') || define('STATUT_LIVRAISON_ASSIGNEE', 'assignee');
defined('STATUT_LIVRAISON_EN_COURS') || define('STATUT_LIVRAISON_EN_COURS', 'en_cours');
defined('STATUT_LIVRAISON_LIVREE') || define('STATUT_LIVRAISON_LIVREE', 'livree');
defined('STATUT_LIVRAISON_ECHOUEE') || define('STATUT_LIVRAISON_ECHOUEE', 'echouee');

// Retour physique au dépôt (commande annulée pendant qu'un livreur a déjà le
// colis) — noms distincts de STATUT_RETOUR_* ci-dessous (domaine retour-produit
// fournisseur, table `retours`, sans rapport).
defined('STATUT_RETOUR_LIVRAISON_EN_COURS') || define('STATUT_RETOUR_LIVRAISON_EN_COURS', 'en_cours');
defined('STATUT_RETOUR_LIVRAISON_EFFECTUE') || define('STATUT_RETOUR_LIVRAISON_EFFECTUE', 'effectue');

// --- Paiement (cf. migration create_paiements_table) ---------------------
defined('MODE_PAIEMENT_MOBILE_MONEY') || define('MODE_PAIEMENT_MOBILE_MONEY', 'mobile_money');
defined('MODE_PAIEMENT_ESPECES') || define('MODE_PAIEMENT_ESPECES', 'especes');

defined('STATUT_PAIEMENT_EN_ATTENTE') || define('STATUT_PAIEMENT_EN_ATTENTE', 'en_attente');
defined('STATUT_PAIEMENT_CONFIRME') || define('STATUT_PAIEMENT_CONFIRME', 'confirme');
defined('STATUT_PAIEMENT_ECHOUE') || define('STATUT_PAIEMENT_ECHOUE', 'echoue');

// ------ Statuts DEMANDE_SAV (cf. migration create_demandes_sav_table) -------
defined('STATUT_DEMANDE_SAV_EN_ATTENTE') || define('STATUT_DEMANDE_SAV_EN_ATTENTE', 'en_attente');
defined('STATUT_DEMANDE_SAV_PLANIFIEE') || define('STATUT_DEMANDE_SAV_PLANIFIEE', 'planifiee');
defined('STATUT_DEMANDE_SAV_EN_COURS') || define('STATUT_DEMANDE_SAV_EN_COURS', 'en_cours');
defined('STATUT_DEMANDE_SAV_RESOLUE') || define('STATUT_DEMANDE_SAV_RESOLUE', 'resolue');
defined('STATUT_DEMANDE_SAV_CLOTUREE') || define('STATUT_DEMANDE_SAV_CLOTUREE', 'cloturee');

// ----- Statuts INTERVENTION (cf. migration create_interventions_table) -----
defined('STATUT_INTERVENTION_PLANIFIEE') || define('STATUT_INTERVENTION_PLANIFIEE', 'planifiee');
defined('STATUT_INTERVENTION_EN_COURS') || define('STATUT_INTERVENTION_EN_COURS', 'en_cours');
defined('STATUT_INTERVENTION_TERMINEE') || define('STATUT_INTERVENTION_TERMINEE', 'terminee');
defined('STATUT_INTERVENTION_ANNULEE') || define('STATUT_INTERVENTION_ANNULEE', 'annulee');

// --- Statuts RETOUR (cf. migration create_retours_table) -----------------
defined('STATUT_RETOUR_EN_ATTENTE') || define('STATUT_RETOUR_EN_ATTENTE', 'en_attente');
defined('STATUT_RETOUR_ACCEPTE') || define('STATUT_RETOUR_ACCEPTE', 'accepte');
defined('STATUT_RETOUR_REFUSE') || define('STATUT_RETOUR_REFUSE', 'refuse');
defined('STATUT_RETOUR_REMBOURSE') || define('STATUT_RETOUR_REMBOURSE', 'rembourse');

// --- Statuts COMPTE UTILISATEUR (cf. migration create_users_table) -------
defined('STATUT_COMPTE_ACTIF') || define('STATUT_COMPTE_ACTIF', 'actif');
defined('STATUT_COMPTE_SUSPENDU') || define('STATUT_COMPTE_SUSPENDU', 'suspendu');
defined('STATUT_COMPTE_DESACTIVE') || define('STATUT_COMPTE_DESACTIVE', 'desactive');

// --- Garantie de base, gratuite, incluse à l'achat (cf. migration garanties) --
defined('TYPE_GARANTIE_CONSTRUCTEUR') || define('TYPE_GARANTIE_CONSTRUCTEUR', 'constructeur');
defined('TYPE_GARANTIE_ORDISPACE') || define('TYPE_GARANTIE_ORDISPACE', 'ordispace');

// --- Type de livraison PRODUIT (physique vs licence numérique) -----------
defined('TYPE_LIVRAISON_PHYSIQUE') || define('TYPE_LIVRAISON_PHYSIQUE', 'physique');
defined('TYPE_LIVRAISON_NUMERIQUE') || define('TYPE_LIVRAISON_NUMERIQUE', 'numerique');

// --- GarantiX (abonnement de maintenance étendue, distinct de GARANTIE) --
defined('STATUT_ABONNEMENT_GARANTIX_ACTIF') || define('STATUT_ABONNEMENT_GARANTIX_ACTIF', 'actif');
defined('STATUT_ABONNEMENT_GARANTIX_EXPIRE') || define('STATUT_ABONNEMENT_GARANTIX_EXPIRE', 'expire');
defined('STATUT_ABONNEMENT_GARANTIX_RESILIE') || define('STATUT_ABONNEMENT_GARANTIX_RESILIE', 'resilie');

// --- Privilège Space (promotions/codes publiés par l'Administrateur) ----
defined('TYPE_PRIVILEGE_REMISE_POURCENTAGE') || define('TYPE_PRIVILEGE_REMISE_POURCENTAGE', 'remise_pourcentage');
defined('TYPE_PRIVILEGE_REMISE_MONTANT') || define('TYPE_PRIVILEGE_REMISE_MONTANT', 'remise_montant');
defined('TYPE_PRIVILEGE_LIVRAISON_GRATUITE') || define('TYPE_PRIVILEGE_LIVRAISON_GRATUITE', 'livraison_gratuite');
defined('TYPE_PRIVILEGE_PARRAINAGE') || define('TYPE_PRIVILEGE_PARRAINAGE', 'parrainage');

// --- Parrainage & portefeuille client ------------------------------------
defined('PARRAINAGE_CODE_PREFIXE') || define('PARRAINAGE_CODE_PREFIXE', 'ACHAT');
defined('PARRAINAGE_CODE_SUFFIXE') || define('PARRAINAGE_CODE_SUFFIXE', '2026');
defined('TYPE_TRANSACTION_PORTEFEUILLE_CREDIT') || define('TYPE_TRANSACTION_PORTEFEUILLE_CREDIT', 'credit');
defined('TYPE_TRANSACTION_PORTEFEUILLE_DEBIT') || define('TYPE_TRANSACTION_PORTEFEUILLE_DEBIT', 'debit');

defined('STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE') || define('STATUT_TRANSACTION_PORTEFEUILLE_EN_ATTENTE', 'en_attente');
defined('STATUT_TRANSACTION_PORTEFEUILLE_PAYE') || define('STATUT_TRANSACTION_PORTEFEUILLE_PAYE', 'paye');

// --- Livraison gratuite automatique (cf. Privilege type livraison_gratuite) --
// Déclenchée à la Nième commande du client (2 = sa deuxième commande).
defined('SEUIL_COMMANDE_LIVRAISON_GRATUITE') || define('SEUIL_COMMANDE_LIVRAISON_GRATUITE', 2);

// --- Tutoriels & astuces (contenu publié par l'Administrateur) ----------
defined('STATUT_TUTORIEL_BROUILLON') || define('STATUT_TUTORIEL_BROUILLON', 'brouillon');
defined('STATUT_TUTORIEL_PUBLIE') || define('STATUT_TUTORIEL_PUBLIE', 'publie');

// Academy Space (app client) : deux onglets distincts sur le même contenu.
defined('TYPE_TUTORIEL_RAPIDE') || define('TYPE_TUTORIEL_RAPIDE', 'tutoriel_rapide');
defined('TYPE_TUTORIEL_FORMATION') || define('TYPE_TUTORIEL_FORMATION', 'formation');
defined('TUTORIEL_DOSSIER') || define('TUTORIEL_DOSSIER', 'tutoriels');

// --- Journal d'audit (fil "Activités" de la fiche client admin) ---------
defined('ACTION_COMMANDE_CREEE') || define('ACTION_COMMANDE_CREEE', 'commande_creee');
defined('ACTION_GARANTIX_SOUSCRIPTION') || define('ACTION_GARANTIX_SOUSCRIPTION', 'garantix_souscription');
defined('ACTION_PANNE_DECLAREE') || define('ACTION_PANNE_DECLAREE', 'panne_declaree');
defined('ACTION_PRIVILEGE_UTILISE') || define('ACTION_PRIVILEGE_UTILISE', 'privilege_utilise');
defined('ACTION_PANIER_AJOUT') || define('ACTION_PANIER_AJOUT', 'panier_ajout');
defined('ACTION_TUTORIEL_VU') || define('ACTION_TUTORIEL_VU', 'tutoriel_vu');
defined('ACTION_COMMANDE_STATUT_MODIFIE') || define('ACTION_COMMANDE_STATUT_MODIFIE', 'commande_statut_modifie');
defined('ACTION_RECLAMATION_CREEE') || define('ACTION_RECLAMATION_CREEE', 'reclamation_creee');

// --- Suivi individuel des tutoriels/formations par client ----------------
defined('STATUT_PROGRESSION_VU') || define('STATUT_PROGRESSION_VU', 'vu');
defined('STATUT_PROGRESSION_TERMINE') || define('STATUT_PROGRESSION_TERMINE', 'termine');

// --- Réclamations clients (distinct des déclarations de panne SAV) ------
defined('STATUT_RECLAMATION_NOUVELLE') || define('STATUT_RECLAMATION_NOUVELLE', 'nouvelle');
defined('STATUT_RECLAMATION_EN_COURS') || define('STATUT_RECLAMATION_EN_COURS', 'en_cours');
defined('STATUT_RECLAMATION_RESOLUE') || define('STATUT_RECLAMATION_RESOLUE', 'resolue');
defined('STATUT_RECLAMATION_REJETEE') || define('STATUT_RECLAMATION_REJETEE', 'rejetee');

// --- Segmentation client sur le nombre de commandes (hors annulées) -----
// Règle donnée par le PDG : Nouveau Client / Gros Acheteur / VIP, bornée sur
// le nombre de commandes valides passées. Plages ajustées pour ne plus se
// chevaucher (Nouveau 1-2, Gros Acheteur 3-5, VIP 6 et au-delà, sans plafond).
defined('SEUIL_NOUVEAU_CLIENT_MIN') || define('SEUIL_NOUVEAU_CLIENT_MIN', 1);
defined('SEUIL_NOUVEAU_CLIENT_MAX') || define('SEUIL_NOUVEAU_CLIENT_MAX', 2);
defined('SEUIL_GROS_ACHETEUR_MIN') || define('SEUIL_GROS_ACHETEUR_MIN', 3);
defined('SEUIL_GROS_ACHETEUR_MAX') || define('SEUIL_GROS_ACHETEUR_MAX', 5);
defined('SEUIL_VIP_MIN') || define('SEUIL_VIP_MIN', 6);

defined('SEGMENT_CLIENT_NOUVEAU') || define('SEGMENT_CLIENT_NOUVEAU', 'nouveau');
defined('SEGMENT_CLIENT_GROS_ACHETEUR') || define('SEGMENT_CLIENT_GROS_ACHETEUR', 'gros_acheteur');
defined('SEGMENT_CLIENT_VIP') || define('SEGMENT_CLIENT_VIP', 'vip');

// --- Assistance : questions fréquentes (contenu publié par l'Administrateur) --
defined('STATUT_QUESTION_BROUILLON') || define('STATUT_QUESTION_BROUILLON', 'brouillon');
defined('STATUT_QUESTION_PUBLIE') || define('STATUT_QUESTION_PUBLIE', 'publie');
// webm : format par défaut de MediaRecorder dans les navigateurs Chromium.
defined('AUDIO_MIMES_AUTORISES') || define('AUDIO_MIMES_AUTORISES', 'mp3,wav,m4a,ogg,webm');
defined('AUDIO_MAX_POIDS_KO') || define('AUDIO_MAX_POIDS_KO', 15360); // 15 Mo
defined('ASSISTANCE_AUDIO_DOSSIER') || define('ASSISTANCE_AUDIO_DOSSIER', 'assistance');

// --- Agent IA "Ellah" (page Aide rapide) ---------------------------------
defined('ROLE_MESSAGE_IA_CLIENT') || define('ROLE_MESSAGE_IA_CLIENT', 'client');
defined('ROLE_MESSAGE_IA_ASSISTANT') || define('ROLE_MESSAGE_IA_ASSISTANT', 'assistant');

// --- Discussion produit/commande (Espace Coordinateur, Phase 2) ---------
defined('TYPE_MESSAGE_TEXTE') || define('TYPE_MESSAGE_TEXTE', 'texte');
defined('TYPE_MESSAGE_IMAGE') || define('TYPE_MESSAGE_IMAGE', 'image');
defined('TYPE_MESSAGE_VIDEO') || define('TYPE_MESSAGE_VIDEO', 'video');
defined('TYPE_MESSAGE_AUDIO') || define('TYPE_MESSAGE_AUDIO', 'audio');
defined('TYPE_MESSAGE_NOTE_VOCALE') || define('TYPE_MESSAGE_NOTE_VOCALE', 'note_vocale');
defined('TYPE_MESSAGE_DOCUMENT') || define('TYPE_MESSAGE_DOCUMENT', 'document');
// Rapport quotidien automatique — voir MessageController::genererRapportQuotidienSiNecessaire().
defined('TYPE_MESSAGE_RAPPORT') || define('TYPE_MESSAGE_RAPPORT', 'rapport');
// Instantané de commande publié une seule fois à sa création — voir CommandeController::store().
defined('TYPE_MESSAGE_COMMANDE_CREEE') || define('TYPE_MESSAGE_COMMANDE_CREEE', 'commande_creee');
// Instantané {prix_liste, prix_propose} publié au démarrage d'une négociation
// de prix — voir MessageController::demarrerNegociationPrix().
defined('TYPE_MESSAGE_PROPOSITION_PRIX') || define('TYPE_MESSAGE_PROPOSITION_PRIX', 'proposition_prix');
defined('MESSAGE_CONTENU_MAX_LONGUEUR') || define('MESSAGE_CONTENU_MAX_LONGUEUR', 5000);
// Image et audio de message réutilisent les mimes/plafonds existants, mais
// un dossier dédié (pas mélangé aux pièces jointes métier produits/FAQ).
defined('MESSAGE_IMAGE_DOSSIER') || define('MESSAGE_IMAGE_DOSSIER', 'messages/images');
// Preuves jointes à une réclamation (écran "Détails" du coordinateur) — même
// disque/mimes/plafond que les images produit, dossier dédié.
defined('RECLAMATION_PREUVE_DOSSIER') || define('RECLAMATION_PREUVE_DOSSIER', 'reclamations/preuves');
// Preuve de livraison (photo prise par le livreur à la remise du colis).
defined('LIVRAISON_PREUVE_DOSSIER') || define('LIVRAISON_PREUVE_DOSSIER', 'livraisons/preuves');
// Photo de profil (tout utilisateur) — mêmes mimes/plafond que les images produit.
defined('PHOTO_PROFIL_DOSSIER') || define('PHOTO_PROFIL_DOSSIER', 'utilisateurs/photos');
// Types de véhicule déclarables par un Livreur (écran "Mon Profil").
defined('TYPES_VEHICULE_LIVREUR') || define('TYPES_VEHICULE_LIVREUR', ['moto', 'voiture', 'tricycle', 'velo']);
defined('MESSAGE_AUDIO_DOSSIER') || define('MESSAGE_AUDIO_DOSSIER', 'messages/audio');
// Vidéo et document : aucun précédent dans le code, constantes nouvelles.
defined('VIDEO_MIMES_AUTORISES') || define('VIDEO_MIMES_AUTORISES', 'mp4,mov,webm');
defined('VIDEO_MAX_POIDS_KO') || define('VIDEO_MAX_POIDS_KO', 51200); // 50 Mo
defined('MESSAGE_VIDEO_DOSSIER') || define('MESSAGE_VIDEO_DOSSIER', 'messages/videos');
defined('DOCUMENT_MIMES_AUTORISES') || define('DOCUMENT_MIMES_AUTORISES', 'pdf,doc,docx,xls,xlsx');
defined('DOCUMENT_MAX_POIDS_KO') || define('DOCUMENT_MAX_POIDS_KO', 10240); // 10 Mo
defined('MESSAGE_DOCUMENT_DOSSIER') || define('MESSAGE_DOCUMENT_DOSSIER', 'messages/documents');

// --- Localités (frais de livraison, Espace Coordinateur) -----------------
// Liste fixe : communes d'Abidjan d'un côté, villes de Côte d'Ivoire de
// l'autre — utilisée à la fois pour l'adresse client et le barème produit.
defined('TYPE_LOCALITE_COMMUNE_ABIDJAN') || define('TYPE_LOCALITE_COMMUNE_ABIDJAN', 'commune_abidjan');
defined('TYPE_LOCALITE_VILLE') || define('TYPE_LOCALITE_VILLE', 'ville');
