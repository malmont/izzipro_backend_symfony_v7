<?php

namespace App\LossPrevention\Controller;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\UseCase\LossShipmentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les boîtiers vendus, à expédier (lot L6-b, 17/11/2026, `docs/prevention-pertes.md` § 19.5), super administrateur seul (règle
 * `^/api/platform` de `access_control`, et l'attribut ci-dessous) :
 * - GET → { shipments [{ id, serial, tenantCode, orderRef, status, boxId, createdAt, createdBy, pluggedAt, confirmBefore, confirmedAt,
 *   cancelledAt, cancelledBy }] } (jamais le code ni une empreinte) ;
 * - POST { serial, code, tenantCode, orderRef? } → 200 { preview { serial, orderRef, site { code, name, host, lossPrevention }, pairing } }
 *   sans rien écrire ; avec `confirm: true` → 201 { shipment } ; 422 VALIDATION_FAILED { fields } (site inconnu, sans le module, code
 *   déjà jumelé…), 409 SHIPMENT_EXISTS ;
 * - POST /{id}/annuler → { shipment } ; 404 SHIPMENT_NOT_FOUND, 409 SHIPMENT_NOT_CANCELLABLE.
 */
#[Route('/api/platform/pertes/expeditions')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class LossShipmentPlatformController extends AbstractController
{
    public function __construct(private readonly LossShipmentUseCase $shipments)
    {
    }

    #[Route('', name: 'api_platform_loss_shipments_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->shipments->all());
    }

    #[Route('', name: 'api_platform_loss_shipments_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'objet JSON attendu', 'fields' => ['body' => 'INVALID_JSON']], 422);
        }
        try {
            $result = $this->shipments->reserveOrPreview($data, (string) $this->getUser()?->getUserIdentifier());
        } catch (GestionValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => 'Expédition invalide.', 'fields' => $e->fields], 422);
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }

        return $this->json($result, isset($result['shipment']) ? 201 : 200);
    }

    #[Route('/{id}/annuler', name: 'api_platform_loss_shipments_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        try {
            return $this->json($this->shipments->cancel($id, (string) $this->getUser()?->getUserIdentifier()));
        } catch (GestionConflictException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }
}
