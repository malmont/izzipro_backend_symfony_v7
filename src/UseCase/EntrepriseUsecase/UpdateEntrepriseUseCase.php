<?php

namespace App\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseService;
use Symfony\Bundle\SecurityBundle\Security;

class UpdateEntrepriseUseCase
{
    /**
     * Activations des modules : facturées par la plateforme, donc réservées au super administrateur (même grille que la fiche
     * EasyAdmin, lot 2a du 03/11/2026 ; verrou API depuis le 04/11/2026). Un administrateur de site peut renvoyer ces clés avec leur
     * valeur actuelle (l'éditeur renvoie parfois la fiche entière) : elles sont alors ignorées ; une valeur différente → 403
     * SUPER_ADMIN_REQUIRED, rien n'est écrit.
     */
    public const SUPER_ADMIN_FIELDS = ['isBoutiqueActive', 'isLandingPageActive', 'isBoussoleEsgActive', 'isMemoireVivanteActive'];

    private EntrepriseService $entrepriseService;

    public function __construct(EntrepriseService $entrepriseService, private readonly ContentAuditRecorder $audit, private readonly Security $security, private readonly ?\App\Services\Platform\EntitlementFlagSync $flagSync = null)
    {
        $this->entrepriseService = $entrepriseService;
    }

    /**
     * @param array<string, mixed> $data
     * @throws EntrepriseForbiddenException
     */
    public function execute(int $id, array $data, string $host, string $locale): ?EntrepriseDto
    {
        $data = $this->guardSuperAdminFields($id, $data, $locale);
        // journal des écritures : valeurs avant / après des seules clés envoyées
        $before = $this->entrepriseService->snapshot($id, array_keys($data), $locale);
        $dto = $this->entrepriseService->updateEntreprise($id, $data, $host, $locale);
        if ($dto !== null && array_intersect(self::SUPER_ADMIN_FIELDS, array_keys($data)) !== []) {
            $this->flagSync?->afterEntrepriseId($id); // droits d'usage alignés sur les activations (console, lot 3, 04/11/2026)
        }
        if ($dto !== null && $before !== null) {
            $after = $this->entrepriseService->snapshot($id, array_keys($before), $locale);
            $changed = array_keys(array_filter($after, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->audit->record('entreprise', $id, 'update', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $changed, $locale);
            }
        }

        return $dto;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed> les données sans les clés réservées laissées inchangées
     */
    private function guardSuperAdminFields(int $id, array $data, string $locale): array
    {
        $sent = array_values(array_intersect(self::SUPER_ADMIN_FIELDS, array_keys($data)));
        if ($sent === [] || $this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $data;
        }
        $current = $this->entrepriseService->snapshot($id, $sent, $locale);
        if ($current === null) {
            return $data; // fiche inconnue : le service répondra 404
        }
        $refused = [];
        foreach ($sent as $field) {
            if ((bool) $data[$field] !== (bool) ($current[$field] ?? false)) {
                $refused[] = $field;
            }
            unset($data[$field]);
        }
        if ($refused !== []) {
            throw new EntrepriseForbiddenException($refused);
        }

        return $data;
    }
}
