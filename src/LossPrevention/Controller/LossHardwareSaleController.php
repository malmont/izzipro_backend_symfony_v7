<?php

namespace App\LossPrevention\Controller;

use App\LossPrevention\Master\LossHardwareProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le matériel vendu par la plateforme (lot L6-a, 17/11/2026, `docs/prevention-pertes.md` § 19), super administrateur seul (règle
 * `^/api/platform` de `access_control`, et l'attribut ci-dessous) : GET → { products } (tous, actifs ou non) ; PUT /{code} { kind?, level?,
 * labelFr?, labelEn?, descriptionFr?, descriptionEn?, priceCents?, currency?, buyUrl?, isActive?, position? } crée ou modifie →
 * { product } ; 422 VALIDATION_FAILED { errors [{ path, message }] }. Les sites le lisent dans la gamme (`GET /api/gestion/pertes/materiel`).
 */
#[Route('/api/platform/pertes/materiel')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class LossHardwareSaleController extends AbstractController
{
    public function __construct(private readonly LossHardwareProductRepository $products)
    {
    }

    #[Route('', name: 'api_platform_loss_hardware_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['products' => $this->products->all(false)]);
    }

    #[Route('/{code}', name: 'api_platform_loss_hardware_put', methods: ['PUT'], requirements: ['code' => '[a-z0-9_]{2,64}'])]
    public function put(string $code, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'corps JSON attendu', 'errors' => [['path' => '', 'message' => 'objet JSON attendu']]], 422);
        }
        $errors = $this->products->upsert($code, $data, $this->getUser()?->getUserIdentifier());
        if ($errors !== []) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $errors[0]['path'] . ' : ' . $errors[0]['message'], 'errors' => $errors], 422);
        }

        return $this->json(['product' => $this->products->find($code)]);
    }
}
