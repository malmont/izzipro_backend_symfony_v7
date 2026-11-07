<?php

namespace App\Services\ProductAiService;

use App\Entity\Categories;
use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Entity\ProductTranslation;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Création d'un produit à partir d'une fiche validée (07/11/2026) : produit, variante avec prix et stock, traductions (celle de la langue
 * de rédaction plus celles fournies ou traduites), photos (URL du médiathèque ou nom de fichier), catégories, canaux (site, caisse, landing)
 * et modes de vente. Même objet quel que soit le client : back-office, site, application de gestion.
 */
class ProductSheetCreator
{
    public const MESSAGES = ['NAME_REQUIRED' => 'Le nom du produit est obligatoire.', 'PRICE_INVALID' => 'Prix en cents attendu, supérieur à zéro.', 'CATEGORY_UNKNOWN' => 'Catégorie inconnue.', 'PICTURE_INVALID' => 'Photo invalide : adresse https ou nom de fichier attendu.'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit)
    {
    }

    /**
     * @param array<string, mixed> $input {name, description?, moreInformations?, tags?, language (fr|en), translations?: {en: {name, description?, moreInformations?, tags?}},
     *                                   priceCents, stockQuantity?, categoryIds?: int[], pictures?: string[], channels?: {web?, pos?, landing?}, modes?: {sale?, rental?, subscription?},
     *                                   barcode?, code?, requiresShipping?}
     * @return array{status: int, body: array<string, mixed>}
     */
    public function create(array $input, ?string $by): array
    {
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
        if ($name === '') {
            return $this->refuse('NAME_REQUIRED');
        }
        $price = (int) ($input['priceCents'] ?? 0);
        if ($price <= 0 || $price > 100_000_000) {
            return $this->refuse('PRICE_INVALID');
        }
        $em = $this->emProvider->getEntityManager();
        $categories = [];
        foreach (array_unique(array_map('intval', (array) ($input['categoryIds'] ?? []))) as $id) {
            $category = $id > 0 ? $em->getRepository(Categories::class)->find($id) : null;
            if ($category === null) {
                return $this->refuse('CATEGORY_UNKNOWN', ['categoryId' => $id]);
            }
            $categories[] = $category;
        }
        $pictures = [];
        foreach ((array) ($input['pictures'] ?? []) as $p) {
            $p = trim((string) $p);
            if ($p === '' || strlen($p) > 500 || (!preg_match('#^https://#', $p) && !preg_match('#^[A-Za-z0-9._/-]+$#', $p))) {
                return $this->refuse('PICTURE_INVALID', ['picture' => mb_substr($p, 0, 80)]);
            }
            $pictures[] = $p;
        }
        $language = in_array($input['language'] ?? 'fr', ['fr', 'en'], true) ? $input['language'] : 'fr';
        $channels = (array) ($input['channels'] ?? []);
        $modes = (array) ($input['modes'] ?? []);
        $slug = $this->uniqueSlug($name);
        $code = mb_substr(trim((string) ($input['code'] ?? '')), 0, 60) ?: 'IA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $product = (new Product())->setName($name)->setDescription($this->text($input['description'] ?? null))->setMoreinformations($this->text($input['moreInformations'] ?? null))
            ->setPrice((float) $price)->setCode($code)->setSlug($slug)->setTags(mb_substr(trim((string) ($input['tags'] ?? '')), 0, 500) ?: null)->setQuantity((int) ($input['stockQuantity'] ?? 0))
            ->setIsWeb(($channels['web'] ?? true) !== false)->setIsPos(($channels['pos'] ?? false) === true)->setIsLandingPage(($channels['landing'] ?? false) === true)
            ->setSaleEnabled(($modes['sale'] ?? true) !== false)->setRentalEnabled(($modes['rental'] ?? false) === true)->setSubscriptionEnabled(($modes['subscription'] ?? false) === true)
            ->setIsbestseller(false)->setIsnewarrival(true)->setIsfeatured(false)->setIsspecialoffer(false)->setIsPreOrder(false)->setIsAccessory(false)->setCustomizable(false)
            ->setRequiresShipping(($input['requiresShipping'] ?? true) !== false)->setCreatedAt(new \DateTimeImmutable());
        if (isset($input['barcode']) && trim((string) $input['barcode']) !== '') {
            $product->setBarcode(mb_substr(trim((string) $input['barcode']), 0, 64));
        }
        if ($pictures !== []) {
            $product->setImage($pictures[0]);
        }
        foreach ($categories as $category) {
            $product->addCategory($category);
        }
        $translations = [$language => ['name' => $name, 'description' => $this->text($input['description'] ?? null), 'moreInformations' => $this->text($input['moreInformations'] ?? null), 'tags' => mb_substr(trim((string) ($input['tags'] ?? '')), 0, 500) ?: null]];
        foreach ((array) ($input['translations'] ?? []) as $locale => $t) {
            if (!in_array($locale, ['fr', 'en'], true) || $locale === $language || !is_array($t) || trim((string) ($t['name'] ?? '')) === '') {
                continue;
            }
            $translations[$locale] = ['name' => mb_substr(trim((string) $t['name']), 0, 255), 'description' => $this->text($t['description'] ?? null), 'moreInformations' => $this->text($t['moreInformations'] ?? null), 'tags' => mb_substr(trim((string) ($t['tags'] ?? '')), 0, 500) ?: null];
        }
        foreach ($translations as $locale => $t) {
            $product->addTranslation((new ProductTranslation())->setLocale($locale)->setName($t['name'])->setDescription($t['description'])->setMoreinformations($t['moreInformations'])->setTags($t['tags'])->setSlug($locale === $language ? $slug : $this->uniqueSlug($t['name'])));
        }
        foreach ($pictures as $p) {
            $product->addPicture((new ProductPicture())->setImageUrl($p));
        }
        $variant = (new ProductVariant())->setStockQuantity(max(0, (int) ($input['stockQuantity'] ?? 0)))->setPrice($price);
        $variant->setProduct($product);
        $em->persist($product);
        $em->persist($variant);
        $em->flush();
        $this->audit->record('product', (int) $product->getId(), 'create', [], ['name' => $name, 'priceCents' => $price, 'source' => 'ai_sheet', 'languages' => array_keys($translations), 'pictures' => count($pictures), 'channels' => ['web' => $product->isWeb(), 'pos' => $product->isPos(), 'landing' => $product->isLandingPage()], 'by' => $by], ['product']);

        return ['status' => 201, 'body' => ['productId' => (int) $product->getId(), 'variantId' => (int) $variant->getId(), 'slug' => $slug, 'code' => $code, 'languages' => array_keys($translations), 'pictures' => count($pictures)]];
    }

    private function uniqueSlug(string $name): string
    {
        $base = (new AsciiSlugger('fr'))->slug($name)->lower()->toString() ?: 'produit';
        $slug = $base;
        $repo = $this->emProvider->getEntityManager()->getRepository(Product::class);
        for ($i = 2; $repo->findOneBy(['slug' => $slug]) !== null && $i < 1000; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function text(mixed $value): ?string
    {
        $t = is_string($value) ? trim($value) : '';

        return $t === '' ? null : mb_substr($t, 0, 20000);
    }

    private function refuse(string $code, array $extra = []): array
    {
        return ['status' => 422, 'body' => ['error' => $code, 'message' => self::MESSAGES[$code]] + $extra];
    }
}
