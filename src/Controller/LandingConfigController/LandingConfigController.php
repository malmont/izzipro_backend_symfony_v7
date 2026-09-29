<?php

namespace App\Controller\LandingConfigController;

use App\Services\LandingConfigService\LandingConfigException;
use App\Services\TenantConnectionProvider;
use App\UseCase\LandingConfigUseCase\GetLandingConfigStatusUseCase;
use App\UseCase\LandingConfigUseCase\RollbackLandingConfigUseCase;
use App\UseCase\LandingConfigUseCase\SyncLandingConfigUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Synchronisation des fichiers de configuration des landing pages publiés par le frontend (schéma, catalogue de
 * l'assistant, jeu d'essai). Réservé au propriétaire de la plateforme : utilisateur ROLE_SUPER_ADMIN, ou jeton de
 * déploiement DEPLOY_SYNC_TOKEN (en-tête X-Deploy-Token, GitHub Action). Contrôle d'accès ici : la règle de
 * security.yaml laisse passer ces routes (PUBLIC_ACCESS) pour le jeton.
 */
#[Route('/api/landingpage-config')]
class LandingConfigController extends AbstractController
{
    public const TOKEN_HEADER = 'X-Deploy-Token';
    /** Jeton plus court : considéré comme non configuré */
    public const MIN_TOKEN_LENGTH = 32;

    public function __construct(
        private readonly GetLandingConfigStatusUseCase $statusUseCase,
        private readonly SyncLandingConfigUseCase $syncUseCase,
        private readonly RollbackLandingConfigUseCase $rollbackUseCase,
        private readonly TenantConnectionProvider $tenantProvider,
        #[Autowire('%env(default::DEPLOY_SYNC_TOKEN)%')]
        private readonly ?string $deployToken
    ) {
    }

    #[Route('/status', name: 'api_landingpage_config_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        return $this->handle($request, fn () => $this->statusUseCase->execute());
    }

    #[Route('/sync', name: 'api_landingpage_config_sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        return $this->handle($request, fn (string $author) => $this->syncUseCase->execute($author));
    }

    #[Route('/rollback', name: 'api_landingpage_config_rollback', methods: ['POST'])]
    public function rollback(Request $request): JsonResponse
    {
        return $this->handle($request, fn (string $author) => $this->rollbackUseCase->execute($author));
    }

    private function handle(Request $request, callable $action): JsonResponse
    {
        try {
            return new JsonResponse($action($this->author($request)));
        } catch (LandingConfigException $e) {
            return new JsonResponse($e->toArray(), $e->getStatusCode());
        }
    }

    /** Auteur de l'action (historique), après contrôle d'accès */
    private function author(Request $request): string
    {
        $token = $request->headers->get(self::TOKEN_HEADER);
        if ($token !== null) {
            $expected = (string) $this->deployToken;
            if (strlen($expected) >= self::MIN_TOKEN_LENGTH && hash_equals($expected, $token)) {
                return 'jeton de déploiement';
            }
            throw new LandingConfigException(401, 'Jeton invalide', 'Jeton de déploiement invalide.');
        }
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return sprintf('%s (%s)', $this->getUser()?->getUserIdentifier(), $this->tenantProvider->getTenantCode() ?? '?');
        }

        throw $this->getUser() !== null
            ? new LandingConfigException(403, 'Accès refusé', 'Réservé au propriétaire de la plateforme (ROLE_SUPER_ADMIN).')
            : new LandingConfigException(401, 'Authentification requise', 'Connectez-vous en super administrateur ou utilisez le jeton de déploiement.');
    }
}
