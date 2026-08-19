<?php

namespace App\ESG\Controller;

use App\ESG\DTO\Input\UploadDocumentInputDTO;
use App\ESG\Entity\EsgUser;
use App\ESG\UseCase\Document\DeleteDocumentUseCase;
use App\ESG\UseCase\Document\DownloadDocumentUseCase;
use App\ESG\UseCase\Document\ListDocumentsUseCase;
use App\ESG\UseCase\Document\UploadDocumentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/boussole/documents')]
class DocumentController extends AbstractController
{
    #[Route('', name: 'esg_documents_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        UploadDocumentUseCase $useCase,
        ValidatorInterface $validator
    ): Response {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $dto = new UploadDocumentInputDTO();
        $dto->code = $request->request->get('code', '');
        $dto->domain = $request->request->get('domain', '');

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Aucun fichier fourni.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $useCase->execute($user, $file, $dto);
            return $this->json($output, Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('', name: 'esg_documents_list', methods: ['GET'])]
    public function list(Request $request, ListDocumentsUseCase $useCase): Response
    {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $domain = $request->query->get('domain');

        try {
            $output = $useCase->execute($user, $domain);
            return $this->json($output, Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Domaine invalide.'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'esg_documents_delete', methods: ['DELETE'])]
    public function delete(int $id, DeleteDocumentUseCase $useCase): Response
    {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $useCase->execute($user, $id);
            return new Response(null, Response::HTTP_NO_CONTENT);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/download/{id}', name: 'esg_documents_download_id', methods: ['GET'])]
    public function downloadById(int $id, DownloadDocumentUseCase $useCase): Response
    {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            return $useCase->execute($user, $id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/download', name: 'esg_documents_download_path', methods: ['GET'])]
    public function downloadByPath(
        Request $request,
        DownloadDocumentUseCase $useCase,
        \App\Services\TenantEntityManagerProvider $emProvider
    ): Response {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $path = $request->query->get('path', '');
        if (empty($path)) {
            return $this->json(['error' => 'Chemin manquant.'], Response::HTTP_BAD_REQUEST);
        }

        // Query the document by path to get its ID
        $em = $emProvider->getEntityManager();
        $doc = $em->getRepository(\App\ESG\Entity\EsgDocument::class)->findOneBy(['filePath' => $path]);
        if (!$doc) {
            return $this->json(['error' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            return $useCase->execute($user, $doc->getId());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
