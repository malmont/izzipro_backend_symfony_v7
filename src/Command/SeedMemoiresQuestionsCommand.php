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
            $criteria = [
                'bookType' => $item['bookType'],
                'theme' => $item['theme'],
                'displayOrder' => $item['displayOrder']
            ];
            if (array_key_exists('role', $item)) {
                $criteria['role'] = $item['role'];
            }

            $existing = $repo->findOneBy($criteria);

            $q = $existing ?? new MemoireQuestion();
            $q->setBookType($item['bookType']);
            $q->setTheme($item['theme']);
            $q->setQuestionText($item['questionText']);
            $q->setTip($item['tip'] ?? null);
            $q->setDisplayOrder($item['displayOrder']);
            $q->setRole($item['role'] ?? null);
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
            // ==========================================
            // LIVRES DE FAMILLE V2 (SAGA FAMILIALE)
            // ==========================================

            // Chapitre 1 — « L'Histoire des parents & Nos racines » (histoire_parents) - TRANSVERSAL
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => null,
                'displayOrder' => 0,
                'questionText' => "Racontez les origines et les racines qui ont vu naître cette histoire de famille",
                'tip' => "D'où vient la famille, les villages ou villes d'origine, l'esprit de l'époque."
            ],

            // Chapitre 1 — « L'Histoire des parents & Nos racines » (histoire_parents) - PARENT
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'parent',
                'displayOrder' => 1,
                'questionText' => "Où et comment vous êtes-vous rencontrés ?",
                'tip' => "Racontez le lieu, la saison, votre première impression et ce qui a fait battre votre cœur."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'parent',
                'displayOrder' => 2,
                'questionText' => "Racontez vos premiers temps ensemble et l'installation de votre foyer",
                'tip' => "Le premier appartement ou la première maison, les petits débuts, les habitudes à deux."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'parent',
                'displayOrder' => 3,
                'questionText' => "Comment s'est passée la décision de fonder une famille et l'arrivée de vos enfants ?",
                'tip' => "Les joies, l'émotion de devenir parents, ce qui a changé dans votre vie."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'parent',
                'displayOrder' => 4,
                'questionText' => "Quel a été l'un des plus beaux défis ou l'une des plus belles aventures traversés ensemble ?",
                'tip' => "Un projet marquant, un voyage, une épreuve surmontée main dans la main."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'parent',
                'displayOrder' => 5,
                'questionText' => "Quel regard portez-vous aujourd'hui sur votre histoire d'amour et le chemin parcouru à deux ?",
                'tip' => "La fierté de voir grandir la famille, ce qui fait la force de votre complicité."
            ],

            // Chapitre 1 — « L'Histoire des parents & Nos racines » (histoire_parents) - ENFANT
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'enfant',
                'displayOrder' => 1,
                'questionText' => "Que vous ont-ils raconté sur leur rencontre et leurs débuts ensemble ?",
                'tip' => "La version de cette histoire que vous avez toujours entendue raconter à la maison."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'enfant',
                'displayOrder' => 2,
                'questionText' => "Qu'avez-vous appris ou deviné sur leur jeunesse avant votre naissance ?",
                'tip' => "Leurs passions d'alors, leurs débuts professionnels, leur mode de vie."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'enfant',
                'displayOrder' => 3,
                'questionText' => "Comment décririez-vous leur complicité et leur vie à deux au quotidien quand vous étiez enfant ?",
                'tip' => "La manière dont ils se complétaient, leurs regards, leurs fous rires ou leurs petits rituels à deux."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'enfant',
                'displayOrder' => 4,
                'questionText' => "Une anecdote marquante ou touchante sur votre père et votre mère ensemble",
                'tip' => "Un voyage en famille, une surprise, un moment où leur amour était évident pour tous."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'histoire_parents',
                'role' => 'enfant',
                'displayOrder' => 5,
                'questionText' => "Ce qui, selon vous, a fait la force de leur union et de votre foyer à travers les années",
                'tip' => "Leur solidité, leur respect mutuel, leur façon d'élever les enfants ensemble."
            ],

            // Compatibilité ascendante : histoire_aine (pointe vers les questions d'histoire des parents)
            [
                'bookType' => 'famille',
                'theme' => 'histoire_aine',
                'role' => null,
                'displayOrder' => 0,
                'questionText' => "Racontez l'histoire et les origines des parents à la tête de la famille",
                'tip' => null
            ],

            // Chapitre 2 — « Paroles d'enfants » (regards_croises) - PARENT
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'parent',
                'displayOrder' => 0,
                'questionText' => "Quel regard portez-vous sur l'enfance de vos enfants et ce foyer que vous avez bâti ensemble ?",
                'tip' => "L'atmosphère de la maison, les défis surmontés, la joie de voir grandir votre famille."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'parent',
                'displayOrder' => 1,
                'questionText' => "Quelle est votre plus grande fierté en les voyant aujourd'hui adultes ?",
                'tip' => "Leurs accomplissements, leurs valeurs, la complicité qui vous unit aujourd'hui."
            ],

            // Chapitre 2 — « Paroles d'enfants » (regards_croises) - ENFANT
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 0,
                'questionText' => "Quel est votre tout premier souvenir d'enfance avec vos parents ?",
                'tip' => "Une image, une odeur, un sentiment de sécurité ou une aventure de tout petit."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 1,
                'questionText' => "Décrivez l'ambiance de la maison quand vous grandissiez (les matins, les repas, les dimanches)",
                'tip' => "L'animation, la musique, les disputes d'enfants, la chaleur du foyer."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 2,
                'questionText' => "Quel souvenir particulier et précieux gardez-vous avec votre père ?",
                'tip' => "Un moment privilégié à deux, une discussion, une passion partagée."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 3,
                'questionText' => "Quel souvenir particulier et précieux gardez-vous avec votre mère ?",
                'tip' => "Un câlin, une confidence, un moment de complicité inoubliable."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 4,
                'questionText' => "Un moment où vous avez ressenti une immense fierté ou une grande admiration pour vos parents",
                'tip' => "Une réussite, une générosité, un geste courageux ou inspirant."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'enfant',
                'displayOrder' => 5,
                'questionText' => "Quelle est la phrase, le conseil ou la valeur de vos parents qui résonne encore en vous chaque jour ?",
                'tip' => "Ce qu'ils vous ont transmis et que vous transmettez à votre tour."
            ],

            // Chapitre 2 — (regards_croises) - PROCHE
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'proche',
                'displayOrder' => 0,
                'questionText' => "Comment décririez-vous cette famille et la façon dont les parents ont élevé leurs enfants ?",
                'tip' => null
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_croises',
                'role' => 'proche',
                'displayOrder' => 1,
                'questionText' => "Un souvenir chaleureux ou marquant partagé avec eux",
                'tip' => null
            ],

            // Chapitre 3 — « La relève » (regards_petits_enfants) - PETIT-ENFANT
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'role' => 'petit_enfant',
                'displayOrder' => 0,
                'questionText' => "Ton souvenir le plus doux ou le plus rigolo avec papy et mamie ?",
                'tip' => "Une bêtise partagée, une histoire du soir, un goûter inoubliable."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'role' => 'petit_enfant',
                'displayOrder' => 1,
                'questionText' => "Qu'est-ce que tu adores faire quand tu vas chez eux ?",
                'tip' => "Les jeux dans le jardin, cuisiner ensemble, bricoler ou se balader."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'role' => 'petit_enfant',
                'displayOrder' => 2,
                'questionText' => "Quelle est la chose la plus chouette qu'ils t'ont apprise ou transmise ?",
                'tip' => "Un secret de jardinier, une recette, une règle de vie."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'regards_petits_enfants',
                'role' => 'petit_enfant',
                'displayOrder' => 3,
                'questionText' => "Quel petit mot doux ou message d'amour veux-tu leur dire du fond du cœur ?",
                'tip' => "Ce que tu gardes dans ton cœur pour eux."
            ],

            // Chapitre 4 — « Ce qui fait notre famille » (rituels_et_valeurs) - TRANSVERSAL
            [
                'bookType' => 'famille',
                'theme' => 'rituels_et_valeurs',
                'role' => null,
                'displayOrder' => 0,
                'questionText' => "Quels sont les rituels, fêtes ou vacances incontournables qui rassemblent votre famille ?",
                'tip' => "Les repas de Noël, les vacances d'été chez les grands-parents, les anniversaires."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'rituels_et_valeurs',
                'role' => null,
                'displayOrder' => 1,
                'questionText' => "Le plat culte, la recette secrète ou le parfum associé à la cuisine de la maison",
                'tip' => "Le rôti du dimanche, les tartes aux fruits, les crêpes au petit-déjeuner..."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'rituels_et_valeurs',
                'role' => null,
                'displayOrder' => 2,
                'questionText' => "Les expressions cultes, répliques drôles ou petites manies typiques de votre famille",
                'tip' => "Les phrases que tout le monde répète en rigolant."
            ],
            [
                'bookType' => 'famille',
                'theme' => 'rituels_et_valeurs',
                'role' => null,
                'displayOrder' => 3,
                'questionText' => "Quelles sont les valeurs cardinales qui définissent le plus profondément votre famille ?",
                'tip' => "La solidarité, le travail, la franchise, la bienveillance, la générosité..."
            ],

            // Chapitre 5 — « Lettre d'amour & Gratitude » (epilogue_collectif) - PARENT
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'role' => 'parent',
                'displayOrder' => 0,
                'questionText' => "Quel message d'amour et de bienveillance souhaitez-vous laisser à vos enfants et petits-enfants ?",
                'tip' => "Vos souhaits et bénédictions pour leur avenir, ce que vous portez dans votre cœur pour eux."
            ],
            // Chapitre 5 — « Lettre d'amour & Gratitude » (epilogue_collectif) - ENFANT
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'role' => 'enfant',
                'displayOrder' => 0,
                'questionText' => "Quel message d'amour, de reconnaissance ou de tendresse souhaitez-vous adresser à vos parents / grands-parents ?",
                'tip' => "Un mot du cœur, un remerciement sincère pour tout ce qu'ils vous ont apporté."
            ],
            // Chapitre 5 — « Lettre d'amour & Gratitude » (epilogue_collectif) - PETIT-ENFANT
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'role' => 'petit_enfant',
                'displayOrder' => 0,
                'questionText' => "Quel message d'amour, de reconnaissance ou de tendresse souhaitez-vous adresser à vos parents / grands-parents ?",
                'tip' => "Un mot doux, un dessin ou un vœu plein de tendresse pour papy et mamie."
            ],
            // Chapitre 5 — « Lettre d'amour & Gratitude » (epilogue_collectif) - PROCHE
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'role' => 'proche',
                'displayOrder' => 0,
                'questionText' => "Quel message d'amour, de reconnaissance ou de tendresse souhaitez-vous adresser à vos parents / grands-parents ?",
                'tip' => "Vos mots chaleureux d'amitié, de tendresse et de gratitude."
            ],
            // Chapitre 5 — « Lettre d'amour & Gratitude » (epilogue_collectif) - TRANSVERSAL
            [
                'bookType' => 'famille',
                'theme' => 'epilogue_collectif',
                'role' => null,
                'displayOrder' => 1,
                'questionText' => "Quelles valeurs ou sagesses familiales souhaitez-vous transmettre et voir perdurer dans les générations futures ?",
                'tip' => "Ce que cette famille souhaite préserver et transmettre aux générations futures."
            ],

            // ==========================================
            // LIVRES HOMMAGE
            // ==========================================
            // Chapitre 2 — « Les voix » (les_voix) - ENFANT
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 0,
                'questionText' => "Quel est votre tout premier souvenir avec lui/elle ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 1,
                'questionText' => "Décrivez-le/la physiquement — sa voix, sa démarche, ses mains, sa façon d'entrer dans une pièce",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 2,
                'questionText' => "Quelle phrase répétait-il/elle tout le temps ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 3,
                'questionText' => "Racontez un moment précis où vous avez compris qui il/elle était vraiment",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 4,
                'questionText' => "Qu'est-ce qu'il/elle vous a transmis sans le vouloir ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 5,
                'questionText' => "Y a-t-il quelque chose que vous n'avez jamais eu le temps de lui dire ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 6,
                'questionText' => "Qu'est-ce qui vous manque le plus, concrètement, au quotidien ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'enfant',
                'displayOrder' => 7,
                'questionText' => "Que voulez-vous que ses petits-enfants sachent de lui/elle ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - PETIT-ENFANT
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'petit_enfant',
                'displayOrder' => 0,
                'questionText' => "Ton souvenir préféré avec lui/elle",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'petit_enfant',
                'displayOrder' => 1,
                'questionText' => "Comment il/elle t'appelait ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'petit_enfant',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce que vous faisiez ensemble ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'petit_enfant',
                'displayOrder' => 3,
                'questionText' => "Qu'est-ce qu'il/elle t'a appris ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'petit_enfant',
                'displayOrder' => 4,
                'questionText' => "Que voudrais-tu lui dire aujourd'hui ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - CONJOINT SURVIVANT
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 0,
                'questionText' => "Comment vous êtes-vous rencontrés ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 1,
                'questionText' => "Qu'est-ce qui vous a fait rester, toutes ces années ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 2,
                'questionText' => "Quel était votre rituel à vous deux ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 3,
                'questionText' => "Quelle épreuve avez-vous traversée ensemble ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 4,
                'questionText' => "Qu'est-ce que personne ne sait de lui/elle ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'conjoint',
                'displayOrder' => 5,
                'questionText' => "Que lui diriez-vous ce soir ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - FRÈRE / SŒUR
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'frere_soeur',
                'displayOrder' => 0,
                'questionText' => "Comment l'avez-vous connu(e) et quel est votre premier souvenir ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'frere_soeur',
                'displayOrder' => 1,
                'questionText' => "Quel souvenir vous revient en premier de votre jeunesse partagée ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'frere_soeur',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qui le/la rendait différent(e) des autres ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'frere_soeur',
                'displayOrder' => 3,
                'questionText' => "Une anecdote que sa famille ne connaît peut-être pas",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'frere_soeur',
                'displayOrder' => 4,
                'questionText' => "Que voulez-vous que ses enfants sachent de lui/elle ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - AMI
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'ami',
                'displayOrder' => 0,
                'questionText' => "Comment l'avez-vous connu(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'ami',
                'displayOrder' => 1,
                'questionText' => "Quel souvenir vous revient en premier ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'ami',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qui le/la rendait différent(e) des autres ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'ami',
                'displayOrder' => 3,
                'questionText' => "Une anecdote que sa famille ne connaît peut-être pas",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'ami',
                'displayOrder' => 4,
                'questionText' => "Que voulez-vous que ses proches sachent de lui/elle ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - COLLÈGUE
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'collegue',
                'displayOrder' => 0,
                'questionText' => "Dans quel contexte professionnel l'avez-vous rencontré(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'collegue',
                'displayOrder' => 1,
                'questionText' => "Quelle était sa manière de travailler et sa présence au quotidien ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'collegue',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qui le/la rendait inspirant(e) ou unique aux yeux de ses pairs ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'collegue',
                'displayOrder' => 3,
                'questionText' => "Un moment fort ou un projet marquant partagé ensemble",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'collegue',
                'displayOrder' => 4,
                'questionText' => "Que voulez-vous que sa famille sache de sa vie professionnelle ?",
                'tip' => null
            ],

            // Chapitre 2 — « Les voix » (les_voix) - RÔLE GÉNÉRIQUE PROCHE
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'proche',
                'displayOrder' => 0,
                'questionText' => "Comment l'avez-vous connu(e) ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'proche',
                'displayOrder' => 1,
                'questionText' => "Quel souvenir vous revient en premier ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'proche',
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qui le/la rendait différent(e) des autres ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'proche',
                'displayOrder' => 3,
                'questionText' => "Une anecdote ou un souvenir que sa famille ne connaît peut-être pas",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => 'proche',
                'displayOrder' => 4,
                'questionText' => "Que voulez-vous que ses proches sachent de lui/elle ?",
                'tip' => null
            ],

            // QUESTION TRANSVERSALE PHOTO (posée à chaque contributeur sans exception, role: null)
            [
                'bookType' => 'hommage',
                'theme' => 'les_voix',
                'role' => null,
                'displayOrder' => 99,
                'questionText' => "Quelle photo de lui/elle ressemble le plus à l'idée que vous en avez ?",
                'tip' => "Téléversez cette photo ou décrivez-la en quelques mots."
            ],

            // Chapitre 4 — « Ce qu'il/elle nous a laissé » (ce_quil_nous_laisse)
            [
                'bookType' => 'hommage',
                'theme' => 'ce_quil_nous_laisse',
                'role' => null,
                'displayOrder' => 0,
                'questionText' => "Quelles sont les expressions ou phrases qu'il/elle répétait tout le temps et qui vous reviennent encore ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'ce_quil_nous_laisse',
                'role' => null,
                'displayOrder' => 1,
                'questionText' => "Quels gestes, habitudes ou valeurs concrètes continuez-vous de faire vivre au quotidien ?",
                'tip' => null
            ],
            [
                'bookType' => 'hommage',
                'theme' => 'ce_quil_nous_laisse',
                'role' => null,
                'displayOrder' => 2,
                'questionText' => "Qu'est-ce qu'il/elle a laissé de plus précieux à ceux qui restent ?",
                'tip' => null
            ],

            // Épilogue — « Ce qu'on aurait voulu dire » (ce_quon_aurait_voulu_dire)
            [
                'bookType' => 'hommage',
                'theme' => 'ce_quon_aurait_voulu_dire',
                'role' => null,
                'displayOrder' => 0,
                'questionText' => "Ce que vous auriez voulu lui dire une dernière fois, ou ce que vous lui dites aujourd'hui dans votre cœur",
                'tip' => "Une phrase ou un court paragraphe adressé directement à lui/elle."
            ],
        ];
    }
}
