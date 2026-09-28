<?php

namespace App\Controller\SharedMediaApiController;

use App\Entity\SharedMedia;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: ['/api/shared-media', '/shared-media'])]
class SharedMediaApiController extends AbstractController
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private MediaUrlResolver $mediaUrlResolver
    ) {}

    #[Route('', name: 'api_shared_media_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(SharedMedia::class);

        $type = $request->query->get('type');
        $criteria = ['visibility' => SharedMedia::VISIBILITY_PUBLIC];
        if ($type) {
            $criteria['mediaType'] = $type;
        }

        /** @var SharedMedia[] $medias */
        $medias = $repo->findBy($criteria, ['createdAt' => 'DESC']);

        $fallbackHost = $request->getSchemeAndHttpHost();
        $result = [];

        foreach ($medias as $media) {
            $result[] = $this->formatMedia($media, $fallbackHost);
        }

        return $this->json($result);
    }

    #[Route('/{id}', name: 'api_shared_media_get_one', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        /** @var SharedMedia|null $media */
        $media = $em->getRepository(SharedMedia::class)->find($id);

        // Média privé : clé comparée en temps constant, et lien expiré traité comme introuvable
        $key = (string) $request->query->get('key', '');
        if (!$media || ($media->isPrivate() && (!hash_equals((string) $media->getAccessKey(), $key) || $media->isExpired()))) {
            return $this->json(['message' => 'Document ou média introuvable'], Response::HTTP_NOT_FOUND);
        }

        $fallbackHost = $request->getSchemeAndHttpHost();
        return $this->json($this->formatMedia($media, $fallbackHost));
    }

    private function formatMedia(SharedMedia $media, ?string $fallbackHost): array
    {
        $url = $this->mediaUrlResolver->resolveSharedMediaUrl($media, $fallbackHost);

        return [
            'id' => $media->getId(),
            'titre' => $media->getTitre(),
            'title' => $media->getTitre(),
            'mediaType' => $media->getMediaType(),
            'type' => $media->getMediaType(),
            'originalFilename' => $media->getOriginalFilename(),
            'mimeType' => $media->getMimeType(),
            'fileSize' => $media->getFileSize(),
            'formattedSize' => $media->getFormattedFileSize(),
            'visibility' => $media->getVisibility(),
            'url' => $url,
            'shareUrl' => $url,
            'downloadUrl' => $media->isPrivate() ? ($url . '?download=1') : $url,
            'description' => $media->getDescription(),
            'createdAt' => $media->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
