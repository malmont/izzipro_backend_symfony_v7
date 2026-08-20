<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Services\TenantEntityManagerProvider;
use App\MemoiresVivantes\Entity\Chapter;
use Symfony\Component\Uid\Uuid;
use App\MemoiresVivantes\Message\GenerateChapterMessage;

#[AsCommand(name: 'app:trigger-gen')]
class TriggerGenCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TenantEntityManagerProvider $emProvider
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
        
        $this->emProvider->switchTenant('db_memoiresvivantes', 'memoiresvivantes');
        $em = $this->emProvider->getEntityManager();
        
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
        if (!$chapter) {
            $output->writeln("Chapitre non trouvé");
            return Command::FAILURE;
        }

        $chapter->setGenerationStatus('pending');
        $chapter->setContentPart1(null);
        $chapter->setContentPart2(null);
        $chapter->setContentGenerated(null);
        $chapter->setContentFinal(null);
        $em->flush();

        $this->messageBus->dispatch(new GenerateChapterMessage((string)$chapter->getId(), 1, 'memoiresvivantes.arkanoa-media.com', 'intime et chaleureux'));

        $output->writeln("Regeneration triggered successfully via MessageBus!");
        return Command::SUCCESS;
    }
}
