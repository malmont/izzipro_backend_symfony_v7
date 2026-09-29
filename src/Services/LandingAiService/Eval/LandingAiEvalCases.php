<?php

namespace App\Services\LandingAiService\Eval;

use App\Services\LandingAiService\CompositionInspector;
use App\Services\LandingAiService\LandingAiCreateResult;
use App\Services\LandingAiService\LandingAiEditResult;

/**
 * Cas de l'étape 1 (retouche) du jeu d'essai, avec leurs vérifications propres quand elles sont mesurables.
 * Les compositions de départ sont les modèles du catalogue (« groupe T » = presentation-group/group-type-t…).
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
                'id' => 'R1', 'presetId' => 'group-type-t', 'prompt' => 'Rends la section plus aérée.',
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
                'id' => 'R2', 'presetId' => 'group-type-v', 'prompt' => 'Cartes des formules sur 2 colonnes.',
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
                'id' => 'R10', 'presetId' => 'group-type-v', 'prompt' => 'Ajoute un filtre sombre sur l\'image de fond.',
                'check' => function (object $before, object $after, LandingAiEditResult $r) {
                    $inventedImage = ($after->bgImage ?? '') !== ($before->bgImage ?? '');

                    return $this->result(!$inventedImage && $r->warnings !== [], ($inventedImage ? 'image de fond ajoutée' : 'aucune image inventée') . ($r->warnings ? ' ; avertissement : ' . mb_substr($r->warnings[0], 0, 120) : ' ; aucun avertissement'));
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
                    $video = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'video' && ($b->url ?? null) === 'https://media.example.com/intro.mp4');
                    $contact = $this->find($r->composition, fn ($b) => ($b->type ?? null) === 'button' && (($b->action ?? null) === 'contact' || ($b->url ?? null) === '#contact'));

                    return $this->result($video !== null && $contact !== null, sprintf('vidéo avec l\'URL exacte : %s ; bouton de contact : %s', $video ? 'oui' : 'non', $contact ? 'oui' : 'non'));
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

                    return $this->result($form !== null && $email && $phone, sprintf('formulaire de contact : %s ; e-mail lié : %s ; téléphone lié : %s', $form ? 'oui' : 'non', $email ? 'oui' : 'non', $phone ? 'oui' : 'non'));
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
                'id' => 'C8', 'componentKey' => 'PresentationGroup', 'media' => [], 'prompt' => 'Enregistre ce résultat comme modèle "Cartes premium".',
                'skip' => 'sans objet côté backend : l\'enregistrement d\'un modèle personnel se fait dans l\'éditeur (reglablePresets, PUT des réglages) ; l\'endpoint n\'écrit jamais les réglages',
            ],
        ];
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
