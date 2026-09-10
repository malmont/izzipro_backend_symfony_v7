<?php

namespace App\Command;

use App\Entity\ServiceOffer;
use App\Entity\ServiceOfferTranslation;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[AsCommand(
    name: 'app:seed-service-offers',
    description: 'Initialise les prestations détaillées en base de données (ServiceOffer) pour un ou tous les tenants.',
)]
class SeedServiceOffersCommand extends Command
{
    public const SERVICES = [
        // Pôle 1 : Art de vivre et accompagnement
        [
            'titre' => 'Accompagnement aux rendez-vous médicaux',
            'titreCommentaire' => 'Présence attentionnée et transport sécurisé',
            'descriptions' => 'Présence attentionnée, transport et soutien discret lors de consultations médicales, examens ou rendez-vous chez les spécialistes.',
        ],
        [
            'titre' => 'Accompagnement aux rendez-vous privés',
            'titreCommentaire' => 'Démarches personnelles et administratives',
            'descriptions' => 'Déplacement accompagné pour vos démarches personnelles, notariales, bancaires ou rendez-vous d\'affaires privées.',
        ],
        [
            'titre' => 'Accompagnement sorties culturelles & loisirs',
            'titreCommentaire' => 'Culture, expositions et moments choisis',
            'descriptions' => 'Visite guidée d\'expositions, galeries, concerts, théâtres, déjeuners au restaurant ou moments de convivialité choisis.',
        ],
        [
            'titre' => 'Magasinage privé & emplettes personnalisées',
            'titreCommentaire' => 'Achats plaisir et conseils sur mesure',
            'descriptions' => 'Aide et accompagnement sur mesure pour vos achats : mode, librairie, épicerie fine ou marché de quartier.',
        ],
        [
            'titre' => 'Livraisons de courses & commandes sur mesure',
            'titreCommentaire' => 'Achats réalisés et rangés à domicile',
            'descriptions' => 'Courses et achats de proximité réalisés pour vous par votre intendante et soigneusement rangés à votre domicile.',
        ],
        [
            'titre' => 'Organisation de soins bien-être à domicile',
            'titreCommentaire' => 'Coiffure, esthétique et détente',
            'descriptions' => 'Accueil et supervision de praticiens à domicile : coiffure privée, manucure, soins esthétiques ou massages relaxants.',
        ],
        [
            'titre' => 'Balades & promenades douces',
            'titreCommentaire' => 'Grand air et bien-être à votre rythme',
            'descriptions' => 'Moments d\'évasion au grand air dans vos parcs et lieux favoris, avec un rythme adapté, sécurisant et bienveillant.',
        ],
        [
            'titre' => 'Planification & préparation de voyages',
            'titreCommentaire' => 'Itinéraires et conciergerie de voyage',
            'descriptions' => 'Élaboration d\'itinéraires, réservations adaptées, gestion des bagages et conciergerie de voyage.',
        ],
        [
            'titre' => 'Activités artistiques & ateliers créatifs',
            'titreCommentaire' => 'Moments d\'expression et créativité',
            'descriptions' => 'Séances d’éveil et de partage à domicile : peinture, dessin, écriture, composition florale ou musique.',
        ],
        [
            'titre' => 'Organisation d\'événements familiaux & réceptions',
            'titreCommentaire' => 'Repas de famille et réceptions privées',
            'descriptions' => 'Préparation, décoration de table, accueil et coordination pour vos repas de famille ou anniversaires à domicile.',
        ],

        // Pôle 2 : Intendance immobilière
        [
            'titre' => 'Ménage & entretien soigné du domicile',
            'titreCommentaire' => 'Propreté et confort de votre intérieur',
            'descriptions' => 'Entretien méticuleux de votre intérieur, dépoussiérage, soin du linge et tenue impeccable de vos espaces de vie.',
        ],
        [
            'titre' => 'Préparation d\'un repas à domicile',
            'titreCommentaire' => 'Cuisine fraîche et repas équilibré',
            'descriptions' => 'Élaboration et confection sur place de plats savoureux et équilibrés, selon vos goûts et préférences culinaires.',
        ],
        [
            'titre' => 'Accompagnement aux courses',
            'titreCommentaire' => 'Aide pratique et portage des achats',
            'descriptions' => 'Accompagnement pour faire vos courses au marché ou en magasin, avec prise en charge du port des sacs.',
        ],

        // Pôle 3 : Secrétariat privé
        [
            'titre' => 'Traitement du courrier & classement confidentiel',
            'titreCommentaire' => 'Tri, suivi et archivage sécurisé',
            'descriptions' => 'Dépouillement, tri rigoureux, préparation des réponses administratives et archivage confidentiel.',
        ],
        [
            'titre' => 'Pointage & règlement des factures',
            'titreCommentaire' => 'Vérification et suivi des quittances',
            'descriptions' => 'Contrôle attentif de vos factures et quittances, tenue de l\'échéancier et préparation des paiements en toute transparence.',
        ],
        [
            'titre' => 'Assistance numérique (ordinateur, tablette, smartphone)',
            'titreCommentaire' => 'Pédagogie et démarches dématérialisées',
            'descriptions' => 'Pédagogie et aide individuelle pour naviguer, sécuriser vos outils, communiquer avec vos proches et effectuer vos démarches en ligne.',
        ],
        [
            'titre' => 'Gestion d\'agenda & prise de rendez-vous',
            'titreCommentaire' => 'Organisation de votre emploi du temps',
            'descriptions' => 'Planification quotidienne, calage et confirmation de vos rendez-vous privés, rappels personnalisés.',
        ],
        [
            'titre' => 'Coordination du dossier de santé',
            'titreCommentaire' => 'Ordonnances, mutuelle et praticiens',
            'descriptions' => 'Organisation de vos pièces médicales, renouvellement d\'ordonnances en pharmacie et lien avec les praticiens de santé.',
        ],

        // Pôle 4 : Mémoire vivante
        [
            'titre' => 'Livre biographique – Entretien découverte & cadrage',
            'titreCommentaire' => 'Premier échange confidentiel et bienveillant',
            'descriptions' => 'Première rencontre confidentielle (à domicile ou à distance) pour faire connaissance, esquisser la trame de votre livre et planifier les étapes d\'écriture.',
        ],

        // Pôle 5 : Premier contact
        [
            'titre' => 'Échange confidentiel & bilan personnalisé',
            'titreCommentaire' => 'Définition de votre projet d\'accompagnement',
            'descriptions' => 'Consultation initiale pour étudier l\'ensemble de vos besoins et définir un accompagnement sur mesure.',
        ],
    ];

    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private TagAwareCacheInterface $cache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Code ou nom de la base tenant (ex: lintendantprive ou db_lintendantprive)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Peupler sur tous les tenants enregistrés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantOpt = $input->getOption('tenant');
        $allOpt = $input->getOption('all');

        if (!$tenantOpt && !$allOpt) {
            $tenantOpt = 'lintendantprive'; // Valeur par défaut
        }

        if ($tenantOpt) {
            $this->seedTenant($tenantOpt, $io);
        } else {
            $io->title('Initialisation des prestations sur TOUS les tenants');
            $dbNames = $this->tenantManager->getAllTenantDbNames();
            foreach ($dbNames as $dbName) {
                $this->seedTenant($dbName, $io);
            }
        }

        $io->success('Toutes les prestations ont été synchronisées avec succès !');
        return Command::SUCCESS;
    }

    private function seedTenant(string $tenantIdentifier, SymfonyStyle $io): void
    {
        $dbName = $tenantIdentifier;
        $tenantCode = $tenantIdentifier;

        if (!str_starts_with($tenantIdentifier, 'db_')) {
            $tenantData = $this->tenantManager->findTenantByCode($tenantIdentifier);
            if ($tenantData && isset($tenantData['dbname'])) {
                $dbName = $tenantData['dbname'];
                $tenantCode = $tenantData['code'];
            } else {
                $dbName = 'db_' . $tenantIdentifier;
            }
        }

        $io->section("Traitement du tenant : {$tenantCode} (BDD: {$dbName})");

        try {
            $this->emProvider->switchTenant($dbName, $tenantCode);
            $em = $this->emProvider->getEntityManager();

            $repo = $em->getRepository(ServiceOffer::class);
            $countCreated = 0;
            $countUpdated = 0;

            foreach (self::SERVICES as $data) {
                /** @var ServiceOffer|null $existing */
                $existing = $repo->findOneBy(['titre' => $data['titre']]);

                if (!$existing) {
                    $offer = new ServiceOffer();
                    $offer->setTitre($data['titre']);
                    $offer->setTitreCommentaire($data['titreCommentaire']);
                    $offer->setDescriptions($data['descriptions']);

                    // Traduction par défaut en français
                    $translation = new ServiceOfferTranslation();
                    $translation->setLanguage('fr');
                    $translation->setTitre($data['titre']);
                    $translation->setTitreCommentaire($data['titreCommentaire']);
                    $translation->setDescriptions($data['descriptions']);
                    $offer->addTranslation($translation);

                    $em->persist($offer);
                    $countCreated++;
                    $io->writeln(" <info>[+] Ajout :</info> {$data['titre']}");
                } else {
                    $existing->setTitreCommentaire($data['titreCommentaire']);
                    $existing->setDescriptions($data['descriptions']);
                    $countUpdated++;
                    $io->writeln(" <comment>[~] Mise à jour :</comment> {$data['titre']}");
                }
            }

            $em->flush();

            // Invalidation du cache des réservations pour ce tenant
            $this->cache->invalidateTags([
                $tenantCode . '_reservations',
                $tenantCode . '_service_offers_all',
                'reservations',
                'service_offers_all',
            ]);

            $io->success("Tenant {$tenantCode} : {$countCreated} créées, {$countUpdated} mises à jour.");

        } catch (\Throwable $e) {
            $io->error("Erreur sur le tenant {$tenantCode} : " . $e->getMessage());
        }
    }
}
