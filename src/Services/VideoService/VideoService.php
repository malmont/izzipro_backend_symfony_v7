<?php
namespace App\Services\VideoService;

use App\Dto\VideoInputDto;
use App\Entity\Video;
use App\Services\TenantEntityManagerProvider;

class VideoService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllVideos(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Video::class)->findAll();
    }

    public function findVideo(int $id): ?Video
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Video::class)->find($id);
    }

    public function createVideo(VideoInputDto $dto): Video
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $video = new Video();
        $video->setTitre($dto->titre);
        $video->setLienVideo($dto->lienVideo);
        $video->setImageDeFond($dto->imageDeFond);
        
        $tenantEm->persist($video);
        $tenantEm->flush();

        return $video;
    }

    public function updateVideo(Video $video, VideoInputDto $dto): Video
    {
        $tenantEm = $this->emProvider->getEntityManager();

        $video->setTitre($dto->titre ?? $video->getTitre());
        $video->setLienVideo($dto->lienVideo ?? $video->getLienVideo());
        $video->setImageDeFond($dto->imageDeFond ?? $video->getImageDeFond());
        
        $tenantEm->flush();

        return $video;
    }

    public function deleteVideo(Video $video): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->remove($video);
        $tenantEm->flush();
    }
}