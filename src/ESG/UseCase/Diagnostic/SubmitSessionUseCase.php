<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\SessionDetailOutputDTO;
use App\ESG\Entity\DiagnosticAnswer;
use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Entity\DiagnosticReport;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Enum\SessionStatusEnum;
use App\ESG\Message\GenerateEsgReportMessage;
use App\ESG\Service\RecommendationEngine;
use App\ESG\Service\ScoringService;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SubmitSessionUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ScoringService $scoringService,
        private readonly RecommendationEngine$recommendationEngine,
        private readonly TenantConnectionProvider $connectionProvider,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    public function execute(string $uuid): SessionDetailOutputDTO
    {
        $em = $this->emProvider->getEntityManager();
        
        /** @var DiagnosticSession|null $session */
        $session = $em->getRepository(DiagnosticSession::class)->findByUuid($uuid);
        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // 1. Check permission via Voter
        if (!$this->authorizationChecker->isGranted('SUBMIT', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas l\'autorisation de soumettre cette session.');
        }

        if ($session->getStatus() !== SessionStatusEnum::IN_PROGRESS) {
            throw new AccessDeniedHttpException('Cette session est déjà complétée ou archivée.');
        }

        // 2. Load all active questions
        /** @var DiagnosticQuestion[] $questions */
        $questions = $em->getRepository(DiagnosticQuestion::class)->findAllActiveOrdered();

        // 3. Load existing answers
        /** @var DiagnosticAnswer[] $answers */
        $answers = $em->getRepository(DiagnosticAnswer::class)->findBySession($session);

        // 4. Verify all active questions have been answered
        $answersMap = [];
        foreach ($answers as $answer) {
            $answersMap[$answer->getQuestion()->getId()] = $answer->getAnswerValue();
        }

        $missingQuestionIds = [];
        foreach ($questions as $question) {
            if (!isset($answersMap[$question->getId()])) {
                $missingQuestionIds[] = $question->getId();
            }
        }

        if (!empty($missingQuestionIds)) {
            throw new UnprocessableEntityHttpException(
                sprintf('Des questions n\'ont pas de réponse. Questions manquantes : %s', implode(', ', $missingQuestionIds))
            );
        }

        // 5. Compute scores using ScoringService
        $domainScores = $this->scoringService->computeAllScores($answersMap, $questions);
        $globalScore = $this->scoringService->computeGlobalScore($domainScores);
        $maturityLevel = $this->scoringService->getMaturityLevel($globalScore);

        // 6. Update session
        $session->setScoreEnvironment($domainScores['environment'] ?? 0.0);
        $session->setScoreGovernance($domainScores['governance'] ?? 0.0);
        $session->setScoreSocial($domainScores['social'] ?? 0.0);
        $session->setScoreClimate($domainScores['climate_activator'] ?? 0.0);
        $session->setScoreGlobal($globalScore);
        $session->setMaturityLevel($maturityLevel);
        $session->setStatus(SessionStatusEnum::COMPLETED);
        $session->setCompletedAt(new \DateTimeImmutable());

        // 7. Create DiagnosticReport (status = PENDING)
        $report = new DiagnosticReport();
        $report->setSession($session);
        $em->persist($report);
        $session->setReport($report);

        // 8. Generate recommendations via RecommendationEngine
        $company = $session->getCompany();
        $recommendations = $this->recommendationEngine->generate(
            $session,
            $domainScores,
            $globalScore,
            $company->getTerritory()
        );

        foreach ($recommendations as $reco) {
            $em->persist($reco);
            $session->addRecommendation($reco);
        }

        // Flush database changes
        $em->flush();

        // 9. Dispatch GenerateEsgReportMessage
        $tenantCode = $this->connectionProvider->getTenantCode() ?? 'boussoleesg';
        $this->messageBus->dispatch(new GenerateEsgReportMessage($report->getId(), $tenantCode));

        return new SessionDetailOutputDTO($session);
    }
}
