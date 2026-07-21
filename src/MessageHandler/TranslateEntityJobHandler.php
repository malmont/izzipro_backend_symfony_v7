<?php
// src/MessageHandler/TranslateEntityJobHandler.php

namespace App\MessageHandler;

use App\Entity\TranslatableInterface; // On va en avoir besoin
use App\Message\TranslateEntityJob;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class TranslateEntityJobHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private TranslationGeneratorService $translationGenerator // L'outil de traduction
    ) {
    }

    public function __invoke(TranslateEntityJob $message)
    {
        $this->logger->info(sprintf(
            '[Traduction Job] Reçu ordre de traduction pour %s (ID: %d) du Tenant ID %d',
            $message->getEntityClass(),
            $message->getEntityId(),
            $message->getTenantId()
        ));

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            $this->logger->error("[Traduction Fail] Tenant ID {$message->getTenantId()} non trouvé.");
            return;
        }

        $tenantEm = null;
        try {
            // --- 1. On se connecte à la BDD du tenant ---
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();

            // --- 2. On récupère l'entité à traduire ---
            $entity = $tenantEm->getRepository($message->getEntityClass())->find($message->getEntityId());

            if (!$entity) {
                $this->logger->warning(sprintf(
                    '[Traduction Fail] Entité %s (ID: %d) non trouvée pour Tenant ID %d. Job abandonné (Unrecoverable).',
                    $message->getEntityClass(), $message->getEntityId(), $message->getTenantId()
                ));
                throw new UnrecoverableMessageHandlingException(sprintf(
                    'Entité %s (ID: %d) non trouvée. Impossible de traduire.',
                    $message->getEntityClass(), $message->getEntityId()
                ));
            }

            // On vérifie qu'elle est bien traduisible
            if (!$entity instanceof TranslatableInterface) {
                $this->logger->warning(sprintf(
                    'Entité %s (ID: %d) n\'implémente pas TranslatableInterface. Traduction ignorée.',
                    $message->getEntityClass(), $message->getEntityId()
                ));
                return;
            }

            // --- 3. ON APPELLE LE SERVICE (la bombe) ---
            $this->translationGenerator->generateTranslations($entity);
            $tenantEm->flush();

            $this->logger->info(sprintf(
                '[Traduction Success] %s (ID: %d) traduite avec succès.',
                $message->getEntityClass(), $message->getEntityId()
            ));

        } catch (\Throwable $e) {
            $this->logger->error('[Traduction Fail] ' . $e->getMessage());
            throw $e; 
        }
    }
}