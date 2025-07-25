<?php
namespace App\UseCase\VideoUseCase;

use App\Dto\VideoInputDto;
use App\Entity\Video;
use App\Services\VideoService\VideoService;

class CreateVideoUseCase
{
    private VideoService $videoService;
    public function __construct(VideoService $videoService) { $this->videoService = $videoService; }
    public function execute(VideoInputDto $dto): Video { return $this->videoService->createVideo($dto); }
}