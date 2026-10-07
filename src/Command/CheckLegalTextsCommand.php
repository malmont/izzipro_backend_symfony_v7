<?php

namespace App\Command;

use App\Services\LandingPageSettingsService\LegalTextPolicy;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:entreprise:check-legal-texts',
    description: 'Inventaire des textes longs de la fiche entreprise (mentions légales…) que LegalTextPolicy refuserait, par site (lecture seule)'
)]
class CheckLegalTextsCommand extends Command
{
    private const COLUMNS = ['legal_notice' => 'LegalNotice', 'condition_of_use' => 'conditionOfUse', 'privacy_policy' => 'privacyPolicy', 'apropos' => 'apropos'];

    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->connectionManager->getPdoMaster()
            ->query("SELECT dbname, MIN(code) AS code FROM tenants GROUP BY dbname ORDER BY dbname")->fetchAll(\PDO::FETCH_ASSOC);
        $refused = 0;
        foreach ($rows as $row) {
            $this->emProvider->switchTenant($row['dbname'], $row['code']);
            $connection = $this->emProvider->getEntityManager()->getConnection();
            $columns = implode(', ', array_keys(self::COLUMNS));
            $texts = [
                ...array_map(fn ($r) => ['base'] + $r, $connection->fetchAllAssociative("SELECT $columns FROM entreprise")),
                ...array_map(fn ($r) => [$r['language']] + $r, $connection->fetchAllAssociative("SELECT language, $columns FROM entreprise_translation")),
            ];
            $site = 0;
            foreach ($texts as $text) {
                foreach (self::COLUMNS as $column => $field) {
                    if (!is_string($text[$column] ?? null) || $text[$column] === '') {
                        continue;
                    }
                    $problems = LegalTextPolicy::problems($text[$column]);
                    if ($problems) {
                        $site++;
                        $io->writeln(sprintf('   <error>%s</error> %s (%s) : %d problème(s), ex. %s', $row['dbname'], $field, $text[0], count($problems), implode(' | ', array_slice($problems, 0, 3))));
                    }
                }
            }
            $refused += $site;
            $io->writeln(sprintf(' %s <info>%s</info> : %d texte(s) refusé(s)', $site ? '✗' : '✓', $row['dbname'], $site));
        }
        $refused ? $io->warning(sprintf('%d texte(s) seraient refusés à l\'enregistrement.', $refused)) : $io->success('Tous les textes passent.');

        return Command::SUCCESS;
    }
}
