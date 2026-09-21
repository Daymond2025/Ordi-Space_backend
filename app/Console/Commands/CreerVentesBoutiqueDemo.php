<?php

namespace App\Console\Commands;

use App\Models\Adresse;
use App\Models\CanalVente;
use App\Models\Client;
use App\Models\Commande;
use App\Models\Coordinateur;
use App\Models\DemandeRetrait;
use App\Models\LienAffilie;
use App\Models\LigneCommande;
use App\Models\Livraison;
use App\Models\Localite;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\User;
use App\Models\VenteBoutique;
use App\Models\Vitrine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de données réaliste pour l'écran "Centre des ventes" d'un livreur :
 * de vraies commandes (clients, adresses, lignes, livraisons, stock) créées
 * avec les mêmes champs que POST /commandes, puis rattachées au livreur
 * comme ventes "Boutique" — pas des lignes fictives insérées à part. Les
 * changements de statut passent par Commande::appliquerChangementStatut(),
 * comme lorsqu'un Coordinateur/Admin traite la commande.
 *
 * Volontairement hors DatabaseSeeder : de la donnée de démonstration ne doit
 * jamais atterrir en production par accident.
 */
class CreerVentesBoutiqueDemo extends Command
{
    protected $signature = 'boutique:ventes-demo
        {email : E-mail du livreur qui reçoit les ventes}
        {--produit= : Id du produit vendu (défaut : premier produit à commission de revente en stock)}';

    protected $description = "Crée des ventes Boutique réalistes (commandes réelles) pour un livreur, pour visualiser l'écran « Centre des ventes ».";

    /** [prénom, nom, téléphone, source, statut final, ancienneté en jours] */
    private const SCENARIOS = [
        ['Jean', 'Kouassi', '+2250700100101', 'manuelle', STATUT_COMMANDE_LIVREE, 6],
        ['Aminata', 'Traoré', '+2250700100102', 'whatsapp', STATUT_COMMANDE_EN_LIVRAISON, 3],
        ['Koffi', 'Yao', '+2250700100103', 'qr', STATUT_COMMANDE_ANNULEE, 2],
        ['Fatou', 'Diabaté', '+2250700100104', 'whatsapp', STATUT_COMMANDE_EN_ATTENTE, 0],
        ['Ibrahim', 'Coulibaly', '+2250700100105', 'qr', STATUT_COMMANDE_LIVREE, 5],
        ['Mariam', 'Sanogo', '+2250700100106', 'whatsapp', STATUT_COMMANDE_LIVREE, 4],
    ];

    /** Visites du lien affilié simulées (aucune page publique n'enregistre encore de visite). */
    private const VUES_DEMO = 51;
    private const CLICS_VITRINE_DEMO = 24;
    private const SCANS_VITRINE_DEMO = 17;

    public function handle(): int
    {
        $livreur = User::where('email', $this->argument('email'))->where('type_utilisateur', ROLE_LIVREUR)->first();
        if (! $livreur) {
            $this->error('Aucun livreur avec cet e-mail.');

            return self::FAILURE;
        }

        $produit = $this->option('produit')
            ? Produit::find($this->option('produit'))
            : Produit::whereNotNull('commission_revente')->where('quantite_stock', '>', 0)->first();
        if (! $produit || ! $produit->estVisibleALaVente() || $produit->commission_revente === null) {
            $this->error('Produit introuvable, non publié, ou sans commission de revente.');

            return self::FAILURE;
        }

        // De préférence un coordinateur joignable : sa fiche est affichée au livreur ("Mes infos").
        $coordinateurId = Coordinateur::whereHas('user', fn ($q) => $q->whereNotNull('telephone'))->value('user_id')
            ?? Coordinateur::query()->value('user_id');
        $agentId = User::where('email', AGENT_IA_EMAIL)->value('id');
        $localite = Localite::where('nom', 'Cocody')->first();
        if (! $coordinateurId || ! $agentId || ! $localite) {
            $this->error("Coordinateur, agent IA ou localité \"Cocody\" manquant — lancez d'abord les seeders.");

            return self::FAILURE;
        }

        // La livraison d'une vente est assurée par un autre livreur quand il y
        // en a un (le vendeur n'est pas forcément celui qui livre).
        $livreurDeLivraison = User::where('type_utilisateur', ROLE_LIVREUR)->where('id', '!=', $livreur->id)->value('id') ?? $livreur->id;

        $lien = LienAffilie::firstOrCreate(
            ['produit_id' => $produit->id, 'livreur_id' => $livreur->id],
            ['code' => LienAffilie::genererCode()]
        );

        // Vitrine (onglet Profil) : visites du lien et scans de l'affiche simulés de la même façon.
        $vitrine = Vitrine::pour($livreur);
        if ($vitrine->clics === 0 && $vitrine->scans === 0) {
            $vitrine->update(['clics' => self::CLICS_VITRINE_DEMO, 'scans' => self::SCANS_VITRINE_DEMO]);
        }

        // Fiche du coordinateur (carte partenaire de "Mes infos") et numéro du
        // support Ordi'Space (réglage Admin) : renseignés seulement s'ils sont vides.
        $fiche = Coordinateur::find($coordinateurId);
        if ($fiche && ! $fiche->adresse && ! $fiche->horaires && ! $fiche->zone_couverte) {
            $fiche->update([
                'adresse' => 'Cocody, Riviera Palmeraie',
                'horaires' => 'Lun-Sam • 8h-18h',
                'zone_couverte' => 'Abidjan et périphérie (25 km)',
            ]);
        }
        if (! Parametre::lire(PARAMETRE_SUPPORT_TELEPHONE)) {
            Parametre::ecrire(PARAMETRE_SUPPORT_TELEPHONE, User::where('type_utilisateur', ROLE_ADMINISTRATEUR)->value('telephone'));
        }

        // Relançable sans doublon : un scénario déjà créé pour ce livreur
        // (repéré par le téléphone du client) est simplement ignoré.
        $scenarios = array_filter(self::SCENARIOS, fn (array $s) => ! VenteBoutique::where('livreur_id', $livreur->id)
            ->whereHas('commande.client.user', fn ($q) => $q->where('telephone', $s[2]))
            ->exists());

        if ($scenarios === []) {
            $this->info('Toutes les ventes de démonstration existent déjà pour ce livreur.');
            $this->creerRetraitsDemo($livreur);

            return self::SUCCESS;
        }

        if ($produit->quantite_stock < count($scenarios)) {
            $this->error('Stock insuffisant pour créer les commandes de démonstration.');

            return self::FAILURE;
        }

        foreach ($scenarios as [$prenom, $nom, $telephone, $source, $statutFinal, $anciennete]) {
            $commande = DB::transaction(fn () => $this->creerCommande(
                $produit, $prenom, $nom, $telephone, $source, $localite, $agentId, now()->subDays($anciennete)
            ));

            VenteBoutique::enregistrer($livreur, $commande, $source, $source === 'manuelle' ? null : $lien);
            // Une commande saisie à la main est livrée par le livreur lui-même ; les
            // ventes par lien/QR sont confiées à un autre livreur.
            $this->avancerStatut($commande, $statutFinal, $coordinateurId, $source === 'manuelle' ? $livreur->id : $livreurDeLivraison);

            $this->line("Commande n°{$commande->id} — {$prenom} {$nom} — {$source} — {$statutFinal}");
        }

        // Le lien affilié a été visité avant d'aboutir à ces commandes.
        if ($lien->fresh()->vues === 0) {
            $lien->update(['vues' => self::VUES_DEMO, 'derniere_activite_le' => now()->subHours(2)]);
        }

        $this->creerRetraitsDemo($livreur);

        $this->info('Ventes créées. Stock restant : '.$produit->fresh()->quantite_stock);

        return self::SUCCESS;
    }

    /**
     * Trois demandes de retrait dans les trois états visibles du portefeuille
     * (payée, refusée, en attente), créées seulement si le livreur n'en a
     * aucune et s'il a assez de commission pour les couvrir.
     */
    private function creerRetraitsDemo(User $livreur): void
    {
        if (DemandeRetrait::where('user_id', $livreur->id)->exists()) {
            return;
        }

        $adminId = User::where('type_utilisateur', ROLE_ADMINISTRATEUR)->value('id');
        $demandes = [
            ['montant' => 20000, 'operateur' => 'Orange', 'statut' => STATUT_RETRAIT_VALIDE, 'reference' => 'OM-260912-48213', 'jours' => 8],
            ['montant' => 30000, 'operateur' => 'Wave', 'statut' => STATUT_RETRAIT_REFUSE, 'remarque' => 'Numéro Wave incorrect, merci de renvoyer la demande.', 'jours' => 5],
            ['montant' => 10000, 'operateur' => 'Mtn', 'statut' => STATUT_RETRAIT_EN_ATTENTE, 'jours' => 1],
        ];

        foreach ($demandes as $demande) {
            $traite = $demande['statut'] !== STATUT_RETRAIT_EN_ATTENTE;
            $retrait = DemandeRetrait::create([
                'user_id' => $livreur->id,
                'montant' => $demande['montant'],
                'operateur' => $demande['operateur'],
                'telephone' => $livreur->telephone,
                'statut' => $demande['statut'],
                'reference' => $demande['reference'] ?? null,
                'remarque' => $demande['remarque'] ?? null,
                'admin_id' => $traite ? $adminId : null,
                'traite_le' => $traite ? now()->subDays($demande['jours'] - 1) : null,
            ]);
            $retrait->forceFill(['created_at' => now()->subDays($demande['jours'])])->save();
        }

        $this->line('Retraits de démonstration créés (payé, refusé, en attente).');
    }

    private function creerCommande(Produit $produit, string $prenom, string $nom, string $telephone, string $source, Localite $localite, int $agentId, $date): Commande
    {
        $client = User::where('telephone', $telephone)->first()
            ?? Client::creerCompteMinimal($nom, $prenom, $telephone);

        $adresse = Adresse::firstOrCreate(
            ['client_id' => $client->id, 'localite_id' => $localite->id],
            ['libelle' => 'Domicile', 'rue' => $localite->nom, 'ville' => 'Abidjan', 'pays' => "Côte d'Ivoire"]
        );

        $prixVente = $produit->prix_vente ?? $produit->prix;
        $frais = (float) ($produit->fraisLivraison()->where('localite_id', $localite->id)->value('montant') ?? 0);

        $commande = Commande::create([
            'client_id' => $client->id,
            'commercial_id' => $agentId,
            'canal_vente_id' => CanalVente::where('nom_canal', $source === 'whatsapp' ? 'WhatsApp' : CANAL_VENTE_BOUTIQUE_APPLICATION)->value('id'),
            'livraison_gratuite_appliquee' => false,
            'statut_commande' => STATUT_COMMANDE_EN_ATTENTE,
            'montant_total' => $prixVente,
            'montant_remise' => 0,
            'frais_livraison' => $frais,
            'date_commande' => $date,
        ]);

        LigneCommande::create([
            'commande_id' => $commande->id,
            'produit_id' => $produit->id,
            'quantite' => 1,
            'prix_unitaire' => $prixVente,
            'prix_partenaire_unitaire' => $produit->prix_vente !== null ? $produit->prix : null,
        ]);
        $produit->decrement('quantite_stock');

        Livraison::create([
            'commande_id' => $commande->id,
            'adresse_id' => $adresse->id,
            'statut_livraison' => STATUT_LIVRAISON_EN_PREPARATION,
        ]);

        return $commande;
    }

    /** Rejoue le parcours normal d'une commande jusqu'au statut voulu. */
    private function avancerStatut(Commande $commande, string $statutFinal, int $coordinateurId, int $livreurId): void
    {
        $parcours = match ($statutFinal) {
            STATUT_COMMANDE_LIVREE => [STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON, STATUT_COMMANDE_LIVREE],
            STATUT_COMMANDE_EN_LIVRAISON => [STATUT_COMMANDE_VALIDEE, STATUT_COMMANDE_EN_PREPARATION, STATUT_COMMANDE_EN_LIVRAISON],
            STATUT_COMMANDE_ANNULEE => [STATUT_COMMANDE_ANNULEE],
            default => [],
        };

        foreach ($parcours as $etape) {
            $commande->refresh()->appliquerChangementStatut($etape, livreurId: $livreurId, coordinateurId: $coordinateurId);
        }
    }
}
