<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Input\SaveAnswersInputDTO;
use App\ESG\Entity\DiagnosticAnswer;
use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Enum\SessionStatusEnum;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SaveAnswersUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    /**
     * @return array{saved: int}
     */
    public function execute(SaveAnswersInputDTO $dto): array
    {
        $em = $this->emProvider->getEntityManager();
        $session = $em->getRepository(DiagnosticSession::class)->findByUuid($dto->sessionUuid);

        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // Check permission via Voter
        if (!$this->authorizationChecker->isGranted('EDIT', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas l\'autorisation de modifier cette session.');
        }

        if ($session->getStatus() !== SessionStatusEnum::IN_PROGRESS) {
            throw new AccessDeniedHttpException('Cette session est complétée ou archivée et ne peut pas être modifiée.');
        }

        $answerRepo = $em->getRepository(DiagnosticAnswer::class);
        $questionRepo = $em->getRepository(DiagnosticQuestion::class);

        // 1. Précharger toutes les questions nécessaires en 1 seule requête
        $questionIds = array_filter(array_column($dto->answers, 'questionId'));
        $questions = !empty($questionIds) 
            ? $questionRepo->findBy(['id' => $questionIds, 'isActive' => true]) 
            : [];
        $questionsById = [];
        foreach ($questions as $q) {
            $questionsById[$q->getId()] = $q;
        }

        // 2. Précharger toutes les réponses existantes de la session en 1 seule requête
        /** @var DiagnosticAnswer[] $existingAnswers */
        $existingAnswers = $answerRepo->findBySessionWithQuestion($session);
        $answersByQuestionId = [];
        foreach ($existingAnswers as $existingAnswer) {
            $answersByQuestionId[$existingAnswer->getQuestion()->getId()] = $existingAnswer;
        }

        $savedCount = 0;

        foreach ($dto->answers as $ansData) {
            $questionId = $ansData['questionId'] ?? null;
            $value = $ansData['answerValue'] ?? null;

            if ($questionId === null || $value === null) {
                continue;
            }

            if (!isset($questionsById[$questionId])) {
                throw new NotFoundHttpException(sprintf('Question active introuvable pour l\'ID : %s', $questionId));
            }
            $question = $questionsById[$questionId];

            $answer = $answersByQuestionId[$questionId] ?? null;

            if (!$answer) {
                $answer = new DiagnosticAnswer();
                $answer->setSession($session);
                $answer->setQuestion($question);
                $em->persist($answer);
                $answersByQuestionId[$questionId] = $answer;
            }

            $answer->setAnswerValue($value);
            $answer->setAnsweredAt(new \DateTimeImmutable());
            $savedCount++;
        }

        $em->flush();

        return ['saved' => $savedCount];
    }
}
