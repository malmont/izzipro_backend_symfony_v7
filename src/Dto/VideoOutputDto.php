<?php
namespace App\Dto;

use App\Entity\Video;

class VideoOutputDto
{
    public int $id;
    public ?string $titre;
    public ?string $title;
    public ?string $lienVideo;
    public ?string $lien_video;
    public ?string $url;
    public ?string $embedUrl;
    public ?string $imageDeFondUrl;
    public ?string $image_de_fond_url;

    public function __construct(Video $video, string $baseImageUrl = '', string $locale = 'fr')
    {
        $translation = $video->getTranslation($locale);
        
        $this->id = (int) $video->getId();
        $this->titre = $translation?->getTitre() ?? $video->getTitre();
        $this->title = $this->titre;
        $rawLien = $video->getLienVideo();
        if ($rawLien && str_starts_with($rawLien, '/')) {
            $parsedHost = parse_url($baseImageUrl, PHP_URL_HOST);
            $parsedScheme = parse_url($baseImageUrl, PHP_URL_SCHEME) ?: 'https';
            $baseHost = $parsedHost ? ($parsedScheme . '://' . $parsedHost) : '';
            $finalLien = $baseHost . $rawLien;
        } else {
            $finalLien = $rawLien;
        }

        $this->lienVideo = $finalLien;
        $this->lien_video = $finalLien;
        $this->url = $finalLien;
        $this->embedUrl = $this->buildEmbedUrl($finalLien);

        $imageDeFond = $video->getImageDeFond();
        if ($imageDeFond) {
            $this->imageDeFondUrl = str_starts_with($imageDeFond, 'http')
                ? $imageDeFond
                : rtrim($baseImageUrl, '/') . '/' . ltrim($imageDeFond, '/');
        } else {
            $this->imageDeFondUrl = null;
        }
        $this->image_de_fond_url = $this->imageDeFondUrl;
    }

    private function buildEmbedUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        // YouTube: watch?v=ID or youtu.be/ID or embed/ID
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }

        // Vimeo: vimeo.com/ID
        if (preg_match('/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|video\/|)(\d+)/i', $url, $matches)) {
            return 'https://player.vimeo.com/video/' . end($matches);
        }

        // Dailymotion: dailymotion.com/video/ID
        if (preg_match('/dailymotion\.com\/video\/([a-zA-Z0-9]+)/i', $url, $matches)) {
            return 'https://www.dailymotion.com/embed/video/' . $matches[1];
        }

        return $url;
    }
}