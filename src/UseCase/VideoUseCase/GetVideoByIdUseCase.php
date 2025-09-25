<?php
namespace App\UseCase\VideoUseCase;

use App\Dto\VideoOutputDto;
use App\Entity\Video;
use App\Services\VideoService\VideoService; 

class GetVideoByIdUseCase
{
    public function __construct(
        private VideoService $videoService 
    ) {}

    public function execute(int $id, string $baseImageUrl, string $locale): ?VideoOutputDto
    {
        $video = $this->videoService->findVideoByIdAndLocale($id, $locale);
        if (!$video) {
            return null;
        }
        return new VideoOutputDto($video, $baseImageUrl, $locale);
    }
}