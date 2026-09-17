<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;

class HommageAggregationService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * Vérifie si les conditions sont réunies pour générer un chapitre de synthèse Hommage.
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
        foreach ($book->getChapters() as $otherChapter) {
            // Si c'est le chapitre de synthèse lui-même, passer
            if ($otherChapter->getId() && $chapter->getId() && (string)$otherChapter->getId() === (string)$chapter->getId()) {
                continue;
            }

            $contribAnswers = $otherChapter->getContributorAnswers();
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

            $soloAnswers = $otherChapter->getAnswers();
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
                'reason' => 'Les témoignages des proches doivent être saisis dans les autres chapitres (notamment « Les voix ») avant de pouvoir générer ce chapitre de synthèse.'
            ];
        }

        return ['canGenerate' => true, 'reason' => null];
    }

    /**
     * Agrège l'ensemble des métadonnées du défunt et des témoignages des proches
     * sous une forme structurée prête à être injectée dans le prompt d'Anthropic.
     */
    public function aggregateForSynthesis(Chapter $currentChapter): array
    {
        $book = $currentChapter->getBook();
        $em = $this->emProvider->getEntityManager();

        // Questions en base pour enrichir le contexte
        $questionEntities = $em->getRepository(MemoireQuestion::class)->findBy([
            'bookType' => 'hommage',
            'isActive' => true,
        ]);
        $questionMap = [];
        foreach ($questionEntities as $q) {
            $key = ($q->getRole() ? $q->getRole() . '_' : '') . $q->getDisplayOrder();
            $questionMap[$q->getTheme()][$key] = $q->getQuestionText();
        }

        $deceasedInfo = [
            'name' => $book->getPerson1FirstName() ?: $book->getTitle(),
            'birthplace' => $book->getBirthplace() ?: $book->getPerson1Birthplace(),
            'birthYear' => $book->getBirthYear(),
            'deathYear' => $book->getDeathYear(),
            'epigraph' => $book->getEpigraph(),
        ];

        $testimoniesByContributor = [];

        foreach ($book->getChapters() as $chapter) {
            $theme = $chapter->getTheme();
            $chapterTitle = $chapter->getTitle();

            // 1. Réponses par contributeurs
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

            // 2. Réponses directes du chapitre (ex: si des questions collectives ont été renseignées)
            $directAnswers = $chapter->getAnswers();
            if (is_array($directAnswers) && !empty($directAnswers) && empty($contribAnswers)) {
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
                    $testimoniesByContributor['Collectif']['chapters'][$chapterTitle] = $formattedAnswers;
                }
            }
        }

        // Construire la chaîne de texte consolidée
        $formattedText = "=== INFORMATIONS SUR LA PERSONNE CÉLÉBRÉE ===\n";
        $formattedText .= "Prénom/Nom : {$deceasedInfo['name']}\n";
        if ($deceasedInfo['birthplace']) $formattedText .= "Lieu de naissance : {$deceasedInfo['birthplace']}\n";
        if ($deceasedInfo['birthYear']) $formattedText .= "Année de naissance : {$deceasedInfo['birthYear']}\n";
        if ($deceasedInfo['deathYear']) $formattedText .= "Année de décès : {$deceasedInfo['deathYear']}\n";
        if ($deceasedInfo['epigraph']) $formattedText .= "Phrase d'exergue de la famille : « {$deceasedInfo['epigraph']} »\n";
        $formattedText .= "\n=== TÉMOIGNAGES CROISÉS RECUEILLIS AUPRÈS DES PROCHES ===\n\n";

        foreach ($testimoniesByContributor as $name => $data) {
            $role = $data['role'] ?? 'proche';
            $formattedText .= "--- Témoin : {$name} (Lien : {$role}) ---\n";
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
            'deceased' => $deceasedInfo,
            'contributors' => $testimoniesByContributor,
            'formattedContext' => $formattedText,
        ];
    }
}
