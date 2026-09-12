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
    public ?array $latestPrintOrder = null;
    public ?array $latest_print_order = null;
    public bool $hasPrintOrder = false;
    public bool $has_print_order = false;
    public int $printOrdersCount = 0;
    public int $print_orders_count = 0;

    public function __construct(Book $book, string $host, ?\App\MemoiresVivantes\Entity\BookPrintOrder $latestOrder = null, int $ordersCount = 0)
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

        if ($latestOrder) {
            $orderData = [
                'id' => $latestOrder->getId()->toRfc4122(),
                'order_id' => $latestOrder->getId()->toRfc4122(),
                'status' => $latestOrder->getStatus(),
                'lulu_print_job_id' => $latestOrder->getLuluPrintJobId(),
                'tracking_number' => $latestOrder->getTrackingNumber(),
                'tracking_url' => $latestOrder->getTrackingUrl(),
                'carrier_name' => $latestOrder->getCarrierName(),
                'quantity' => $latestOrder->getQuantity(),
                'total_cost' => $latestOrder->getTotalCost(),
                'currency' => $latestOrder->getCurrency(),
                'cover_style' => $latestOrder->getCoverStyle(),
                'created_at' => $latestOrder->getCreatedAt() ? $latestOrder->getCreatedAt()->format(\DateTimeInterface::ATOM) : null,
            ];
            $this->latestPrintOrder = $orderData;
            $this->latest_print_order = $orderData;
            $this->hasPrintOrder = true;
            $this->has_print_order = true;
        }

        $this->printOrdersCount = $ordersCount;
        $this->print_orders_count = $ordersCount;
    }
}
