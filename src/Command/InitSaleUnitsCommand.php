<?php

namespace App\Command;

use App\Entity\SaleUnit;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init-sale-units',
    description: 'Initialise les unités de vente (Lbs, Kg, etc.) sur un ou tous les tenants.',
)]
class InitSaleUnitsCommand extends Command
{
    private const UNITS = [
        1 => 'Lbs',
        2 => 'Gramme',
        3 => 'Kilo',
        4 => 'Gallon',
        5 => 'Pinte',
        6 => 'Once',
        7 => 'Litre',
        8 => 'PIED LINÉAIRE',
        9 => 'Maillon',
    ];

    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dbname', InputArgument::OPTIONAL, 'Nom de la base spécifique (ex: gmasuite ou db_larameemarineinc2). Si vide, traite tous les tenants.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dbname = $input->getArgument('dbname');

        if ($dbname) {
            $this->processDatabase($dbname, $io);
        } else {
            $io->title('Initialisation des unités sur TOUS les tenants');
            $tenants = $this->tenantManager->getAllTenantDbNames();
            
            // On ajoute aussi la base template gmasuite qui n'est pas dans la liste des tenants
            array_unshift($tenants, 'gmasuite');

            foreach ($tenants as $db) {
                $this->processDatabase($db, $io);
            }
        }

        $io->success('Initialisation terminée.');

        return Command::SUCCESS;
    }

    private function processDatabase(string $dbname, SymfonyStyle $io): void
    {
        $io->section("Traitement de la base : $dbname");

        try {
            // On switch manuellement l'EM sur cette DB
            $this->emProvider->switchTenant($dbname);
            $em = $this->emProvider->getEntityManager();

            foreach (self::UNITS as $id => $name) {
                $unit = $em->getRepository(SaleUnit::class)->find($id);
                if (!$unit) {
                    $unit = new SaleUnit();
                    $unit->setId($id);
                    $unit->setName($name);
                    $em->persist($unit);
                    $io->writeln(" - Ajout de l'unité : $name (ID $id)");
                } else {
                    $io->writeln(" - Unité déjà présente : $name");
                }
            }

            $em->flush();
            $io->success("Fin du traitement pour $dbname");

        } catch (\Throwable $e) {
            $io->error("Erreur sur $dbname : " . $e->getMessage());
        }
    }
}
