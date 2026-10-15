<?php

namespace App\UseCase\AppKeyUseCase;

use App\Dto\AppKeyInputDto;
use App\Dto\AppKeyOutputDto;
use App\Entity\Entreprise;
use App\Services\AppKeyService\AppKeyService;
use App\Services\AppKeyService\AppQrCodeGenerator;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;

/**
 * Génère une clé d'activation pour le site courant (administrateur), l'envoie par e-mail au contact du site
 * (fiche entreprise) si demandé, et la rend EN CLAIR une seule fois dans la réponse.
 */
class GenerateAppKeyUseCase
{
    public function __construct(
        private readonly AppKeyService $keys,
        private readonly TenantConnectionProvider $tenant,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantPublicHostResolver $hosts,
        private readonly EmailSenderService $emails,
        private readonly LoggerInterface $logger,
        private readonly AppQrCodeGenerator $qr
    ) {
    }

    /** @return array<string, mixed> la clé (`key`), sa ligne et `emailSentTo` (null si non envoyé) */
    public function execute(AppKeyInputDto $input, string $createdBy, string $domain): array
    {
        $tenantCode = $this->tenant->getTenantCode() ?? throw new \RuntimeException('Site non identifié');
        $generated = $this->keys->generate($tenantCode, $input->app, $input->label, $createdBy, $input->expiresAt);
        $emailSentTo = null;
        if ($input->sendEmail) {
            $emailSentTo = $this->sendByEmail($generated['key'], $input, $tenantCode, $domain);
        }

        return AppKeyOutputDto::toArray($generated['row']) + ['key' => $generated['key'], 'emailSentTo' => $emailSentTo];
    }

    private function sendByEmail(string $key, AppKeyInputDto $input, string $tenantCode, string $domain): ?string
    {
        try {
            $entreprise = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
            $to = $entreprise?->getEmail();
            if (!$to) {
                $this->logger->warning('Clé d\'activation : fiche entreprise sans e-mail, clé non envoyée', ['tenant' => $tenantCode]);
                return null;
            }
            $tenant = $this->hosts->describe($tenantCode);
            $png = $this->qr->png($key); // code QR = la clé ; incorporé dans le corps et joint en PNG
            $sent = $this->emails->sendTemplatedEmail($to, 'Clé d\'activation : ' . $input->label, 'emails/app_key.html.twig', [
                'key' => $key, 'app' => $input->app, 'appLabel' => AppKeyService::LABELS[$input->app] ?? $input->app, 'label' => $input->label,
                'expiresAt' => $input->expiresAt, 'siteName' => $tenant['name'] ?? $tenantCode, 'siteHost' => $tenant['host'] ?? null, 'qrCid' => 'app_key_qr',
            ], 'fr', $domain, null, null, [
                ['name' => 'app_key_qr', 'body' => $png, 'contentType' => 'image/png', 'inline' => true],
                ['name' => 'cle-activation-' . $input->app . '.png', 'body' => $png, 'contentType' => 'image/png'],
            ]);

            return $sent ? $to : null;
        } catch (\Throwable $e) {
            $this->logger->error('Clé d\'activation : envoi de l\'e-mail en échec : ' . $e->getMessage(), ['tenant' => $tenantCode]);
            return null;
        }
    }
}
