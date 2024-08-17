<?php
namespace App\Controller\ColorsController;

use App\Entity\Color;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ColorController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/colors', name: 'get_colors', methods: ['GET'])]
    public function getColors(): JsonResponse
    {
        $colors = $this->entityManager->getRepository(Color::class)->findAll();

        $colorsArray = [];
        foreach ($colors as $color) {
            $colorsArray[] = [
                'id' => $color->getId(),
                'name' => $color->getName(),
                'codeHexa' => $color->getCodeHexa(),
            ];
        }

        return $this->json($colorsArray, JsonResponse::HTTP_OK);
    }
}
