<?php

namespace App\ESG\DTO\Output;

use App\ESG\Entity\DiagnosticQuestion;

class QuestionOutputDTO
{
    public int $id;
    public string $domain;
    public string $questionText;
    public ?string $helpText;
    public int $weight;
    public string $answerType;
    public int $displayOrder;

    public function __construct(DiagnosticQuestion $question)
    {
        $this->id = $question->getId();
        $this->domain = $question->getDomain()->value;
        $this->questionText = $question->getQuestionText();
        $this->helpText = $question->getHelpText();
        $this->weight = $question->getWeight();
        $this->answerType = $question->getAnswerType()->value;
        $this->displayOrder = $question->getDisplayOrder();
    }
}
