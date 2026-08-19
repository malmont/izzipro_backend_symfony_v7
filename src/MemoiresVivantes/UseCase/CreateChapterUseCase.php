<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Messenger\MessageBusInterface;

class CreateChapterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly MessageBusInterface $messageBus
    ) {}

    public function execute(Book $book, ChapterInputDto $dto, string $tenantHost): Chapter
    {
        $em = $this->emProvider->getEntityManager();

        $chapter = new Chapter();
        $chapter->setBook($book);
        $chapter->setTitle($dto->title);
        $chapter->setTheme($dto->theme);
        $chapter->setPosition($dto->position);
        $chapter->setAnswers($dto->answers);
        if ($dto->contributorAnswers !== null) {
            $chapter->setContributorAnswers($dto->contributorAnswers);
        }
        $chapter->setGenerationStatus('pending');

        $em->persist($chapter);
        $em->flush();

        // Dispatch AI generation
        $this->messageBus->dispatch(new GenerateChapterMessage((string) $chapter->getId(), 1, $tenantHost));

        return $chapter;
    }
}
