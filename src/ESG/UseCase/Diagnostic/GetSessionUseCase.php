<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\SessionDetailOutputDTO;
use App\ESG\Entity\DiagnosticSession;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GetSessionUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    public function execute(string $uuid): SessionDetailOutputDTO
    {
        $em = $this->emProvider->getEntityManager();
        $session = $em->getRepository(DiagnosticSession::class)->findByUuid($uuid);

        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // Check permission via Voter
        if (!$this->authorizationChecker->isGranted('VIEW', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette session de diagnostic.');
        }

        return new SessionDetailOutputDTO($session);
    }
}
