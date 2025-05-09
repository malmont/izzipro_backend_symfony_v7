<?php
// src/Dto/ExploreCardDto.php
namespace App\Dto;

class ExploreCardDto
{
    public int    $id;
    public bool   $isDifferent;
    public ?string $standardTitle;
    public ?string $differentTitle;
    public ?string $description;
    public ?string $link;
    public ?string $imageUrl;
    public ?string $videoUrl;

    public function __construct(
        int    $id,
        bool   $isDifferent,
        ?string $standardTitle,
        ?string $differentTitle,
        ?string $description,
        ?string $link,
        ?string $imageUrl,
        ?string $videoUrl
    ) {
        $this->id             = $id;
        $this->isDifferent    = $isDifferent;
        $this->standardTitle  = $standardTitle;
        $this->differentTitle = $differentTitle;
        $this->description    = $description;
        $this->link           = $link;
        $this->imageUrl       = $imageUrl;
        $this->videoUrl       = $videoUrl;
    }
}
