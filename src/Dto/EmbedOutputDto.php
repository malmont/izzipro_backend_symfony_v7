<?php
namespace App\Dto;

use App\Entity\Embed;

class EmbedOutputDto
{
    public int $id;
    public string $titre;
    public string $embedUrl;

    public function __construct(Embed $embed)
    {
        $this->id = $embed->getId();
        $this->titre = $embed->getTitre();
        $this->embedUrl = $embed->getEmbedUrl();
    }
}
