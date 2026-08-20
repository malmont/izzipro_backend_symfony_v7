<?php

namespace App\UseCase\ContactUseCase;

use App\Dto\ContactSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;

class SubmitContactUseCase
{
    public function __construct(
        private EmailSenderService $emailSenderService,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager
    ) {}

    public function execute(ContactSubmitDto $dto, string $host, string $locale = 'fr'): void
    {
        try {
            $tenantEm = $this->emProvider->getEntityManager();
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]);

            if (!$entreprise || !$entreprise->getEmail()) {
                throw new \Exception("L'adresse email de l'entreprise n'est pas configurée.");
            }

            $toEmail = $entreprise->getEmail();

            $this->emailSenderService->sendTemplatedEmail(
                $toEmail,
                'Nouveau message de contact - ' . $dto->service,
                'emails/contact_form.html.twig',
                [
                    'contact' => $dto,
                ],
                $locale,
                $host,
                null, // customFromName
                $dto->email // replyTo
            );

            // Accusé de réception destiné au client
            $this->emailSenderService->sendTemplatedEmail(
                $dto->email,
                'Accusé de réception - ' . $dto->service,
                'emails/contact_acknowledgement.html.twig',
                [
                    'contact' => $dto,
                ],
                $locale,
                $host
            );

            // Mode Autonome : Synchronisation GemSuite désactivée
            // $tenantCode = $this->tenantManager->getCurrentTenantCode();
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la soumission du formulaire de contact: " . $e->getMessage());
            throw $e;
        }
    }
}
