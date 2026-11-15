<?php

namespace App\Services\BarcodeService;

use App\Cache\CacheInvalidationQueue;
use App\Cache\CacheTags;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Codes-barres du catalogue (lot CB-1, 15/11/2026, `docs/codes-barres.md`) : LE passage de tout chemin qui écrit un code d'article ou de
 * variante (fiches d'EasyAdmin, bouton « Générer », création d'une fiche par l'IA, « Générer les codes manquants »).
 *
 * La règle : sur un site, un code désigne UNE seule chose, un article ou une variante (jamais deux articles, deux variantes, une variante
 * et un article, même le sien). La base la garantit seule (déclencheur `catalog_barcode_guard`, un index unique par table) ; ce service
 * la fait respecter AVANT d'écrire, pour rendre un refus clair au lieu d'une erreur de la base, et tient ce que la base ne sait pas :
 * les caractères permis et les formes réservées à la caisse.
 *
 * - Un code saisi n'est jamais écrasé ni renuméroté : la génération ne touche que ce qui n'a pas de code (`BARCODE_ALREADY_SET` sinon),
 *   relu en base sous verrou de ligne, pour que deux demandes simultanées ne se recouvrent pas.
 * - Le code généré va à la variante ; à l'article seulement s'il n'a aucune variante (`BARCODE_HAS_VARIANTS` sinon).
 * - La génération se fait à la demande, jamais d'office.
 */
class CatalogBarcodeService
{
    public const ALREADY_USED = 'BARCODE_ALREADY_USED';
    public const ALREADY_SET = 'BARCODE_ALREADY_SET';
    public const HAS_VARIANTS = 'BARCODE_HAS_VARIANTS';

    /** Plafond d'un lancement de « Générer les codes manquants » : on relance pour la suite */
    public const BULK_LIMIT = 500;
    public const AUDIT_RESOURCE = 'catalog_barcode';

    private const SEQUENCE = 'catalog_barcode_seq';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit,
        private readonly CacheInvalidationQueue $cacheQueue, // écritures en SQL de la génération en masse : cache du site vidé à la validation (obligatoire : sans elle le cache ne serait jamais vidé, sans rien dire)
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Forme canonique (espaces autour retirés, vide → null, majuscules) : celle qu'on écrit et celle qu'on cherche */
    public function normalize(?string $raw): ?string
    {
        return CatalogBarcode::normalize($raw);
    }

    /**
     * Code saisi, prêt à être écrit sur `$for` : mis en forme, de forme permise, non réservé et libre. Rend la forme canonique, ou null
     * pour « pas de code ». `$for` est l'article ou la variante qui va le porter (son propre code ne le gêne pas) ; null pour une création.
     *
     * @throws CatalogBarcodeException `BARCODE_INVALID`, `BARCODE_RESERVED` ou `BARCODE_ALREADY_USED`
     */
    public function check(?string $raw, Product|ProductVariant|null $for = null): ?string
    {
        $code = $this->validate($raw);
        if ($code !== null) {
            $this->assertFree($code, $for);
        }

        return $code;
    }

    /**
     * Forme seule (sans lecture de la base) : rend la forme canonique ou null.
     *
     * @throws CatalogBarcodeException `BARCODE_INVALID` ou `BARCODE_RESERVED`
     */
    public function validate(?string $raw): ?string
    {
        $code = CatalogBarcode::normalize($raw);
        if ($code === null) {
            return null;
        }
        $refusal = CatalogBarcode::refusal($code);
        if ($refusal === CatalogBarcode::INVALID) {
            throw new CatalogBarcodeException($refusal, 'Code-barres refusé : 1 à 64 caractères, lettres sans accent, chiffres et - . _ / seulement, sans espace.');
        }
        if ($refusal === CatalogBarcode::RESERVED) {
            throw new CatalogBarcodeException($refusal, 'Ce code-barres a la forme d\'un document de la caisse (ticket, avoir, carte-cadeau, fiche client) ou d\'un coupon de fabricant : il ne peut pas désigner un article.');
        }

        return $code;
    }

    /**
     * Avertissement à montrer après l'enregistrement d'un code accepté, ou null : 13 chiffres dont la clé de contrôle est fausse.
     * `$before` : le code que la fiche portait ; un code inchangé n'avertit pas de nouveau (pas à chaque enregistrement de la fiche).
     */
    public function warning(?string $code, ?string $before = null): ?string
    {
        return $code !== null && $code !== $before && CatalogBarcode::hasWrongCheckDigit($code)
            ? 'Ce code de 13 chiffres n\'a pas une clé de contrôle d\'EAN-13 juste : il est accepté et sera imprimé en Code 128.'
            : null;
    }

    /**
     * Ce qui porte le code (forme canonique) sur le site, hors `$except` ; null si le code est libre.
     *
     * @return array{type: string, id: int, productId: int, label: string}|null
     */
    public function holder(string $code, Product|ProductVariant|null $except = null): ?array
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT 'product' AS type, id, id AS product_id FROM product WHERE barcode = :code"
            . " UNION ALL SELECT 'variant' AS type, id, product_id FROM product_variant WHERE barcode = :code ORDER BY type, id",
            ['code' => $code]
        );
        $exceptType = $except instanceof Product ? 'product' : ($except instanceof ProductVariant ? 'variant' : null);
        $exceptId = $except?->getId();
        foreach ($rows as $row) {
            if ($exceptId !== null && $row['type'] === $exceptType && (int) $row['id'] === $exceptId) {
                continue;
            }

            return ['type' => (string) $row['type'], 'id' => (int) $row['id'], 'productId' => (int) $row['product_id'], 'label' => $this->label((string) $row['type'], (int) $row['id'])];
        }

        return null;
    }

    /** @throws CatalogBarcodeException `BARCODE_ALREADY_USED`, avec ce qui porte déjà le code */
    public function assertFree(string $code, Product|ProductVariant|null $for = null): void
    {
        $holder = $this->holder($code, $for);
        if ($holder !== null) {
            throw new CatalogBarcodeException(self::ALREADY_USED, sprintf('Ce code-barres est déjà celui de « %s ».', $holder['label']), $holder);
        }
    }

    /** Un code généré libre (EAN-13 interne, préfixe 29) ; rien n'est écrit, le numéro tiré n'est jamais redonné */
    public function generate(): string
    {
        return $this->generateMany(1)[0];
    }

    /**
     * Donne un code généré à une variante, ou à un article sans variante, qui n'en a pas ; l'écrit et le rend.
     *
     * `$audit` (lot CB-3) : contexte d'une ligne au journal du site, écrite dans la même transaction (`catalog_barcode`, `generate`,
     * la fiche, le code) ; null : pas de ligne (les boutons des fiches d'EasyAdmin, inchangés).
     *
     * @param array<string, mixed>|null $audit
     *
     * @throws CatalogBarcodeException `BARCODE_ALREADY_SET` (un code n'est jamais écrasé), `BARCODE_HAS_VARIANTS`
     */
    public function generateFor(Product|ProductVariant $target, ?array $audit = null): string
    {
        $id = $target->getId();
        if ($id === null) {
            throw new \LogicException('Code-barres : la fiche doit être enregistrée avant de recevoir un code généré');
        }
        $em = $this->em();
        $conn = $em->getConnection();
        $table = $target instanceof Product ? 'product' : 'product_variant';
        $conn->beginTransaction();
        try {
            // La valeur de la BASE, sous verrou de ligne : deux demandes simultanées ne se recouvrent pas (la seconde attend, puis voit le
            // code de la première), et un code saisi entre-temps reste. FOR NO KEY UPDATE : le verrou d'une mise à jour ordinaire, qui
            // ne bloque pas l'écriture d'une ligne de vente référençant la fiche
            $current = $conn->fetchOne(sprintf('SELECT barcode FROM %s WHERE id = ? FOR NO KEY UPDATE', $table), [$id]);
            if ($current === false) {
                throw new \LogicException('Code-barres : fiche introuvable');
            }
            if (CatalogBarcode::normalize($current) !== null || CatalogBarcode::normalize($target->getBarcode()) !== null) {
                throw new CatalogBarcodeException(self::ALREADY_SET, 'Cette fiche porte déjà un code-barres : un code saisi n\'est jamais remplacé par un code généré.',
                    null, CatalogBarcode::normalize($current) ?? CatalogBarcode::normalize($target->getBarcode()));
            }
            if ($target instanceof Product && $conn->fetchOne('SELECT 1 FROM product_variant WHERE product_id = ? LIMIT 1', [$id]) !== false) {
                throw new CatalogBarcodeException(self::HAS_VARIANTS, 'Cet article a des variantes : le code généré se pose sur chaque variante, pas sur l\'article.');
            }
            $code = $this->generate();
            $target->setBarcode($code);
            $em->flush();
            if ($audit !== null) {
                $this->audit->record(self::AUDIT_RESOURCE, self::key($target), 'generate', null, $audit + ['barcode' => $code, 'type' => self::type($target), 'id' => $id], ['barcode']);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $code;
    }

    /**
     * Pose sur une fiche un code saisi ou scanné, ou le retire (`$raw` vide ou null) : lot CB-3, routes de la gestion. Le code en
     * place est celui de la BASE, relu sous verrou de ligne ; un code inchangé ne fait rien (un code ancien, d'une forme refusée
     * aujourd'hui, ne bloque donc pas). Sinon : forme, formes réservées, code libre (`check`), écriture, et une ligne au journal du
     * site (`catalog_barcode`, `set` ou `remove`, avant / après) dans la même transaction. Le cache du site est vidé par l'écriture.
     * L'objet est relu depuis la ligne avant d'être modifié : ne pas l'appeler avec d'autres modifications en attente sur la fiche.
     *
     * Garde-fou (décision du 15/11/2026) : une fiche qui porte déjà un AUTRE code ne le perd que si `$replace` est vrai, qu'on le
     * remplace ou qu'on le retire ; sinon `BARCODE_ALREADY_SET`, avec le code en place (`current`). Un scan distrait ne remplace pas
     * le code du fabricant. Le code proposé est jugé d'abord (forme, code libre) : on ne demande pas de confirmer un code refusé.
     *
     * @param array<string, mixed> $context ajouté à la ligne du journal (d'où vient l'écriture)
     *
     * @return array{barcode: ?string, before: ?string, changed: bool}
     *
     * @throws CatalogBarcodeException `BARCODE_INVALID`, `BARCODE_RESERVED`, `BARCODE_ALREADY_USED`, `BARCODE_ALREADY_SET`
     */
    public function assign(Product|ProductVariant $target, ?string $raw, bool $replace = false, array $context = []): array
    {
        $id = $target->getId();
        if ($id === null) {
            throw new \LogicException('Code-barres : la fiche doit être enregistrée avant de recevoir un code');
        }
        $em = $this->em();
        $conn = $em->getConnection();
        $table = $target instanceof Product ? 'product' : 'product_variant';
        $code = CatalogBarcode::normalize($raw);
        $conn->beginTransaction();
        try {
            $current = $conn->fetchOne(sprintf('SELECT barcode FROM %s WHERE id = ? FOR NO KEY UPDATE', $table), [$id]);
            if ($current === false) {
                throw new \LogicException('Code-barres : fiche introuvable');
            }
            $before = CatalogBarcode::normalize($current);
            if ($code === $before) {
                $conn->commit();

                return ['barcode' => $before, 'before' => $before, 'changed' => false];
            }
            if ($code !== null) {
                $this->check($code, $target);
            }
            if ($before !== null && !$replace) {
                throw new CatalogBarcodeException(self::ALREADY_SET, sprintf('Cette fiche porte déjà le code-barres « %s » : confirmez pour le %s.', $before, $code === null ? 'retirer' : 'remplacer'), null, $before);
            }
            $em->refresh($target); // l'objet repart de la ligne verrouillée : Doctrine écrit bien le changement, et rien d'autre
            $target->setBarcode($code);
            $em->flush();
            $this->audit->record(self::AUDIT_RESOURCE, self::key($target), $code === null ? 'remove' : 'set', ['barcode' => $before],
                $context + ['barcode' => $code, 'type' => self::type($target), 'id' => $id], ['barcode']);
            $conn->commit();
        } catch (UniqueConstraintViolationException $e) {
            // Le même code posé au même instant sur une autre fiche : la base a tranché (déclencheur, index uniques)
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw new CatalogBarcodeException(self::ALREADY_USED, 'Ce code-barres vient d\'être pris par une autre fiche.');
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return ['barcode' => $code, 'before' => $before, 'changed' => true];
    }

    /**
     * `generateFor` pour un bouton d'écran : rien ne remonte. Rend le code posé, ou le texte à montrer : le refus de la règle tel
     * quel ; pour une panne (doublon en course, séquence épuisée, base), un texte neutre, le détail allant au journal du serveur.
     *
     * @return array{code: ?string, refusal: ?string, failed: bool}
     */
    public function tryGenerateFor(Product|ProductVariant $target): array
    {
        try {
            return ['code' => $this->generateFor($target), 'refusal' => null, 'failed' => false];
        } catch (CatalogBarcodeException $e) {
            return ['code' => null, 'refusal' => $e->getMessage(), 'failed' => false];
        } catch (\Throwable $e) {
            $this->logger?->error('Codes-barres : génération d\'un code en échec (' . $e::class . ').', ['exception' => $e]);

            return ['code' => null, 'refusal' => 'La génération a échoué : aucun code n\'a été écrit. Réessayez dans un instant.', 'failed' => true];
        }
    }

    /** Le code que la fiche portait à sa lecture (celui de la base), avant les modifications en cours ; null pour une fiche nouvelle */
    public function storedCode(Product|ProductVariant $entity): ?string
    {
        if ($entity->getId() === null) {
            return null;
        }
        $original = $this->em()->getUnitOfWork()->getOriginalEntityData($entity);

        return isset($original['barcode']) ? (string) $original['barcode'] : null;
    }

    /**
     * Ce que « Générer les codes manquants » écrirait sur tout le catalogue : les variantes sans code et les articles sans variante ni code.
     *
     * @return array{variants: int, products: int, total: int}
     */
    public function missingCount(): array
    {
        $conn = $this->connection();
        $variants = (int) $conn->fetchOne('SELECT COUNT(*) FROM product_variant WHERE barcode IS NULL');
        $products = (int) $conn->fetchOne('SELECT COUNT(*) FROM product p WHERE p.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id)');

        return ['variants' => $variants, 'products' => $products, 'total' => $variants + $products];
    }

    /**
     * « Générer les codes manquants » : donne un code généré à ce qui n'en a pas (variantes d'abord, puis articles sans variante), au plus
     * `$limit` par lancement, sans jamais toucher un code présent. Une ligne au journal du site par lancement (qui, quand, combien).
     *
     * @param array<string, mixed> $context ajouté à la ligne du journal (lot CB-3 : d'où vient le lancement)
     *
     * @return array{written: int, variants: int, products: int, remaining: int}
     */
    public function generateMissing(int $limit = self::BULK_LIMIT, array $context = []): array
    {
        $limit = max(1, min(self::BULK_LIMIT, $limit));
        $conn = $this->connection();
        $conn->beginTransaction();
        try {
            // Les lignes sans code sont verrouillées par leur identifiant, le temps d'écrire (quelques dizaines de millisecondes) :
            // - FOR NO KEY UPDATE : le verrou d'une mise à jour ordinaire ; il ne bloque pas l'écriture d'une ligne de vente ;
            // - SKIP LOCKED : une ligne tenue par une vente en cours, ou par un autre lancement, est laissée pour le lancement suivant
            //   (`remaining` le dit) au lieu d'être attendue : le lancement n'attend personne, et pas d'interblocage avec la caisse
            //   (une vente d'une fiche que le lancement tient, elle, attend la fin du lancement : une fraction de seconde).
            // Le verrou se prend en SQL : la requête Doctrine des articles joint la table des véhicules (héritage), ce qu'un verrou refuse.
            $variantIds = array_map('intval', $conn->fetchFirstColumn(sprintf('SELECT id FROM product_variant WHERE barcode IS NULL ORDER BY id LIMIT %d FOR NO KEY UPDATE SKIP LOCKED', $limit)));
            $productIds = count($variantIds) >= $limit ? [] : array_map('intval', $conn->fetchFirstColumn(sprintf(
                'SELECT p.id FROM product p WHERE p.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM product_variant v WHERE v.product_id = p.id) ORDER BY p.id LIMIT %d FOR NO KEY UPDATE OF p SKIP LOCKED',
                $limit - count($variantIds)
            )));
            $codes = $this->write($variantIds, $productIds);
            $result = ['written' => count($codes), 'variants' => count($variantIds), 'products' => count($productIds), 'remaining' => $this->missingCount()['total']];
            if ($codes !== []) {
                $this->audit->record(self::AUDIT_RESOURCE, null, 'generate', null, $context + $result + ['scope' => 'catalog', 'first' => $codes[0], 'last' => $codes[count($codes) - 1]], ['barcode']);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * Donne un code généré à celles de ces variantes qui n'en ont pas (lot CB-2 : étiquettes d'une commande reçue), au plus `BULK_LIMIT`
     * par appel, sans jamais toucher un code présent : mêmes verrous et même écriture que `generateMissing` (une variante tenue par
     * une vente en cours est laissée pour l'appel suivant). Une ligne au journal du site par appel qui écrit ; `$context` s'y ajoute
     * (d'où vient la demande).
     *
     * @param list<int|string> $variantIds
     * @param array<string, mixed> $context
     *
     * @return array<int, string> le code posé, par identifiant de variante (vide si rien n'était à générer)
     */
    public function generateForVariants(array $variantIds, array $context = []): array
    {
        $variantIds = array_values(array_unique(array_map('intval', $variantIds)));
        if ($variantIds === []) {
            return [];
        }
        $conn = $this->connection();
        $conn->beginTransaction();
        try {
            $locked = array_map('intval', $conn->fetchFirstColumn(
                sprintf('SELECT id FROM product_variant WHERE id IN (?) AND barcode IS NULL ORDER BY id LIMIT %d FOR NO KEY UPDATE SKIP LOCKED', self::BULK_LIMIT),
                [$variantIds], [ArrayParameterType::INTEGER]
            ));
            $codes = $this->write($locked, []);
            $written = $locked === [] ? [] : array_combine($locked, $codes);
            if ($written !== []) {
                $this->audit->record(self::AUDIT_RESOURCE, null, 'generate', null,
                    $context + ['written' => count($written), 'variants' => count($written), 'products' => 0, 'scope' => 'selection', 'first' => $codes[0], 'last' => $codes[count($codes) - 1]], ['barcode']); // le contexte de l'appelant prime (`scope`)
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return $written;
    }

    /**
     * Écrit un code généré sur ces lignes, déjà verrouillées par l'appelant dans SA transaction : variantes, puis articles.
     *
     * En SQL, pas par les entités : `ProductVariant` re-flushe après chaque écriture (rappel qui recalcule le stock de l'article), et
     * N variantes écrites en un flush coûtaient N² flush à vide (plusieurs minutes à 500, verrous tenus). Ce que Doctrine faisait pour
     * nous est donc fait ici : le cache du site est invalidé à la validation, et les objets déjà en mémoire reprennent la ligne.
     *
     * @param list<int> $variantIds
     * @param list<int> $productIds
     *
     * @return list<string> les codes écrits, dans l'ordre : variantes puis articles
     */
    private function write(array $variantIds, array $productIds): array
    {
        if ($variantIds === [] && $productIds === []) {
            return [];
        }
        $em = $this->em();
        $conn = $em->getConnection();
        $codes = $this->generateMany(count($variantIds) + count($productIds));
        $i = 0;
        foreach (['product_variant' => $variantIds, 'product' => $productIds] as $table => $ids) {
            foreach ($ids as $id) {
                // `barcode IS NULL` : dernier garde-fou contre l'écrasement, même si un appelant oubliait de verrouiller
                if ($conn->executeStatement("UPDATE $table SET barcode = ? WHERE id = ? AND barcode IS NULL", [$codes[$i++], $id]) !== 1) {
                    throw new \RuntimeException('Code-barres : une fiche a reçu un code pendant la génération, rien n\'est écrit');
                }
            }
        }
        // Cache du site : fiches et listes des articles touchés, vidées à la validation de la transaction (file d'invalidation)
        $touched = array_values(array_unique(array_merge($productIds, $variantIds === [] ? [] : array_map('intval',
            $conn->fetchFirstColumn('SELECT DISTINCT product_id FROM product_variant WHERE id IN (?) AND product_id IS NOT NULL', [$variantIds], [ArrayParameterType::INTEGER])))));
        $dbname = $conn->getParams()['dbname'] ?? '';
        if (!is_string($dbname) || $dbname === '') {
            throw new \LogicException('Code-barres : base du site inconnue, le cache ne pourrait pas être vidé ; rien n\'est écrit');
        }
        $tags = [];
        foreach ([CacheTags::PRODUCTS, CacheTags::VEHICLES] as $component) {
            $tags[] = TenantCacheService::componentTag($dbname, '', $component . '.list');
            foreach ($touched as $productId) {
                $tags[] = TenantCacheService::componentTag($dbname, '', $component . '.item.' . $productId);
            }
        }
        $this->cacheQueue->add($dbname, $tags);
        // Objets déjà en mémoire : ils reprennent la valeur de la base (l'objet et la ligne portent le même code)
        $uow = $em->getUnitOfWork();
        foreach ([ProductVariant::class => $variantIds, Product::class => $productIds] as $class => $ids) {
            foreach ($ids as $id) {
                $entity = $uow->tryGetById($id, $class);
                if (is_object($entity)) {
                    $em->refresh($entity);
                }
            }
        }

        return $codes;
    }

    /**
     * `$count` codes générés libres. Un numéro tiré de la séquence n'y revient jamais ; si le code existe déjà (saisi à la main), on prend
     * le suivant.
     *
     * @return list<string>
     */
    private function generateMany(int $count): array
    {
        $conn = $this->connection();
        $codes = [];
        for ($round = 0; count($codes) < $count; ++$round) {
            if ($round > 20) {
                throw new \RuntimeException('Code-barres : aucun code généré libre après plusieurs tirages');
            }
            $numbers = $conn->fetchFirstColumn(sprintf("SELECT nextval('%s') FROM generate_series(1, %d)", self::SEQUENCE, $count - count($codes)));
            $drawn = array_map(static fn ($n): string => CatalogBarcode::generated((int) $n), $numbers);
            $taken = $conn->fetchFirstColumn(
                'SELECT barcode FROM product WHERE barcode IN (:codes) UNION SELECT barcode FROM product_variant WHERE barcode IN (:codes)',
                ['codes' => $drawn],
                ['codes' => ArrayParameterType::STRING]
            );
            array_push($codes, ...array_values(array_diff($drawn, $taken)));
        }

        return $codes;
    }

    /** Nom lisible de ce qui porte un code : « Casquette » ou « Casquette, Rouge / M » */
    private function label(string $type, int $id): string
    {
        $em = $this->em();
        if ($type === 'product') {
            $name = trim((string) $em->find(Product::class, $id)?->getName());

            return $name !== '' ? $name : sprintf('article n° %d', $id);
        }
        $variant = $em->find(ProductVariant::class, $id);
        $name = trim((string) $variant?->getProductName());
        $parts = array_values(array_filter([trim((string) $variant?->getColor()?->getName()), trim((string) $variant?->getSize()?->getName())], static fn (string $p): bool => $p !== ''));

        return ($name !== '' ? $name : 'article') . ', ' . ($parts !== [] ? implode(' / ', $parts) : sprintf('variante n° %d', $id));
    }

    /** `variant` ou `product` */
    private static function type(Product|ProductVariant $target): string
    {
        return $target instanceof Product ? 'product' : 'variant';
    }

    /** Clé d'une fiche au journal du site : `variant-412`, `product-95` */
    private static function key(Product|ProductVariant $target): string
    {
        return self::type($target) . '-' . $target->getId();
    }

    private function em(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}
