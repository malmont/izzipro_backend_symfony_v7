<?php

namespace App\Import\Controller;

use App\Import\CatalogImportService;
use App\Import\CustomerImportService;
use App\Import\ImportException;
use App\Import\ImportJobStore;
use App\Import\OrderArchive;
use App\Import\OrderImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import d'une boutique existante (`docs/import-boutique.md`), `ROLE_ADMIN` (`access_control` `/api/site/imports`) : le catalogue (lot
 * IMP-1) et les clients (lot IMP-2, champ `kind=customers` au dépôt). Chaque import garde sa nature (`kind`) : les routes d'un import
 * aiguillent vers le service de sa nature. Un refus rend `{ error: CODE, message, … }` avec un code stable.
 */
class SiteImportController extends AbstractController
{
    public function __construct(private readonly CatalogImportService $imports, private readonly CustomerImportService $customers, private readonly ImportJobStore $store,
        private readonly OrderImportService $orders, private readonly OrderArchive $archive)
    {
    }

    /** Les derniers imports du site, catalogue et clients mêlés, du plus récent au plus ancien → 200 { imports: [...] } */
    #[Route('/api/site/imports', name: 'site_imports_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $this->customers->purgeDue(); // les invités dont le délai est passé sont effacés à la lecture aussi (le passage horaire le fait pour tous les sites)
        } catch (\Throwable) {
            // la liste se lit quand même
        }

        return $this->json(['imports' => array_map(fn (array $job): array => $this->serviceOf((string) $job['kind'])->summary($job), $this->store->latest())]);
    }

    /** Dépose un fichier d'export (multipart : `file`, `source` = shopify, `kind` = catalog (défaut) | customers | orders) et l'analyse sans rien écrire → 201, l'import analysé */
    #[Route('/api/site/imports', name: 'site_imports_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $kind = (string) $request->request->get('kind', ImportJobStore::KIND_CATALOG);
            if (!in_array($kind, ImportJobStore::KINDS, true)) {
                throw new ImportException('IMPORT_KIND_UNKNOWN', 422, ['kinds' => ImportJobStore::KINDS]);
            }
            $source = (string) $request->request->get('source', ImportJobStore::SOURCE_SHOPIFY);
            return $this->json($this->serviceOf($kind)->upload($request->files->get('file'), $source, $this->by()), Response::HTTP_CREATED);
        });
    }

    /** Un import : état, rapport d'analyse, résultat → 200 */
    #[Route('/api/site/imports/{id}', name: 'site_imports_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->of($id)->show($id)));
    }

    /** Confirme l'import analysé (catalogue : { visibility?, pos?, pricesIncludeTax? } ; clients : { attestMarketingConsent? }) : il s'exécute en tâche de fond → 202 */
    #[Route('/api/site/imports/{id}/confirm', name: 'site_imports_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            $content = trim((string) $request->getContent());
            $body = $content === '' ? [] : json_decode($content, true);
            if (!is_array($body)) {
                throw new ImportException('VALIDATION_FAILED', 400);
            }

            return $this->json($this->of($id)->confirm($id, $body, $this->by()), Response::HTTP_ACCEPTED);
        });
    }

    /**
     * Annule l'import : abandonné s'il n'a pas commencé ; catalogue : ses articles jamais vendus retirés s'il est terminé depuis moins de
     * 7 jours ; clients : ses invités qui n'ont pas accepté effacés, tant qu'il en reste → 200
     */
    #[Route('/api/site/imports/{id}/cancel', name: 'site_imports_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->of($id)->cancel($id, $this->by())));
    }

    /** Le fichier des lignes signalées (refus, codes-barres écartés, images refusées), pour un tableur → 200 text/csv */
    #[Route('/api/site/imports/{id}/issues.csv', name: 'site_imports_issues', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function issues(int $id): Response
    {
        try {
            return new Response($this->of($id)->issuesCsv($id), Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="import-%d-lignes-signalees.csv"', $id), 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (ImportException $e) {
            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra, $e->status);
        }
    }

    /** « Envoyer les invitations » aux clients repris par un import de clients : une par invité, une seule fois, en tâche de fond → 202 { importId, queued } */
    #[Route('/api/site/imports/{id}/invitations', name: 'site_imports_invite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function invite(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->customers->invite($id, $this->by()), Response::HTTP_ACCEPTED));
    }

    /** Les clients repris par un import de clients, avec leur état (`?state=imported|invited|accepted|kept`, `?page=`) → 200 { customers, page, pages, total } */
    #[Route('/api/site/imports/{id}/customers', name: 'site_imports_customers', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function customers(int $id, Request $request): JsonResponse
    {
        $state = $request->query->get('state');

        return $this->guard(fn () => $this->json($this->customers->customers($id, max(1, $request->query->getInt('page', 1)), is_string($state) && $state !== '' ? $state : null)));
    }

    /**
     * Lot IMP-3 : les commandes d'avant la migration d'un compte du site (`?userId=`, `?page=`), pour sa fiche côté administration →
     * 200 { orders: [{ ref, orderedAt, day, state, financialStatus, total, net, currency, items, lines: [{ name, quantity, unitPrice, productId }] }], page, pages, total }
     */
    #[Route('/api/site/imports/archive/orders', name: 'site_imports_archive_orders', methods: ['GET'])]
    public function archiveOrders(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        if (!is_string($userId) || preg_match('/^[1-9]\d{0,9}$/', $userId) !== 1) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => 'userId est attendu.', 'fields' => ['userId' => 'REQUIRED']], 422);
        }

        return $this->json($this->archive->ordersOf((int) $userId, max(1, $request->query->getInt('page', 1))));
    }

    /** Le service de la nature de cet import ; 404 pour un import inconnu */
    private function of(int $id): CatalogImportService|CustomerImportService|OrderImportService
    {
        $job = $this->store->find($id) ?? throw new ImportException('IMPORT_NOT_FOUND', 404);

        return $this->serviceOf((string) $job['kind']);
    }

    private function serviceOf(string $kind): CatalogImportService|CustomerImportService|OrderImportService
    {
        return match ($kind) {
            ImportJobStore::KIND_CUSTOMERS => $this->customers,
            ImportJobStore::KIND_ORDERS => $this->orders,
            default => $this->imports,
        };
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ImportException $e) {
            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra, $e->status);
        }
    }

    private function by(): ?string
    {
        return $this->getUser()?->getUserIdentifier();
    }
}
