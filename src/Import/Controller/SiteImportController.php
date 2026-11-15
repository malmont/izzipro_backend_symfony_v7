<?php

namespace App\Import\Controller;

use App\Import\CatalogImportService;
use App\Import\ImportException;
use App\Import\ImportJobStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import du catalogue d'une boutique existante (lot IMP-1, 15/11/2026, `docs/import-boutique.md`), `ROLE_ADMIN`
 * (`access_control` `/api/site/imports`). Un refus rend `{ error: CODE, message, … }` avec un code stable.
 */
class SiteImportController extends AbstractController
{
    public function __construct(private readonly CatalogImportService $imports)
    {
    }

    /** Les derniers imports du site → 200 { imports: [...] } */
    #[Route('/api/site/imports', name: 'site_imports_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['imports' => $this->imports->latest()]);
    }

    /** Dépose un fichier d'export (multipart : `file`, `source` = shopify) et l'analyse sans rien écrire → 201, l'import analysé */
    #[Route('/api/site/imports', name: 'site_imports_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->imports->upload($request->files->get('file'), (string) $request->request->get('source', ImportJobStore::SOURCE_SHOPIFY), $this->by()), Response::HTTP_CREATED));
    }

    /** Un import : état, rapport d'analyse, résultat → 200 */
    #[Route('/api/site/imports/{id}', name: 'site_imports_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->imports->show($id)));
    }

    /** Confirme l'import analysé { visibility?, pos?, pricesIncludeTax? } : il s'exécute en tâche de fond → 202 */
    #[Route('/api/site/imports/{id}/confirm', name: 'site_imports_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            $content = trim((string) $request->getContent());
            $body = $content === '' ? [] : json_decode($content, true);
            if (!is_array($body)) {
                throw new ImportException('VALIDATION_FAILED', 400);
            }

            return $this->json($this->imports->confirm($id, $body, $this->by()), Response::HTTP_ACCEPTED);
        });
    }

    /** Annule l'import : abandonné s'il n'a pas commencé, ses articles jamais vendus retirés s'il est terminé depuis moins de 7 jours → 200 */
    #[Route('/api/site/imports/{id}/cancel', name: 'site_imports_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->imports->cancel($id, $this->by())));
    }

    /** Le fichier des lignes signalées (refus, codes-barres écartés, images refusées), pour un tableur → 200 text/csv */
    #[Route('/api/site/imports/{id}/issues.csv', name: 'site_imports_issues', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function issues(int $id): Response
    {
        try {
            return new Response($this->imports->issuesCsv($id), Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => sprintf('attachment; filename="import-%d-lignes-signalees.csv"', $id), 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (ImportException $e) {
            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()] + $e->extra, $e->status);
        }
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
