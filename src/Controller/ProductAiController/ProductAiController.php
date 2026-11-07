<?php

namespace App\Controller\ProductAiController;

use App\Entity\Categories;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\ProductAiService\ProductSheetCreator;
use App\Services\ProductAiService\ProductSheetDrafter;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\MediaUseCase\UploadMediaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche produit par IA (07/11/2026), pour le site, le back-office et l'application de gestion. Administrateurs du site ; crédits IA du
 * site (le propriétaire de la plateforme ne paie pas).
 * - POST /api/products/ai/sheet (multipart : photos[] 1 à 4, comment?, categoryId?, locale?, store? = 1) → { draft, pictures: [{id, url}], credits } ;
 *   402 AI_CREDITS_EXHAUSTED, 422 PHOTO_INVALID, 429 AI_TOO_MANY_FAILURES, 503 AI_UNAVAILABLE. Rien n'est créé ; les photos sont déposées
 *   dans la médiathèque (store) pour servir à la création.
 * - POST /api/products/ai/translate { name, description?, moreInformations?, tags?, source? (langue du site), targets? (fr, en) } → { translations, credits }.
 * - POST /api/products/ai/create { name, description?, moreInformations?, tags?, language?, translations?, priceCents, stockQuantity?, categoryIds?,
 *   pictures? (URL https ou nom de fichier), channels? {web, pos, landing}, modes? {sale, rental, subscription}, barcode?, translate? (bool :
 *   traduction automatique dans l'autre langue du site, un crédit) } → 201 { productId, variantId, slug, code, languages, pictures, translated } ;
 *   422 NAME_REQUIRED | PRICE_INVALID | CATEGORY_UNKNOWN | PICTURE_INVALID.
 */
#[Route('/api/products/ai', priority: 30)]
#[IsGranted('ROLE_ADMIN')]
class ProductAiController extends AbstractController
{
    public function __construct(
        private readonly ProductSheetDrafter $drafter,
        private readonly ProductSheetCreator $creator,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingAiQuotaService $quota,
        private readonly UploadMediaUseCase $upload,
        private readonly ?EmailLocale $siteLocale = null,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    #[Route('/sheet', name: 'api_products_ai_sheet', methods: ['POST'])]
    public function sheet(Request $request): JsonResponse
    {
        $files = $request->files->get('photos');
        $files = $files instanceof UploadedFile ? [$files] : (is_array($files) ? array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile)) : []);
        if ($files === []) {
            $single = $request->files->get('photo');
            $files = $single instanceof UploadedFile ? [$single] : [];
        }
        if ($files === []) {
            return $this->json(['error' => 'PHOTO_REQUIRED', 'code' => 'PHOTO_REQUIRED', 'message' => 'Au moins une photo (champ photos[] ou photo, multipart/form-data).'], 422);
        }
        $photos = [];
        foreach ($files as $f) {
            if (!$f->isValid()) {
                return $this->json(['error' => 'PHOTO_INVALID', 'code' => 'PHOTO_INVALID', 'message' => 'Photo illisible.'], 422);
            }
            $photos[] = ['data' => (string) file_get_contents($f->getPathname()), 'mime' => (string) ($f->getMimeType() ?: $f->getClientMimeType())];
        }
        $language = $this->language($request->request->get('locale'));
        $categoryHint = (int) $request->request->get('categoryId', 0);
        $categories = $this->categoryNames();
        try {
            $result = $this->drafter->draft($photos, trim((string) $request->request->get('comment', '')), array_values($categories), $language, $this->getUser()?->getUserIdentifier(), !$this->isGranted('ROLE_SUPER_ADMIN'), $this->currency?->code());
        } catch (LandingAiException $e) {
            return $this->aiError($e);
        }
        $draft = $result['draft'];
        $draft['categoryId'] = $categoryHint > 0 && isset($categories[$categoryHint]) ? $categoryHint : ($draft['categoryName'] !== null ? (array_search($draft['categoryName'], $categories, true) ?: null) : null);
        $pictures = [];
        if (filter_var($request->request->get('store', '1'), FILTER_VALIDATE_BOOLEAN)) {
            foreach ($files as $f) {
                try {
                    $media = $this->upload->execute($f, $draft['name'], false, $request->getSchemeAndHttpHost());
                    $pictures[] = ['id' => $media->id, 'url' => $media->url];
                } catch (HttpException $e) {
                    $pictures[] = ['id' => null, 'url' => null, 'error' => $e->getMessage()];
                }
            }
        }

        return $this->json(['draft' => $draft, 'pictures' => $pictures, 'usage' => $result['usage'], 'credits' => $this->quota->credits()]);
    }

    #[Route('/translate', name: 'api_products_ai_translate', methods: ['POST'])]
    public function translate(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->json(['error' => 'NAME_REQUIRED', 'code' => 'NAME_REQUIRED', 'message' => 'name attendu.'], 422);
        }
        $source = $this->language($body['source'] ?? null);
        $targets = is_array($body['targets'] ?? null) ? array_map('strval', $body['targets']) : array_values(array_diff(array_keys(ProductSheetDrafter::LANGUAGES), [$source]));
        try {
            $result = $this->drafter->translate(['name' => (string) $body['name'], 'description' => $body['description'] ?? null, 'moreInformations' => $body['moreInformations'] ?? null, 'tags' => $body['tags'] ?? null], $source, $targets, $this->getUser()?->getUserIdentifier(), !$this->isGranted('ROLE_SUPER_ADMIN'));
        } catch (LandingAiException $e) {
            return $this->aiError($e);
        }

        return $this->json($result + ['credits' => $this->quota->credits()]);
    }

    #[Route('/create', name: 'api_products_ai_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $body['language'] = $this->language($body['language'] ?? null);
        $translated = [];
        if (($body['translate'] ?? false) === true && trim((string) ($body['name'] ?? '')) !== '') {
            try {
                $result = $this->drafter->translate(['name' => (string) $body['name'], 'description' => $body['description'] ?? null, 'moreInformations' => $body['moreInformations'] ?? null, 'tags' => $body['tags'] ?? null], $body['language'], array_values(array_diff(array_keys(ProductSheetDrafter::LANGUAGES), [$body['language']])), $this->getUser()?->getUserIdentifier(), !$this->isGranted('ROLE_SUPER_ADMIN'));
                $body['translations'] = ($body['translations'] ?? []) + $result['translations'];
                $translated = array_keys($result['translations']);
            } catch (LandingAiException $e) {
                return $this->aiError($e);
            }
        }
        $result = $this->creator->create($body, $this->getUser()?->getUserIdentifier());
        if ($result['status'] !== 201) {
            return $this->json($result['body'] + ['code' => $result['body']['error']], $result['status']);
        }

        return $this->json($result['body'] + ['translated' => $translated], 201);
    }

    /** @return array<int, string> id => nom */
    private function categoryNames(): array
    {
        $out = [];
        foreach ($this->emProvider->getEntityManager()->getRepository(Categories::class)->findBy([], ['name' => 'ASC']) as $c) {
            if ($c->isVisible() !== false && trim((string) $c->getName()) !== '') {
                $out[(int) $c->getId()] = (string) $c->getName();
            }
        }

        return $out;
    }

    private function language(mixed $asked): string
    {
        $code = is_string($asked) ? strtolower(substr(trim($asked), 0, 2)) : '';

        return isset(ProductSheetDrafter::LANGUAGES[$code]) ? $code : ($this->siteLocale?->language(null) ?? 'fr');
    }

    private function aiError(LandingAiException $e): JsonResponse
    {
        $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 422 => 'PHOTO_INVALID', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

        return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage(), 'credits' => $this->quota->credits()], $e->getStatusCode(), $e->getHeaders());
    }
}
