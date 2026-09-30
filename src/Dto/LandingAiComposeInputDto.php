<?php

namespace App\Dto;

/**
 * Requête POST /api/landingpage-ai/compose. Construite depuis le corps JSON décodé en objets, pour que la
 * composition garde ses {} (un {} décodé en tableau PHP deviendrait [] et serait refusé par le schéma).
 */
class LandingAiComposeInputDto
{
    public const MODES = ['edit', 'create', 'page'];
    public const MAX_PROMPT_LENGTH = 2000;
    public const MAX_MEDIA = 20;
    /** Composition envoyée en retouche : au-delà, chaque appel à l'IA coûterait des centaines de milliers de jetons */
    public const MAX_COMPOSITION_BYTES = 204800;
    public const MAX_BODY_BYTES = 1048576;
    /** Images (captures d'écran, charte) : nombre, poids en base64 (limite de l'API : 5 Mo) et côté maximal */
    public const MAX_IMAGES = 5;
    public const MAX_IMAGE_BASE64_BYTES = 5000000;
    public const MAX_IMAGE_SIDE = 8000;
    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];

    public ?string $mode = null;
    public ?string $componentKey = null;
    public string|int|null $dataType = null;
    public ?object $composition = null;
    public string $prompt = '';
    public string $locale = 'fr';
    /** @var list<array{kind: string, url: ?string, mediaKey: ?string, label: ?string}> */
    public array $media = [];
    /** @var list<array{mediaType: string, data: string}> images décodées depuis leurs data URL (data = base64) */
    public array $images = [];

    /** @var list<array{path: string, message: string}> erreurs de forme relevées à la lecture */
    private array $shapeErrors = [];

    public static function fromRequestBody(mixed $body): self
    {
        $dto = new self();
        if (!is_object($body)) {
            $dto->shapeErrors[] = ['path' => '', 'message' => 'objet JSON attendu'];

            return $dto;
        }

        $dto->mode = is_string($body->mode ?? null) ? $body->mode : null;
        $dto->componentKey = is_string($body->componentKey ?? null) ? $body->componentKey : null;
        if (isset($body->dataType)) {
            is_string($body->dataType) || is_int($body->dataType)
                ? $dto->dataType = $body->dataType
                : $dto->shapeErrors[] = ['path' => 'dataType', 'message' => 'texte ou nombre attendu'];
        }
        if (property_exists($body, 'composition') && $body->composition !== null) {
            is_object($body->composition)
                ? $dto->composition = $body->composition
                : $dto->shapeErrors[] = ['path' => 'composition', 'message' => 'objet attendu'];
        }
        $dto->prompt = is_string($body->prompt ?? null) ? trim($body->prompt) : '';
        if (isset($body->locale)) {
            is_string($body->locale) && preg_match('/^[a-z]{2}$/', $body->locale)
                ? $dto->locale = $body->locale
                : $dto->shapeErrors[] = ['path' => 'locale', 'message' => 'code de langue à 2 lettres attendu (ex. fr)'];
        }

        foreach (is_array($body->media ?? null) ? $body->media : [] as $i => $media) {
            if (!is_object($media)) {
                $dto->shapeErrors[] = ['path' => "media[$i]", 'message' => 'objet attendu'];
                continue;
            }
            $kind = $media->kind ?? null;
            $url = $media->url ?? null;
            $key = $media->mediaKey ?? null;
            if (!in_array($kind, ['image', 'video'], true)) {
                $dto->shapeErrors[] = ['path' => "media[$i].kind", 'message' => 'image ou video attendu'];
            }
            if ($url !== null && (!is_string($url) || !preg_match('#^https?://[^\s\\\\]+$|^/(?!/)[^\s\\\\]*$#', $url))) {
                $dto->shapeErrors[] = ['path' => "media[$i].url", 'message' => 'URL http(s) ou chemin commençant par / attendu'];
            }
            if ($key !== null && (!is_string($key) || !preg_match('/^[a-fA-F0-9]{64}$/', $key))) {
                $dto->shapeErrors[] = ['path' => "media[$i].mediaKey", 'message' => 'clé de 64 caractères hexadécimaux attendue'];
            }
            if ($url === null && $key === null) {
                $dto->shapeErrors[] = ['path' => "media[$i]", 'message' => 'url ou mediaKey attendu'];
            }
            $dto->media[] = [
                'kind' => is_string($kind) ? $kind : '',
                'url' => is_string($url) ? $url : null,
                'mediaKey' => is_string($key) ? $key : null,
                'label' => is_string($media->label ?? null) ? mb_substr($media->label, 0, 200) : null,
            ];
        }
        if (isset($body->media) && !is_array($body->media)) {
            $dto->shapeErrors[] = ['path' => 'media', 'message' => 'liste attendue'];
        }
        if (isset($body->images) && !is_array($body->images)) {
            $dto->shapeErrors[] = ['path' => 'images', 'message' => 'liste de data URL attendue'];
        }
        $images = is_array($body->images ?? null) ? $body->images : [];
        if (count($images) > self::MAX_IMAGES) {
            $dto->shapeErrors[] = ['path' => 'images', 'message' => sprintf('%d images au plus', self::MAX_IMAGES)];
            $images = [];
        }
        foreach ($images as $i => $image) {
            $error = null;
            $read = self::readImage($image, $error);
            $read !== null
                ? $dto->images[] = $read
                : $dto->shapeErrors[] = ['path' => "images[$i]", 'message' => $error];
        }

        return $dto;
    }

    /**
     * data:image/png|jpeg|webp|gif;base64,… dont le contenu est bien une image de ce type.
     *
     * @return array{mediaType: string, data: string}|null
     */
    private static function readImage(mixed $image, ?string &$error): ?array
    {
        if (!is_string($image) || !preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/]+={0,2})$#', $image, $m)) {
            $error = 'data URL base64 d\'une image PNG, JPEG, WebP ou GIF attendue';

            return null;
        }
        if (strlen($m[2]) > self::MAX_IMAGE_BASE64_BYTES) {
            $error = sprintf('image trop lourde (%d Mo au plus en base64)', intdiv(self::MAX_IMAGE_BASE64_BYTES, 1000000));

            return null;
        }
        $bytes = base64_decode($m[2], true);
        $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if ($info === false || (self::IMAGE_TYPES[$info[2]] ?? null) !== $m[1]) {
            $error = sprintf('contenu illisible ou différent du type annoncé (%s)', $m[1]);

            return null;
        }
        if ($info[0] > self::MAX_IMAGE_SIDE || $info[1] > self::MAX_IMAGE_SIDE) {
            $error = sprintf('image trop grande (%d px au plus de côté)', self::MAX_IMAGE_SIDE);

            return null;
        }

        return ['mediaType' => $m[1], 'data' => $m[2]];
    }

    /** Page et requêtes avec images : traitées en tâche de fond (réponse 202, résultat à interroger) */
    public function isAsync(): bool
    {
        return $this->mode === 'page' || $this->images !== [];
    }

    /** Requête normalisée, relue par le worker avec fromRequestBody() */
    public function toJson(): string
    {
        return json_encode([
            'mode' => $this->mode,
            'componentKey' => $this->componentKey,
            'dataType' => $this->dataType,
            'composition' => $this->composition,
            'prompt' => $this->prompt,
            'locale' => $this->locale,
            'media' => $this->media,
            'images' => array_map(fn ($image) => sprintf('data:%s;base64,%s', $image['mediaType'], $image['data']), $this->images),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Taille maximale du corps de la requête : JSON ordinaire plus les images */
    public static function maxBodyBytes(): int
    {
        return self::MAX_BODY_BYTES + self::MAX_IMAGES * (self::MAX_IMAGE_BASE64_BYTES + 64);
    }

    /**
     * @param list<string> $componentKeys familles du catalogue
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $componentKeys): array
    {
        $errors = $this->shapeErrors;
        if (!in_array($this->mode, self::MODES, true)) {
            $errors[] = ['path' => 'mode', 'message' => 'edit, create ou page attendu'];
        }
        // en mode page, componentKey est facultatif : il impose alors la famille de toutes les sections
        if (($this->mode !== 'page' || $this->componentKey !== null) && !in_array($this->componentKey, $componentKeys, true)) {
            $errors[] = ['path' => 'componentKey', 'message' => 'famille absente du catalogue de l\'éditeur'];
        }
        if ($this->prompt === '') {
            $errors[] = ['path' => 'prompt', 'message' => 'demande obligatoire'];
        } elseif (mb_strlen($this->prompt) > self::MAX_PROMPT_LENGTH) {
            $errors[] = ['path' => 'prompt', 'message' => sprintf('%d caractères au plus', self::MAX_PROMPT_LENGTH)];
        }
        if ($this->mode === 'edit' && $this->composition === null) {
            $errors[] = ['path' => 'composition', 'message' => 'composition actuelle de la section obligatoire en retouche'];
        }
        if ($this->composition !== null && strlen((string) json_encode($this->composition)) > self::MAX_COMPOSITION_BYTES) {
            $errors[] = ['path' => 'composition', 'message' => sprintf('composition trop volumineuse (%d Ko au plus)', self::MAX_COMPOSITION_BYTES / 1024)];
        }
        if (count($this->media) > self::MAX_MEDIA) {
            $errors[] = ['path' => 'media', 'message' => sprintf('%d médias au plus', self::MAX_MEDIA)];
        }

        return $errors;
    }
}
