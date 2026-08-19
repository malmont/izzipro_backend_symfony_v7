<?php

namespace App\MemoiresVivantes\Dto;

use App\MemoiresVivantes\Entity\Book;

class BookOutputDto
{
    public string $id;
    public string $title;
    public ?string $subtitle;
    public ?string $birthplace;
    public string $format;
    public string $status;
    public ?string $coverPhotoPath;
    public string $createdAt;
    public string $updatedAt;
    public string $type;
    public ?string $person1FirstName;
    public ?string $person1Birthplace;
    public ?string $person2FirstName;
    public ?string $person2Birthplace;
    public array $contributors = [];
    public array $chapters = [];

    public function __construct(Book $book, string $host)
    {
        $this->id = (string) $book->getId();
        $this->title = $book->getTitle();
        $this->subtitle = $book->getSubtitle();
        $this->birthplace = $book->getBirthplace();
        $this->format = $book->getFormat();
        $this->status = $book->getStatus();
        $this->coverPhotoPath = $book->getCoverPhotoPath() ? $host . '/uploads/memoires/' . $book->getCoverPhotoPath() : null;
        $this->createdAt = $book->getCreatedAt()->format(\DateTimeInterface::ATOM);
        $this->updatedAt = $book->getUpdatedAt()->format(\DateTimeInterface::ATOM);
        $this->type = $book->getType();
        $this->person1FirstName = $book->getPerson1FirstName();
        $this->person1Birthplace = $book->getPerson1Birthplace();
        $this->person2FirstName = $book->getPerson2FirstName();
        $this->person2Birthplace = $book->getPerson2Birthplace();

        foreach ($book->getContributors() as $contributor) {
            $this->contributors[] = [
                'id' => (string) $contributor->getId(),
                'firstName' => $contributor->getFirstName(),
                'role' => $contributor->getRole(),
                'sortOrder' => $contributor->getSortOrder(),
                'createdAt' => $contributor->getCreatedAt()->format(\DateTimeInterface::ATOM)
            ];
        }

        foreach ($book->getChapters() as $chapter) {
            $this->chapters[] = new ChapterOutputDto($chapter, $host);
        }
    }
}
