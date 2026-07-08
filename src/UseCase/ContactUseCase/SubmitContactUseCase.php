<?php

namespace App\UseCase\ContactUseCase;

use App\Dto\ContactSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionManager;
use App\Services\GemsuiteImporterService\GemsuiteClientManager;
use Psr\Log\LoggerInterface;

class SubmitContactUseCase
{
    public function __construct(
        private EmailSenderService $emailSenderService,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private GemsuiteClientManager $gemsuiteClientManager
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

            // Synchronisation du prospect sur GEM-SUITE (Type 2 pour contact / pieces-services)
            $tenantCode = $this->tenantManager->getCurrentTenantCode();
            if ($tenantCode) {
                $fullName = trim($dto->name ?? '');
                $parts = explode(' ', $fullName, 2);
                $firstName = $parts[0] ?: 'Contact';
                $lastName = $parts[1] ?? 'User';

                try {
                    $this->gemsuiteClientManager->findOrCreateClient(
                        $dto->email,
                        $firstName,
                        $lastName,
                        $tenantCode,
                        true, // isProspect
                        null, // address (non présente dans ContactSubmitDto)
                        $dto->phone,
                        2 // Type 2 pour le formulaire de contact / pièces-services
                    );
                } catch (\Throwable $e) {
                    $this->logger->error("Échec de la création du prospect sur GEM-SUITE pour le contact : " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la soumission du formulaire de contact: " . $e->getMessage());
            throw $e;
        }
    }
}
