<?php
namespace App\Dto;

class HomeSliderDTO
{
    public int $id;
    public string $title;
    public ?string $image;
    public string $description;
    public string $buttonMessage;
    public string $buttonUrl;
    public bool $isDiplayed;

    public function __construct(int $id, string $title, ?string $image, string $description, string $buttonMessage,string $buttonUrl,bool $isDiplayed)
    {
        $this->id = $id;
        $this->title = $title;
        $this->image = $image;
        $this->description = $description;
        $this->buttonMessage = $buttonMessage;
        $this->buttonUrl = $buttonUrl;
        $this->isDiplayed = $isDiplayed;
    }

    public static function fromEntity($homeSlider, string $host): self
    {
        return new self(
            $homeSlider->getId(),
            $homeSlider->getTitle(),
            $homeSlider->getImage(),
            $homeSlider->getDescription(),
            $homeSlider->getButtonMessage(),
            $homeSlider->getButtonUrl(),
            $homeSlider->isIsDiplayed(),
        );
    }
}


