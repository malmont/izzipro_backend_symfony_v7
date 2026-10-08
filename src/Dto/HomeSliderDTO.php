<?php
namespace App\Dto;

use App\Entity\HomeSlider;
use App\Services\MediaUrlResolver;

class HomeSliderDTO
{
    public int $id;
    public ?string $title;
    public ?string $image;
    public ?string $description;
    public ?string $buttonMessage;
    public ?string $buttonUrl;
    public ?bool $isDiplayed;

    public function __construct(
        int $id,
        ?string $title,
        ?string $image,
        ?string $description,
        ?string $buttonMessage,
        ?string $buttonUrl,
        ?bool $isDiplayed
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->image = $image;
        $this->description = $description;
        $this->buttonMessage = $buttonMessage;
        $this->buttonUrl = $buttonUrl;
        $this->isDiplayed = $isDiplayed;
    }

   public static function fromEntity(HomeSlider $homeSlider, string $host, string $locale): self
    {
        $translation = $homeSlider->getTranslation($locale);

        $image = MediaUrlResolver::joinStored($homeSlider->getImage(), rtrim($host, '/') . '/assets/uploads/slider');

        return new self(
            $homeSlider->getId(),
            $translation?->getTitle() ?? $homeSlider->getTitle(),
            $image,
            $translation?->getDescription() ?? $homeSlider->getDescription(),
            $translation?->getButtonMessage() ?? $homeSlider->getButtonMessage(),
            $translation?->getButtonUrl() ?? $homeSlider->getButtonUrl(),
            $homeSlider->isIsDiplayed(),
        );
    }
}