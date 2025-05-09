<?php
namespace App\Dto;

class FeatureDTO
{
    public int    $id;
    public string $title;
    public ?string $iconUrl;

    public function __construct(int $id, string $title, ?string $iconUrl)
    {
        $this->id      = $id;
        $this->title   = $title;
        $this->iconUrl = $iconUrl;
    }
}
 