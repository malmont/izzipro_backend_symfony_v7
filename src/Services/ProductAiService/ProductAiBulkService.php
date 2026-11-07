<?php

namespace App\Services\ProductAiService;

use App\Entity\AiJob;
use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Entity\ProductTranslation;
use App\Message\ProductAiBulkMessage;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiJobService;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * IA en lot sur le catalogue (07/11/2026) : en un clic, rédiger toutes les fiches incomplètes (`sheets`), traduire tous les produits sans
 * traduction dans une langue du site (`translations`), générer les images mannequin manquantes (`images`, quand un fournisseur est branché).
 * `plan()` compte les produits concernés et les crédits nécessaires, les compare aux crédits restants du mois et dit ce qui manque ;
 * `start()` crée une tâche de fond (`ai_job`) traitée par le worker IA (`ProductAiBulkHandler` → `run()`), produit par produit, un crédit
 * réservé à la fois : la tâche s'arrête proprement quand les crédits sont épuisés et dit où elle en est.
 */
class ProductAiBulkService
{
    public const KINDS = ['sheets', 'translations', 'images'];
    public const COSTS = ['sheets' => ProductSheetDrafter::COST_DRAFT, 'translations' => ProductSheetDrafter::COST_TRANSLATE, 'images' => ProductImageGeneratorInterface::COST];
    public const LABELS = ['sheets' => 'Fiches produit rédigées par l\'IA', 'translations' => 'Traductions des fiches', 'images' => 'Images mannequin'];
    public const MAX_PER_JOB = 500;
    private const MIN_DESCRIPTION = 40;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly ProductSheetDrafter $drafter,
        private readonly ProductImageGeneratorInterface $images,
        private readonly LandingAiQuotaService $quota,
        private readonly LandingAiJobService $jobs,
        private readonly MessageBusInterface $bus,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly ?EmailLocale $siteLocale = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function plan(string $kind, bool $overwrite = false, bool $charge = true): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('kind : sheets, translations ou images attendu');
        }
        $candidates = $this->candidates($kind, $overwrite);
        $credits = $this->quota->credits();
        $perItem = self::COSTS[$kind];
        $needed = $charge ? count($candidates['items']) * $perItem : 0;
        $remaining = (int) ($credits['remaining'] ?? 0);
        $available = $kind !== 'images' || $this->images->isConfigured();

        return [
            'kind' => $kind, 'label' => self::LABELS[$kind], 'available' => $available, 'unavailableReason' => $available ? null : 'Aucun fournisseur d\'images n\'est encore branché sur la plateforme.',
            'total' => count($candidates['items']), 'items' => array_slice($candidates['items'], 0, 50), 'skipped' => $candidates['skipped'], 'languages' => $this->languages(),
            'creditsPerItem' => $perItem, 'creditsNeeded' => $needed, 'creditsRemaining' => $remaining, 'monthlyCredits' => (int) ($credits['monthly'] ?? 0), 'resetAt' => $credits['resetAt'] ?? null,
            'enough' => $needed <= $remaining, 'missingCredits' => max(0, $needed - $remaining), 'doableNow' => $perItem > 0 ? min(count($candidates['items']), intdiv($remaining, $perItem)) : count($candidates['items']),
            'charge' => $charge,
        ];
    }

    /**
     * Lance le lot : réserve un crédit par produit au fil du traitement (pas de blocage global). Sans assez de crédits : refusé sauf `partial`.
     * @return array{status: int, body: array<string, mixed>}
     */
    public function start(string $kind, bool $overwrite, bool $partial, ?int $limit, ?string $user, bool $charge = true): array
    {
        $plan = $this->plan($kind, $overwrite, $charge);
        if (!$plan['available']) {
            return ['status' => 409, 'body' => ['error' => 'IMAGE_PROVIDER_MISSING', 'message' => $plan['unavailableReason'], 'plan' => $plan]];
        }
        if ($plan['total'] === 0) {
            return ['status' => 409, 'body' => ['error' => 'NOTHING_TO_DO', 'message' => 'Rien à faire : tous les produits sont déjà complets pour cette action.', 'plan' => $plan]];
        }
        if ($charge && !$plan['enough'] && !$partial) {
            return ['status' => 402, 'body' => ['error' => 'AI_CREDITS_EXHAUSTED', 'message' => sprintf('Il faut %d crédit(s), il en reste %d : il en manque %d. Lancez quand même pour traiter les %d premier(s) produit(s), ou ajoutez des crédits.', $plan['creditsNeeded'], $plan['creditsRemaining'], $plan['missingCredits'], $plan['doableNow']), 'plan' => $plan]];
        }
        $ids = array_column($this->candidates($kind, $overwrite)['items'], 'id');
        $max = min(self::MAX_PER_JOB, $limit !== null && $limit > 0 ? $limit : self::MAX_PER_JOB, $charge && $partial ? max(0, $plan['doableNow']) : self::MAX_PER_JOB);
        $ids = array_slice($ids, 0, $max);
        if ($ids === []) {
            return ['status' => 402, 'body' => ['error' => 'AI_CREDITS_EXHAUSTED', 'message' => 'Aucun crédit disponible pour commencer.', 'plan' => $plan]];
        }
        $usage = $this->quota->reserve('assistant', 'product_bulk_' . $kind, 0, $user, sprintf('Lot IA %s : %d produit(s)', $kind, count($ids)), 86400);
        $job = $this->jobs->create($usage, $user, json_encode(['kind' => $kind, 'overwrite' => $overwrite, 'productIds' => $ids, 'charge' => $charge, 'creditsPerItem' => self::COSTS[$kind], 'startedBy' => $user], JSON_UNESCAPED_UNICODE));
        $this->bus->dispatch(new ProductAiBulkMessage($job->getId(), (string) $this->tenants->getCurrentTenantCode()));

        return ['status' => 202, 'body' => ['jobId' => $job->getId(), 'kind' => $kind, 'products' => count($ids), 'creditsNeeded' => $charge ? count($ids) * self::COSTS[$kind] : 0, 'partial' => count($ids) < $plan['total']]];
    }

    /** @return array<string, mixed>|null état d'une tâche : status, progress, errors ; null si inconnue */
    public function status(string $jobId): ?array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return null;
        }
        $input = json_decode((string) $job->getInput(), true) ?: [];
        $result = json_decode((string) $job->getResult(), true) ?: [];

        return ['jobId' => $job->getId(), 'status' => $job->getStatus(), 'kind' => $input['kind'] ?? null, 'total' => count($input['productIds'] ?? []), 'createdAt' => $job->getCreatedAt()->format('Y-m-d H:i:s'), 'finishedAt' => $job->getFinishedAt()?->format('Y-m-d H:i:s'), 'error' => $job->getError()]
            + ['done' => (int) ($result['done'] ?? 0), 'failed' => (int) ($result['failed'] ?? 0), 'skipped' => (int) ($result['skipped'] ?? 0), 'creditsUsed' => (int) ($result['creditsUsed'] ?? 0), 'stoppedForCredits' => (bool) ($result['stoppedForCredits'] ?? false), 'items' => $result['items'] ?? [], 'current' => $result['current'] ?? null];
    }

    /** Exécution par le worker IA (tenant déjà sélectionné). Un crédit par produit, réservé puis consommé ou rendu. */
    public function run(string $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->getStatus() === AiJob::STATUS_DONE) {
            return;
        }
        $input = json_decode((string) $job->getInput(), true) ?: [];
        $kind = (string) ($input['kind'] ?? '');
        $ids = array_map('intval', (array) ($input['productIds'] ?? []));
        $overwrite = (bool) ($input['overwrite'] ?? false);
        $charge = ($input['charge'] ?? true) !== false;
        $user = $input['startedBy'] ?? null;
        $em = $this->emProvider->getEntityManager();
        $job->start();
        $em->flush();
        $result = ['done' => 0, 'failed' => 0, 'skipped' => 0, 'creditsUsed' => 0, 'stoppedForCredits' => false, 'items' => [], 'current' => null];
        $save = function (bool $final = false) use ($job, &$result, $em) {
            $json = json_encode($result, JSON_UNESCAPED_UNICODE);
            $final ? $job->succeed($json) : $job->progress($json);
            $em->flush();
        };
        foreach ($ids as $id) {
            $product = $em->getRepository(Product::class)->find($id);
            if ($product === null) {
                $result['skipped']++;
                $result['items'][] = ['id' => $id, 'status' => 'skipped', 'note' => 'produit supprimé'];
                continue;
            }
            $result['current'] = ['id' => $id, 'name' => $product->getName()];
            $save();
            try {
                $note = match ($kind) {
                    'sheets' => $this->describeProduct($product, $overwrite, $user, $charge),
                    'translations' => $this->translateProduct($product, $overwrite, $user, $charge),
                    'images' => $this->generateProductImage($product, 'mannequin', $user, $charge),
                    default => throw new \InvalidArgumentException('kind inconnu'),
                };
                $result['done']++;
                $result['creditsUsed'] += $charge ? self::COSTS[$kind] : 0;
                $result['items'][] = ['id' => $id, 'name' => $product->getName(), 'status' => 'done', 'note' => $note];
            } catch (LandingAiException $e) {
                if ($e->getStatusCode() === 402) {
                    $result['stoppedForCredits'] = true;
                    $result['items'][] = ['id' => $id, 'name' => $product->getName(), 'status' => 'stopped', 'note' => $e->getMessage()];
                    break;
                }
                $result['failed']++;
                $result['items'][] = ['id' => $id, 'name' => $product->getName(), 'status' => 'failed', 'note' => $e->getMessage()];
                if ($e->getStatusCode() === 429) {
                    break;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['items'][] = ['id' => $id, 'name' => $product->getName(), 'status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 200)];
                $this->logger->error(sprintf('[Lot IA %s] produit %d : %s', $kind, $id, $e->getMessage()));
            }
        }
        $result['current'] = null;
        $save(true);
        $this->audit->record('product', 0, 'ai_bulk', [], ['jobId' => $jobId, 'kind' => $kind] + array_diff_key($result, ['items' => 1]), ['products']);
    }

    // ---------------------------------------------------------------- candidats

    /** @return array{items: list<array{id: int, name: string, reason: string}>, skipped: list<array{id: int, name: string, reason: string}>} */
    private function candidates(string $kind, bool $overwrite): array
    {
        $em = $this->emProvider->getEntityManager();
        $items = [];
        $skipped = [];
        $languages = $this->languages();
        /** @var Product $p */
        foreach ($em->getRepository(Product::class)->findBy([], ['id' => 'ASC']) as $p) {
            $photos = $this->photos($p);
            if ($kind === 'sheets') {
                $incomplete = mb_strlen(trim(strip_tags((string) $p->getDescription()))) < self::MIN_DESCRIPTION;
                if (!$overwrite && !$incomplete) {
                    continue;
                }
                if ($photos === []) {
                    $skipped[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'reason' => 'aucune photo'];
                    continue;
                }
                $items[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'reason' => $incomplete ? 'description absente ou trop courte' : 'réécriture demandée'];
            } elseif ($kind === 'translations') {
                $missing = $this->missingLanguages($p, $languages['others'], $overwrite);
                if ($missing !== []) {
                    $items[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'reason' => 'manque : ' . implode(', ', $missing)];
                }
            } else {
                $hasAi = false;
                foreach ($p->getPictures() as $pic) {
                    $hasAi = $hasAi || $pic->getSource() === 'ai';
                }
                if ($hasAi && !$overwrite) {
                    continue;
                }
                if ($photos === []) {
                    $skipped[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'reason' => 'aucune photo du vêtement'];
                    continue;
                }
                $items[] = ['id' => (int) $p->getId(), 'name' => (string) $p->getName(), 'reason' => 'aucune image mannequin'];
            }
        }
        $em->clear();

        return ['items' => $items, 'skipped' => $skipped];
    }

    /** @return array{main: string, others: list<string>} */
    private function languages(): array
    {
        $main = $this->siteLocale?->language(null) ?? 'fr';
        $main = isset(ProductSheetDrafter::LANGUAGES[$main]) ? $main : 'fr';

        return ['main' => $main, 'others' => array_values(array_diff(array_keys(ProductSheetDrafter::LANGUAGES), [$main]))];
    }

    /** @return list<string> */
    private function missingLanguages(Product $p, array $targets, bool $overwrite): array
    {
        $have = [];
        foreach ($p->getTranslations() as $t) {
            if (trim((string) $t->getName()) !== '' && trim(strip_tags((string) $t->getDescription())) !== '') {
                $have[(string) $t->getLocale()] = true;
            }
        }

        return array_values(array_filter($targets, fn ($l) => $overwrite || !isset($have[$l])));
    }

    /** Photos exploitables d'un produit : fichiers locaux en base64, adresses https telles quelles @return list<array{data?: string, mime?: string, url?: string}> */
    private function photos(Product $p): array
    {
        $out = [];
        $names = array_values(array_filter([$p->getImage(), ...array_map(fn (ProductPicture $pic) => $pic->getImageUrl(), $p->getPictures()->toArray())]));
        foreach (array_unique($names) as $name) {
            if (count($out) >= ProductSheetDrafter::MAX_PHOTOS) {
                break;
            }
            if (preg_match('#^https://#', (string) $name)) {
                $out[] = ['url' => (string) $name];
                continue;
            }
            $path = $this->projectDir . '/var/storage/public_bucket/assets/uploads/products/' . ltrim((string) $name, '/');
            if (is_file($path) && filesize($path) > 0 && filesize($path) <= ProductSheetDrafter::MAX_PHOTO_BYTES) {
                $mime = (string) (mime_content_type($path) ?: '');
                if (in_array($mime, ProductSheetDrafter::MIME_TYPES, true)) {
                    $out[] = ['data' => (string) file_get_contents($path), 'mime' => $mime];
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- exécution par produit

    /** Rédige (ou réécrit) la fiche d'un produit depuis ses photos : description, informations, mots-clés et traduction principale ; un crédit */
    public function describeProduct(Product $product, bool $overwrite, ?string $user, bool $charge): string
    {
        $em = $this->emProvider->getEntityManager();
        $photos = $this->photos($product);
        if ($photos === []) {
            throw new \RuntimeException('aucune photo exploitable');
        }
        $languages = $this->languages();
        $categories = array_map(fn ($c) => (string) $c->getName(), $product->getCategory()->toArray());
        $comment = 'Nom actuel : ' . $product->getName() . ($categories !== [] ? ' ; catégorie : ' . implode(', ', $categories) : '') . ($product->getPrice() ? sprintf(' ; prix de vente : %.2f', $product->getPrice() / 100) : '');
        $draft = $this->drafter->draft($photos, $comment, [], $languages['main'], $user, $charge)['draft'];
        $changed = [];
        if ($overwrite || trim(strip_tags((string) $product->getDescription())) === '') {
            $product->setDescription($draft['description']);
            $changed[] = 'description';
        }
        if ($overwrite || trim((string) $product->getMoreinformations()) === '') {
            $product->setMoreinformations($draft['moreInformations']);
            $changed[] = 'moreInformations';
        }
        if ($overwrite || trim((string) $product->getTags()) === '') {
            $product->setTags($draft['tags']);
            $changed[] = 'tags';
        }
        $main = null;
        foreach ($product->getTranslations() as $t) {
            if ((string) $t->getLocale() === $languages['main']) {
                $main = $t;
            }
        }
        if ($main === null) {
            $main = (new ProductTranslation())->setLocale($languages['main'])->setName((string) $product->getName())->setSlug((string) $product->getSlug());
            $product->addTranslation($main);
            $em->persist($main);
        }
        if ($overwrite || trim(strip_tags((string) $main->getDescription())) === '') {
            $main->setDescription($draft['description'])->setMoreinformations($draft['moreInformations'])->setTags($draft['tags']);
        }
        $em->flush();

        return 'rempli : ' . (implode(', ', $changed) ?: 'traduction principale');
    }

    /** Traduit la fiche dans les autres langues du site (manquantes, ou toutes si $overwrite) ; un crédit */
    public function translateProduct(Product $product, bool $overwrite, ?string $user, bool $charge): string
    {
        $em = $this->emProvider->getEntityManager();
        $languages = $this->languages();
        $targets = $this->missingLanguages($product, $languages['others'], $overwrite);
        if ($targets === []) {
            throw new \RuntimeException('déjà traduit');
        }
        $sheet = ['name' => (string) $product->getName(), 'description' => $product->getDescription(), 'moreInformations' => $product->getMoreinformations(), 'tags' => $product->getTags()];
        foreach ($product->getTranslations() as $t) {
            if ((string) $t->getLocale() === $languages['main'] && trim((string) $t->getName()) !== '') {
                $sheet = ['name' => (string) $t->getName(), 'description' => $t->getDescription() ?: $product->getDescription(), 'moreInformations' => $t->getMoreinformations() ?: $product->getMoreinformations(), 'tags' => $t->getTags() ?: $product->getTags()];
            }
        }
        $translations = $this->drafter->translate($sheet, $languages['main'], $targets, $user, $charge)['translations'];
        $slugger = new AsciiSlugger('fr');
        foreach ($translations as $locale => $tr) {
            $existing = null;
            foreach ($product->getTranslations() as $t) {
                if ((string) $t->getLocale() === $locale) {
                    $existing = $t;
                }
            }
            if ($existing === null) {
                $existing = (new ProductTranslation())->setLocale($locale);
                $product->addTranslation($existing);
                $em->persist($existing);
            }
            $existing->setName($tr['name'])->setDescription($tr['description'])->setMoreinformations($tr['moreInformations'])->setTags($tr['tags'])->setSlug($slugger->slug($tr['name'])->lower()->toString() . '-' . $locale);
        }
        $em->flush();

        return 'traduit en ' . implode(', ', array_keys($translations));
    }

    /** Image générée depuis une photo existante : `mannequin` (personne portant le vêtement) ou `packshot` (produit seul, fond propre) ; cinq crédits */
    public function generateProductImage(Product $product, string $style, ?string $user, bool $charge): string
    {
        if (!$this->images->isConfigured()) {
            throw new \RuntimeException('aucun fournisseur d\'images');
        }
        $photos = $this->photos($product);
        if ($photos === []) {
            throw new \RuntimeException('aucune photo du vêtement');
        }
        $stats = new LandingAiUsageStats('image-generator');
        $usage = $charge ? $this->quota->reserve('assistant', 'product_image', ProductImageGeneratorInterface::COST, $user, 'Image mannequin : ' . $product->getName()) : null;
        try {
            $generated = $this->images->generate($photos[0], ['style' => $style === 'packshot' ? 'packshot' : 'mannequin', 'comment' => (string) $product->getName()]);
            $dir = $this->projectDir . '/var/storage/public_bucket/assets/uploads/products';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('stockage des images indisponible');
            }
            $count = 0;
            foreach ($generated as $img) {
                $ext = ['image/png' => 'png', 'image/webp' => 'webp'][$img['mime']] ?? 'jpg';
                $name = sprintf('ia-%d-%s.%s', $product->getId(), bin2hex(random_bytes(4)), $ext);
                file_put_contents($dir . '/' . $name, $img['data']);
                $product->addPicture((new ProductPicture())->setImageUrl($name)->setSource('ai'));
                $count++;
            }
            $this->emProvider->getEntityManager()->flush();
            if ($usage !== null) {
                $this->quota->complete($usage, $stats, sprintf('%d image(s)', $count));
            }

            return sprintf('%d image(s) générée(s)', $count);
        } catch (\Throwable $e) {
            if ($usage !== null) {
                $this->quota->release($usage, $stats);
            }
            throw $e;
        }
    }
}
