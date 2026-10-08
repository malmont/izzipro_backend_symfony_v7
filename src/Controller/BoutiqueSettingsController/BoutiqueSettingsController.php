<?php

namespace App\Controller\BoutiqueSettingsController;

use App\Dto\BoutiqueSettingsInputDto;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\UseCase\BoutiqueSettingsUseCase\GetBoutiqueSettingsUseCase;
use App\UseCase\BoutiqueSettingsUseCase\UpdateBoutiqueSettingsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages publiés de la boutique réglable : lecture publique (le site les affiche), écriture par un administrateur du
 * site. Même contrat que /api/landingpage-settings : le PUT enregistre le document tel quel, le GET le restitue à
 * l'identique ; seuls sont contrôlés les compositions, les pages système, la charte et le commerce.
 */
#[Route('/api/boutique-settings')]
class BoutiqueSettingsController extends AbstractController
{
    /** Corps : configuration de 4 Mo au plus, plus l'enveloppe */
    private const MAX_BODY_BYTES = BoutiqueSettingsService::MAX_CONFIGURATION_BYTES + 16384;

    public function __construct(
        private readonly GetBoutiqueSettingsUseCase $getUseCase,
        private readonly UpdateBoutiqueSettingsUseCase $updateUseCase
    ) {
    }

    #[Route('', name: 'api_boutique_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $raw = $this->getUseCase->execute();

        // JSON stocké restitué sans transformation (un {} reste {}, l'ordre des clés est conservé)
        return $raw !== null ? new JsonResponse($raw, Response::HTTP_OK, [], true) : $this->json(null);
    }

    #[Route('', name: 'api_boutique_settings_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $this->updateUseCase->execute($this->body($request));
        } catch (BoutiqueSettingsException $e) {
            return $this->json(array_filter([
                'error' => $e->getStatusCode() === 422 ? 'Configuration de la boutique invalide' : $e->getMessage(),
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ]), $e->getStatusCode());
        }

        return $this->json(['status' => 'Configuration sauvegardée']);
    }

    /**
     * Corps { configuration } relu en objets : un {} décodé en tableau PHP deviendrait [] (validation faussée,
     * contenu modifié).
     *
     * @throws BoutiqueSettingsException 400, 413 ou 422
     */
    private function body(Request $request): BoutiqueSettingsInputDto
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new BoutiqueSettingsException(413, sprintf('Corps trop volumineux : configuration de %d Mo au plus.', BoutiqueSettingsService::MAX_CONFIGURATION_BYTES / 1048576));
        }
        try {
            $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new BoutiqueSettingsException(400, 'Corps JSON invalide.');
        }
        $configuration = is_object($body) ? ($body->configuration ?? null) : null;
        if (!is_object($configuration)) {
            throw new BoutiqueSettingsException(422, 'configuration : objet attendu', [['path' => 'configuration', 'message' => 'objet attendu (navbar, footer, tabs, reglablePresets, charter, commerce)']]);
        }

        return new BoutiqueSettingsInputDto($configuration, json_encode($configuration, ContentAuditRecorder::JSON_FLAGS | JSON_THROW_ON_ERROR));
    }
}
