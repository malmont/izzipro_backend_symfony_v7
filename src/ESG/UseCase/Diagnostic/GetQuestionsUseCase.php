<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\QuestionOutputDTO;
use App\ESG\Entity\DiagnosticQuestion;
use App\ESG\Enum\DomainEnum;
use App\Services\TenantEntityManagerProvider;

class GetQuestionsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return QuestionOutputDTO[]
     */
    public function execute(?string $domain = null): array
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(DiagnosticQuestion::class);

        if ($domain !== null) {
            $domainEnum = DomainEnum::from($domain);
            $questions = $repo->findActiveByDomain($domainEnum);
        } else {
            $questions = $repo->findAllActiveOrdered();
        }

        $dtos = [];
        foreach ($questions as $question) {
            $dtos[] = new QuestionOutputDTO($question);
        }

        return $dtos;
    }
}
