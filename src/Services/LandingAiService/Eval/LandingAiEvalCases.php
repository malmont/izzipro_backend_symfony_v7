<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\LandingAiCreateResult;
use App\Services\LandingAiService\LandingAiEditResult;
use App\Services\LandingAiService\LandingAiPageResult;

/**
 * Cas de l'étape 1 (retouche) du jeu d'essai, avec leurs vérifications propres quand elles sont mesurables.
 * Les compositions de départ sont les modèles du catalogue (« groupe S » = presentation-group/group-type-s…). Le 07/10/2026,
 * group-type-t et group-type-v ont quitté le catalogue (textes d'un client) : R1 passe sur group-type-s, R2 sur
 * group-type-r (formules en liste répétée), R10 sur group-type-o (sans image de fond).
 * Corrections du 28/09/2026 (frontend) : R4 part de footer-type-d, R9 de video-type-a.
 * Une vérification renvoie ['ok' => true|false|null, 'detail' => string] ; null = non mesurable sur ce départ.
 */
final class LandingAiEvalCases
{
    private const TEXT_KEYS = CompositionInspector::TEXT_KEYS;

    public function __construct(private readonly CompositionInspector $inspector)
    {
    }

    /**
     * @return list<array{id: string, presetId: string, prompt: string, check: callable(object, object, LandingAiEditResult, array): array}>
     */
    public function editCases(): array
    {
        return [
            [
                'id' => 'R1', 'presetId' => 'group-type-s', 'prompt' => 'Rends la section plus aérée.',
                'check' => function (object $before, object $after) {
                    $gapUp = ($after->rootGap ?? 0) > ($before->rootGap ?? 0);
                    foreach ($this->pairs($before, $after) as [$b, $a]) {
                        if (($b->type ?? null) === 'container' && ($a->gap ?? 0) > ($b->gap ?? 0)) {
                            $gapUp = true;
                        }
                    }
                    $texts = $this->changedTexts($before, $after);

                    return $this->result($gapUp && !$texts, ($gapUp ? 'espacements augmentés' : 'aucun espacement augmenté') . ($texts ? ' ; textes modifiés : ' . implode(', ', $texts) : ''));
                },
            ],
            [
                'id' => 'R2', 'presetId' => 'group-type-r', 'prompt' => 'Cartes des formules sur 2 colonnes.',
                'check' => function (object $before, object $after) {
                    $list = $this->find($after, fn ($b) => is_object($b->repeat ?? null));
                    $beforeList = $list ? ($this->inspector->blocksById($before)[$list->id] ?? null) : null;
                    $mobileSame = json_encode($list?->mobile->columns ?? null) === json_encode($beforeList?->mobile->columns ?? null);

                    return $this->result(($list->columns ?? null) === 2 && $mobileSame, sprintf('columns = %s ; mobile.columns %s', json_encode($list->columns ?? null), $mobileSame ? 'inchangé' : 'modifié'));
                },
            ],
            [
                'id' => 'R3', 'presetId' => 'presentation-type-j', 'prompt' => 'Passe aux couleurs vert sapin et or de la marque.',
                'check' => function (object $before, object $after, LandingAiEditResult $r, array $palette) {
                    $known = array_merge(array_keys($palette['colors'] ?? []), array_keys($this->inspector->colors($before)));
                    $known = array_map('strtolower', $known);
                    $new = array_values(array_filter(array_keys($this->inspector->colors($after)), fn ($c) => !in_array(strtolower($c), $known, true)));
                    $texts = $this->changedTexts($before, $after);

                    return $this->result(!$new && !$texts, ($new ? 'couleurs hors site : ' . implode(', ', array_slice($new, 0, 4)) : 'couleurs du site uniquement') . ($texts ? ' ; textes modifiés' : ''));
                },
            ],
            [
                'id' => 'R4', 'presetId' => 'footer-type-d', 'prompt' => 'Sur mobile, centre tout et masque la colonne Navigation.',
                'check' => function (object $before, object $after) {
                    $centered = (($after->mobile->align ?? null) === 'center') || $this->find($after, fn ($b) => ($b->mobile->align ?? null) === 'center') !== null;
                    $desktopChanged = [];
                    foreach ($this->pairs($before, $after) as [$b, $a]) {
                        $bb = clone $b; $aa = clone $a;
                        unset($bb->mobile, $aa->mobile);
                        if (json_encode($bb) !== json_encode($aa)) {
                            $desktopChanged[] = $b->id;
                        }
                    }
                    $navTitle = $this->find($before, fn ($b) => trim(strip_tags((string) ($b->text ?? ''))) === 'Navigation');
                    $navColumn = $navTitle ? ($this->inspector->blocksById($after)[$navTitle->parentId ?? ''] ?? null) : null;
                    $hidden = $navColumn !== null && ($navColumn->mobile->hidden ?? false) === true;

                    return $this->result($centered && !$desktopChanged && $hidden, ($centered ? 'mobile.align = center' : 'pas de centrage mobile')
                        . ($desktopChanged ? ' ; grand écran modifié : ' . implode(', ', $desktopChanged) : ' ; grand écran inchangé')
                        . ($navTitle === null ? ' ; colonne Navigation absente du modèle' : ($hidden ? ' ; colonne Navigation masquée sur mobile' : ' ; colonne Navigation non masquée')));
                },
            ],
            [
                'id' => 'R5', 'presetId' => 'navbar-type-g', 'prompt' => 'Mets le bouton Démarrer un projet en dégradé.',
                'check' => function (object $before, object $after, LandingAiEditResult $r) {
                    $button = $this->find($after, fn ($b) => ($b->type ?? null) === 'button' && str_contains((string) ($b->text ?? ''), 'Démarrer'));
                    $gradient = is_string($button->background ?? null) && preg_match('/^(repeating-)?(linear|radial|conic)-gradient\(/i', $button->background);
                    $others = array_diff($r->touchedBlockIds, $button ? [$button->id] : []);

                    return $this->result($gradient && !$others, ($gradient ? 'bouton en dégradé' : 'bouton sans dégradé') . ($others ? ' ; autres blocs touchés : ' . implode(', ', $others) : ''));
                },
            ],
            [
                'id' => 'R6', 'presetId' => 'group-type-s', 'prompt' => 'Réécris le titre et l\'introduction, ton plus chaleureux.',
                'check' => function (object $before, object $after) {
                    $changed = $this->changedTexts($before, $after);
                    $unexpected = array_diff($changed, ['s-titre', 's-intro']);

                    return $this->result($changed && !$unexpected, 'textes modifiés : ' . ($changed ? implode(', ', $changed) : 'aucun') . ($unexpected ? ' (hors titre et introduction)' : ''));
                },
            ],
            [
                'id' => 'R7', 'presetId' => 'presentation-type-i', 'prompt' => 'Traduis tous les textes en anglais.',
                'check' => function (object $before, object $after) {
                    $missing = [];
                    foreach ($this->inspector->blocks($after) as $block) {
                        if (is_string($block->text ?? null) && trim(strip_tags($block->text)) !== '' && !isset($block->bindings->text)
                            && !is_string($block->translations->en->text ?? null)) {
                            $missing[] = $block->id;
                        }
                    }
                    $texts = $this->changedTexts($before, $after);

                    return $this->result(!$missing && !$texts, ($missing ? 'sans translations.en : ' . implode(', ', $missing) : 'textes non liés traduits') . ($texts ? ' ; textes de base modifiés' : ''));
                },
            ],
            [
                'id' => 'R8', 'presetId' => 'group-type-r', 'prompt' => 'Ajoute une animation d\'apparition échelonnée aux cartes.',
                'check' => function (object $before, object $after) {
                    $list = $this->find($after, fn ($b) => is_object($b->repeat ?? null));
                    $stagger = ($list->repeat->stagger ?? 0) > 0;
                    $card = $list ? $this->find($after, fn ($b) => ($b->parentId ?? null) === $list->id) : null;

                    return $this->result($stagger || isset($card->animation), sprintf('stagger = %s ; animation de la carte = %s', json_encode($list->repeat->stagger ?? null), json_encode($card->animation ?? null)));
                },
            ],
            [
                'id' => 'R9', 'presetId' => 'video-type-a', 'prompt' => 'Supprime le badge et agrandis le titre.',
                'check' => function (object $before, object $after) {
                    $hadBadge = $this->find($before, fn ($b) => ($b->type ?? null) === 'badge') !== null;
                    $badgeLeft = $this->find($after, fn ($b) => ($b->type ?? null) === 'badge') !== null;
                    $bigger = false;
                    foreach ($this->pairs($before, $after) as [$b, $a]) {
                        if (($b->type ?? null) === 'title' && ($a->size ?? 0) > ($b->size ?? 0)) {
                            $bigger = true;
                        }
                    }

                    return $this->result(!$badgeLeft && $bigger, ($hadBadge ? ($badgeLeft ? 'badge encore présent' : 'badge retiré') : 'pas de badge dans le modèle') . ($bigger ? ' ; titre agrandi' : ' ; titre non agrandi'));
                },
            ],
            [
                'id' => 'R10', 'presetId' => 'group-type-o', 'prompt' => 'Ajoute un filtre sombre sur l\'image de fond.',
                'check' => function (object $before, object $after, LandingAiEditResult $r) {
                    $inventedImage = ($after->bgImage ?? '') !== ($before->bgImage ?? '');

                    return $this->result(!$inventedImage && $r->warnings !== [], ($inventedImage ? 'image de fond ajoutée' : 'aucune image inventée') . ($r->warnings ? ' ; avertissement : ' . mb_substr($r->warnings[0], 0, 120) : ' ; aucun avertissement'));
                },
            ],
            [
                // Ajout d'un bloc : le seul cas où les exemples de la famille pourraient manquer (mesure du 01/10/2026)
                'id' => 'R11', 'presetId' => 'presentation-type-f', 'prompt' => 'Ajoute un second bouton « Nous contacter » à côté du bouton existant, vers l\'ancre #contact.',
                'check' => function (object $before, object $after) {
                    $beforeIds = array_keys($this->inspector->blocksById($before));
                    $existing = $this->inspector->blocksById($after)['f-bouton'] ?? null;
                    $added = $this->find($after, fn ($b) => ($b->type ?? null) === 'button' && !in_array($b->id ?? null, $beforeIds, true));
                    $target = $added !== null && (($added->url ?? null) === '#contact' || ($added->action ?? null) === 'contact');
                    $placed = $added !== null && $existing !== null && $this->sameRow($after, $added, $existing);

                    return $this->result($added !== null && $target && $existing !== null, sprintf(
                        'bouton ajouté : %s ; vers #contact : %s ; bouton existant conservé : %s ; placé avec lui : %s',
                        $added ? 'oui (' . ($added->id ?? '?') . ')' : 'non', $target ? 'oui' : 'non', $existing ? 'oui' : 'non', $placed ? 'oui' : 'non'
                    ));
                },
            ],
        ];
    }

    /** Clé de média fictive fournie avec la demande C4 (format de GET /media/secure/{clé}) */
    public const C4_MEDIA_KEY = 'a4c1e2f3b5d60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * Cas de l'étape 2 (création). Les sites cités par le jeu d'essai ne sont pas relus : tous les cas jouent sur le
     * tenant de test de la commande (données, palette et médias de ce site).
     *
     * @return list<array{id: string, componentKey: string, prompt: string, media: list<array>, skip?: string, check?: callable(LandingAiCreateResult, list<string>): array}>
     */
    public function createCases(): array
    {
        return [
            [
                'id' => 'C1', 'componentKey' => 'Service', 'media' => [], 'prompt' => 'Grille de mes services avec prix et bouton Réserver.',
                'check' => function (LandingAiCreateResult $r) {
                    $list = $this->find($r->composition, fn ($b) => ($b->repeat->source ?? null) === 'services');
                    $bound = $this->boundPaths($r->composition);
                    $button = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'button' && ($b->action ?? null) === 'reservation');

                    return $this->result($list !== null && in_array('item.title', $bound, true) && in_array('item.price', $bound, true) && ($button->bindings->offer ?? null) === 'item.title',
                        sprintf('liste services : %s ; item.title : %s ; item.price : %s ; bouton reservation + offer=item.title : %s',
                            $list ? 'oui' : 'non', in_array('item.title', $bound, true) ? 'oui' : 'non', in_array('item.price', $bound, true) ? 'oui' : 'non',
                            ($button->bindings->offer ?? null) === 'item.title' ? 'oui' : 'non'));
                },
            ],
            [
                'id' => 'C2', 'componentKey' => 'PresentationGroup', 'media' => [], 'prompt' => 'Section qui présente mes forfaits.',
                'check' => function (LandingAiCreateResult $r, array $availableIds) {
                    $items = array_filter($this->boundPaths($r->composition), fn ($p) => str_starts_with($p, 'item.'));

                    return $this->result(in_array($r->dataType, $availableIds, true) && $items !== [],
                        sprintf('dataType = %s (%s) ; liaisons item.* : %d', json_encode($r->dataType), in_array($r->dataType, $availableIds, true) ? 'donnée du site' : 'hors site', count($items)));
                },
            ],
            [
                'id' => 'C3', 'componentKey' => 'Presentation', 'media' => [], 'prompt' => 'Héros avec cette vidéo : https://media.example.com/intro.mp4, titre et bouton de contact.',
                'check' => function (LandingAiCreateResult $r) {
                    // vidéo : bloc video ou vidéo de fond (bgVideo) de la section ou d'un conteneur, avec l'URL exacte
                    $url = 'https://media.example.com/intro.mp4';
                    $video = match (true) {
                        ($r->composition->bgVideo ?? null) === $url => 'fond de section',
                        $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'video' && ($b->url ?? null) === $url) !== null => 'bloc vidéo',
                        $this->find($r->composition, fn ($b) => ($b->bgVideo ?? null) === $url) !== null => 'fond de conteneur',
                        default => null,
                    };
                    $contact = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'button' && (($b->action ?? null) === 'contact' || ($b->url ?? null) === '#contact'));

                    return $this->result($video !== null && $contact !== null, sprintf('vidéo avec l\'URL exacte : %s ; bouton de contact : %s', $video ?? 'non', $contact ? 'oui' : 'non'));
                },
            ],
            [
                'id' => 'C4', 'componentKey' => 'Presentation', 'prompt' => 'Section À propos avec la photo de l\'équipe.',
                'media' => [['kind' => 'image', 'url' => null, 'mediaKey' => self::C4_MEDIA_KEY, 'label' => 'photo de l\'équipe']],
                'check' => function (LandingAiCreateResult $r) {
                    $image = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'image' && ($b->mediaKey ?? null) === self::C4_MEDIA_KEY);

                    return $this->result($image !== null, $image ? 'image avec la clé fournie' : 'clé de média fournie non utilisée');
                },
            ],
            [
                'id' => 'C5', 'componentKey' => 'PresentationGroup', 'media' => [], 'prompt' => 'Comme la section Tarifs, mais pour le Branding.',
                'check' => function (LandingAiCreateResult $r) {
                    $structure = $this->find($r->composition, fn ($b) => ($b->tabs ?? false) === true || ($b->repeat->source ?? null) === 'presentationGroup');
                    $branding = stripos(json_encode($r->composition, JSON_UNESCAPED_UNICODE), 'branding') !== false;

                    return $this->result($structure !== null && $branding, sprintf('structure onglets / liste de formules : %s ; textes sur le Branding : %s', $structure ? 'oui' : 'non', $branding ? 'oui' : 'non'));
                },
            ],
            [
                'id' => 'C6', 'componentKey' => 'Contact', 'media' => [], 'prompt' => 'Formulaire de contact avec coordonnées à gauche.',
                'check' => function (LandingAiCreateResult $r) {
                    $form = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'form' && ($b->formType ?? 'contact') === 'contact');
                    $bound = $this->boundPaths($r->composition);
                    $email = in_array('email', $bound, true) || in_array('emailUrl', $bound, true);
                    $phone = in_array('phone', $bound, true) || in_array('telUrl', $bound, true);
                    // Données de l'entreprise liables par la famille (relevé du frontend du 30/09/2026 : medium les omettait)
                    $logo = in_array('logoUrl', $bound, true);
                    $tagline = in_array('tagline', $bound, true);

                    return $this->result($form !== null && $email && $phone && $logo && $tagline, sprintf('formulaire de contact : %s ; e-mail lié : %s ; téléphone lié : %s ; logo lié : %s ; accroche liée : %s', $form ? 'oui' : 'non', $email ? 'oui' : 'non', $phone ? 'oui' : 'non', $logo ? 'oui' : 'non', $tagline ? 'oui' : 'non'));
                },
            ],
            [
                'id' => 'C7', 'componentKey' => 'Navbar', 'media' => [], 'prompt' => 'Barre transparente sur le héros, menu burger sur mobile.',
                'check' => function (LandingAiCreateResult $r) {
                    $nav = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'nav');
                    $overlay = ($r->composition->overlayTop ?? false) === true;
                    $burger = $nav !== null && in_array($nav->navMobile ?? 'burger', ['burger'], true);
                    $linked = ($nav->bindings->links ?? null) === 'navLinks';

                    return $this->result($overlay && $burger && $linked, sprintf('overlayTop : %s ; burger mobile : %s ; liens liés aux onglets : %s', $overlay ? 'oui' : 'non', $burger ? 'oui' : 'non', $linked ? 'oui' : 'non'));
                },
            ],
            [
                // Scène au défilement (contrat du 02/10/2026) : la donnée vidéo du site de test est un fichier .mp4
                'id' => 'C9', 'componentKey' => 'Video', 'media' => [],
                'prompt' => 'Scène au défilement avec la vidéo du site : quatre étapes qui présentent notre méthode (écouter, concevoir, réaliser, accompagner).',
                'check' => function (LandingAiCreateResult $r) {
                    $c = $r->composition;
                    $blocks = $this->inspector->blocks($c);
                    $scenes = array_values(array_filter($blocks, fn ($b) => ($b->type ?? null) === 'container' && ($b->layout ?? null) === 'scroll'));
                    $children = $scenes ? array_values(array_filter($blocks, fn ($b) => ($b->parentId ?? null) === $scenes[0]->id)) : [];
                    $video = $children[0] ?? null;
                    $steps = array_slice($children, 1);
                    $source = ($video->type ?? null) === 'video' && (!empty($video->url) || !empty($video->mediaKey) || is_string($video->bindings->url ?? null));
                    $cards = count(array_filter($steps, fn ($b) => ($b->type ?? null) === 'container'));
                    $section = ($c->fullWidth ?? false) === true && !isset($c->contentWidth) && ($c->rootPadding ?? null) === 0 && empty($c->bgVideo);
                    $ok = count($scenes) === 1 && $source && count($steps) >= 3 && count($steps) <= 5 && $cards === count($steps) && $section;

                    return $this->result($ok, sprintf(
                        'scènes : %d ; vidéo en premier enfant avec une source : %s ; étapes : %d dont %d en carte ; section pleine largeur sans marge : %s',
                        count($scenes), $source ? 'oui' : 'non', count($steps), $cards, $section ? 'oui' : 'non'
                    ));
                },
            ],
            [
                'id' => 'C8', 'componentKey' => 'PresentationGroup', 'media' => [], 'prompt' => 'Enregistre ce résultat comme modèle "Cartes premium".',
                'skip' => 'sans objet côté backend : l\'enregistrement d\'un modèle personnel se fait dans l\'éditeur (reglablePresets, PUT des réglages) ; l\'endpoint n\'écrit jamais les réglages',
            ],
        ];
    }

    public const P1_COLORS = ['#1B2A4A', '#C9A227'];
    public const P1_FONT = 'Montserrat';
    public const P1_LOGO = 'https://media.example.com/horizon-conseil-logo.png';

    /**
     * Cas de l'étape 3 (page, images). Les images sont dans Eval/fixtures (P1 : charte 2 couleurs, 1 police, logo ;
     * P2 : capture d'une section services, 3 cartes en colonnes ; P3 : relecture visuelle, captures ordinateur et mobile
     * du rendu d'une section dégradée). P3 est une retouche (presetId, prepare) : vérifications V1 à V6 de la retouche.
     * P4 : page sans image dont la demande veut un rendu premium et vivant ; mesure les effets utilisés.
     *
     * @return list<array{id: string, prompt: string, componentKey: ?string, media: list<array>, images: list<string>, skip?: string, check?: callable(LandingAiPageResult): array}>
     */
    public function pageCases(): array
    {
        return [
            [
                'id' => 'P1', 'componentKey' => null, 'images' => ['p1-charte.png'],
                'media' => [['kind' => 'image', 'url' => self::P1_LOGO, 'mediaKey' => null, 'label' => 'logo du cabinet (celui de la charte)']],
                'prompt' => 'Page d\'accueil d\'un cabinet de conseil : héros, services, témoignages, contact.',
                'check' => function (LandingAiPageResult $r) {
                    $families = array_column($r->sections, 'componentKey');
                    $colors = [];
                    $fonts = [];
                    $logo = false;
                    $heights = [];
                    foreach ($r->sections as $i => $section) {
                        $colors += $this->inspector->colors($section['composition']);
                        $fonts += $this->inspector->fonts($section['composition']);
                        $logo = $logo || str_contains(json_encode($section['composition'], JSON_UNESCAPED_SLASHES), self::P1_LOGO);
                        if (($issue = $this->screenHeightIssue($section['composition'])) !== null) {
                            $heights[] = sprintf('%s (%s)', $section['componentKey'], $issue);
                        }
                    }
                    $offColors = array_values(array_filter(array_keys($colors), fn ($c) => !$this->charterColor($c, self::P1_COLORS)));
                    $offFonts = array_values(array_filter(array_keys($fonts), fn ($f) => stripos($f, self::P1_FONT) === false && !in_array(strtolower(trim($f)), ['inherit', 'sans-serif', 'serif'], true)));
                    // le logo fourni doit être placé (un logoUrl lié ailleurs ne le remplace pas)
                    $ok = count($r->sections) === 4 && end($families) === 'Contact' && !$offColors && !$offFonts && $logo && !$heights;

                    return $this->result($ok, sprintf(
                        'sections : %s ; couleurs hors charte : %s ; polices hors charte : %s ; logo fourni placé : %s ; hauteur d\'écran : %s ; ancres : %s',
                        implode(' > ', $families),
                        $offColors ? implode(', ', array_slice($offColors, 0, 4)) : 'aucune',
                        $offFonts ? implode(', ', $offFonts) : 'aucune',
                        $logo ? 'oui' : 'non',
                        $heights ? implode(', ', $heights) : 'conforme',
                        implode(', ', array_map(fn ($s) => is_string($s['composition']->anchor ?? null) ? $s['composition']->anchor : '(aucune)', $r->sections))
                    ));
                },
            ],
            [
                'id' => 'P2', 'componentKey' => null, 'images' => ['p2-capture.png'], 'media' => [],
                'prompt' => 'Reproduis cette section.',
                'check' => function (LandingAiPageResult $r) {
                    $composition = $r->sections[0]['composition'] ?? null;
                    if (count($r->sections) !== 1 || !is_object($composition)) {
                        return $this->result(false, sprintf('%d section(s) au lieu d\'une', count($r->sections)));
                    }
                    $columns = $this->threeColumns($composition);
                    $title = $this->find($composition, fn ($b) => ($b->type ?? null) === 'title') !== null;
                    $button = $this->find($composition, fn ($b) => ($b->type ?? null) === 'button') !== null;

                    return $this->result($columns !== null && $title && $button, sprintf(
                        '%s ; 3 colonnes : %s ; titre : %s ; bouton : %s',
                        $r->sections[0]['componentKey'],
                        $columns ?? 'non',
                        $title ? 'oui' : 'non',
                        $button ? 'oui' : 'non'
                    ));
                },
            ],
            [
                // Relecture visuelle (bouton de l'éditeur) : retouche avec les captures du rendu (ordinateur 1280 px,
                // puis mobile 390 px, JPEG). Départ : presentation-type-f dégradé comme sur les captures.
                'id' => 'P3', 'presetId' => 'presentation-type-f', 'images' => ['p3-ordinateur.jpg', 'p3-mobile.jpg'],
                'prompt' => 'Relecture visuelle : repère et corrige les défauts visibles sur les captures.',
                'prepare' => function (object $composition): void {
                    $blocks = $this->inspector->blocksById($composition);
                    $blocks['f-texte']->color = self::P3_WEAK_TEXT; // contraste 1,7:1 sur fond blanc
                    $blocks['f-gauche']->gap = 4;                    // colonne tassée
                    $blocks['f-titre']->size = 56;                   // titre coupé sur mobile
                },
                'check' => function (object $before, object $after) {
                    $blocks = $this->inspector->blocksById($after);
                    $background = is_string($after->background ?? null) ? $after->background : '#ffffff';
                    $contrast = $this->contrast((string) ($blocks['f-texte']->color ?? ''), $background);
                    $gap = $blocks['f-gauche']->gap ?? 0;
                    $mobileSize = $blocks['f-titre']->mobile->size ?? $blocks['f-titre']->size ?? 56;
                    $contrastFixed = $contrast !== null && $contrast >= 4.5;
                    $gapFixed = is_numeric($gap) && $gap >= 12;

                    return $this->result($contrastFixed || $gapFixed, sprintf(
                        'contraste du texte : %s (1,7 avant) ; espacement de la colonne : %s px (4 avant) ; titre mobile : %s px (56 avant)',
                        $contrast !== null ? number_format($contrast, 1, ',', '') : '?',
                        is_numeric($gap) ? $gap : '?',
                        is_numeric($mobileSize) ? $mobileSize : '?'
                    ));
                },
            ],
            [
                'id' => 'P4', 'componentKey' => null, 'images' => [], 'media' => [],
                'prompt' => 'Page d\'accueil premium et vivante pour un studio de design : héros, services, réalisations, contact. Rendu moderne, avec des effets.',
                'check' => function (LandingAiPageResult $r) {
                    $effects = [];
                    foreach ($r->sections as $section) {
                        foreach ($this->effects($section['composition']) as $effect => $count) {
                            $effects[$effect] = ($effects[$effect] ?? 0) + $count;
                        }
                    }
                    ksort($effects);
                    $ok = count($r->sections) === 4 && count($effects) >= 3 && isset($effects['animation'], $effects['hover']);

                    return $this->result($ok, sprintf(
                        'sections : %s ; effets : %s',
                        implode(' > ', array_column($r->sections, 'componentKey')),
                        $effects ? implode(', ', array_map(fn ($k, $v) => "$k × $v", array_keys($effects), $effects)) : 'aucun'
                    ));
                },
            ],
        ];
    }

    /** @return array<string, int> effets du contrat utilisés dans la composition => nombre d'emplois */
    private function effects(object $composition): array
    {
        $effects = !empty($composition->bgGradient) ? ['bgGradient' => 1] : [];
        foreach ($this->inspector->blocks($composition) as $block) {
            foreach (['animation', 'hover', 'shadow', 'textGradient', 'loop'] as $key) {
                if (!empty($block->$key)) {
                    $effects[$key] = ($effects[$key] ?? 0) + 1;
                }
            }
            if (!empty($block->repeat->stagger)) {
                $effects['stagger'] = ($effects['stagger'] ?? 0) + 1;
            }
        }

        return $effects;
    }

    public const P3_WEAK_TEXT = '#C4C4C4';

    /** Rapport de contraste WCAG entre deux couleurs opaques, ou null si l'une est illisible */
    private function contrast(string $foreground, string $background): ?float
    {
        $luminance = function (string $color): ?float {
            $rgb = $this->rgb(trim($color));
            if ($rgb === null) {
                return null;
            }
            $linear = array_map(fn ($c) => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };
        $a = $luminance($foreground);
        $b = $luminance(in_array(strtolower($background), ['transparent', 'white'], true) ? '#ffffff' : $background);

        return $a === null || $b === null ? null : (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Couleur de la charte (quelle que soit l'opacité) ou neutre (blanc, noir, gris) */
    private function charterColor(string $value, array $charter): bool
    {
        if (preg_match('/^(transparent|inherit|currentcolor|white|black)$/i', trim($value))) {
            return true;
        }
        $charterRgb = array_map(fn ($hex) => $this->rgb($hex), $charter);
        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', $value, $m);
        if (!$m[0]) {
            return false;
        }
        foreach ($m[0] as $token) {
            $rgb = $this->rgb($token);
            if ($rgb === null || !(in_array($rgb, $charterRgb, true) || $this->saturation($rgb) <= 0.2)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Saturation HSL (0 à 1) : un gris neutre reste sous 0,2, gris froids courants compris (#4b5563 : 0,14 ;
     * #374151 : 0,19), alors qu'un vert sapin foncé (#243b35 : 0,24) ou un beige (#d9cbb0 : 0,35) n'en sont pas.
     *
     * @param array{int, int, int} $rgb
     */
    private function saturation(array $rgb): float
    {
        $max = max($rgb) / 255;
        $min = min($rgb) / 255;
        $divider = 1 - abs($max + $min - 1);

        return $divider > 0 ? ($max - $min) / $divider : 0.0;
    }

    /** @return array{int, int, int}|null */
    private function rgb(string $color): ?array
    {
        if (preg_match('/^#([0-9a-f]{3,4})$/i', $color, $m)) {
            return array_map(fn ($c) => hexdec($c . $c), str_split(substr($m[1], 0, 3)));
        }
        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/i', $color, $m)) {
            return array_map('hexdec', str_split($m[1], 2));
        }
        if (preg_match('/^rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i', $color, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3]];
        }

        return null;
    }

    /**
     * Rangée de 3 colonnes : container en grille de 3 colonnes, container en rangée d'au moins 3 enfants (ou liste
     * répétée en rangée), ou, en positions libres, 3 containers alignés sur la même hauteur. Renvoie la forme trouvée.
     */
    private function threeColumns(object $composition): ?string
    {
        $blocks = $this->inspector->blocks($composition);
        $children = [];
        foreach ($blocks as $block) {
            $children[$block->parentId ?? ''][] = $block;
        }
        foreach ($blocks as $block) {
            if (($block->type ?? null) !== 'container') {
                continue;
            }
            $layout = $block->layout ?? 'free';
            if ($layout === 'grid' && ($block->columns ?? null) === 3) {
                return sprintf('grille de 3 colonnes (%s)', $block->id);
            }
            if (in_array($layout, ['row', 'grid'], true) && count($children[$block->id] ?? []) >= 3) {
                return sprintf('rangée de %d éléments (%s)', count($children[$block->id]), $block->id);
            }
            if (in_array($layout, ['row', 'grid'], true) && is_object($block->repeat ?? null)) {
                return sprintf('liste répétée en %s (%s, source %s)', $layout, $block->id, $block->repeat->source ?? '?');
            }
        }
        $containers = array_values(array_filter($blocks, fn ($b) => ($b->type ?? null) === 'container' && is_numeric($b->y ?? null) && is_numeric($b->x ?? null)));
        foreach ($containers as $a) {
            $aligned = array_filter($containers, fn ($b) => abs($b->y - $a->y) <= 3 && ($b->parentId ?? null) === ($a->parentId ?? null));
            if (count($aligned) >= 3 && count(array_unique(array_map(fn ($b) => (int) round($b->x), $aligned))) >= 3) {
                return sprintf('%d containers alignés en positions libres', count($aligned));
            }
        }

        return null;
    }

    /**
     * Section à hauteur d'écran (minHeightVh) : contenu centré (conteneur racine avec une hauteur minimale et valign
     * « center » ou « end ») et hauteur mobile réduite (mobileMinHeightVh plus petit). Renvoie le défaut, ou null.
     */
    private function screenHeightIssue(object $composition): ?string
    {
        $height = $composition->minHeightVh ?? null;
        if (!is_numeric($height)) {
            return null;
        }
        $centered = $this->find($composition, fn ($b) => ($b->type ?? null) === 'container' && ($b->parentId ?? null) === null
            && in_array($b->valign ?? null, ['center', 'end'], true) && (is_numeric($b->minHeightVh ?? null) || is_numeric($b->minHeight ?? null)));
        $mobile = $composition->mobileMinHeightVh ?? null;

        return match (true) {
            $centered === null => sprintf('minHeightVh %s sans contenu centré', $height),
            !is_numeric($mobile) || $mobile >= $height => sprintf('minHeightVh %s sans mobileMinHeightVh réduit', $height),
            default => null,
        };
    }

    /** @return list<string> chemins de liaison utilisés dans la composition (blocs et section) */
    private function boundPaths(object $composition): array
    {
        $paths = array_values((array) ($composition->bindings ?? []));
        foreach ($this->inspector->blocks($composition) as $block) {
            array_push($paths, ...array_values((array) ($block->bindings ?? [])));
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /** @return list<array{0: object, 1: object}> blocs présents avant et après, par identifiant */
    private function pairs(object $before, object $after): array
    {
        $afterById = $this->inspector->blocksById($after);
        $pairs = [];
        foreach ($this->inspector->blocksById($before) as $id => $block) {
            if (isset($afterById[$id])) {
                $pairs[] = [$block, $afterById[$id]];
            }
        }

        return $pairs;
    }

    /** @return list<string> identifiants des blocs dont un texte de base a changé */
    private function changedTexts(object $before, object $after): array
    {
        $changed = [];
        foreach ($this->pairs($before, $after) as [$b, $a]) {
            foreach (self::TEXT_KEYS as $key) {
                if (($b->$key ?? null) !== ($a->$key ?? null)) {
                    $changed[] = $b->id;
                    break;
                }
            }
        }

        return $changed;
    }

    /** Deux blocs côte à côte : même parent, ou réunis dans un container en rangée */
    private function sameRow(object $composition, object $a, object $b): bool
    {
        if (($a->parentId ?? null) === ($b->parentId ?? null)) {
            return true;
        }
        $parent = $this->inspector->blocksById($composition)[$a->parentId ?? ''] ?? null;

        return $parent !== null && ($parent->layout ?? null) === 'row';
    }

    private function find(object $composition, callable $predicate): ?object
    {
        foreach ($this->inspector->blocks($composition) as $block) {
            if ($predicate($block)) {
                return $block;
            }
        }

        return null;
    }

    private function result(bool $ok, string $detail): array
    {
        return ['ok' => $ok, 'detail' => $detail];
    }
}
