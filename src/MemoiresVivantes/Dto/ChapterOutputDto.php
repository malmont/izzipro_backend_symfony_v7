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

    public function __construct(Chapter $chapter, string $host, array $questions = [])
    {
        $this->id = (string) $chapter->getId();
        $this->bookId = (string) $chapter->getBook()->getId();
        $this->title = $chapter->getTitle();
        $this->theme = $chapter->getTheme();
        $this->questions = $questions;
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
        $this->answers = $formattedAnswers;

        $rawContribAnswers = $chapter->getContributorAnswers();
        if (is_array($rawContribAnswers)) {
            $formattedContribAnswers = [];
            foreach ($rawContribAnswers as $contrib) {
                if (is_array($contrib) && isset($contrib['answers']) && is_array($contrib['answers'])) {
                    $formattedContribsAnswers = [];
                    foreach ($contrib['answers'] as $ans) {
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
                        $formattedContribsAnswers[] = $ans;
                    }
                    $contrib['answers'] = $formattedContribsAnswers;
                }
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
