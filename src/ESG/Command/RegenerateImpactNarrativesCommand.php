<?php
// src/ESG/Command/RegenerateImpactNarrativesCommand.php

namespace App\ESG\Command;

use App\ESG\Entity\CertificationRecommendation;
use App\ESG\Service\RecommendationEngine;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'esg:regen-narratives',
    description: 'Régénère le champ impactNarrative pour les recommandations de sessions complétées où il est nul.'
)]
class RegenerateImpactNarrativesCommand extends Command
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly RecommendationEngine $recommendationEngine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Nom de la base tenant à traiter', 'db_boussoleesg');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantDbName = $input->getOption('tenant');

        // Validation sécurisée de l'input tenant contre la liste des bases existantes
        $allTenants = $this->connectionManager->getAllTenantDbNames();
        if (!in_array($tenantDbName, $allTenants, true)) {
            $io->error(sprintf('Base tenant "%s" invalide ou inexistante.', $tenantDbName));
            return Command::FAILURE;
        }

        // Récupérer le code correspondant au tenant dans la base master
        $stmt = $this->connectionManager->getPdoMaster()->prepare('SELECT code FROM tenants WHERE dbname = :db');
        $stmt->execute(['db' => $tenantDbName]);
        $tenantCode = $stmt->fetchColumn() ?: null;

        $io->title(sprintf('Régénération des impactNarratives pour le tenant : %s (code : %s)', $tenantDbName, $tenantCode));

        try {
            // Switch au tenant concerné
            $this->emProvider->switchTenant($tenantDbName, $tenantCode);
            $em = $this->emProvider->getEntityManager();

            // Trouver toutes les CertificationRecommendation avec impactNarrative = null
            $recommendations = $em->getRepository(CertificationRecommendation::class)->findBy([
                'impactNarrative' => null
            ]);

            if (empty($recommendations)) {
                $io->success('Aucune recommandation avec un impactNarrative null n\'a été trouvée.');
                return Command::SUCCESS;
            }

            $io->progressStart(count($recommendations));
            $count = 0;

            foreach ($recommendations as $reco) {
                $session = $reco->getSession();
                if ($session && $session->getStatus()->value === 'completed') {
                    // Calculer et sauvegarder l'impactNarrative
                    $narrative = $this->recommendationEngine->generateImpactNarrativeForRecommendation($reco);
                    $reco->setImpactNarrative($narrative);
                    $count++;
                }
                $io->progressAdvance();
            }

            $em->flush();
            $io->progressFinish();
            $io->newLine();
            $io->success(sprintf('✅ Régénération terminée avec succès. %d recommandation(s) mise(s) à jour.', $count));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Une erreur est survenue : %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
