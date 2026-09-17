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
        $role = $request->query->get('role');

        $qb = $repo->createQueryBuilder('q')
            ->where('q.isActive = true');

        if ($theme) {
            $qb->andWhere('q.theme = :theme')
               ->setParameter('theme', $theme);
        }
        if ($bookType) {
            $qb->andWhere('q.bookType = :bookType')
               ->setParameter('bookType', $bookType);
        }
        if ($role) {
            $qb->andWhere('(q.role IS NULL OR q.role = :role)')
               ->setParameter('role', $role);
        }

        $qb->orderBy('q.displayOrder', 'ASC');
        $questions = $qb->getQuery()->getResult();

        $result = array_map(fn(MemoireQuestion $q) => $q->toFrontArray(), $questions);

        return $this->json($result);
    }
}
