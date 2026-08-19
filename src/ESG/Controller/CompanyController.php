<?php

namespace App\ESG\Controller;

use App\ESG\DTO\Input\CompanyProfileInputDTO;
use App\ESG\Entity\EsgUser;
use App\ESG\UseCase\Company\GetCompanyProfileUseCase;
use App\ESG\UseCase\Company\UpdateCompanyProfileUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/boussole/company')]
class CompanyController extends AbstractController
{
    #[Route('', name: 'esg_company_get', methods: ['GET'])]
    public function getProfile(GetCompanyProfileUseCase $useCase): Response
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

    #[Route('', name: 'esg_company_update', methods: ['PUT'])]
    public function updateProfile(
        Request $request,
        UpdateCompanyProfileUseCase $useCase,
        ValidatorInterface $validator
    ): Response {
        /** @var EsgUser|null $user */
        $user = $this->getUser();
        if (!$user instanceof EsgUser) {
            return $this->json(['error' => 'Non authentifié ou type d\'utilisateur incorrect.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new CompanyProfileInputDTO();
        $dto->name = $data['name'] ?? '';
        $dto->sector = $data['sector'] ?? '';
        $dto->sizeCategory = $data['sizeCategory'] ?? '';
        $dto->territory = $data['territory'] ?? '';
        $dto->existingCertifications = $data['existingCertifications'] ?? [];
        $dto->contactEmail = $data['contactEmail'] ?? '';
        $dto->city = $data['city'] ?? null;
        $dto->website = $data['website'] ?? null;

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $output = $useCase->execute($user, $dto);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
