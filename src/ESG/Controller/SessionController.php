<?php

namespace App\ESG\Controller;

use App\ESG\DTO\Input\SaveAnswersInputDTO;
use App\ESG\Entity\EsgUser;
use App\ESG\UseCase\Diagnostic\CreateSessionUseCase;
use App\ESG\UseCase\Diagnostic\GetSessionUseCase;
use App\ESG\UseCase\Diagnostic\ListSessionsUseCase;
use App\ESG\UseCase\Diagnostic\SaveAnswersUseCase;
use App\ESG\UseCase\Diagnostic\SubmitSessionUseCase;
use App\ESG\UseCase\Diagnostic\GetHistoriqueUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/boussole/sessions')]
class SessionController extends AbstractController
{
    #[Route('', name: 'esg_session_create', methods: ['POST'])]
    public function create(CreateSessionUseCase $useCase): Response
    {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié ou type d\'utilisateur incorrect.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $output = $useCase->execute($user);
            return $this->json($output, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('', name: 'esg_sessions_list', methods: ['GET'])]
    public function list(ListSessionsUseCase $useCase): Response
    {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié ou type d\'utilisateur incorrect.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $output = $useCase->execute($user);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/historique', name: 'esg_sessions_historique', methods: ['GET'])]
    public function historique(GetHistoriqueUseCase $useCase): Response
    {
        $this->denyAccessUnlessGranted('ROLE_COMPANY');

        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié ou type d\'utilisateur incorrect.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $output = $useCase->execute($user);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/{uuid}', name: 'esg_session_get', methods: ['GET'])]
    public function getSession(string $uuid, GetSessionUseCase $useCase): Response
    {
        try {
            $output = $useCase->execute($uuid);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/{uuid}/answers', name: 'esg_session_save_answers', methods: ['PUT'])]
    public function saveAnswers(
        string $uuid,
        Request $request,
        SaveAnswersUseCase $useCase,
        ValidatorInterface $validator
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new SaveAnswersInputDTO();
        $dto->sessionUuid = $uuid;
        $dto->answers = $data['answers'] ?? [];

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $useCase->execute($dto);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }
    }

    #[Route('/{uuid}/submit', name: 'esg_session_submit', methods: ['POST'])]
    public function submit(string $uuid, SubmitSessionUseCase $useCase): Response
    {
        try {
            $output = $useCase->execute($uuid);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{uuid}/recommendations', name: 'esg_session_recommendations', methods: ['GET'])]
    public function getRecommendations(string $uuid, GetSessionUseCase $useCase): Response
    {
        try {
            $output = $useCase->execute($uuid);
            return $this->json($output->recommendations, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}
