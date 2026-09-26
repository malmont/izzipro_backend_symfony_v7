<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publie les types de livre et les questions Mémoires Vivantes d'un tenant de référence vers la base modèle
 * (gmasuite), clonée à chaque création de tenant : les nouveaux tenants démarrent avec ces types.
 *
 * Le contenu Mémoires Vivantes du modèle est remplacé intégralement (types, chapitres, rôles, consignes,
 * questions actives). Refusé si le modèle contient des livres.
 */
#[AsCommand(
    name: 'app:memoires:publish-template',
    description: 'Copie les types de livre et questions Mémoires Vivantes d\'un tenant de référence vers la base modèle des nouveaux tenants'
)]
class PublishMemoiresTemplateCommand extends Command
{
    private const TEMPLATE_DB = 'gmasuite';

    public function __construct(
        private readonly EntityManagerInterface $defaultEm
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Code du tenant de référence (base db_<code>)', 'memoiresvivantes')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Base modèle à mettre à jour', self::TEMPLATE_DB)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait copié sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDb = 'db_' . $input->getOption('from');
        $targetDb = (string) $input->getOption('to');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($sourceDb === $targetDb) {
            $io->error('La base source et la base modèle doivent être différentes.');
            return Command::FAILURE;
        }

        $source = $this->connect($sourceDb);
        $target = $this->connect($targetDb);

        $io->title(sprintf('Publication Mémoires Vivantes : %s → %s%s', $sourceDb, $targetDb, $dryRun ? ' (simulation)' : ''));

        $books = (int) $target->fetchOne('SELECT COUNT(*) FROM mv_book');
        if ($books > 0) {
            $io->error("La base {$targetDb} contient {$books} livre(s) : ce n'est pas une base modèle, publication refusée.");
            return Command::FAILURE;
        }

        $types = $source->fetchAllAssociative('SELECT * FROM mv_book_type ORDER BY display_order, id');
        if ($types === []) {
            $io->error("Aucun type de livre dans {$sourceDb} : rien à publier.");
            return Command::FAILURE;
        }
        $questions = $source->fetchAllAssociative(
            'SELECT book_type, theme, role, question_text, tip, display_order FROM mv_question WHERE is_active = true ORDER BY book_type, theme, display_order, id'
        );

        $before = [
            'types' => (int) $target->fetchOne('SELECT COUNT(*) FROM mv_book_type'),
            'questions' => (int) $target->fetchOne('SELECT COUNT(*) FROM mv_question'),
        ];

        $target->beginTransaction();
        try {
            $target->executeStatement('DELETE FROM mv_question');
            $target->executeStatement('DELETE FROM mv_book_type'); // chapitres et rôles supprimés en cascade

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $rows = [];
            foreach ($types as $type) {
                $oldId = $type['id'];
                $newId = (int) $target->fetchOne("SELECT nextval('mv_book_type_id_seq')");
                $this->insert($target, 'mv_book_type', ['id' => $newId, 'created_at' => $now, 'updated_at' => null] + $type);

                $chapters = $source->fetchAllAssociative('SELECT * FROM mv_book_type_chapter WHERE book_type_id = :id ORDER BY position, id', ['id' => $oldId]);
                foreach ($chapters as $chapter) {
                    $this->insert($target, 'mv_book_type_chapter', [
                        'id' => (int) $target->fetchOne("SELECT nextval('mv_book_type_chapter_id_seq')"),
                        'book_type_id' => $newId,
                        'created_at' => $now,
                        'updated_at' => null,
                    ] + $chapter);
                }

                $roles = $source->fetchAllAssociative('SELECT * FROM mv_book_type_role WHERE book_type_id = :id ORDER BY display_order, id', ['id' => $oldId]);
                foreach ($roles as $role) {
                    $this->insert($target, 'mv_book_type_role', [
                        'id' => (int) $target->fetchOne("SELECT nextval('mv_book_type_role_id_seq')"),
                        'book_type_id' => $newId,
                    ] + $role);
                }

                $typeQuestions = array_filter($questions, fn (array $q) => $q['book_type'] === $type['code']);
                $rows[] = [
                    $type['code'],
                    $type['family'],
                    $type['is_active'] ? 'oui' : 'non',
                    $type['prompt_source'],
                    count($chapters),
                    count($roles),
                    count($typeQuestions),
                ];
            }

            foreach ($questions as $question) {
                $this->insert($target, 'mv_question', $question + ['is_active' => true, 'created_at' => $now]);
            }

            $io->table(['Type', 'Famille', 'Actif', 'Consignes', 'Chapitres', 'Rôles', 'Questions'], $rows);

            $orphans = count(array_filter($questions, fn (array $q) => !in_array($q['book_type'], array_column($types, 'code'), true)));
            if ($orphans > 0) {
                $io->warning("{$orphans} question(s) copiée(s) pour des types absents de mv_book_type.");
            }

            if ($dryRun) {
                $target->rollBack();
                $io->note("Simulation : rien n'a été modifié ({$targetDb} garde {$before['types']} type(s) et {$before['questions']} question(s)).");
                return Command::SUCCESS;
            }

            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            $io->error('Publication annulée, aucune modification : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s mis à jour : %d type(s), %d question(s) (avant : %d type(s), %d question(s)). Les prochains tenants créés en hériteront.',
            $targetDb,
            count($types),
            count($questions),
            $before['types'],
            $before['questions']
        ));

        return Command::SUCCESS;
    }

    private function connect(string $dbName): Connection
    {
        $params = $this->defaultEm->getConnection()->getParams();
        $params['dbname'] = $dbName;
        unset($params['url']);

        return DriverManager::getConnection($params);
    }

    private function insert(Connection $connection, string $table, array $row): void
    {
        $types = array_map(fn ($value) => is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING, $row);
        $connection->insert($table, $row, $types);
    }
}
