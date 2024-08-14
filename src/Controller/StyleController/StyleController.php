<?php
namespace App\Controller\StyleController;
use App\Entity\Style;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class StyleController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/styles', name: 'get_styles', methods: ['GET'])]
    public function getStyles(): JsonResponse
    {
        $styles = $this->entityManager->getRepository(Style::class)->findAll();

        // Manuellement composer la réponse JSON pour éviter les problèmes de sérialisation
        $stylesArray = [];
        foreach ($styles as $style) {
            $stylesArray[] = [
                'id' => $style->getId(),
                'name' => $style->getName(),
            ];
        }

        return new JsonResponse($stylesArray, JsonResponse::HTTP_OK);
    }
}
