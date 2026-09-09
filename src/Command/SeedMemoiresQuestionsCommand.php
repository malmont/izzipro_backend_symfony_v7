<?php

namespace App\Command;

use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:memoires:seed-questions',
    description: 'Seed initial questions and tips for Mémoires Vivantes into database'
)]
class SeedMemoiresQuestionsCommand extends Command
{
    public function __construct(
        private readonly \App\Services\TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntityManagerInterface $defaultEm
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Tenant name (e.g. memoiresvivantes, esgboost)', 'memoiresvivantes')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Clear existing questions before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenant = $input->getOption('tenant');
        $force = $input->getOption('force');

        $tenantConfig = $this->tenantManager->findTenantConfigByHost($tenant);
        if ($tenantConfig) {
            $this->tenantManager->switchToTenant($tenantConfig);
        } else {
            $dbName = str_starts_with($tenant, 'db_') ? $tenant : 'db_' . $tenant;
            $this->emProvider->switchTenant($dbName, $tenant);
        }

        $em = $this->emProvider->getEntityManager();
        $io->title("Seeding Mémoires Vivantes questions for tenant: {$tenant}");

        $repo = $em->getRepository(MemoireQuestion::class);

        if ($force) {
            $io->warning('Clearing existing questions in mv_question...');
            $em->createQuery('DELETE FROM App\MemoiresVivantes\Entity\MemoireQuestion q')->execute();
        }

        $questionsData = $this->getInitialQuestionsData();
        $inserted = 0;

        foreach ($questionsData as $item) {
            // Avoid duplicate if not forcing
            $existing = $repo->findOneBy([
                'theme' => $item['theme'],
                'displayOrder' => $item['displayOrder']
            ]);

            if ($existing && !$force) {
                continue;
            }

            $q = $existing ?? new MemoireQuestion();
            $q->setBookType($item['bookType']);
            $q->setTheme($item['theme']);
            $q->setQuestionText($item['questionText']);
            $q->setTip($item['tip'] ?? null);
            $q->setDisplayOrder($item['displayOrder']);
            $q->setIsActive(true);

            $em->persist($q);
            $inserted++;
        }

        $em->flush();

        $io->success("Successfully seeded {$inserted} questions for Mémoires Vivantes ({$tenant})!");
        return Command::SUCCESS;
    }

    public function getInitialQuestionsData(): array
    {
        return [
            // ==========================================
            // LIVRES INDIVIDUELS
            // ==========================================
            // Thème : enfance
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 0,
                'questionText' => "Où êtes-vous né(e) ? Décrivez cet endroit tel que vous vous en souvenez.",
                'tip' => "Fermez les yeux. Que voyez-vous, entendez-vous ?"
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 1,
                'questionText' => "Qui étaient vos parents ? Qu'est-ce qui les caractérisait le mieux ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 2,
                'questionText' => "Aviez-vous des frères et sœurs ? Quelle était votre place dans la famille ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 3,
                'questionText' => "Quel est votre tout premier souvenir dans la vie ?",
                'tip' => "Même un fragment court — une image, une odeur, un son."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 4,
                'questionText' => "Comment était votre maison d'enfance ? Décrivez-la.",
                'tip' => "Les détails sensoriels sont souvent les plus vivants."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 5,
                'questionText' => "Quel enfant étiez-vous ? Sage, espiègle, timide, curieux ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 6,
                'questionText' => "Y avait-il une odeur, une saveur ou une chanson qui vous ramène à votre enfance ?",
                'tip' => "Cette question débloque souvent les souvenirs les plus vifs."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'enfance',
                'displayOrder' => 7,
                'questionText' => "Quels souvenirs gardez-vous de l'école ?",
                'tip' => null
            ],

            // Thème : adulte
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 0,
                'questionText' => "Comment avez-vous choisi votre chemin de vie ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 1,
                'questionText' => "Quel a été votre plus grand accomplissement dans votre vie adulte ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 2,
                'questionText' => "Comment avez-vous rencontré les personnes les plus importantes de votre vie ?",
                'tip' => "Un partenaire, un ami, un mentor..."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 3,
                'questionText' => "Y a-t-il un voyage ou un lieu qui vous a particulièrement marqué(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 4,
                'questionText' => "Quelle a été la période la plus difficile de votre vie ? Comment l'avez-vous traversée ?",
                'tip' => "Prenez le temps qu'il vous faut. Vous pouvez passer si vous préférez."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 5,
                'questionText' => "Quelle était votre passion en dehors du travail ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'adulte',
                'displayOrder' => 6,
                'questionText' => "Avez-vous vécu un moment inattendu qui a changé le cours de votre vie ?",
                'tip' => null
            ],

            // Thème : sagesse
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 0,
                'questionText' => "Quelle est la leçon de vie la plus importante que vous ayez apprise ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 1,
                'questionText' => "Y a-t-il quelque chose que vous feriez différemment si vous pouviez recommencer ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 2,
                'questionText' => "Si vous pouviez rencontrer le jeune homme ou la jeune femme que vous étiez à 15 ou 20 ans, quel conseil lui donneriez-vous avant qu'il ou elle ne commence sa vie d'adulte ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 3,
                'questionText' => "Qu'est-ce qui vous a rendu le plus fier ou la plus fière dans votre vie ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 4,
                'questionText' => "Quel conseil donneriez-vous à un jeune qui commence sa vie ?",
                'tip' => null
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 5,
                'questionText' => "Comment aimeriez-vous qu'on se souvienne de vous ?",
                'tip' => "Souvent la plus belle réponse de toutes."
            ],
            [
                'bookType' => 'individuel',
                'theme' => 'sagesse',
                'displayOrder' => 6,
                'questionText' => "Y a-t-il un message que vous souhaitez laisser aux générations futures ?",
                'tip' => null
            ],

            // ==========================================
            // LIVRES DE COUPLE
            // ==========================================
            // avant_nous_1
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 0,
                'questionText' => "Où et quand êtes-vous né(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 1,
                'questionText' => "Décrivez votre famille d'origine",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 2,
                'questionText' => "Quel enfant étiez-vous ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 3,
                'questionText' => "Quel métier rêviez-vous de faire, enfant ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 4,
                'questionText' => "Qui étiez-vous avant de le/la rencontrer ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_1',
                'displayOrder' => 5,
                'questionText' => "Quel était votre rêve de jeunesse ?",
                'tip' => null
            ],

            // avant_nous_2
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 0,
                'questionText' => "Où et quand êtes-vous né(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 1,
                'questionText' => "Décrivez votre famille d'origine",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 2,
                'questionText' => "Quel enfant étiez-vous ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 3,
                'questionText' => "Quel métier rêviez-vous de faire, enfant ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 4,
                'questionText' => "Qui étiez-vous avant de le/la rencontrer ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'avant_nous_2',
                'displayOrder' => 5,
                'questionText' => "Quel était votre rêve de jeunesse ?",
                'tip' => null
            ],

            // la_rencontre
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 0,
                'questionText' => "Où et comment vous êtes-vous rencontrés ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 1,
                'questionText' => "Quelle a été votre première impression l'un de l'autre ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qui vous a fait dire « c'est la bonne personne » ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 3,
                'questionText' => "Racontez votre première sortie ensemble",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 4,
                'questionText' => "Comment s'est passée la demande ou la décision de vous engager ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'la_rencontre',
                'displayOrder' => 5,
                'questionText' => "Que pensaient vos familles de votre union ?",
                'tip' => null
            ],

            // construire_ensemble
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 0,
                'questionText' => "Décrivez votre mariage ou le début de votre vie commune",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 1,
                'questionText' => "Quel a été votre premier logement ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 2,
                'questionText' => "Comment avez-vous accueilli chaque enfant ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 3,
                'questionText' => "Quel a été le moment le plus difficile traversé ensemble ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 4,
                'questionText' => "Comment l'avez-vous surmonté ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 5,
                'questionText' => "Quel a été votre plus beau voyage ou souvenir commun ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 6,
                'questionText' => "Comment décririez-vous votre vie de famille ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'construire_ensemble',
                'displayOrder' => 7,
                'questionText' => "Qu'est-ce qui vous a fait rire le plus souvent ensemble ?",
                'tip' => null
            ],

            // ce_que_nous_avons_appris
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 0,
                'questionText' => "Qu'est-ce que votre conjoint(e) vous a appris ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 1,
                'questionText' => "Y a-t-il quelque chose que vous feriez différemment ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 2,
                'questionText' => "Quel a été votre plus grand sacrifice l'un pour l'autre ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 3,
                'questionText' => "Qu'est-ce qui vous rend le plus fiers de votre vie ensemble ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 4,
                'questionText' => "Quel conseil donneriez-vous à un jeune couple qui commence ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'ce_que_nous_avons_appris',
                'displayOrder' => 5,
                'questionText' => "Comment décririez-vous l'amour après toutes ces années ?",
                'tip' => null
            ],

            // message_final
            [
                'bookType' => 'couple',
                'theme' => 'message_final',
                'displayOrder' => 0,
                'questionText' => "Quel message voulez-vous laisser l'un à l'autre ?",
                'tip' => null
            ],
            [
                'bookType' => 'couple',
                'theme' => 'message_final',
                'displayOrder' => 1,
                'questionText' => "Quel message voulez-vous laisser à vos enfants et petits-enfants ?",
                'tip' => null
            ],

            // ==========================================
            // LIVRES DE FAMILLE
            // ==========================================
            // regards_croises
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 0,
                'questionText' => "Quel est votre souvenir le plus marquant avec lui/elle ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 1,
                'questionText' => "Ce que votre parent vous a transmis sans le savoir ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 2,
                'questionText' => "Décrivez un moment où vous avez été fier/fière de lui/elle",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 3,
                'questionText' => "Quelle qualité admirez-vous le plus chez lui/elle ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 4,
                'questionText' => "Quel conseil vous a-t-il/elle donné qui vous suit encore ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'displayOrder' => 5,
                'questionText' => "Que voulez-vous qu'il/elle sache aujourd'hui ?",
                'tip' => null
            ],

            // regards_petits_enfants
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'displayOrder' => 0,
                'questionText' => "Ton souvenir préféré avec mamie/papi ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'displayOrder' => 1,
                'questionText' => "Ce que tu aimes faire avec lui/elle ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qu'il/elle t'a appris ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'displayOrder' => 3,
                'questionText' => "Que veux-tu lui dire ?",
                'tip' => null
            ],

            // epilogue_collectif
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'displayOrder' => 0,
                'questionText' => "Message collectif de la famille à la personne célébrée",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'displayOrder' => 1,
                'questionText' => "Ce que cette famille souhaite transmettre aux générations futures",
                'tip' => null
            ],
        ];
    }
}
