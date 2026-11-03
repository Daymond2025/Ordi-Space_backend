<?php

namespace Database\Seeders;

use App\Models\DemandeSav;
use App\Models\Garantie;
use App\Models\Intervention;
use App\Models\RendezVous;
use App\Models\TechnicienMaintenance;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de rendez-vous SAV de démonstration (présentation de l'app
 * Maintenancier) : un technicien et un client déjà existants, des
 * demandes couvrant tous les états des écrans (nouvelle demande,
 * confirmé, reporté, en cours, terminé, annulé).
 *
 * Rejoué à chaque déploiement (db:seed du deploy-hook) : les demandes de
 * démo sont supprimées puis recréées, datées par rapport au jour du
 * déploiement — relancer le workflow "Run workflow" le matin de la
 * présentation remet tout à neuf. Sans effet si l'un des deux comptes
 * n'existe pas (tests, local). À retirer de DatabaseSeeder après la
 * présentation.
 *
 * Les comptes sont repérés par l'empreinte SHA-256 des 8 derniers
 * chiffres de leur téléphone (le dépôt est public : aucun numéro en clair).
 */
class DemoSavSeeder extends Seeder
{
    private const EMPREINTE_TECHNICIEN = '27dde3020047e8d3fcc9af3b8ca9cfed3850b609781ec7810551a40adabacb22';

    private const EMPREINTE_CLIENT = 'afecd30d3b27acd610d75893fc6e2d30f6ad74db036e1e0fd583c3c5afd53cb5';

    public function run(): void
    {
        $technicien = $this->trouver(ROLE_TECHNICIEN_MAINTENANCE, self::EMPREINTE_TECHNICIEN);
        $client = $this->trouver(ROLE_CLIENT, self::EMPREINTE_CLIENT);

        if (! $technicien || ! $client || ! $client->client) {
            return;
        }

        TechnicienMaintenance::firstOrCreate(['user_id' => $technicien->id])->update(['disponible' => true]);

        $garanties = Garantie::whereHas('ligneCommande.commande', fn ($q) => $q->where('client_id', $client->id))
            ->latest('id')->limit(5)->pluck('id')->all();
        $adresse = $client->client->adresses()->with('localite')->first();
        $lieu = $adresse ? collect([$adresse->localite?->nom ?? $adresse->ville, $adresse->rue])->filter()->implode(', ') : null;

        DB::transaction(function () use ($technicien, $client, $garanties, $lieu) {
            // Remise à zéro : interventions et rendez-vous suivent en cascade.
            DemandeSav::where('client_id', $client->id)
                ->whereIn('description_probleme', array_column($this->scenarios(), 'description'))
                ->delete();

            foreach ($this->scenarios() as $i => $scenario) {
                $this->creer($scenario, $technicien->id, $client->id, $garanties[$i % max(count($garanties), 1)] ?? null, $lieu);
            }
        });

        $this->command?->info('Démo SAV prête : '.count($this->scenarios()).' demandes.');
    }

    private function trouver(string $role, string $empreinte): ?User
    {
        return User::where('type_utilisateur', $role)->whereNotNull('telephone')->get()
            ->first(fn (User $u) => hash_equals($empreinte, hash('sha256', substr(preg_replace('/\D/', '', $u->telephone), -8))));
    }

    private function creer(array $s, int $technicienId, int $clientId, ?int $garantieId, ?string $lieu): void
    {
        $demande = DemandeSav::create([
            'client_id' => $clientId,
            'garantie_id' => $garantieId,
            'description_probleme' => $s['description'],
            'statut_demande' => $s['statut_demande'],
            'date_demande' => $s['date_demande'],
        ]);

        if (! isset($s['rdv'])) {
            return;
        }

        $rdv = RendezVous::create([
            'demande_sav_id' => $demande->id,
            'technicien_id' => $technicienId,
            'date_rdv' => $s['rdv'],
            'lieu' => $lieu,
            'statut_rdv' => $s['statut_rdv'],
        ]);

        if (isset($s['intervention'])) {
            Intervention::create([
                ...$s['intervention'],
                'rendez_vous_id' => $rdv->id,
                'technicien_id' => $technicienId,
                'date_intervention' => $s['rdv'],
            ]);
        }
    }

    private function scenarios(): array
    {
        $aujourdhui = Carbon::today();
        $a = fn (int $jours, int $h, int $m = 0) => $aujourdhui->copy()->addDays($jours)->setTime($h, $m);

        $diagnosticSurchauffe = [
            'problemes' => ['Surchauffe', 'Bruit anormal / ventilateur'],
            'probleme_identifie' => 'Ventilateur encrassé, température processeur à 95 °C en charge.',
            'cause_panne' => 'Poussière accumulée et pâte thermique sèche.',
            'niveau_gravite' => 'moyenne',
            'duree_estimee_minutes' => 90,
        ];

        return [
            [
                'description' => "Mon ordinateur ne s'allume plus depuis ce matin, le voyant de charge reste éteint.",
                'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
                'date_demande' => $a(0, 8, 15),
            ],
            [
                'description' => "L'écran clignote et affiche des lignes verticales au démarrage.",
                'statut_demande' => STATUT_DEMANDE_SAV_EN_ATTENTE,
                'date_demande' => $a(0, 7, 40),
                'rdv' => $a(0, 15, 30),
                'statut_rdv' => STATUT_RDV_DEMANDE,
            ],
            [
                'description' => "La batterie se décharge en moins d'une heure, même en veille.",
                'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
                'date_demande' => $a(-1, 16, 5),
                'rdv' => $a(0, 11, 0),
                'statut_rdv' => STATUT_RDV_CONFIRME,
            ],
            [
                'description' => 'Plusieurs touches du clavier ne répondent plus (A, Z et la barre espace).',
                'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
                'date_demande' => $a(-2, 10, 20),
                'rdv' => $a(1, 14, 0),
                'statut_rdv' => STATUT_RDV_REPORTE,
            ],
            [
                'description' => "Le PC chauffe énormément et le ventilateur fait un bruit fort après 10 minutes d'utilisation.",
                'statut_demande' => STATUT_DEMANDE_SAV_EN_COURS,
                'date_demande' => $a(-1, 9, 0),
                'rdv' => $a(0, 9, 0),
                'statut_rdv' => STATUT_RDV_CONFIRME,
                'intervention' => [
                    ...$diagnosticSurchauffe,
                    'statut_intervention' => STATUT_INTERVENTION_EN_COURS,
                    'diagnostic' => 'Surchauffe, Bruit anormal / ventilateur — '.$diagnosticSurchauffe['probleme_identifie'].' — Cause : '.$diagnosticSurchauffe['cause_panne'],
                ],
            ],
            [
                'description' => 'Ordinateur très lent, Windows met plus de 5 minutes à démarrer.',
                'statut_demande' => STATUT_DEMANDE_SAV_PLANIFIEE,
                'date_demande' => $a(-1, 14, 30),
                'rdv' => $a(3, 10, 0),
                'statut_rdv' => STATUT_RDV_CONFIRME,
            ],
            [
                'description' => "Le disque dur fait des clics et certains fichiers ne s'ouvrent plus.",
                'statut_demande' => STATUT_DEMANDE_SAV_RESOLUE,
                'date_demande' => $a(-3, 11, 0),
                'rdv' => $a(-1, 10, 0),
                'statut_rdv' => STATUT_RDV_TERMINE,
                'intervention' => [
                    'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
                    'problemes' => ['Disque dur / stockage', 'Lenteur du système'],
                    'probleme_identifie' => 'Disque dur mécanique en fin de vie (secteurs défectueux).',
                    'cause_panne' => 'Usure du disque.',
                    'niveau_gravite' => 'critique',
                    'duree_estimee_minutes' => 120,
                    'diagnostic' => 'Disque dur / stockage, Lenteur du système — Disque dur mécanique en fin de vie (secteurs défectueux). — Cause : Usure du disque.',
                    'prestations' => ['Diagnostic', 'Récupération de données', 'Clonage', 'Installation Windows'],
                    'pieces_remplacees' => ['SSD'],
                    'reparation_effectuee' => 'Diagnostic, Récupération de données, Clonage, Installation Windows — Pièces remplacées : SSD',
                    'cout' => 45000,
                    'date_fin' => $a(-1, 12, 15),
                    'duree_reelle_minutes' => 135,
                    'note_fin' => 'Données du client récupérées et transférées sur le nouveau SSD.',
                ],
            ],
            [
                'description' => 'Fenêtres publicitaires qui s\'ouvrent toutes seules et navigateur détourné.',
                'statut_demande' => STATUT_DEMANDE_SAV_RESOLUE,
                'date_demande' => $a(-6, 15, 0),
                'rdv' => $a(-5, 9, 30),
                'statut_rdv' => STATUT_RDV_TERMINE,
                'intervention' => [
                    'statut_intervention' => STATUT_INTERVENTION_TERMINEE,
                    'problemes' => ['Virus / logiciel malveillant'],
                    'probleme_identifie' => 'Logiciels publicitaires installés avec un programme gratuit.',
                    'niveau_gravite' => 'faible',
                    'duree_estimee_minutes' => 60,
                    'diagnostic' => 'Virus / logiciel malveillant — Logiciels publicitaires installés avec un programme gratuit.',
                    'prestations' => ['Antivirus', 'Nettoyage', 'Optimisation'],
                    'pieces_remplacees' => [],
                    'reparation_effectuee' => 'Antivirus, Nettoyage, Optimisation',
                    'cout' => 15000,
                    'date_fin' => $a(-5, 10, 30),
                    'duree_reelle_minutes' => 60,
                    'note_fin' => 'Antivirus installé et navigateur réinitialisé.',
                ],
            ],
            [
                'description' => 'Le Wi-Fi se déconnecte toutes les deux minutes.',
                'statut_demande' => STATUT_DEMANDE_SAV_CLOTUREE,
                'date_demande' => $a(-4, 10, 0),
                'rdv' => $a(-2, 16, 0),
                'statut_rdv' => STATUT_RDV_CONFIRME,
                'intervention' => [
                    'statut_intervention' => STATUT_INTERVENTION_ANNULEE,
                    'problemes' => ['Problème Wi-Fi / réseau'],
                    'probleme_identifie' => 'Problème venant de la box du client, pas de l\'ordinateur.',
                    'niveau_gravite' => 'faible',
                    'diagnostic' => 'Problème Wi-Fi / réseau — Problème venant de la box du client, pas de l\'ordinateur.',
                ],
            ],
        ];
    }
}
