<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\SessionOutputDTO;
use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgUser;
use App\ESG\Enum\SessionStatusEnum;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CreateSessionUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    public function execute(EsgUser $user): SessionOutputDTO
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $sessionRepo = $em->getRepository(DiagnosticSession::class);

        // 1. Verify if there is already an in-progress session
        $inProgressSession = $sessionRepo->findInProgressByCompany($company);
        if ($inProgressSession) {
            throw new ConflictHttpException('Une session de diagnostic est déjà en cours pour cette entreprise.');
        }

        // 2. Get active referential version
        $refRepo = $em->getRepository(CertificationReferential::class);
        $activeRef = $refRepo->findOneBy(['isActive' => true]);
        $version = $activeRef ? $activeRef->getVersion() : 'v1';

        // 3. Create Session
        $session = new DiagnosticSession();
        $session->setCompany($company);
        $session->setStatus(SessionStatusEnum::IN_PROGRESS);
        $session->setReferentialVersion($version);

        $em->persist($session);
        $em->flush();

        return new SessionOutputDTO($session);
    }
}
