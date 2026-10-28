<?php

namespace App\Dto;

/**
 * Requête POST /api/landingpage-ai/compose. Construite depuis le corps JSON décodé en objets, pour que la
 * composition garde ses {} (un {} décodé en tableau PHP deviendrait [] et serait refusé par le schéma).
 */
class LandingAiComposeInputDto
{
    public const MODES = ['edit', 'create', 'page', 'site'];
    public const MAX_PROMPT_LENGTH = 2000;
    public const MAX_MEDIA = 20;
    /** Composition envoyée en retouche : au-delà, chaque appel à l'IA coûterait des centaines de milliers de jetons */
    public const MAX_COMPOSITION_BYTES = 204800;
    public const MAX_BODY_BYTES = 1048576;
    /** Images (captures d'écran, charte) : nombre, poids en base64 (limite de l'API : 5 Mo) et côté maximal */
    public const MAX_IMAGES = 5;
    public const MAX_IMAGE_BASE64_BYTES = 5000000;
    public const MAX_IMAGE_SIDE = 8000;
    /** Maquette en PDF (15/10/2026) : un seul document, compté dans MAX_IMAGES, 10 Mo au plus en base64 (API : 32 Mo) */
    public const PDF_TYPE = 'application/pdf';
    public const MAX_PDF_BASE64_BYTES = 10000000;
    /** Charte en texte (DESIGN.md) : taille maximale */
    public const MAX_DESIGN_MD_LENGTH = 40000;
    /** Pages composées par un site complet (mode site), en plus de l'accueil : pages système (boutique) ou onglets (landing page) */
    public const MAX_SITE_PAGES = 8;
    /** Applications d'un site complet : onglets libres de la landing page, ou pages système de la boutique */
    public const SITE_APPS = ['landingpage', 'boutique'];
    /** Onglet d'une landing page à composer : titre (sans < ni >) et brief facultatif */
    public const MAX_TAB_TITLE_LENGTH = 60;
    public const MAX_TAB_BRIEF_LENGTH = 300;
    /** Historique de la console : nombre d'échanges, longueur de la demande et du résumé gardés, issues possibles */
    public const MAX_HISTORY = 10;
    public const MAX_HISTORY_PROMPT_LENGTH = 2000;
    public const MAX_HISTORY_SUMMARY_LENGTH = 1000;
    public const HISTORY_OUTCOMES = ['applied', 'rejected', 'undone'];
    /** Réponses aux questions de l'assistant : nombre, longueur de la question et de chaque réponse, réponses multiples */
    /** Réponses aux questions de l'assistant : autant que de questions (5 pour une demande de formulaire) */
    public const MAX_ANSWERS = 5;
    public const MAX_ANSWER_LENGTH = 500;
    public const MAX_ANSWER_CHOICES = 4;
    /** Réglages de style facultatifs : clé => valeurs acceptées */
    public const STYLE_OPTIONS = [
        'tone' => ['sobre', 'equilibre', 'expressif'],
        'motion' => ['aucun', 'discret', 'vivant'],
        'density' => ['aeree', 'normale', 'dense'],
    ];
    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];

    public ?string $mode = null;
    public ?string $componentKey = null;
    public string|int|null $dataType = null;
    public ?object $composition = null;
    public string $prompt = '';
    public string $locale = 'fr';
    /** @var list<array{kind: string, url: ?string, mediaKey: ?string, label: ?string}> */
    public array $media = [];
    /** @var list<array{mediaType: string, data: string}> images (et au plus un PDF) décodées depuis leurs data URL (data = base64) */
    public array $images = [];
    /** Mode page : page système de la boutique à composer (clé du catalogue « systemPages », ex. product, login) */
    public ?string $system = null;
    /** Mode site : pages système de la boutique à composer après l'accueil (clés du catalogue), 1 à MAX_SITE_PAGES */
    public array $pages = [];
    /**
     * Mode site d'une landing page : onglets à composer après l'accueil (entrées de « pages » données en objets).
     *
     * @var list<array{title: string, brief: ?string}>
     */
    public array $tabs = [];
    /** Mode site : application du site (SITE_APPS) ; absent : déduit de la forme de « pages », voir validate() */
    public ?string $app = null;
    /**
     * Mode page : onglet de la landing page à composer dans le style du site (exclusif avec « system »).
     *
     * @var array{title: string, brief: ?string}|null
     */
    public ?array $tab = null;
    /** Charte à suivre (clés de la charte de la boutique : primaryColor, accentColor, textColor, backgroundColor, headingFont, bodyFont, radius, buttonStyle) */
    public ?object $charter = null;
    /** @var array<string, string>|null réglages de style (STYLE_OPTIONS) */
    public ?array $style = null;
    /** Charte en texte (fichier DESIGN.md ou description libre) */
    public ?string $designMd = null;
    /**
     * Mode site, usage interne (jamais lu depuis la requête) : tâches des pages système, créées avec la tâche de
     * l'accueil et lancées quand il est composé. Page système => identifiant de tâche.
     *
     * @var array<string, string>
     */
    public array $children = [];
    /** Mode site, usage interne : résumé du style de l'accueil composé, redonné pour chaque page système */
    public ?string $siteStyle = null;
    /**
     * Réponses de l'administrateur aux questions de l'assistant (19/10/2026, un seul tour : avec des réponses, le modèle
     * ne pose plus de question). La question est renvoyée avec sa réponse : le serveur ne garde rien entre deux demandes.
     *
     * @var list<array{id: string, question: string, answer: string|list<string>}>
     */
    public array $answers = [];
    /** L'administrateur ne veut pas de questions : le modèle compose avec le choix le plus sûr */
    public bool $noQuestions = false;
    /**
     * Historique de la console (19/10/2026) : les derniers échanges sur la même section ou la même page, du plus ancien
     * au plus récent, gardés par la console et renvoyés à chaque demande (rien n'est stocké par le serveur). outcome :
     * applied (appliqué), rejected (refusé par l'administrateur), undone (annulé ensuite), ou absent.
     *
     * @var list<array{prompt: string, summary: string, outcome: ?string}>
     */
    public array $history = [];
    /**
     * Clé de mémoire de la section ou de la page (19/10/2026), choisie par la console et stable d'une session à l'autre :
     * le résumé de la réponse est gardé sous cette clé, et sans « history » les derniers échanges gardés sont redonnés
     * au modèle (LandingAiMemoryService).
     */
    public ?string $memoryKey = null;

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
        if (isset($body->system)) {
            is_string($body->system) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', $body->system)
                ? $dto->system = $body->system
                : $dto->shapeErrors[] = ['path' => 'system', 'message' => 'clé d\'une page système attendue (ex. product, login)'];
        }
        if (isset($body->app)) {
            is_string($body->app) && in_array($body->app, self::SITE_APPS, true)
                ? $dto->app = $body->app
                : $dto->shapeErrors[] = ['path' => 'app', 'message' => 'landingpage ou boutique attendu'];
        }
        if (isset($body->pages)) {
            if (!is_array($body->pages)) {
                $dto->shapeErrors[] = ['path' => 'pages', 'message' => 'liste attendue : clés de pages système (boutique) ou objets { title, brief? } (onglets d\'une landing page)'];
            } else {
                foreach ($body->pages as $i => $page) {
                    if (is_object($page)) {
                        ($tab = self::readTab($page, "pages[$i]", $dto->shapeErrors)) !== null ? $dto->tabs[] = $tab : null;
                    } else {
                        is_string($page) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', $page)
                            ? $dto->pages[] = $page
                            : $dto->shapeErrors[] = ['path' => "pages[$i]", 'message' => 'clé d\'une page système (ex. product, login) ou objet { title, brief? } attendu'];
                    }
                }
            }
        }
        if (property_exists($body, 'tab') && $body->tab !== null) {
            is_object($body->tab)
                ? $dto->tab = self::readTab($body->tab, 'tab', $dto->shapeErrors)
                : $dto->shapeErrors[] = ['path' => 'tab', 'message' => 'objet { title, brief? } attendu'];
        }
        if (property_exists($body, 'charter') && $body->charter !== null) {
            is_object($body->charter)
                ? $dto->charter = $body->charter
                : $dto->shapeErrors[] = ['path' => 'charter', 'message' => 'objet attendu (primaryColor, accentColor, textColor, backgroundColor, headingFont, bodyFont, radius, buttonStyle)'];
        }
        if (property_exists($body, 'style') && $body->style !== null) {
            if (!is_object($body->style)) {
                $dto->shapeErrors[] = ['path' => 'style', 'message' => 'objet attendu (tone, motion, density)'];
            } else {
                $dto->style = [];
                foreach (get_object_vars($body->style) as $key => $value) {
                    if (!isset(self::STYLE_OPTIONS[$key])) {
                        $dto->shapeErrors[] = ['path' => "style.$key", 'message' => 'réglage inconnu (connus : ' . implode(', ', array_keys(self::STYLE_OPTIONS)) . ')'];
                    } elseif ($value !== null && !in_array($value, self::STYLE_OPTIONS[$key], true)) {
                        $dto->shapeErrors[] = ['path' => "style.$key", 'message' => 'valeur attendue : ' . implode(', ', self::STYLE_OPTIONS[$key])];
                    } elseif ($value !== null) {
                        $dto->style[$key] = $value;
                    }
                }
                if ($dto->style === []) {
                    $dto->style = null;
                }
            }
        }
        if (isset($body->answers)) {
            if (!is_array($body->answers) || count($body->answers) > self::MAX_ANSWERS) {
                $dto->shapeErrors[] = ['path' => 'answers', 'message' => sprintf('liste de %d réponses au plus attendue ({ id, question, answer })', self::MAX_ANSWERS)];
            } else {
                foreach ($body->answers as $i => $answer) {
                    ($read = self::readAnswer($answer, "answers[$i]", $dto->shapeErrors)) !== null ? $dto->answers[] = $read : null;
                }
            }
        }
        if (isset($body->history)) {
            if (!is_array($body->history) || count($body->history) > self::MAX_HISTORY) {
                $dto->shapeErrors[] = ['path' => 'history', 'message' => sprintf('liste de %d échanges au plus attendue ({ prompt, summary, outcome? })', self::MAX_HISTORY)];
            } else {
                foreach ($body->history as $i => $exchange) {
                    ($read = self::readExchange($exchange, "history[$i]", $dto->shapeErrors)) !== null ? $dto->history[] = $read : null;
                }
            }
        }
        if (isset($body->memoryKey)) {
            is_string($body->memoryKey) && preg_match(\App\Services\LandingAiService\LandingAiMemoryService::KEY_PATTERN, $body->memoryKey)
                ? $dto->memoryKey = $body->memoryKey
                : $dto->shapeErrors[] = ['path' => 'memoryKey', 'message' => 'clé de mémoire de 1 à 120 caractères attendue (lettres, chiffres, : . _ -)'];
        }
        if (isset($body->noQuestions)) {
            is_bool($body->noQuestions)
                ? $dto->noQuestions = $body->noQuestions
                : $dto->shapeErrors[] = ['path' => 'noQuestions', 'message' => 'booléen attendu'];
        }
        if (isset($body->designMd)) {
            is_string($body->designMd) && trim($body->designMd) !== ''
                ? $dto->designMd = trim($body->designMd)
                : $dto->shapeErrors[] = ['path' => 'designMd', 'message' => 'texte non vide attendu (contenu d\'un fichier DESIGN.md)'];
        }
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
        $pdfs = 0;
        foreach ($images as $i => $image) {
            $error = null;
            $read = self::readImage($image, $error);
            if ($read !== null && $read['mediaType'] === self::PDF_TYPE && ++$pdfs > 1) {
                $read = null;
                $error = 'un seul document PDF par demande';
            }
            $read !== null
                ? $dto->images[] = $read
                : $dto->shapeErrors[] = ['path' => "images[$i]", 'message' => $error];
        }

        return $dto;
    }

    /**
     * Onglet d'une landing page : { title (1 à MAX_TAB_TITLE_LENGTH caractères, sans < ni >), brief? (MAX_TAB_BRIEF_LENGTH au plus) }.
     *
     * @param list<array{path: string, message: string}> $errors
     * @return array{title: string, brief: ?string}|null
     */
    /**
     * Réponse à une question de l'assistant : { id, question (texte de la question posée), answer (texte, ou liste pour
     * une question à réponses multiples) }. Texte brut : les balises sont refusées.
     *
     * @return array{id: string, question: string, answer: string|list<string>}|null
     */
    private static function readAnswer(mixed $answer, string $path, array &$errors): ?array
    {
        if (!is_object($answer)) {
            $errors[] = ['path' => $path, 'message' => 'objet { id, question, answer } attendu'];

            return null;
        }
        $valid = true;
        $id = is_string($answer->id ?? null) ? $answer->id : '';
        if (!preg_match(\App\Services\LandingAiService\LandingAiQuestions::ID_PATTERN, $id)) {
            $errors[] = ['path' => "$path.id", 'message' => 'identifiant de la question attendu (celui renvoyé par l\'assistant)'];
            $valid = false;
        }
        $question = is_string($answer->question ?? null) ? trim($answer->question) : '';
        if (mb_strlen($question) > \App\Services\LandingAiService\LandingAiQuestions::MAX_QUESTION_LENGTH || preg_match('/[<>]/', $question)) {
            $errors[] = ['path' => "$path.question", 'message' => sprintf('texte de la question de %d caractères au plus, sans < ni >', \App\Services\LandingAiService\LandingAiQuestions::MAX_QUESTION_LENGTH)];
            $valid = false;
        }
        $text = fn (mixed $v) => is_string($v) && mb_strlen(trim($v)) <= self::MAX_ANSWER_LENGTH && !preg_match('/[<>]/', $v);
        $value = $answer->answer ?? null;
        if (is_array($value)) {
            if (count($value) > self::MAX_ANSWER_CHOICES || array_filter($value, fn ($v) => !$text($v)) !== []) {
                $errors[] = ['path' => "$path.answer", 'message' => sprintf('%d réponses au plus, chacune de %d caractères au plus, sans < ni >', self::MAX_ANSWER_CHOICES, self::MAX_ANSWER_LENGTH)];
                $valid = false;
            }
            $value = array_values(array_map(fn ($v) => trim((string) $v), $value));
        } elseif (!$text($value)) {
            $errors[] = ['path' => "$path.answer", 'message' => sprintf('réponse de %d caractères au plus, sans < ni > (texte, ou liste pour plusieurs choix)', self::MAX_ANSWER_LENGTH)];
            $valid = false;
        } else {
            $value = trim($value);
        }

        return $valid ? ['id' => $id, 'question' => $question, 'answer' => $value] : null;
    }

    /**
     * Échange de l'historique de la console : { prompt, summary, outcome? }. Les balises sont retirées (données).
     *
     * @return array{prompt: string, summary: string, outcome: ?string}|null
     */
    private static function readExchange(mixed $exchange, string $path, array &$errors): ?array
    {
        if (!is_object($exchange)) {
            $errors[] = ['path' => $path, 'message' => 'objet { prompt, summary, outcome? } attendu'];

            return null;
        }
        $plain = fn (mixed $v) => is_string($v) ? trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5)) : null;
        $prompt = $plain($exchange->prompt ?? null);
        $summary = $plain($exchange->summary ?? '');
        $outcome = $exchange->outcome ?? null;
        $valid = true;
        if ($prompt === null || $prompt === '' || mb_strlen($prompt) > self::MAX_HISTORY_PROMPT_LENGTH) {
            $errors[] = ['path' => "$path.prompt", 'message' => sprintf('demande de 1 à %d caractères attendue', self::MAX_HISTORY_PROMPT_LENGTH)];
            $valid = false;
        }
        if ($summary === null || mb_strlen($summary) > self::MAX_HISTORY_SUMMARY_LENGTH) {
            $errors[] = ['path' => "$path.summary", 'message' => sprintf('résumé de %d caractères au plus attendu', self::MAX_HISTORY_SUMMARY_LENGTH)];
            $valid = false;
        }
        if ($outcome !== null && !in_array($outcome, self::HISTORY_OUTCOMES, true)) {
            $errors[] = ['path' => "$path.outcome", 'message' => 'valeur attendue : ' . implode(', ', self::HISTORY_OUTCOMES) . ' (ou absent)'];
            $valid = false;
        }

        return $valid ? ['prompt' => $prompt, 'summary' => $summary, 'outcome' => $outcome] : null;
    }

    private static function readTab(object $tab, string $path, array &$errors): ?array
    {
        $title = is_string($tab->title ?? null) ? trim($tab->title) : '';
        $brief = isset($tab->brief) ? $tab->brief : null;
        $valid = true;
        if ($title === '' || mb_strlen($title) > self::MAX_TAB_TITLE_LENGTH || preg_match('/[<>]/', $title)) {
            $errors[] = ['path' => "$path.title", 'message' => sprintf('titre de l\'onglet attendu : 1 à %d caractères, sans < ni >', self::MAX_TAB_TITLE_LENGTH)];
            $valid = false;
        }
        if ($brief !== null && (!is_string($brief) || mb_strlen(trim($brief)) > self::MAX_TAB_BRIEF_LENGTH)) {
            $errors[] = ['path' => "$path.brief", 'message' => sprintf('texte de %d caractères au plus attendu', self::MAX_TAB_BRIEF_LENGTH)];
            $valid = false;
        }
        $brief = is_string($brief) ? trim($brief) : null;

        return $valid ? ['title' => $title, 'brief' => $brief === '' ? null : $brief] : null;
    }

    /**
     * data:image/png|jpeg|webp|gif;base64,… dont le contenu est bien une image de ce type, ou
     * data:application/pdf;base64,… d'une maquette en PDF (15/10/2026 ; l'API lit chaque page comme une image et son texte).
     *
     * @return array{mediaType: string, data: string}|null
     */
    private static function readImage(mixed $image, ?string &$error): ?array
    {
        if (!is_string($image) || !preg_match('#^data:(image/(?:png|jpeg|webp|gif)|application/pdf);base64,([A-Za-z0-9+/]+={0,2})$#', $image, $m)) {
            $error = 'data URL base64 d\'une image PNG, JPEG, WebP ou GIF, ou d\'un document PDF, attendue';

            return null;
        }
        if ($m[1] === self::PDF_TYPE) {
            if (strlen($m[2]) > self::MAX_PDF_BASE64_BYTES) {
                $error = sprintf('document PDF trop lourd (%d Mo au plus en base64)', intdiv(self::MAX_PDF_BASE64_BYTES, 1000000));

                return null;
            }
            $head = base64_decode(substr($m[2], 0, 12), true);
            if ($head === false || !str_starts_with($head, '%PDF-')) {
                $error = 'contenu illisible ou différent du type annoncé (application/pdf)';

                return null;
            }

            return ['mediaType' => self::PDF_TYPE, 'data' => $m[2]];
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

    /** Page, site et requêtes avec images : traitées en tâche de fond (réponse 202, résultat à interroger) */
    public function isAsync(): bool
    {
        return in_array($this->mode, ['page', 'site'], true) || $this->images !== [];
    }

    /** Requête normalisée, relue par le worker avec fromRequestBody() (children : lu à part par le worker) */
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
            'system' => $this->system,
            'app' => $this->app,
            'pages' => $this->app === 'landingpage' ? $this->tabs : $this->pages,
            'tab' => $this->tab,
            'charter' => $this->charter,
            'style' => $this->style,
            'designMd' => $this->designMd,
            'children' => $this->children,
            'siteStyle' => $this->siteStyle,
            'answers' => $this->answers,
            'noQuestions' => $this->noQuestions,
            'history' => $this->history,
            'memoryKey' => $this->memoryKey,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Relecture d'une tâche enregistrée (worker) : comme une requête, plus les tâches filles du mode site */
    public static function fromStoredJob(string $json): self
    {
        $body = json_decode($json, false);
        $dto = self::fromRequestBody($body);
        foreach (is_object($body) && is_object($body->children ?? null) ? get_object_vars($body->children) : [] as $page => $jobId) {
            if (is_string($jobId)) {
                $dto->children[$page] = $jobId;
            }
        }
        if (is_object($body) && is_string($body->siteStyle ?? null)) {
            $dto->siteStyle = $body->siteStyle;
        }

        return $dto;
    }

    /** Taille maximale du corps de la requête : JSON ordinaire plus les images et un PDF */
    public static function maxBodyBytes(): int
    {
        return self::MAX_BODY_BYTES + (self::MAX_IMAGES - 1) * (self::MAX_IMAGE_BASE64_BYTES + 64) + self::MAX_PDF_BASE64_BYTES + 64;
    }

    /**
     * @param list<string> $componentKeys familles du catalogue
     * @param list<string> $systemPages clés des pages système du catalogue (vide : catalogue sans pages système)
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $componentKeys, array $systemPages = []): array
    {
        $errors = $this->shapeErrors;
        if (!in_array($this->mode, self::MODES, true)) {
            $errors[] = ['path' => 'mode', 'message' => 'edit, create, page ou site attendu'];
        }
        // en mode page, componentKey est facultatif : il impose alors la famille de toutes les sections ; en mode site, il n'a pas de sens
        if ($this->mode === 'site' && $this->componentKey !== null) {
            $errors[] = ['path' => 'componentKey', 'message' => 'sans objet en mode site'];
        } elseif (!in_array($this->mode, ['page', 'site'], true) || $this->componentKey !== null) {
            if (!in_array($this->componentKey, $componentKeys, true)) {
                $errors[] = ['path' => 'componentKey', 'message' => 'famille absente du catalogue de l\'éditeur'];
            }
        }
        // app (landingpage ou boutique) : envoyé par l'éditeur dans tous les modes, utilisé seulement en mode site
        // (forme des pages) ; ignoré ailleurs (18/10/2026 : le refus hors mode site cassait les demandes de la console)
        if ($this->tab !== null) {
            if ($this->mode !== 'page') {
                $errors[] = ['path' => 'tab', 'message' => 'seulement en mode page'];
            } elseif ($this->system !== null) {
                $errors[] = ['path' => 'tab', 'message' => 'un onglet de la landing page ou une page système de la boutique, pas les deux'];
            }
        }
        $unknownPage = fn (string $key) => $systemPages === []
            ? 'le catalogue de l\'éditeur ne publie pas de pages système'
            : sprintf('page système inconnue (connues : %s)', implode(', ', $systemPages));
        if ($this->system !== null) {
            if ($this->mode !== 'page') {
                $errors[] = ['path' => 'system', 'message' => 'seulement en mode page'];
            } elseif (!in_array($this->system, $systemPages, true)) {
                $errors[] = ['path' => 'system', 'message' => $unknownPage($this->system)];
            }
        }
        if ($this->mode === 'site') {
            // application du site : explicite, sinon déduite de la forme de pages (clés = boutique, objets = landing page)
            if ($this->pages !== [] && $this->tabs !== []) {
                $errors[] = ['path' => 'pages', 'message' => 'une seule forme : clés de pages système (boutique) ou objets { title, brief? } (landing page)'];
            } elseif ($this->app === null) {
                $this->app = $this->tabs !== [] ? 'landingpage' : 'boutique';
            } elseif ($this->app === 'boutique' && $this->tabs !== []) {
                $errors[] = ['path' => 'app', 'message' => 'app boutique : pages = clés de pages système attendues (les objets { title } sont des onglets de landing page)'];
            } elseif ($this->app === 'landingpage' && $this->pages !== []) {
                $errors[] = ['path' => 'app', 'message' => 'app landingpage : pages = objets { title, brief? } attendus (les clés désignent des pages système de la boutique)'];
            }
        }
        if ($this->mode === 'site' && $this->app === 'landingpage') {
            if ($this->tabs === []) {
                $errors[] = ['path' => 'pages', 'message' => 'au moins un onglet à composer après l\'accueil (ex. [{ "title": "Services" }, { "title": "Contact" }])'];
            } elseif (count($this->tabs) > self::MAX_SITE_PAGES) {
                $errors[] = ['path' => 'pages', 'message' => sprintf('%d onglets au plus', self::MAX_SITE_PAGES)];
            } else {
                $titles = array_map(fn ($tab) => mb_strtolower($tab['title']), $this->tabs);
                if (count(array_unique($titles)) !== count($titles)) {
                    $errors[] = ['path' => 'pages', 'message' => 'titres d\'onglets distincts attendus (sans distinction de casse)'];
                }
            }
        } elseif ($this->mode === 'site') {
            if ($this->pages === []) {
                $errors[] = ['path' => 'pages', 'message' => 'au moins une page système à composer après l\'accueil (ex. ["product", "login", "register", "account"])'];
            } elseif (count($this->pages) > self::MAX_SITE_PAGES) {
                $errors[] = ['path' => 'pages', 'message' => sprintf('%d pages au plus', self::MAX_SITE_PAGES)];
            } elseif (count(array_unique($this->pages)) !== count($this->pages)) {
                $errors[] = ['path' => 'pages', 'message' => 'chaque page une seule fois'];
            }
            foreach ($this->pages as $i => $page) {
                if (!in_array($page, $systemPages, true)) {
                    $errors[] = ['path' => "pages[$i]", 'message' => $unknownPage($page)];
                }
            }
        }
        if ($this->mode !== 'site' && ($this->pages !== [] || $this->tabs !== [])) {
            $errors[] = ['path' => 'pages', 'message' => 'seulement en mode site'];
        }
        if ($this->designMd !== null && mb_strlen($this->designMd) > self::MAX_DESIGN_MD_LENGTH) {
            $errors[] = ['path' => 'designMd', 'message' => sprintf('%d caractères au plus', self::MAX_DESIGN_MD_LENGTH)];
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
