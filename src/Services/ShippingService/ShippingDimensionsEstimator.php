<?php

namespace App\Services\ShippingService;

use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use Psr\Log\LoggerInterface;

/**
 * Estimation du poids et des dimensions des produits par l'IA (livraison, lot 1 bis, 07/11/2026) : à partir du nom, de la
 * description, des catégories et, quand elle existe, de la photo, le modèle propose poids, dimensions, classe d'expédition et un niveau
 * de confiance pour jusqu'à MAX_PRODUCTS produits par appel. Rien n'est enregistré ici : la page « Poids et dimensions » préremplit la
 * grille et l'administrateur confirme ; l'assistant propose une action confirmée. Un crédit IA du site par appel (mode `assistant`,
 * composant `shipping_estimate`), remboursé si l'IA échoue ; pas de crédit pour le propriétaire de la plateforme.
 */
class ShippingDimensionsEstimator
{
    public const MODEL = 'claude-sonnet-5-5';
    public const MAX_PRODUCTS = 25;
    public const MAX_IMAGES = 8;
    public const COST = 1;
    private const TIMEOUT = 90.0;

    public function __construct(private readonly LandingAiClientInterface $client, private readonly LandingAiQuotaService $quota, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param list<array{productId: int, name: string, description: string, categories: list<string>, imageUrl: ?string}> $products
     * @param list<array{id: int, name: string}> $classes classes d'expédition du site
     * @return array{proposals: list<array{productId: int, weightKg: float, lengthCm: float, widthCm: float, heightCm: float, shippingClassId: ?int, shippingClass: ?string, confidence: string, note: string}>, usage: array<string, mixed>}
     * @throws LandingAiException 402 crédits, 429 trop d'échecs, 503 IA indisponible
     */
    public function estimate(array $products, array $classes, ?string $user, string $language = 'fr', bool $charge = true): array
    {
        $products = array_slice(array_values($products), 0, self::MAX_PRODUCTS);
        if ($products === []) {
            return ['proposals' => [], 'usage' => []];
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        $usage = $charge ? $this->quota->reserve('assistant', 'shipping_estimate', self::COST, $user, sprintf('Estimation du poids de %d produit(s)', count($products))) : null;
        try {
            $proposals = $this->ask($products, $classes, $language, $stats);
            if ($usage !== null) {
                $this->quota->complete($usage, $stats, sprintf('%d proposition(s)', count($proposals)));
            }

            return ['proposals' => $proposals, 'usage' => $stats->toArray()];
        } catch (\Throwable $e) {
            if ($usage !== null) {
                $this->quota->release($usage, $stats);
            }
            $this->logger->error('[Livraison] estimation des poids échouée : ' . $e->getMessage());
            if ($e instanceof LandingAiException) {
                throw $e;
            }
            throw new LandingAiException(503, 'Estimation indisponible', 'L\'estimation ne répond pas pour le moment, réessayez dans un instant.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function ask(array $products, array $classes, string $language, LandingAiUsageStats $stats): array
    {
        $classNames = array_map(fn ($c) => $c['name'], $classes);
        $lines = [];
        $content = [];
        $images = 0;
        foreach ($products as $p) {
            $lines[] = sprintf('- id %d : « %s »%s%s', $p['productId'], $p['name'], $p['categories'] !== [] ? ' (catégories : ' . implode(', ', $p['categories']) . ')' : '', $p['description'] !== '' ? ' — ' . $p['description'] : '');
            if (!empty($p['imageUrl']) && $images < self::MAX_IMAGES && preg_match('#^https://#', (string) $p['imageUrl'])) {
                $content[] = ['type' => 'text', 'text' => sprintf('Photo du produit id %d :', $p['productId'])];
                $content[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $p['imageUrl']]];
                $images++;
            }
        }
        array_unshift($content, ['type' => 'text', 'text' => "Produits à estimer :\n" . implode("\n", $lines)]);
        $system = ($language === 'en' ? 'Answer in English for the notes.' : 'Les notes sont en français.') . ' ' . <<<TXT
Tu estimes le poids emballé et les dimensions d'expédition de produits d'une boutique en ligne, pour calculer des tarifs postaux. Pour chaque produit, donne : weightKg (poids avec son emballage de vente, en kg, décimal), lengthCm, widthCm, heightCm (dimensions du produit emballé, en cm, longueur ≥ largeur ≥ hauteur), shippingClass (une des classes du site, ou null), confidence (high | medium | low), note (une phrase courte : hypothèse retenue). Utilise les photos quand elles existent. Un vêtement plié, un livre, un objet de décoration ont des valeurs bien connues : ne surestime pas. Classes du site : {$this->json($classNames)}. Réponds uniquement par l'outil propose_dimensions, un élément par produit, dans l'ordre.
TXT;
        $response = $this->client->createMessage([
            'model' => self::MODEL, 'max_tokens' => 2500, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            'tools' => [['name' => 'propose_dimensions', 'description' => 'Propositions de poids et dimensions, un élément par produit', 'input_schema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'productId' => ['type' => 'integer'], 'weightKg' => ['type' => 'number'], 'lengthCm' => ['type' => 'number'], 'widthCm' => ['type' => 'number'], 'heightCm' => ['type' => 'number'],
                'shippingClass' => ['type' => ['string', 'null']], 'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'note' => ['type' => 'string'],
            ], 'required' => ['productId', 'weightKg', 'lengthCm', 'widthCm', 'heightCm', 'confidence']]]], 'required' => ['items']]]],
            'tool_choice' => \App\Services\LandingAiService\LandingAiPromptBuilder::toolChoice(self::MODEL, 'propose_dimensions'),
        ], self::TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $items = [];
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'propose_dimensions') {
                $input = json_decode(json_encode($block->input ?? new \stdClass()), true);
                $items = is_array($input['items'] ?? null) ? $input['items'] : [];
            }
        }
        $known = [];
        foreach ($products as $p) {
            $known[(int) $p['productId']] = true;
        }
        $byName = [];
        foreach ($classes as $c) {
            $byName[mb_strtolower($c['name'])] = (int) $c['id'];
        }
        $proposals = [];
        foreach ($items as $it) {
            $id = (int) ($it['productId'] ?? 0);
            if (!isset($known[$id]) || isset($proposals[$id])) {
                continue;
            }
            $w = (float) ($it['weightKg'] ?? 0);
            $dims = [(float) ($it['lengthCm'] ?? 0), (float) ($it['widthCm'] ?? 0), (float) ($it['heightCm'] ?? 0)];
            if ($w <= 0 || $w > 1000 || min($dims) <= 0 || max($dims) > 500) {
                continue;
            }
            rsort($dims);
            $className = is_string($it['shippingClass'] ?? null) ? trim($it['shippingClass']) : null;
            $proposals[$id] = [
                'productId' => $id, 'weightKg' => round($w, 3), 'lengthCm' => round($dims[0], 1), 'widthCm' => round($dims[1], 1), 'heightCm' => round($dims[2], 1),
                'shippingClassId' => $className !== null ? ($byName[mb_strtolower($className)] ?? null) : null, 'shippingClass' => $className,
                'confidence' => in_array($it['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $it['confidence'] : 'low',
                'note' => mb_substr(trim((string) ($it['note'] ?? '')), 0, 200),
            ];
        }

        return array_values($proposals);
    }

    private function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
