<?php
namespace App\Dto;

use App\Entity\HomeSlider;

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
        // --- Logique "en attente" ---
        // $translation = $homeSlider->getTranslation($locale);
        // TODO: Après la migration, on branchera la logique de traduction ici.

        // --- Logique actuelle ---
        $title = $homeSlider->getTitle();
        $description = $homeSlider->getDescription();
        $buttonMessage = $homeSlider->getButtonMessage();
        // Pour les champs optionnels (image, buttonUrl)
        // $buttonUrl = $translation ? $translation->getButtonUrl() : $homeSlider->getButtonUrl();
        $imagePath = $homeSlider->getImage();
        $buttonUrl = $homeSlider->getButtonUrl();


        if ($imagePath) {
            $image = str_starts_with($imagePath, 'http') ? $imagePath : rtrim($host, '/') . '/assets/uploads/slider/' . $imagePath;
        } else {
            $image = null;
        }

        return new self(
            $homeSlider->getId(),
            $title,
            $image,
            $description,
            $buttonMessage,
            $buttonUrl,
            $homeSlider->isIsDiplayed(),
        );
    }
}