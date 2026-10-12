<?php

namespace App\Command;

use App\Services\SiteContentService\ReferenceContentBuilder;
use App\Services\SiteContentService\ReferenceImageGenerator;
use App\Services\SiteContentService\SiteContentCopier;
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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Construit le contenu d'exemple du site de référence (gmasuite, modèle de tout nouveau site) depuis
 * config/site-content/reference.php : images sans texte (assets/uploads/<dossier>/exemple-*.jpg, communes à tous les
 * sites), puis fiches de la landing page et de la boutique, marquées is_demo. Une ressource déjà remplie est laissée
 * telle quelle : la commande se relance sans doublon (pour refaire une ressource, vider d'abord sa table).
 *
 * --referentials-from=<base> : copie aussi les référentiels de la boutique (statuts de commande et de paiement, moyens
 * et types de paiement, types et sources de commande, mouvements de stock, taxes, transporteurs, unités de vente),
 * avec leurs identifiants, dans les tables encore vides.
 *
 * Seulement sur la base de référence ou une base de test (nom finissant par _test) : jamais sur un site client.
 */
#[AsCommand(
    name: 'app:site-content:build-reference',
    description: 'Écrit le contenu d\'exemple (landing page et boutique) dans la base de référence des nouveaux sites'
)]
class BuildReferenceContentCommand extends Command
{
    /** Référentiels de la boutique, dans l'ordre de copie (traduction : table <nom>_translation si elle existe) */
    private const REFERENTIALS = ['status_commande', 'status_payment', 'payment_method', 'payment_type', 'order_type', 'order_source', 'movement_type', 'tax', 'carrier', 'sale_unit'];

    public function __construct(
        private readonly EntityManagerInterface $defaultEm,
        private readonly SiteContentCopier $copier,
        private readonly ReferenceContentBuilder $builder,
        private readonly ReferenceImageGenerator $images,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Base à remplir (par défaut : la base de référence)')
            ->addOption('referentials-from', null, InputOption::VALUE_REQUIRED, 'Base d\'où copier les référentiels de la boutique')
            ->addOption('redraw', null, InputOption::VALUE_NONE, 'Redessine les images même si elles existent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetDb = (string) ($input->getOption('to') ?: $this->copier->referenceDb());
        if ($targetDb !== $this->copier->referenceDb() && !str_ends_with($targetDb, '_test')) {
            $io->error(sprintf('Base refusée : seulement la base de référence (%s) ou une base de test (*_test).', $this->copier->referenceDb()));

            return Command::FAILURE;
        }
        $definition = require $this->projectDir . '/config/site-content/reference.php';
        $io->title(sprintf('Contenu d\'exemple → %s', $targetDb));

        $drawn = 0;
        foreach ($definition['images'] as $folder => $files) {
            foreach ($files as $file => [$width, $height, $top, $bottom]) {
                $path = sprintf('%s/var/storage/public_bucket/assets/uploads/%s/%s', $this->projectDir, $folder, $file);
                if ($input->getOption('redraw') || !is_file($path)) {
                    $this->images->generate($path, $width, $height, $top, $bottom);
                    $drawn++;
                }
            }
        }
        $io->writeln(sprintf('Images dessinées : %d', $drawn));

        $target = $this->connect($targetDb);
        if ($from = $input->getOption('referentials-from')) {
            $io->writeln('Référentiels copiés : ' . (implode(', ', $this->referentials($this->connect((string) $from), $target)) ?: 'aucun (tables déjà remplies)'));
        }
        $result = $this->builder->build($target, $definition);
        foreach ($result['created'] as $resource => $count) {
            $io->writeln(sprintf('  %-20s %d', $resource, $count));
        }
        if ($result['skipped'] !== []) {
            $io->note('Déjà remplies, laissées telles quelles : ' . implode(', ', $result['skipped']));
        }
        $io->success('Contenu d\'exemple en place.');

        return Command::SUCCESS;
    }

    /** @return list<string> tables copiées */
    private function referentials(Connection $source, Connection $target): array
    {
        $copied = [];
        $target->transactional(function () use ($source, $target, &$copied): void {
            foreach (self::REFERENTIALS as $table) {
                foreach ([$table, $table . '_translation'] as $name) {
                    if (!$this->tableExists($target, $name) || (int) $target->fetchOne("SELECT COUNT(*) FROM $name") > 0) {
                        continue;
                    }
                    $columns = $target->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$name]);
                    foreach ($source->fetchAllAssociative("SELECT * FROM $name ORDER BY id") as $row) {
                        $row = array_intersect_key($row, array_flip($columns));
                        $target->insert($name, $row, array_map(fn ($v) => is_bool($v) ? ParameterType::BOOLEAN : (is_int($v) ? ParameterType::INTEGER : ($v === null ? ParameterType::NULL : ParameterType::STRING)), $row));
                    }
                    if ($target->fetchOne("SELECT to_regclass('{$name}_id_seq')") !== null) {
                        $target->executeQuery("SELECT setval('{$name}_id_seq', COALESCE((SELECT MAX(id) FROM $name), 0) + 1, false)");
                    }
                    $copied[] = $name;
                }
            }
        });

        return $copied;
    }

    private function tableExists(Connection $db, string $table): bool
    {
        return (bool) $db->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$table]);
    }

    private function connect(string $dbName): Connection
    {
        $params = $this->defaultEm->getConnection()->getParams();
        $params['dbname'] = $dbName;
        unset($params['url']);

        return DriverManager::getConnection($params);
    }
}
