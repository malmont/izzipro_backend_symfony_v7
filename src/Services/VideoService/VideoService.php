<?php
namespace App\Services\VideoService;

use App\Dto\VideoInputDto;
use App\Entity\Video;
use App\Repository\VideoRepository;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;

class VideoService
{
    private EntityManagerInterface $em;
    private VideoRepository $repository;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->em = $emProvider->getEntityManager();
        $this->repository = $this->em->getRepository(Video::class);
    }

    public function getAllVideosByLocale(string $locale): array
    {
        return $this->repository->findAllByLocale($locale);
    }

    public function findVideoByIdAndLocale(int $id, string $locale): ?Video
    {
        return $this->repository->findByIdAndLocale($id, $locale);
    }
    
    public function createVideo(VideoInputDto $dto): Video
    {
        $video = new Video();
        $video->setTitre($dto->titre);
        $video->setLienVideo($dto->lienVideo);
        $video->setImageDeFond($dto->imageDeFond);

        $this->em->persist($video);
        $this->em->flush();

        return $video;
    }

    public function updateVideo(Video $video, VideoInputDto $dto): Video
    {
        $video->setTitre($dto->titre ?? $video->getTitre());
        $video->setLienVideo($dto->lienVideo ?? $video->getLienVideo());
        $video->setImageDeFond($dto->imageDeFond ?? $video->getImageDeFond());

        $this->em->flush();

        return $video;
    }

    public function deleteVideo(Video $video): void
    {
        $this->em->remove($video);
        $this->em->flush();
    }
}