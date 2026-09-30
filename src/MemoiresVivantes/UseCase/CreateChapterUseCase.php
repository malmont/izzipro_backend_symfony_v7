<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\ChapterGenerationService;
use App\Services\TenantEntityManagerProvider;

class CreateChapterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ChapterGenerationService $generationService
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
        // Aucune rédaction en cours tant qu'elle n'est pas lancée : « completed » avec un texte vide, statut déjà
        // connu du frontend (« pending » signifierait « rédaction en file d'attente »)
        $chapter->setGenerationStatus('completed');

        $em->persist($chapter);
        $em->flush();

        // Rédaction immédiate seulement si le chapitre arrive avec des réponses. Le frontend crée les chapitres vides :
        // chaque création envoyait à l'IA un chapitre sans réponse (texte inventé ou refus enregistré comme chapitre).
        if ($this->generationService->cannotGenerateReason($chapter) === null) {
            $this->generationService->start($chapter, $tenantHost);
        }

        return $chapter;
    }
}
