<?php

namespace App\Controller\SiteFeaturesController;

use App\Entity\User;
use App\Services\Platform\Essai\EssaiJobStore;
use App\Services\Platform\Essai\EssaiRunner;
use App\Services\Platform\Essai\Message\RunEssaiMessage;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Remplir un SITE D'ESSAI de données fictives depuis le web (lot ESSAI-DONNÉES, 17/11/2026, `docs/contenu-exemple.md`) : le même
 * remplissage que `app:essai:remplir`, exécuté par le worker `media` (c'est long). Administrateur du site ; refusé à tout site qui n'est
 * ni demo ni un site d'une agence de test (422 `ESSAI_NOT_A_TEST_SITE`) ; une seule exécution à la fois (409 `ESSAI_RUNNING`).
 */
#[Route('/api/site/essai/remplir')]
#[IsGranted('ROLE_ADMIN')]
class SiteEssaiController extends AbstractController
{
    public function __construct(
        private readonly EssaiRunner $runner,
        private readonly EssaiJobStore $jobs,
        private readonly MessageBusInterface $bus,
        private readonly TenantConnectionProvider $tenants,
    ) {
    }

    /**
     * L'état du dernier remplissage → 200 `{ allowed, job: null | { state: queued | running | done | failed, requestedAt, requestedBy,
     * startedAt?, finishedAt?, report: list<string>, error: ?string } }`
     */
    #[Route('', name: 'api_site_essai_state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        $tenant = $this->tenant();

        return $this->json(['allowed' => $this->runner->allowed($tenant), 'job' => $this->view($this->jobs->get($tenant))]);
    }

    /** Lancer le remplissage → 202 `{ job }` ; 422 `ESSAI_NOT_A_TEST_SITE` ; 409 `ESSAI_RUNNING` (rien n'est relancé) */
    #[Route('', name: 'api_site_essai_run', methods: ['POST'])]
    public function run(): JsonResponse
    {
        $tenant = $this->tenant();
        if (!$this->runner->allowed($tenant)) {
            return $this->json(['error' => 'ESSAI_NOT_A_TEST_SITE', 'code' => 'ESSAI_NOT_A_TEST_SITE', 'message' => 'Seul un site d\'essai (demo, ou un site d\'une agence de test) se remplit de données fictives.'], 422);
        }
        if ($this->jobs->busy($tenant)) {
            return $this->json(['error' => 'ESSAI_RUNNING', 'code' => 'ESSAI_RUNNING', 'message' => 'Un remplissage est déjà en cours sur ce site.', 'job' => $this->view($this->jobs->get($tenant))], 409);
        }
        $user = $this->getUser();
        $by = $user instanceof User ? (string) ($user->getFirstname() ?: 'administrateur') : 'administrateur';
        $state = ['state' => 'queued', 'since' => time(), 'requestedAt' => gmdate(\DATE_ATOM), 'requestedBy' => $by, 'report' => [], 'error' => null];
        $this->jobs->put($tenant, $state);
        $this->bus->dispatch(new RunEssaiMessage($tenant, $by));

        return $this->json(['job' => $this->view($state)], 202);
    }

    /** @param array<string, mixed>|null $state */
    private function view(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return ['state' => $state['state'] ?? null, 'requestedAt' => $state['requestedAt'] ?? null, 'requestedBy' => $state['requestedBy'] ?? null,
            'startedAt' => $state['startedAt'] ?? null, 'finishedAt' => $state['finishedAt'] ?? null, 'report' => $state['report'] ?? [], 'error' => $state['error'] ?? null];
    }

    private function tenant(): string
    {
        return (string) $this->tenants->getTenantCode();
    }
}
