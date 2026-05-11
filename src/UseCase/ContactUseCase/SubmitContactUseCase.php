<?php

namespace App\UseCase\ContactUseCase;

use App\Dto\ContactSubmitDto;
use App\Entity\Entreprise;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

class SubmitContactUseCase
{
    public function __construct(
        private EmailSenderService $emailSenderService,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger
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
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la soumission du formulaire de contact: " . $e->getMessage());
            throw $e;
        }
    }
}
