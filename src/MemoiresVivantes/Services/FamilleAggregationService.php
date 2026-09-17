<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;

class FamilleAggregationService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * Vérifie si les conditions sont réunies pour générer un chapitre de synthèse Famille.
     *
     * @return array{canGenerate: bool, reason: ?string}
     */
    public function checkCanGenerateSynthesis(Chapter $chapter): array
    {
        $book = $chapter->getBook();
        if (!$book) {
            return ['canGenerate' => false, 'reason' => 'Livre introuvable.'];
        }

        $hasTestimonies = false;

        foreach ($book->getChapters() as $ch) {
            $contribAnswers = $ch->getContributorAnswers();
            if (is_array($contribAnswers) && !empty($contribAnswers)) {
                foreach ($contribAnswers as $contrib) {
                    if (!empty($contrib['answers'])) {
                        foreach ($contrib['answers'] as $ans) {
                            $text = trim($ans['improvedAnswer'] ?? $ans['answer'] ?? '');
                            if ($text !== '') {
                                $hasTestimonies = true;
                                break 3;
                            }
                        }
                    }
                }
            }

            $soloAnswers = $ch->getAnswers();
            if (is_array($soloAnswers) && !empty($soloAnswers)) {
                foreach ($soloAnswers as $ans) {
                    $text = trim($ans['improvedAnswer'] ?? $ans['answer'] ?? '');
                    if ($text !== '') {
                        $hasTestimonies = true;
                        break 2;
                    }
                }
            }
        }

        if (!$hasTestimonies) {
            return [
                'canGenerate' => false,
                'reason' => 'Les témoignages des proches ou des parents doivent être saisis avant de pouvoir générer le chapitre sur l\'histoire des parents.'
            ];
        }

        return ['canGenerate' => true, 'reason' => null];
    }

    /**
     * Agrège l'ensemble des réponses des parents et des enfants pour nourrir la rédaction
     * de la saga familiale et de l'histoire des parents.
     */
    public function aggregateForSynthesis(Chapter $currentChapter): array
    {
        $book = $currentChapter->getBook();
        $em = $this->emProvider->getEntityManager();

        $questionEntities = $em->getRepository(MemoireQuestion::class)->findBy([
            'bookType' => 'famille',
            'isActive' => true,
        ]);
        $questionMap = [];
        foreach ($questionEntities as $q) {
            $key = ($q->getRole() ? $q->getRole() . '_' : '') . $q->getDisplayOrder();
            $questionMap[$q->getTheme()][$key] = $q->getQuestionText();
        }

        $parent1Name = $book->getPerson1FirstName() ?: 'Notre père';
        $parent2Name = $book->getPerson2FirstName() ?: 'Notre mère';
        $parentsInfo = [
            'parent1' => $parent1Name,
            'parent2' => $parent2Name,
            'title' => $book->getTitle(),
            'subtitle' => $book->getSubtitle(),
            'birthplace' => $book->getBirthplace(),
        ];

        $testimoniesByContributor = [];

        foreach ($book->getChapters() as $chapter) {
            $theme = $chapter->getTheme();
            $chapterTitle = $chapter->getTitle();

            $contribAnswers = $chapter->getContributorAnswers();
            if (is_array($contribAnswers)) {
                foreach ($contribAnswers as $contrib) {
                    $contribName = $contrib['contributorName'] ?? $contrib['firstName'] ?? 'Un proche';
                    $role = $contrib['role'] ?? 'proche';

                    if (!isset($testimoniesByContributor[$contribName])) {
                        $testimoniesByContributor[$contribName] = [
                            'name' => $contribName,
                            'role' => $role,
                            'chapters' => []
                        ];
                    }

                    $answers = $contrib['answers'] ?? [];
                    $formattedAnswers = [];
                    foreach ($answers as $ans) {
                        $idx = $ans['index'] ?? 0;
                        $ansText = trim($ans['improvedAnswer'] ?? $ans['answer'] ?? '');
                        if ($ansText === '') continue;

                        $roleKey = $role . '_' . $idx;
                        $qText = $questionMap[$theme][$roleKey] ?? $questionMap[$theme][(string)$idx] ?? ("Question " . ($idx + 1));

                        $formattedAnswers[] = [
                            'question' => $qText,
                            'answer' => $ansText,
                        ];
                    }

                    if (!empty($formattedAnswers)) {
                        $testimoniesByContributor[$contribName]['chapters'][$chapterTitle] = $formattedAnswers;
                    }
                }
            }

            $directAnswers = $chapter->getAnswers();
            if (is_array($directAnswers) && !empty($directAnswers)) {
                $formattedAnswers = [];
                foreach ($directAnswers as $ans) {
                    $idx = $ans['index'] ?? 0;
                    $ansText = trim($ans['improvedAnswer'] ?? $ans['answer'] ?? '');
                    if ($ansText === '') continue;

                    $qText = $questionMap[$theme][(string)$idx] ?? ("Question " . ($idx + 1));
                    $formattedAnswers[] = [
                        'question' => $qText,
                        'answer' => $ansText,
                    ];
                }
                if (!empty($formattedAnswers)) {
                    $testimoniesByContributor['Collectif / Parents']['chapters'][$chapterTitle] = $formattedAnswers;
                }
            }
        }

        $formattedText = "=== INFORMATIONS SUR LA FAMILLE ET LES PARENTS ===\n";
        $formattedText .= "Parents célébrés : {$parent1Name} et {$parent2Name}\n";
        if ($parentsInfo['birthplace']) $formattedText .= "Lieu / Foyer d'origine : {$parentsInfo['birthplace']}\n";
        $formattedText .= "\n=== SOUVENIRS ET TÉMOIGNAGES RECUEILLIS AU SEIN DE LA FAMILLE ===\n\n";

        foreach ($testimoniesByContributor as $name => $data) {
            $role = $data['role'] ?? 'famille';
            $formattedText .= "--- Contributeur : {$name} (Lien : {$role}) ---\n";
            foreach ($data['chapters'] as $chTitle => $qas) {
                $formattedText .= "[Chapitre : {$chTitle}]\n";
                foreach ($qas as $qa) {
                    $formattedText .= "Q : {$qa['question']}\nR : {$qa['answer']}\n";
                }
                $formattedText .= "\n";
            }
            $formattedText .= "\n";
        }

        return [
            'parents' => $parentsInfo,
            'contributors' => $testimoniesByContributor,
            'formattedContext' => $formattedText,
        ];
    }
}
