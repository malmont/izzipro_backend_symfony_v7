<?php

namespace App\Controller\MediaApiController;

use App\UseCase\MediaUseCase\ListMediaUseCase;
use App\UseCase\MediaUseCase\ManageMediaUseCase;
use App\UseCase\MediaUseCase\UploadMediaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Médiathèque du site depuis l'éditeur des landing pages : liste et recherche (ListMediaUseCase), téléversement
 * (UploadMediaUseCase), renommage et suppression d'un média qui ne sert plus (ManageMediaUseCase). ROLE_ADMIN.
 */
#[Route('/api/media')]
#[IsGranted('ROLE_ADMIN')]
class MediaApiController extends AbstractController
{
    public function __construct(
        private readonly UploadMediaUseCase $uploadUseCase,
        private readonly ListMediaUseCase $listUseCase,
        private readonly ManageMediaUseCase $manageUseCase
    ) {
    }

    /** ?type=image|video, page (1…), limit (1 à 100, 40 par défaut), q (titre, contient, sans casse) */
    #[Route('', name: 'api_media_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $query = $request->query;

            return $this->json($this->listUseCase->execute(
                $query->has('type') ? (string) $query->get('type') : null,
                $query->has('page') ? (string) $query->get('page') : null,
                $query->has('limit') ? (string) $query->get('limit') : null,
                $query->has('q') ? (string) $query->get('q') : null,
                $request->getSchemeAndHttpHost()
            ));
        } catch (HttpException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /** { title } : renommer un média pour le retrouver */
    #[Route('/{id}', name: 'api_media_rename', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function rename(int $id, Request $request): JsonResponse
    {
        try {
            return $this->json($this->manageUseCase->rename($id, json_decode($request->getContent(), false, 4), $request->getSchemeAndHttpHost()));
        } catch (HttpException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /** 204 ; 409 { error, usages } si le média sert encore (réglages publiés, modèles de site, contenus) */
    #[Route('/{id}', name: 'api_media_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $usages = $this->manageUseCase->delete($id);
        } catch (HttpException $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        return $usages === []
            ? new JsonResponse(null, 204)
            : $this->json(['error' => 'Média encore utilisé : retirez-le de ces endroits avant de le supprimer.', 'usages' => $usages], 409);
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
