<?php
namespace App\Dto;

use App\Entity\Video;

class VideoOutputDto
{
    public int $id;
    public string $titre;
    public ?string $lienVideo;
    public ?string $imageDeFondUrl;

    public function __construct(Video $video, string $baseImageUrl)
    {
        $this->id = $video->getId();
        $this->titre = $video->getTitre();
        $this->lienVideo = $video->getLienVideo();
        $this->imageDeFondUrl = $video->getImageDeFond()
            ? rtrim($baseImageUrl, '/') . '/' . $video->getImageDeFond()
            : null;
    }
}