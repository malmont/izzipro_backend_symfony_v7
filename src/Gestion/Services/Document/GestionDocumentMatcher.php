<?php

namespace App\Gestion\Services\Document;

use App\Services\TenantEntityManagerProvider;

/**
 * Correspondances calculées en SQL + PHP pour la lecture de documents (lot L1, 10/11/2026) : jamais par l'IA. Articles du site par
 * nom, code et code-barres (jusqu'à 3 candidats, score 0..1), variantes par taille et couleur, fournisseurs et transporteurs
 * par nom, types de note de frais par nom. Sans extension pg_trgm : préfiltre ILIKE sur les mots, puis `similar_text` en PHP.
 */
class GestionDocumentMatcher
{
    public const THRESHOLD = 0.55;
    public const MAX_CANDIDATES = 3;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{product: ?array, candidates: list<array>} */
    public function product(string $label, ?string $code, ?string $size, ?string $color): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $candidates = [];
        if ($code !== null && trim($code) !== '') {
            foreach ($conn->fetchAllAssociative('SELECT id, name, code, barcode FROM product WHERE (LOWER(code) = :c OR LOWER(barcode) = :c) LIMIT 3', ['c' => mb_strtolower(trim($code))]) as $row) {
                $candidates[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'code' => $row['code'], 'score' => 1.0];
            }
        }
        $words = $this->words($label);
        if ($words !== []) {
            $where = [];
            $params = [];
            foreach (array_slice($words, 0, 4) as $i => $w) {
                $where[] = "name ILIKE :w$i";
                $params["w$i"] = '%' . $w . '%';
            }
            foreach ($conn->fetchAllAssociative('SELECT id, name, code, barcode FROM product WHERE (' . implode(' OR ', $where) . ') LIMIT 60', $params) as $row) {
                $score = $this->similarity($label, (string) $row['name']);
                if ($score >= 0.35 && !isset($candidates[(int) $row['id']])) {
                    $candidates[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'code' => $row['code'], 'score' => round($score, 2)];
                }
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice(array_values($candidates), 0, self::MAX_CANDIDATES);
        $best = $candidates[0] ?? null;
        $product = null;
        if ($best !== null && $best['score'] >= self::THRESHOLD) {
            $product = $best + ['variantId' => $this->variant($best['id'], $size, $color)];
        }

        return ['product' => $product, 'candidates' => $candidates];
    }

    /** Variante existante du produit qui porte cette taille et cette couleur (null si le produit n'en a pas ou si rien ne correspond) */
    public function variant(int $productId, ?string $size, ?string $color): ?int
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative('SELECT v.id, LOWER(s.name) AS size, LOWER(c.name) AS color FROM product_variant v LEFT JOIN size s ON s.id = v.size_id LEFT JOIN color c ON c.id = v.color_id WHERE v.product_id = :p', ['p' => $productId]);
        if ($rows === []) {
            return null;
        }
        $size = $size !== null ? mb_strtolower(trim($size)) : null;
        $color = $color !== null ? mb_strtolower(trim($color)) : null;
        foreach ($rows as $row) {
            $sizeOk = $size === null || $size === '' || $row['size'] === $size;
            $colorOk = $color === null || $color === '' || $row['color'] === $color;
            if ($sizeOk && $colorOk && ($size !== null || $color !== null)) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /** @return array{id: ?int, candidates: list<array{id: int, name: string, score: float}>} */
    public function party(string $table, ?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return ['id' => null, 'candidates' => []];
        }
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $candidates = [];
        foreach ($conn->fetchAllAssociative(sprintf('SELECT id, name FROM %s ORDER BY id', $table)) as $row) {
            $score = $this->similarity($name, (string) $row['name']);
            if ($score >= 0.4) {
                $candidates[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'score' => round($score, 2)];
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);

        return ['id' => isset($candidates[0]) && $candidates[0]['score'] >= self::THRESHOLD ? $candidates[0]['id'] : null, 'candidates' => $candidates];
    }

    /** @return array{id: ?int, name: ?string, candidates: list<array{id: int, name: string}>} type de note de frais proposé */
    public function noteType(?string $hint): array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $types = $conn->fetchAllAssociative('SELECT id, name FROM type_note_de_frais ORDER BY id');
        $candidates = array_map(fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name']], $types);
        $best = null;
        if ($hint !== null && trim($hint) !== '') {
            $bestScore = 0.0;
            foreach ($types as $t) {
                $score = $this->similarity($hint, (string) $t['name']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['id' => (int) $t['id'], 'name' => $t['name']];
                }
            }
            if ($bestScore < 0.4) {
                $best = null;
            }
        }

        return ['id' => $best['id'] ?? null, 'name' => $best['name'] ?? null, 'candidates' => $candidates];
    }

    /** Commande fournisseur suggérée pour une facture de transport : numéro de suivi, sinon commande ouverte la plus proche de la date */
    public function commandeFor(?string $trackingNumber, ?string $date): ?array
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        if ($trackingNumber !== null && trim($trackingNumber) !== '') {
            $row = $conn->fetchAssociative('SELECT c.id, c.name FROM frais_de_port f JOIN commande c ON c.id = f.commande_id WHERE LOWER(f.tracknumber) = :t LIMIT 1', ['t' => mb_strtolower(trim($trackingNumber))]);
            if ($row) {
                return ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $row = $conn->fetchAssociative('SELECT c.id, c.name FROM commande c LEFT JOIN frais_de_port f ON f.commande_id = c.id WHERE f.id IS NULL AND c.is_closed IS NOT TRUE AND c.date BETWEEN CAST(:d AS date) - 45 AND CAST(:d AS date) + 15 ORDER BY ABS(EXTRACT(EPOCH FROM (c.date - CAST(:d AS timestamp)))) LIMIT 1', ['d' => $date]);
            if ($row) {
                return ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        return null;
    }

    /** Clé de regroupement d'une désignation (taille et couleur retirées, accents et ponctuation neutralisés) */
    public static function groupKey(string $baseLabel): string
    {
        $key = mb_strtolower(trim($baseLabel));
        $key = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $key);
        $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?? $key;

        return trim($key, '-') ?: 'ligne';
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $text = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $text));
        $words = preg_split('/[^a-z0-9]+/', $text) ?: [];

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 3));
    }

    private function similarity(string $a, string $b): float
    {
        $na = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $a));
        $nb = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $b));
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        if ($na === $nb) {
            return 1.0;
        }
        similar_text($na, $nb, $percent);
        $wa = array_flip($this->words($a));
        $wb = $this->words($b);
        $common = count(array_filter($wb, fn ($w) => isset($wa[$w])));
        $wordScore = ($wa !== [] && $wb !== []) ? $common / max(count($wa), count($wb)) : 0.0;

        return max($percent / 100, $wordScore);
    }
}
