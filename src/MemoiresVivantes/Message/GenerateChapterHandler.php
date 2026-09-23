<?php

namespace App\MemoiresVivantes\Message;

use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\Services\AnthropicService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\HommageAggregationService;
use App\MemoiresVivantes\Services\FamilleAggregationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class GenerateChapterHandler
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenantManager,
        private readonly AnthropicService $anthropicService,
        private readonly HommageAggregationService $hommageAggregationService,
        private readonly FamilleAggregationService $familleAggregationService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(GenerateChapterMessage $message): void
    {
        // 1. Trouver le tenant via le manager existant
        $pdoMaster = $this->tenantManager->getPdoMaster();
        $stmt = $pdoMaster->prepare('SELECT dbname, code FROM tenants WHERE custom_domain = :h OR custom_domain = :a OR code = :c');
        $altHost = str_starts_with($message->tenantHost, 'www.') ? substr($message->tenantHost, 4) : 'www.' . $message->tenantHost;
        $stmt->execute(['h' => $message->tenantHost, 'a' => $altHost, 'c' => explode('.', $message->tenantHost)[0]]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !is_array($row)) {
            $this->logger->error("GenerateChapterHandler: Impossible de trouver le tenant pour le host {$message->tenantHost}. Row: " . json_encode($row));
            return;
        }

        // 2. Switcher de tenant comme dans TenantSetupController
        $this->logger->info("GenerateChapterHandler: Switching to tenant {$row['code']} (DB: {$row['dbname']})");
        $this->emProvider->switchTenant((string)$row['dbname'], (string)$row['code']);
        $em = $this->emProvider->getEntityManager();
        
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($message->chapterId));

        if (!$chapter) {
            $this->logger->error("GenerateChapterHandler: Chapter {$message->chapterId} non trouvé sur le tenant {$row['code']}");
            return;
        }

        $answers = $chapter->getAnswers();
        $this->logger->info("GenerateChapterHandler: Chapter {$message->chapterId} answers count: " . count($answers));
        $this->logger->info("GenerateChapterHandler: Chapter {$message->chapterId} raw answers: " . json_encode($answers));

        try {
            $model = $message->model ?? null;

            if ($message->part === 1) {
                $chapter->setGenerationStatus('generating_part1');
                $em->flush();

                $tone = isset($message->tone) ? $message->tone : 'intime et chaleureux';

                $bookType = $chapter->getBook()->getType();
                if ($bookType === 'hommage') {
                    $theme = $chapter->getTheme();
                    $aggregatedContext = null;
                    if ($theme === 'portrait_croise' || $theme === 'une_vie') {
                        $aggregatedContext = $this->hommageAggregationService->aggregateForSynthesis($chapter);
                    }
                    $text = $this->anthropicService->generatePart1Hommage($chapter, $tone, $aggregatedContext, $model);
                } elseif ($bookType === 'couple') {
                    $text = $this->anthropicService->generatePart1Couple($chapter, $tone, $model);
                } elseif ($bookType === 'famille') {
                    $theme = $chapter->getTheme();
                    $aggregatedContext = null;
                    if ($theme === 'histoire_parents' || $theme === 'histoire_aine') {
                        $aggregatedContext = $this->familleAggregationService->aggregateForSynthesis($chapter);
                    }
                    $text = $this->anthropicService->generatePart1Famille($chapter, $tone, $aggregatedContext, $model);
                } else {
                    $text = $this->anthropicService->generatePart1($chapter, $tone, $model);
                }
                
                $text = $this->anthropicService->checkAndComplete($text, false, $model);

                $chapter->setContentPart1($text);
                $chapter->setGenerationStatus('part1_done');
                $em->flush();

                $this->messageBus->dispatch(new GenerateChapterMessage($message->chapterId, 2, $message->tenantHost, $tone, $model));
            } elseif ($message->part === 2) {
                $chapter->setGenerationStatus('generating_part2');
                $em->flush();

                $tone = isset($message->tone) ? $message->tone : 'intime et chaleureux';

                $bookType = $chapter->getBook()->getType();
                if ($bookType === 'hommage') {
                    $theme = $chapter->getTheme();
                    $aggregatedContext = null;
                    if ($theme === 'portrait_croise' || $theme === 'une_vie') {
                        $aggregatedContext = $this->hommageAggregationService->aggregateForSynthesis($chapter);
                    }
                    $text = $this->anthropicService->generatePart2Hommage($chapter, $tone, $aggregatedContext, $model);
                } elseif ($bookType === 'couple') {
                    $text = $this->anthropicService->generatePart2Couple($chapter, $tone, $model);
                } elseif ($bookType === 'famille') {
                    $theme = $chapter->getTheme();
                    $aggregatedContext = null;
                    if ($theme === 'histoire_parents' || $theme === 'histoire_aine') {
                        $aggregatedContext = $this->familleAggregationService->aggregateForSynthesis($chapter);
                    }
                    $text = $this->anthropicService->generatePart2Famille($chapter, $tone, $aggregatedContext, $model);
                } else {
                    $text = $this->anthropicService->generatePart2($chapter, $tone, $model);
                }

                $text = $this->anthropicService->checkAndComplete($text, true, $model);

                $chapter->setContentPart2($text);
                $fullContent = $chapter->getContentPart1() . "\n\n" . $text;
                $chapter->setContentGenerated($fullContent);
                $chapter->setContentFinal($fullContent);
                $chapter->setGenerationStatus('completed');
                $em->flush();
            }
        } catch (\Exception $e) {
            $this->logger->error("GenerateChapterHandler Error: " . $e->getMessage());
            $chapter->setGenerationStatus('failed');
            $chapter->setGenerationError($e->getMessage());
            $em->flush();
        }
    }
}
