<?php

namespace App\ESG\DTO\Input;

use Symfony\Component\Validator\Constraints as Assert;

class SaveAnswersInputDTO
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $sessionUuid;

    /**
     * @var array<array{questionId: int, answerValue: int}>
     */
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    public array $answers;
}
