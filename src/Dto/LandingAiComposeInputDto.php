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
    public const MAX_BODY_BYTES = 1048576;

    public ?string $mode = null;
    public ?string $componentKey = null;
    public string|int|null $dataType = null;
    public ?object $composition = null;
    public string $prompt = '';
    public string $locale = 'fr';
    /** @var list<array{kind: string, url: ?string, mediaKey: ?string, label: ?string}> */
    public array $media = [];
    /** @var list<string> */
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
        $dto->images = array_values(array_filter(is_array($body->images ?? null) ? $body->images : [], 'is_string'));

        return $dto;
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
        if ($this->mode !== 'page' && !in_array($this->componentKey, $componentKeys, true)) {
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
        if (count($this->media) > self::MAX_MEDIA) {
            $errors[] = ['path' => 'media', 'message' => sprintf('%d médias au plus', self::MAX_MEDIA)];
        }

        return $errors;
    }
}
