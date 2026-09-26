<?php

namespace App\Command;

use App\MemoiresVivantes\BookType\LegacyBookTypeCatalog;
use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\BookTypeRole;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:memoires:import-legacy-book-types',
    description: 'Crée en base les 4 types de livre historiques (individuel, couple, famille, hommage) avec leurs chapitres, rôles et consignes de référence'
)]
class ImportLegacyBookTypesCommand extends Command
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Code du tenant (base db_<code>)', 'memoiresvivantes')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Nom exact de la base (prioritaire sur --tenant)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenant = $input->getOption('tenant');
        $dbName = $input->getOption('db') ?: 'db_' . $tenant;

        $this->emProvider->switchTenant($dbName, $tenant);
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(BookType::class);

        $io->title("Import des types de livre historiques ({$dbName})");

        foreach (LegacyBookTypeCatalog::all() as $def) {
            $type = $repo->findOneBy(['code' => $def['code']]);
            $isNew = $type === null;

            if ($isNew) {
                $type = (new BookType())
                    ->setCode($def['code'])
                    ->setLabel($def['label'])
                    ->setDescription($def['description'])
                    ->setFamily($def['family'])
                    ->setSpeakerCount($def['speakerCount'])
                    ->setSpeaker1Label($def['speaker1Label'])
                    ->setSpeaker2Label($def['speaker2Label'])
                    ->setSubjectsMayBeAbsent($def['subjectsMayBeAbsent'])
                    ->setDefaultRole($def['defaultRole'])
                    ->setDefaultRoleWhenSubjectsAbsent($def['defaultRoleWhenSubjectsAbsent'])
                    ->setPromptRaw($def['prompt'])
                    // Les types historiques restent générés par les consignes du code
                    ->setPromptSource(BookType::PROMPT_SOURCE_CODE)
                    ->setIsSystem(true)
                    ->setDisplayOrder($def['displayOrder']);
                $em->persist($type);
            }

            // Complète sans jamais écraser ce qui existe déjà (modifications de l'admin)
            $addedChapters = 0;
            foreach ($def['chapters'] as $position => $chapterDef) {
                if ($type->getChapter($chapterDef['code'])) {
                    continue;
                }
                $type->addChapter(
                    (new BookTypeChapter())
                        ->setCode($chapterDef['code'])
                        ->setTitle($chapterDef['title'])
                        ->setPosition($position + 1)
                        ->setSpeaker($chapterDef['speaker'])
                        ->setPromptRaw($chapterDef['prompt'])
                        ->setIsActive($chapterDef['active'] ?? true)
                );
                $addedChapters++;
            }

            $addedRoles = 0;
            foreach ($def['roles'] as $order => $roleDef) {
                if ($type->getRole($roleDef['code'])) {
                    continue;
                }
                $type->addRole(
                    (new BookTypeRole())
                        ->setCode($roleDef['code'])
                        ->setLabel($roleDef['label'])
                        ->setDisplayOrder($order + 1)
                );
                $addedRoles++;
            }

            $io->writeln(sprintf(
                ' %s <info>%s</info> : %d chapitre(s), %d rôle(s) ajouté(s)',
                $isNew ? '[créé]  ' : '[existe]',
                $def['code'],
                $addedChapters,
                $addedRoles
            ));
        }

        $em->flush();

        $io->success('Types de livre historiques importés.');
        return Command::SUCCESS;
    }
}
