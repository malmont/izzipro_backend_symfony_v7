<?php
// src/Command/MigrateTranslationsCommand.php

namespace App\Command;

use App\Entity\TranslatableInterface;

use App\Services\GoogleTranslateService\GoogleTranslateService;
use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateTranslationsCommand extends Command
{
    protected static $defaultName = 'app:migrate-translations';

    private GoogleTranslateService $translator;
    private TenantEntityManagerProvider $tenantEmProvider;
    private TenantConnectionManager $connectionManager;
    private TenantConnectionProvider $connectionProvider;

    public function __construct(
        GoogleTranslateService $translator,
        TenantEntityManagerProvider $tenantEmProvider,
        TenantConnectionManager $connectionManager,
        TenantConnectionProvider $connectionProvider
    ) {
        $this->translator = $translator;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->connectionManager = $connectionManager;
        $this->connectionProvider = $connectionProvider;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Migre les données de l\'entité principale vers les tables de traduction (FR et EN) en utilisant Google Translate.')
            ->addArgument('entityClass', InputArgument::REQUIRED, 'La classe de l\'entité à migrer (ex: App\Entity\Product)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Le nom de la base de données (dbname) du tenant à traduire. Si omis, tous les tenants seront traités.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $io = new SymfonyStyle($input, $output);
        $entityClass = $input->getArgument('entityClass');
        $tenantDbName = $input->getOption('tenant');

        $tenantsToProcess = [];
        if ($tenantDbName) {
            $tenantsToProcess[] = $tenantDbName;
            $io->info("Ciblage du tenant unique (DB) : $tenantDbName");
        } else {
            $io->info("Ciblage de TOUS les tenants...");
            $tenantsToProcess = $this->connectionManager->getAllTenantDbNames();
        }

        $io->note("Nombre de bases de données à traiter : " . count($tenantsToProcess) . " tenant(s)");

        foreach ($tenantsToProcess as $currentTenantDbName) {
            $io->section("Traitement du tenant (DB: '$currentTenantDbName')");

            try {
                $this->connectionProvider->switchTenant($currentTenantDbName);
                $em = $this->tenantEmProvider->getEntityManager();
                $repository = $em->getRepository($entityClass);
                $entities = $repository->findAll();
                $io->writeln(count($entities) . " entité(s) trouvée(s).");
                foreach ($entities as $entity) {
                    if (!$entity instanceof TranslatableInterface) {
                        continue;
                    }
                    $this->translateEntity($io, $entity);
                }
                $io->text('Sauvegarde des modifications pour le tenant...');
                $em->flush();
                $io->writeln("Tenant '$currentTenantDbName' traité avec succès.");

            } catch (\Exception $e) {
                $io->error("Une erreur est survenue lors du traitement du tenant '$currentTenantDbName': " . $e->getMessage());
                continue;
            }
        }

        $io->success('Opération de migration terminée pour tous les tenants ciblés !');
        return Command::SUCCESS;
    }


    private function translateEntity(SymfonyStyle $io, TranslatableInterface $entity): void
    {

        
        $io->writeln("  -> Entité ID: {$entity->getId()}");
        $fields = $entity->getTranslatableFields();
        $translationClass = $entity->getTranslationEntityClass();
        
        if ($entity->findTranslationByLocale('fr') === null) {
            $frenchTranslation = new $translationClass();
            $this->setLocaleOrLanguage($frenchTranslation, 'fr');
            foreach ($fields as $field) {
                $getter = 'get' . ucfirst($field); $setter = 'set' . ucfirst($field);
                if (method_exists($entity, $getter) && method_exists($frenchTranslation, $setter)) {
                    $sourceValue = $entity->$getter();
                    if (is_string($sourceValue) || is_null($sourceValue)) {
                        $frenchTranslation->$setter($sourceValue);
                    }
                }
            }
            $entity->addTranslation($frenchTranslation);
            $io->info("     -> Traduction FR créée (copie).");
        } else {
            $io->writeln("     -> Traduction FR existait déjà.");
        }

        if ($entity->findTranslationByLocale('en') === null) {
            $englishTranslation = new $translationClass();
            $this->setLocaleOrLanguage($englishTranslation, 'en');
            foreach ($fields as $field) {
                $getter = 'get' . ucfirst($field); $setter = 'set' . ucfirst($field);
                if (method_exists($entity, $getter) && method_exists($englishTranslation, $setter)) {
                    $sourceValue = $entity->$getter();
                    if ($sourceValue === null) {
                        $englishTranslation->$setter(null);
                    } elseif (is_string($sourceValue)) {
                        $translatedText = $this->translator->translate($sourceValue, 'en', 'fr');
                        $englishTranslation->$setter($translatedText);
                    }
                }
            }
            $entity->addTranslation($englishTranslation);
            $io->info("     -> Traduction EN créée (via API).");
        } else {
            $io->writeln("     -> Traduction EN existait déjà.");
        }
    }


    private function setLocaleOrLanguage(object $translationEntity, string $locale): void
    {

        if (method_exists($translationEntity, 'setLocale')) {
            $translationEntity->setLocale($locale);
        } elseif (method_exists($translationEntity, 'setLanguage')) {
            $translationEntity->setLanguage($locale);
        }
    }
}