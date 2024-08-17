<?php
namespace App\Controller\SizesController;

use App\Entity\Size;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SizeController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/sizes', name: 'get_sizes', methods: ['GET'])]
    public function getSizes(): JsonResponse
    {
        $sizes = $this->entityManager->getRepository(Size::class)->findAll();

        $sizesArray = [];
        foreach ($sizes as $size) {
            $sizesArray[] = [
                'id' => $size->getId(),
                'name' => $size->getName(),
            ];
        }

        return $this->json($sizesArray, JsonResponse::HTTP_OK);
    }
}
