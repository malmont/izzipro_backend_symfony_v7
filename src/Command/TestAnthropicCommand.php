<?php

namespace App\Command;

use App\Services\AnthropicService;
use App\Services\TenantEntityManagerProvider;
use App\MemoiresVivantes\Entity\Chapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:test-anthropic')]
class TestAnthropicCommand extends Command
{
    public function __construct(
        private AnthropicService $anthropicService,
        private TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('chapterId', InputArgument::REQUIRED, 'ID du chapitre');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chapterId = $input->getArgument('chapterId');
        
        // On force le switch sur le bon tenant pour le test
        $this->emProvider->switchTenant('db_memoiresvivantes', 'memoiresvivantes');
        $em = $this->emProvider->getEntityManager();
        
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
        if (!$chapter) {
            $output->writeln("Chapitre non trouvé");
            return Command::FAILURE;
        }

        $output->writeln("Génération en cours pour : " . $chapter->getTitle());
        
        try {
            $text = $this->anthropicService->generatePart1($chapter);
            $output->writeln("--- RÉPONSE CLAUDE ---");
            $output->writeln($text);
            $output->writeln("--- FIN RÉPONSE ---");
            $output->writeln("Taille : " . strlen($text));
        } catch (\Exception $e) {
            $output->writeln("Erreur : " . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
