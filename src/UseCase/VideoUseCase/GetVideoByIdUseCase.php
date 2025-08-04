<?php

namespace App\UseCase\VideoUseCase;

use App\Entity\Video;
use App\Services\VideoService\VideoService; 

class GetVideoByIdUseCase
{
    public function __construct(
        private VideoService $videoService 
    ) {
    }

    public function execute(int $id): ?Video
    {
        return $this->videoService->findVideo($id);
    }
}
