<?php

namespace App\Controller\MediaApiController;

use App\UseCase\MediaUseCase\UploadMediaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Médias téléversés depuis l'éditeur des landing pages (voir UploadMediaUseCase).
 */
#[Route('/api/media')]
#[IsGranted('ROLE_ADMIN')]
class MediaApiController extends AbstractController
{
    public function __construct(private readonly UploadMediaUseCase $uploadUseCase)
    {
    }

    /** multipart/form-data : file (obligatoire), title, prepareForScroll (« 1 » ou « true ») */
    #[Route('', name: 'api_media_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        try {
            $media = $this->uploadUseCase->execute(
                $request->files->get('file'),
                $request->request->get('title'),
                filter_var($request->request->get('prepareForScroll', false), FILTER_VALIDATE_BOOLEAN),
                $request->getSchemeAndHttpHost()
            );
        } catch (HttpException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $this->json($media, 201);
    }
}
