<?php

namespace App\Controller\CommandeController;

use App\Dto\CommandeOutputDTO;
use App\Entity\Commande;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Gestion\Services\GestionInput;
use App\UseCase\CommandeUseCase\UpdateCommandeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Modification d'une commande fournisseur ouverte (lot G6, 10/11/2026) : `PATCH /api/commandes/{id}` (`ROLE_ADMIN`, JSON, champs
 * facultatifs `budget` (cents, devise de la commande), `name`, `date`, `exchangeRate` (taux vers la devise du site, commande en
 * devise d'achat seulement)) → 200 avec la commande (forme de `GET /api/collections/{id}/commandes`) ; 409 `COMMANDE_CLOSED` ;
 * 422 `VALIDATION_FAILED` (`exchangeRate` : `NOT_APPLICABLE` dans la devise du site, `MUST_BE_NUMBER`).
 * Remplace les PUT / PATCH d'API Platform retirés au lot G5.
 */
class CommandeUpdateController extends AbstractController
{
    public function __construct(private readonly UpdateCommandeUseCase $update, private readonly ?TenantCurrencyProvider $currency = null, private readonly ?\App\Gestion\Services\PeriodGuard $periodGuard = null, private readonly ?\App\UseCase\CommandeUseCase\DeleteCommandeUseCase $delete = null)
    {
    }

    #[Route('/api/commandes/{id}', name: 'update_commande', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(Commande $commande, Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $input = new GestionInput($data);
        $fields = [];
        if (array_key_exists('budget', $data)) {
            $fields['budget'] = $input->cents('budget');
        }
        if (array_key_exists('name', $data)) {
            $fields['name'] = $input->text('name');
        }
        if (array_key_exists('date', $data)) {
            $fields['date'] = $input->date('date')?->format('Y-m-d H:i:s');
        }
        if (array_key_exists('exchangeRate', $data)) {
            $rate = $input->decimal('exchangeRate');
            if ($rate !== null && $rate <= 0) {
                throw new \App\Gestion\Services\GestionValidationException(['exchangeRate' => 'MUST_BE_POSITIVE'], 'exchangeRate : nombre > 0 attendu');
            }
            $fields['exchangeRate'] = $rate;
        }
        $input->check();
        // lot P1 (10/11/2026) : commande ouverte et datée dans une période ouverte ; une date déplacée vers une période fermée est refusée
        $this->periodGuard->assertCommandeWritable($commande);
        if (isset($fields['date'])) {
            $this->periodGuard->assertDateWritable(new \DateTimeImmutable($fields['date']));
        }
        $commande = $this->update->execute($commande, $fields);

        return $this->json(new CommandeOutputDTO($commande, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_OK);
    }

    /** Suppression dédiée (lot P1, 10/11/2026 ; avant : API Platform) : 204 sans corps, produits détachés ; 409 COMMANDE_CLOSED / PERIOD_CLOSED */
    #[Route('/api/commandes/{id}', name: 'delete_commande', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Commande $commande): \Symfony\Component\HttpFoundation\Response
    {
        $this->delete->execute($commande);

        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT);
    }
}
