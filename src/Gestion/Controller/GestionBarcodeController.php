<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionBarcodeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Codes-barres du catalogue dans l'application de gestion (lot CB-3, 15/11/2026, `docs/codes-barres.md` § 8), `ROLE_ADMIN`
 * (`access_control` `/api/gestion`) et module de gestion actif (garde par chemin) : chercher ce que porte un code, compter et
 * générer les codes manquants, générer le code d'une fiche, poser ou retirer un code saisi ou scanné, étiquettes hors commande.
 * Messages dans la langue de `?locale=` (en, français sinon). Aucune règle nouvelle : tout passe par `CatalogBarcodeService`.
 */
#[Route('/api/gestion/codes-barres')]
class GestionBarcodeController extends AbstractController
{
    public function __construct(private readonly GestionBarcodeUseCase $useCase)
    {
    }

    /** Ce que porte un code scanné ou saisi → 200 { code, found, refusal, currency, sheet } ; 422 VALIDATION_FAILED { code: REQUIRED } */
    #[Route('/recherche', name: 'gestion_codes_barres_recherche', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        return $this->json($this->useCase->search($request->query->all()['code'] ?? null, self::locale($request)));
    }

    /** Fiches sans code-barres → 200 { variants, products, total, limit } */
    #[Route('/manquants', name: 'gestion_codes_barres_manquants', methods: ['GET'])]
    public function missing(): JsonResponse
    {
        return $this->json($this->useCase->missing());
    }

    /** Un code généré pour ce qui n'en a pas, au plus `limit` par appel, jamais un code remplacé → 200 { written, variants, products, remaining, limit } */
    #[Route('/manquants/generer', name: 'gestion_codes_barres_manquants_generer', methods: ['POST'])]
    public function generateMissing(): JsonResponse
    {
        return $this->json($this->useCase->generateMissing());
    }

    /** Un code généré pour une variante qui n'en a pas → 200 { barcode, sheet } ; 404 VARIANT_NOT_FOUND ; 409 BARCODE_ALREADY_SET */
    #[Route('/variantes/{id}/generer', name: 'gestion_codes_barres_variante_generer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateVariant(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->generate('variant', $id, self::locale($request)));
    }

    /** Un code généré pour un article sans variante qui n'en a pas → 200 { barcode, sheet } ; 404 PRODUCT_NOT_FOUND ; 409 BARCODE_ALREADY_SET, BARCODE_HAS_VARIANTS */
    #[Route('/articles/{id}/generer', name: 'gestion_codes_barres_article_generer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateProduct(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->generate('product', $id, self::locale($request)));
    }

    /** Pose un code saisi ou scanné sur une variante (`barcode` vide ou null : le retire) → 200 { barcode, changed, warning, sheet } ; 422 BARCODE_INVALID, BARCODE_RESERVED ; 409 BARCODE_ALREADY_USED { holder } */
    #[Route('/variantes/{id}', name: 'gestion_codes_barres_variante_put', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function putVariant(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->put('variant', $id, self::body($request), self::locale($request)));
    }

    /** Pose un code saisi ou scanné sur un article (`barcode` vide ou null : le retire) → 200 { barcode, changed, warning, sheet } ; mêmes refus */
    #[Route('/articles/{id}', name: 'gestion_codes_barres_article_put', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function putProduct(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->put('product', $id, self::body($request), self::locale($request)));
    }

    /** Planche d'étiquettes d'une sélection hors commande (A4, 50 × 30 mm) → 200 application/pdf, en-tête X-Labels-Count ; une lecture, rien n'est écrit */
    #[Route('/etiquettes/pdf', name: 'gestion_codes_barres_etiquettes_pdf', methods: ['POST'])]
    public function pdf(Request $request): Response
    {
        $pdf = $this->useCase->pdf(self::body($request), self::locale($request));

        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="etiquettes.pdf"',
            'X-Labels-Count' => (string) $pdf['count'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }

    private static function locale(Request $request): string
    {
        $asked = $request->query->get('locale');

        return is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr';
    }
}
