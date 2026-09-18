<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\Chapter;

class ChapterOutputDto
{
    public string $id;
    public string $bookId;
    public string $title;
    public string $theme;
    public int $position;
    public array $answers;
    public ?array $contributorAnswers;
    public ?string $contentFinal;
    public string $generationStatus;
    public ?string $generationError;
    public array $photos = [];
    public array $questions = [];
    public ?array $photoLayout = null;
    public ?array $photo_layout = null;
    public ?array $photoPages = null;
    public ?array $photo_pages = null;
    public ?array $currentContributor = null;
    public ?string $currentContributorId = null;
    public ?string $current_contributor_id = null;
    public bool $parentsDeceased = false;
    public bool $parents_deceased = false;
    public bool $parentsNotParticipating = false;
    public bool $parents_not_participating = false;

    public function __construct(
        Chapter $chapter,
        string $host,
        array $questions = [],
        ?string $filterContributorId = null,
        ?array $currentContributor = null
    ) {
        $this->id = (string) $chapter->getId();
        $this->bookId = (string) $chapter->getBook()->getId();
        $book = $chapter->getBook();
        if ($book) {
            $this->parentsDeceased = $book->isParentsDeceased();
            $this->parents_deceased = $book->isParentsDeceased();
            $this->parentsNotParticipating = $book->isParentsNotParticipating();
            $this->parents_not_participating = $book->isParentsNotParticipating();
        }
        $this->title = $chapter->getTitle();
        $this->theme = $chapter->getTheme();
        $this->questions = $questions;
        $this->currentContributor = $currentContributor;
        $this->currentContributorId = $filterContributorId;
        $this->current_contributor_id = $filterContributorId;
        $this->position = $chapter->getPosition();
        $rawAnswers = $chapter->getAnswers();
        $formattedAnswers = [];
        foreach ($rawAnswers as $ans) {
            if (is_array($ans)) {
                if (isset($ans['improved_answer']) && !isset($ans['improvedAnswer'])) {
                    $ans['improvedAnswer'] = $ans['improved_answer'];
                }
                
                if (isset($ans['audioUrl']) && $ans['audioUrl'] !== null && $ans['audioUrl'] !== '') {
                    $ans['audioUrl'] = $this->formatAudioUrl($ans['audioUrl'], $host);
                }
                if (isset($ans['audioUrl1']) && $ans['audioUrl1'] !== null && $ans['audioUrl1'] !== '') {
                    $ans['audioUrl1'] = $this->formatAudioUrl($ans['audioUrl1'], $host);
                }
                if (isset($ans['audioUrl2']) && $ans['audioUrl2'] !== null && $ans['audioUrl2'] !== '') {
                    $ans['audioUrl2'] = $this->formatAudioUrl($ans['audioUrl2'], $host);
                }
            }
            $formattedAnswers[] = $ans;
        }
        $allowedQuestionTexts = !empty($questions) ? array_column($questions, 'question') : [];

        if (!empty($questions)) {
            $existingAnswersByText = [];
            foreach ($formattedAnswers as $ans) {
                if (isset($ans['question'])) {
                    $existingAnswersByText[$ans['question']] = $ans;
                }
            }
            
            $synchronizedAnswers = [];
            foreach ($questions as $q) {
                $qText = $q['question'] ?? '';
                if (isset($existingAnswersByText[$qText])) {
                    $ans = $existingAnswersByText[$qText];
                    $ans['index'] = $q['index'] ?? $ans['index'] ?? 0;
                    $ans['role'] = $q['role'] ?? 'transversal';
                    $synchronizedAnswers[] = $ans;
                } else {
                    $synchronizedAnswers[] = [
                        'index' => $q['index'] ?? 0,
                        'question' => $qText,
                        'role' => $q['role'] ?? 'transversal',
                        'answer' => '',
                        'improvedAnswer' => '',
                        'useImproved' => false,
                        'audioUrl' => '',
                        'skipped' => false,
                    ];
                }
            }
            $formattedAnswers = $synchronizedAnswers;
        }

        $this->answers = $formattedAnswers;

        $rawContribAnswers = $chapter->getContributorAnswers();
        if (is_array($rawContribAnswers)) {
            $formattedContribAnswers = [];
            foreach ($rawContribAnswers as $contrib) {
                if (!is_array($contrib)) continue;

                // Filtrage confidentiel si un ID de contributeur est spécifié
                if ($filterContributorId !== null) {
                    $contribId = $contrib['id'] ?? null;
                    $contribName = $contrib['contributorName'] ?? $contrib['firstName'] ?? null;
                    $targetName = $currentContributor['firstName'] ?? null;
                    $matchesId = ($contribId !== null && (string)$contribId === (string)$filterContributorId);
                    $matchesName = ($targetName !== null && $contribName !== null && strcasecmp($contribName, $targetName) === 0);

                    if (!$matchesId && !$matchesName) {
                        continue; // Ne pas divulguer les réponses des autres contributeurs
                    }
                }

                if (isset($contrib['answers']) && is_array($contrib['answers'])) {
                    $formattedContribsAnswers = [];
                    $improvedIndices = $contrib['improvedQuestionIndices'] ?? [];
                    foreach ($contrib['answers'] as $ans) {
                        if (is_array($ans)) {
                            if (!empty($allowedQuestionTexts) && isset($ans['question']) && !in_array($ans['question'], $allowedQuestionTexts, true)) {
                                continue;
                            }
                            if (isset($ans['improved_answer']) && !isset($ans['improvedAnswer'])) {
                                $ans['improvedAnswer'] = $ans['improved_answer'];
                            }
                            
                            $isImp = (!empty($ans['improvedAnswer']) ||
                                (isset($ans['index']) && in_array($ans['index'], $improvedIndices, true)));
                            $ans['alreadyImproved'] = $isImp;
                            $ans['canImprove'] = !$isImp;
                            $ans['already_improved'] = $isImp;
                            $ans['can_improve'] = !$isImp;

                            if (isset($ans['audioUrl']) && $ans['audioUrl'] !== null && $ans['audioUrl'] !== '') {
                                $ans['audioUrl'] = $this->formatAudioUrl($ans['audioUrl'], $host);
                            }
                            if (isset($ans['audioUrl1']) && $ans['audioUrl1'] !== null && $ans['audioUrl1'] !== '') {
                                $ans['audioUrl1'] = $this->formatAudioUrl($ans['audioUrl1'], $host);
                            }
                            if (isset($ans['audioUrl2']) && $ans['audioUrl2'] !== null && $ans['audioUrl2'] !== '') {
                                $ans['audioUrl2'] = $this->formatAudioUrl($ans['audioUrl2'], $host);
                            }
                        }
                        $formattedContribsAnswers[] = $ans;
                    }
                    $contrib['answers'] = $formattedContribsAnswers;
                }
                $contrib['improvedQuestionIndices'] = $contrib['improvedQuestionIndices'] ?? [];
                $contrib['improved_question_indices'] = $contrib['improvedQuestionIndices'];
                $formattedContribAnswers[] = $contrib;
            }
            $this->contributorAnswers = $formattedContribAnswers;
        } else {
            $this->contributorAnswers = $rawContribAnswers;
        }

        $this->contentFinal = $chapter->getContentFinal();
        $this->generationStatus = $chapter->getGenerationStatus();
        $this->generationError = $chapter->getGenerationError();
        $layout = $chapter->getPhotoLayout();
        $this->photoLayout = $layout;
        $this->photo_layout = $layout;
        $this->photoPages = $layout;
        $this->photo_pages = $layout;

        foreach ($chapter->getPhotos() as $photo) {
            $this->photos[] = [
                'id' => (string) $photo->getId(),
                'url' => $host . '/uploads/memoires/' . $photo->getFilePath(),
                'sortOrder' => $photo->getSortOrder(),
                'orientation' => $photo->getOrientation(),
                'paragraphPosition' => $photo->getParagraphPosition()
            ];
        }
    }

    private function formatAudioUrl(?string $url, string $host): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return rtrim($host, '/') . '/' . ltrim($url, '/');
        }
        return $url;
    }
}
