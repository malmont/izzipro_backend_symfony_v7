<?php

namespace App\Services\ProductAiService;

use App\Services\LandingAiService\LandingAiClientInterface;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\LandingAiService\LandingAiUsageStats;
use Psr\Log\LoggerInterface;

/**
 * Fiche produit par IA (07/11/2026) : à partir d'une à quatre photos et d'un commentaire, Claude rédige un brouillon de fiche (nom,
 * descriptions, catégorie, attributs, mots-clés, prix suggéré, classe d'expédition) ; puis traduit une fiche dans les autres langues du site.
 * Un crédit IA du site par rédaction, un par traduction (toutes les langues d'un coup) ; le propriétaire de la plateforme ne paie pas.
 * Rien n'est enregistré ici : l'utilisateur corrige, met le prix et crée (`ProductSheetCreator`).
 */
class ProductSheetDrafter
{
    public const MODEL = 'claude-sonnet-5-5';
    public const COST_DRAFT = 1;
    public const COST_TRANSLATE = 1;
    public const MAX_PHOTOS = 4;
    public const MAX_PHOTO_BYTES = 8_000_000;
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    public const SHIPPING_CLASSES = ['standard', 'fragile', 'volumineux', 'lourd'];
    public const LANGUAGES = ['fr' => 'français', 'en' => 'anglais'];
    private const TIMEOUT = 90.0;

    public function __construct(private readonly LandingAiClientInterface $client, private readonly LandingAiQuotaService $quota, private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param list<array{data?: string, mime?: string, url?: string}> $photos contenu binaire et type MIME, ou adresse https
     * @param list<string> $categories noms des catégories du site (l'IA en choisit une ou aucune)
     * @return array{draft: array<string, mixed>, usage: array<string, mixed>}
     * @throws LandingAiException 402 crédits, 422 photo, 503 IA
     */
    public function draft(array $photos, string $comment, array $categories, string $language, ?string $user, bool $charge = true, ?string $currency = 'CAD'): array
    {
        if ($photos === [] || count($photos) > self::MAX_PHOTOS) {
            throw new LandingAiException(422, 'Photos', sprintf('De 1 à %d photos attendues.', self::MAX_PHOTOS));
        }
        foreach ($photos as $p) {
            if (isset($p['url'])) {
                if (!preg_match('#^https://#', (string) $p['url'])) {
                    throw new LandingAiException(422, 'Photos', 'Photo invalide : adresse https attendue.');
                }
                continue;
            }
            if (!in_array($p['mime'] ?? '', self::MIME_TYPES, true) || strlen($p['data'] ?? '') === 0 || strlen($p['data']) > self::MAX_PHOTO_BYTES) {
                throw new LandingAiException(422, 'Photos', 'Photo invalide : JPEG, PNG, WebP ou GIF de 8 Mo au plus.');
            }
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        $usage = $charge ? $this->quota->reserve('assistant', 'product_sheet', self::COST_DRAFT, $user, 'Fiche produit depuis ' . count($photos) . ' photo(s)' . ($comment !== '' ? ' : ' . mb_substr($comment, 0, 120) : '')) : null;
        try {
            $draft = $this->ask($photos, $comment, $categories, $language, $currency ?: 'CAD', $stats);
            if ($usage !== null) {
                $this->quota->complete($usage, $stats, mb_substr((string) ($draft['name'] ?? ''), 0, 200));
            }

            return ['draft' => $draft, 'usage' => $stats->toArray()];
        } catch (\Throwable $e) {
            if ($usage !== null) {
                $this->quota->release($usage, $stats);
            }
            $this->logger->error('[Fiche produit IA] rédaction échouée : ' . $e->getMessage());
            throw $e instanceof LandingAiException ? $e : new LandingAiException(503, 'IA indisponible', 'La rédaction de la fiche a échoué, réessayez dans un instant.');
        }
    }

    /**
     * Traduit une fiche dans les langues demandées (une seule requête).
     * @param array{name: string, description?: ?string, moreInformations?: ?string, tags?: ?string} $sheet
     * @param list<string> $targets codes de langue (fr, en)
     * @return array{translations: array<string, array{name: string, description: ?string, moreInformations: ?string, tags: ?string}>, usage: array<string, mixed>}
     */
    public function translate(array $sheet, string $source, array $targets, ?string $user, bool $charge = true): array
    {
        $targets = array_values(array_unique(array_filter($targets, fn ($t) => isset(self::LANGUAGES[$t]) && $t !== $source)));
        if ($targets === []) {
            return ['translations' => [], 'usage' => (new LandingAiUsageStats(self::MODEL))->toArray()];
        }
        $stats = new LandingAiUsageStats(self::MODEL);
        $usage = $charge ? $this->quota->reserve('assistant', 'product_translate', self::COST_TRANSLATE, $user, 'Traduction de la fiche « ' . mb_substr((string) $sheet['name'], 0, 80) . ' » en ' . implode(', ', $targets)) : null;
        try {
            $properties = [];
            foreach ($targets as $t) {
                $properties[$t] = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'moreInformations' => ['type' => 'string'], 'tags' => ['type' => 'string']], 'required' => ['name', 'description']];
            }
            $response = $this->client->createMessage([
                'model' => self::MODEL, 'max_tokens' => 4000,
                'system' => 'Tu traduis des fiches produit de boutique en ligne, fidèlement, dans un style commercial naturel pour chaque langue ; conserve les balises HTML simples, ne traduis pas les noms de marque ni les codes.',
                'messages' => [['role' => 'user', 'content' => sprintf("Langue source : %s. Traduire en : %s.\n\nFiche :\n%s", self::LANGUAGES[$source] ?? $source, implode(', ', array_map(fn ($t) => self::LANGUAGES[$t], $targets)), json_encode(['name' => $sheet['name'], 'description' => $sheet['description'] ?? '', 'moreInformations' => $sheet['moreInformations'] ?? '', 'tags' => $sheet['tags'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]],
                'tools' => [['name' => 'translate_product', 'description' => 'Traductions de la fiche, une clé par langue cible', 'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $targets]]],
                'tool_choice' => ['type' => 'tool', 'name' => 'translate_product'],
            ], self::TIMEOUT);
            $stats->attempts++;
            $stats->add($response->usage ?? null);
            $input = [];
            foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
                if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'translate_product') {
                    $input = json_decode(json_encode($block->input ?? new \stdClass()), true) ?: [];
                }
            }
            $out = [];
            foreach ($targets as $t) {
                $tr = is_array($input[$t] ?? null) ? $input[$t] : [];
                if (trim((string) ($tr['name'] ?? '')) === '') {
                    continue;
                }
                $out[$t] = ['name' => mb_substr(trim((string) $tr['name']), 0, 255), 'description' => $this->text($tr['description'] ?? null, 20000), 'moreInformations' => $this->text($tr['moreInformations'] ?? null, 20000), 'tags' => $this->text($tr['tags'] ?? null, 500)];
            }
            if ($out === []) {
                throw new LandingAiException(503, 'IA indisponible', 'Aucune traduction exploitable.');
            }
            if ($usage !== null) {
                $this->quota->complete($usage, $stats, implode(', ', array_keys($out)));
            }

            return ['translations' => $out, 'usage' => $stats->toArray()];
        } catch (\Throwable $e) {
            if ($usage !== null) {
                $this->quota->release($usage, $stats);
            }
            $this->logger->error('[Fiche produit IA] traduction échouée : ' . $e->getMessage());
            throw $e instanceof LandingAiException ? $e : new LandingAiException(503, 'IA indisponible', 'La traduction a échoué, réessayez dans un instant.');
        }
    }

    /** @return array<string, mixed> */
    private function ask(array $photos, string $comment, array $categories, string $language, string $currency, LandingAiUsageStats $stats): array
    {
        $content = [['type' => 'text', 'text' => sprintf("Rédige la fiche de ce produit en %s%s.%s", self::LANGUAGES[$language] ?? 'français', $comment !== '' ? ". Indications du marchand : « " . mb_substr($comment, 0, 1000) . ' »' : '', $categories !== [] ? "\nCatégories du site (choisis la plus juste, ou aucune) : " . implode(' | ', array_slice($categories, 0, 80)) : '')]];
        foreach ($photos as $i => $p) {
            $content[] = ['type' => 'text', 'text' => sprintf('Photo %d :', $i + 1)];
            $content[] = ['type' => 'image', 'source' => isset($p['url']) ? ['type' => 'url', 'url' => $p['url']] : ['type' => 'base64', 'media_type' => $p['mime'], 'data' => base64_encode($p['data'])]];
        }
        $system = <<<TXT
Tu rédiges des fiches produit pour une boutique en ligne à partir de photos et des indications du marchand. Décris ce que tu vois sans inventer de caractéristique invérifiable (pas de matière ni de marque si rien ne l'indique : dis-le dans la note). Donne : name (court, vendeur, sans point final), shortDescription (une phrase), description (HTML simple : <p>, <ul><li>, <strong> ; 80 à 200 mots : usage, points forts, matières visibles, entretien si évident), moreInformations (HTML : dimensions ou tailles visibles, composition, conseils), categoryName (parmi la liste, sinon null), attributes {material, colors (liste), sizes (liste), gender (femme|homme|enfant|mixte|null), season}, tags (5 à 10 mots-clés séparés par des virgules), suggestedPriceCents (prix de vente conseillé en cents de $currency si le marchand a donné un coût ou si l'article est très courant, sinon null), shippingClass (standard|fragile|volumineux|lourd), confidence (high|medium|low), note (ce que tu n'as pas pu déterminer).
TXT;
        $response = $this->client->createMessage([
            'model' => self::MODEL, 'max_tokens' => 3000, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]],
            'tools' => [['name' => 'propose_product_sheet', 'description' => 'Brouillon de fiche produit', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'shortDescription' => ['type' => 'string'], 'description' => ['type' => 'string'], 'moreInformations' => ['type' => 'string'], 'categoryName' => ['type' => ['string', 'null']],
                'attributes' => ['type' => 'object', 'properties' => ['material' => ['type' => ['string', 'null']], 'colors' => ['type' => 'array', 'items' => ['type' => 'string']], 'sizes' => ['type' => 'array', 'items' => ['type' => 'string']], 'gender' => ['type' => ['string', 'null']], 'season' => ['type' => ['string', 'null']]]],
                'tags' => ['type' => 'string'], 'suggestedPriceCents' => ['type' => ['integer', 'null']], 'shippingClass' => ['type' => 'string', 'enum' => self::SHIPPING_CLASSES], 'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'note' => ['type' => 'string'],
            ], 'required' => ['name', 'shortDescription', 'description', 'confidence']]]],
            'tool_choice' => ['type' => 'tool', 'name' => 'propose_product_sheet'],
        ], self::TIMEOUT);
        $stats->attempts++;
        $stats->add($response->usage ?? null);
        $input = [];
        foreach (is_array($response->content ?? null) ? $response->content : [] as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'propose_product_sheet') {
                $input = json_decode(json_encode($block->input ?? new \stdClass()), true) ?: [];
            }
        }
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 255);
        if ($name === '') {
            throw new LandingAiException(503, 'IA indisponible', 'Aucune fiche exploitable : réessayez avec une photo plus nette ou un commentaire.');
        }
        $attributes = is_array($input['attributes'] ?? null) ? $input['attributes'] : [];
        $list = fn ($v) => array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 60), is_array($v) ? $v : []), fn ($x) => $x !== ''));
        $categoryName = is_string($input['categoryName'] ?? null) ? trim($input['categoryName']) : null;
        $price = isset($input['suggestedPriceCents']) && is_numeric($input['suggestedPriceCents']) && (int) $input['suggestedPriceCents'] > 0 ? (int) $input['suggestedPriceCents'] : null;

        return [
            'name' => $name, 'shortDescription' => $this->text($input['shortDescription'] ?? null, 500), 'description' => $this->text($input['description'] ?? null, 20000), 'moreInformations' => $this->text($input['moreInformations'] ?? null, 20000),
            'categoryName' => $categoryName !== null && in_array($categoryName, $categories, true) ? $categoryName : null,
            'attributes' => ['material' => $this->text($attributes['material'] ?? null, 120), 'colors' => $list($attributes['colors'] ?? null), 'sizes' => $list($attributes['sizes'] ?? null), 'gender' => $this->text($attributes['gender'] ?? null, 20), 'season' => $this->text($attributes['season'] ?? null, 40)],
            'tags' => $this->text($input['tags'] ?? null, 500), 'suggestedPriceCents' => $price, 'currency' => $currency,
            'shippingClass' => in_array($input['shippingClass'] ?? null, self::SHIPPING_CLASSES, true) ? $input['shippingClass'] : 'standard',
            'confidence' => in_array($input['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $input['confidence'] : 'low', 'note' => $this->text($input['note'] ?? null, 500), 'language' => $language,
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        $t = is_string($value) ? trim($value) : '';

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
