<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\Service\TeamViewJournal;
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
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TeamViewJournal $journal
    ) {
    }

    public function execute(string $uuid): SessionDetailOutputDTO
    {
        $em = $this->emProvider->getEntityManager();
        /** @var \App\ESG\Repository\DiagnosticSessionRepository $repo */
        $repo = $em->getRepository(DiagnosticSession::class);
        $session = $repo->findWithDetails($uuid);

        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // Check permission via Voter
        if (!$this->authorizationChecker->isGranted('VIEW', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à cette session de diagnostic.');
        }

        // Lecture par l'équipe de la Boussole : inscrite au journal des consultations (une entreprise qui lit le sien n'écrit rien)
        $this->journal->viewed($session->getCompany(), TeamViewJournal::SESSION, ['sessionUuid' => $uuid]);

        return new SessionDetailOutputDTO($session);
    }
}
