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

    /**
     * Texte d'une réponse retenu pour la rédaction : la version améliorée par l'IA si elle existe et que
     * l'utilisateur ne l'a pas écartée (useImproved = false), sinon la réponse saisie. Une clé « improvedAnswer »
     * vide ne masque jamais la réponse saisie.
     */
    public static function answerText(array $entry): string
    {
        $improved = $entry['improvedAnswer'] ?? $entry['improved_answer'] ?? '';
        $improved = is_string($improved) ? trim($improved) : '';
        $answer = $entry['answer'] ?? '';
        $answer = is_string($answer) ? trim($answer) : '';

        // Livres « couple » : une réponse par voix (« Elle: … » / « Lui: … ») et un choix par voix (useImproved1/2)
        if (array_key_exists('useImproved1', $entry) || array_key_exists('useImproved2', $entry)) {
            return self::coupleText($answer, $improved, $entry);
        }

        if ($improved !== '' && ($entry['useImproved'] ?? true) !== false && !self::isLabelsOnly($improved)) {
            return $improved;
        }

        return self::isLabelsOnly($answer) ? '' : $answer;
    }

    /**
     * Compose la réponse d'un couple voix par voix : version améliorée de la voix si elle existe et n'est pas écartée,
     * sinon version saisie. Le frontend envoie toujours les deux textes sous la forme « Prénom: texte », même vides.
     */
    private static function coupleText(string $answer, string $improved, array $entry): string
    {
        $rawVoices = self::voices($answer);
        $improvedVoices = self::voices($improved);
        if ($rawVoices === [] && $improvedVoices === []) {
            return '';
        }

        $lines = [];
        $position = 0;
        foreach (array_keys($rawVoices + $improvedVoices) as $label) {
            $position++;
            $useImproved = ($entry['useImproved' . $position] ?? true) !== false;
            $text = $useImproved && trim($improvedVoices[$label] ?? '') !== '' ? $improvedVoices[$label] : ($rawVoices[$label] ?? '');
            if (trim($text) !== '') {
                $lines[] = $label === '' ? trim($text) : $label . ' : ' . trim($text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Découpe « Elle: texte ⏎ Lui: texte » en [étiquette => texte]. Un texte sans étiquette est rendu sous une clé vide.
     *
     * @return array<string, string>
     */
    private static function voices(string $text): array
    {
        $text = trim(str_replace("\r", '', $text));
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/^([^\n:]{1,40}):[ \t]*(.*?)(?=^[^\n:]{1,40}:|\z)/msu', $text, $matches, PREG_SET_ORDER) || $matches === []) {
            return ['' => $text];
        }

        $voices = [];
        foreach ($matches as [, $label, $part]) {
            $voices[trim($label)] = trim(($voices[trim($label)] ?? '') . ' ' . trim($part));
        }

        return $voices;
    }

    /** « Elle: ⏎ Lui: » : des étiquettes sans aucun texte, ce n'est pas une réponse */
    private static function isLabelsOnly(string $text): bool
    {
        return $text !== '' && trim((string) preg_replace('/^[^\n:]{1,40}:[ \t]*$/mu', '', $text)) === '';
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
