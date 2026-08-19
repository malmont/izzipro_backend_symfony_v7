<?php

namespace App\ESG\Controller;

use App\ESG\UseCase\Diagnostic\GetQuestionsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class QuestionController extends AbstractController
{
    #[Route('/api/boussole/questions', name: 'esg_questions_list', methods: ['GET'])]
    public function listQuestions(Request $request, GetQuestionsUseCase $useCase): Response
    {
        $domain = $request->query->get('domain');

        try {
            $output = $useCase->execute($domain);
            return $this->json($output, Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Domaine invalide.'], Response::HTTP_BAD_REQUEST);
        }
    }
}
