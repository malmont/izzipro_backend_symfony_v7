<?php

namespace App\Controller;

use App\Dto\FinancementSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FinancementApiController extends AbstractController
{
    public function __construct(
        private EmailSenderService $emailSenderService,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private GemsuiteClientManager $gemsuiteClientManager
    ) {}

    #[Route('/api/financement/submit', name: 'api_financement_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        ValidatorInterface $validator
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return $this->json(['message' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
            }

            $dto = new FinancementSubmitDto();
            $dto->firstName = $data['firstName'] ?? null;
            $dto->lastName = $data['lastName'] ?? null;
            $dto->email = $data['email'] ?? null;
            $dto->phone = $data['phone'] ?? null;
            $dto->birthDate = $data['birthDate'] ?? null;
            $dto->vehicleType = $data['vehicleType'] ?? null;
            $dto->address = $data['address'] ?? null;
            $dto->timeAtResidence = $data['timeAtResidence'] ?? null;
            $dto->housingStatus = $data['housingStatus'] ?? null;
            $dto->monthlyPayment = isset($data['monthlyPayment']) ? (float)$data['monthlyPayment'] : null;
            $dto->monthlyIncome = isset($data['monthlyIncome']) ? (float)$data['monthlyIncome'] : null;
            $dto->creditScore = $data['creditScore'] ?? null;

            $violations = $validator->validate($dto);

            if (count($violations) > 0) {
                // Return the first validation error message as a user-friendly message
                return $this->json([
                    'message' => $violations[0]->getMessage()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Retrieve absolute base URL for logos (using request's scheme and host)
            $baseUrl = $request->getSchemeAndHttpHost();
            $locale = $request->getLocale() ?: 'fr';

            // Mode Autonome : Création prospect GemSuite désactivée
            // $tenantCode = $this->tenantManager->getCurrentTenantCode();

            // Load enterprise details from tenant database
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);

            if (!$entreprise || !$entreprise->getEmail()) {
                return $this->json([
                    'message' => "L'adresse courriel de l'entreprise n'est pas configurée."
                ], Response::HTTP_BAD_REQUEST);
            }

            $toEmail = $entreprise->getEmail();

            // Send notification email
            $this->emailSenderService->sendTemplatedEmail(
                $toEmail,
                'Nouvelle demande de financement - ' . $dto->firstName . ' ' . $dto->lastName,
                'emails/financement_form.html.twig',
                [
                    'data' => $dto,
                ],
                $locale,
                $baseUrl,
                null, // customFromName
                $dto->email // replyTo
            );

            return $this->json([
                'success' => true,
                'message' => 'Demande de financement soumise avec succès.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la soumission de la demande de financement: " . $e->getMessage());
            return $this->json([
                'message' => 'Une erreur est survenue lors du traitement de votre demande.',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
