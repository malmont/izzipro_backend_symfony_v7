<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;

/**
 * Fournit les questions à afficher pour un livre ou un chapitre.
 *
 * Questions actives du type + questions archivées auxquelles ce livre a déjà répondu :
 * une question archivée par l'admin disparaît pour les nouveaux livres, mais un livre
 * qui y a répondu continue de voir sa réponse (les réponses sont appariées par texte).
 */
class ChapterQuestionProvider
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * @return array<string, array<int, array>> questions au format front, groupées par thème
     */
    public function forBook(Book $book, ?string $role): array
    {
        if (!$book->getType()) {
            return [];
        }

        $answeredTextsByTheme = [];
        foreach ($book->getChapters() as $chapter) {
            $answeredTextsByTheme[$chapter->getTheme()] = array_merge(
                $answeredTextsByTheme[$chapter->getTheme()] ?? [],
                self::answeredQuestionTexts($chapter)
            );
        }

        $questionsByTheme = [];
        foreach ($this->findQuestions($book->getType(), null, $role, $answeredTextsByTheme) as $q) {
            $questionsByTheme[$q->getTheme()][] = $q->toFrontArray();
        }

        return $questionsByTheme;
    }

    /**
     * @return array<int, array> questions du chapitre au format front
     */
    public function forChapter(Chapter $chapter, ?string $role): array
    {
        if (!$chapter->getTheme()) {
            return [];
        }

        $bookType = $chapter->getBook()?->getType() ?: null;
        $answeredTexts = [$chapter->getTheme() => self::answeredQuestionTexts($chapter)];

        return array_map(
            fn (MemoireQuestion $q) => $q->toFrontArray(),
            $this->findQuestions($bookType, $chapter->getTheme(), $role, $answeredTexts)
        );
    }

    /**
     * @param array<string, string[]> $answeredTextsByTheme
     * @return MemoireQuestion[]
     */
    private function findQuestions(?string $bookType, ?string $theme, ?string $role, array $answeredTextsByTheme): array
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(MemoireQuestion::class);

        $qb = $repo->createQueryBuilder('q')
            ->where('q.isActive = true');
        $this->applyFilters($qb, $bookType, $theme, $role);
        $qb->orderBy('q.displayOrder', 'ASC');
        $questions = $qb->getQuery()->getResult();

        $answeredTextsByTheme = array_filter($answeredTextsByTheme);
        if (empty($answeredTextsByTheme)) {
            return $questions;
        }

        $qb = $repo->createQueryBuilder('q')
            ->where('q.isActive = false');
        $this->applyFilters($qb, $bookType, $theme, $role);
        $archived = array_filter(
            $qb->getQuery()->getResult(),
            fn (MemoireQuestion $q) => in_array($q->getQuestionText(), $answeredTextsByTheme[$q->getTheme()] ?? [], true)
        );

        if (empty($archived)) {
            return $questions;
        }

        $questions = array_merge($questions, array_values($archived));
        usort($questions, fn (MemoireQuestion $a, MemoireQuestion $b) => $a->getDisplayOrder() <=> $b->getDisplayOrder());

        return $questions;
    }

    private function applyFilters($qb, ?string $bookType, ?string $theme, ?string $role): void
    {
        if ($theme !== null) {
            $qb->andWhere('q.theme = :theme')
               ->setParameter('theme', $theme);
        }
        if ($bookType !== null) {
            $qb->andWhere('q.bookType = :bookType')
               ->setParameter('bookType', $bookType);
        }
        if ($role !== null) {
            $qb->andWhere('(q.role IS NULL OR q.role = :role)')
               ->setParameter('role', $role);
        }
    }

    /**
     * Textes des questions auxquelles le chapitre a une réponse non vide (réponses directes et contributeurs).
     *
     * @return string[]
     */
    public static function answeredQuestionTexts(Chapter $chapter): array
    {
        return self::answeredQuestionTextsFromData($chapter->getAnswers(), $chapter->getContributorAnswers());
    }

    /**
     * @return string[]
     */
    public static function answeredQuestionTextsFromData(mixed $answers, mixed $contributorAnswers): array
    {
        $entries = is_array($answers) ? $answers : [];
        foreach (is_array($contributorAnswers) ? $contributorAnswers : [] as $contrib) {
            if (is_array($contrib) && is_array($contrib['answers'] ?? null)) {
                $entries = array_merge($entries, $contrib['answers']);
            }
        }

        $texts = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['question']) && self::isAnswered($entry)) {
                $texts[] = $entry['question'];
            }
        }

        return array_values(array_unique($texts));
    }

    public static function isAnswered(array $entry): bool
    {
        foreach (['answer', 'improvedAnswer', 'improved_answer', 'audioUrl', 'audioUrl1', 'audioUrl2'] as $field) {
            if (isset($entry[$field]) && is_string($entry[$field]) && trim($entry[$field]) !== '') {
                return true;
            }
        }
        return false;
    }
}
