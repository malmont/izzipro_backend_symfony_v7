<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/memoires')]
class QuestionController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    #[Route('/questions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(MemoireQuestion::class);

        $theme = $request->query->get('theme');
        $bookType = $request->query->get('bookType');

        $criteria = ['isActive' => true];
        if ($theme) {
            $criteria['theme'] = $theme;
        }
        if ($bookType) {
            $criteria['bookType'] = $bookType;
        }

        $questions = $repo->findBy($criteria, ['displayOrder' => 'ASC']);

        $result = array_map(fn(MemoireQuestion $q) => $q->toFrontArray(), $questions);

        return $this->json($result);
    }
}
