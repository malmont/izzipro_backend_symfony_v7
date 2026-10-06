<?php

namespace App\Services\LandingPageSettingsService;

/**
 * HTML autorisé dans les textes des compositions réglables : la même liste blanche que RichText du frontend, pour que
 * le backend refuse ce que le frontend retirerait (et ne dépende pas du seul nettoyage à l'affichage).
 *
 * Grammaire stricte : tout « < » suivi d'une lettre, de « / », « ! » ou « ? » (ce qu'un navigateur lit comme une
 * balise, un commentaire ou une déclaration) doit être exactement l'une des formes permises, sinon le texte est refusé :
 *   </balise>   <balise>   <balise/>   <balise style="…">   (style entre guillemets, sans < ni >)
 * On ne cherche donc pas à reconnaître les balises dangereuses : ce qui n'est pas reconnu comme permis est refusé
 * (un analyseur tolérant se contourne, ex. <img src=x" onerror=…>, que le navigateur exécute).
 *
 * Liens (depuis le 06/10/2026) : <a href="…">…</a>, avec href pour SEUL attribut (ni target, ni rel, ni style, ni
 * gestionnaire d'évènement). L'adresse, lue comme le navigateur la lit (entités HTML décodées), doit être https://,
 * http://, mailto:, tel:, un chemin du site (/…) ou une ancre (#…), sans espace ni caractère de contrôle : ce qui
 * ferme la porte à javascript:, data:, vbscript: et à leurs déguisements (« jav&#x09;ascript: », « &#106;avascript: »).
 * Le frontend pose target et rel (noopener noreferrer) à l'affichage et revérifie l'adresse.
 */
final class RichTextPolicy
{
    public const TAGS = ['p', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'br', 'hr', 'ul', 'ol', 'li', 'blockquote',
        'small', 'sub', 'sup', 'h2', 'h3', 'h4', 'h5', 'h6'];
    public const STYLE_PROPERTIES = ['font-style', 'font-weight', 'text-decoration', 'color'];
    /** Adresse permise pour un lien (texte) ou un bouton : décodée, sans espace ni caractère de contrôle */
    public const URL_PATTERN = '#^(https?://[^\s<>"\'\\\\\x00-\x1f\x7f]+|mailto:[^\s<>"\'\\\\\x00-\x1f\x7f]+|tel:\+?[0-9][0-9 ().-]*|/(?![/\\\\])[^\s<>"\'\\\\\x00-\x1f\x7f]*|\#[\w-]*)$#iu';
    public const URL_MAX_LENGTH = 2000;
    private const ANCHOR_FORM = '#\G<a\s+href\s*=\s*(?<href>"[^"<>]*"|\'[^\'<>]*\')\s*>#i';

    private const STYLE_VALUES = [
        'font-style' => '/^(normal|italic|oblique)$/i',
        'font-weight' => '/^(normal|bold|bolder|lighter|[1-9]00)$/i',
        'text-decoration' => '/^(none|underline|line-through|overline)(\s+(underline|line-through|overline))*$/i',
        'color' => '/^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.\s,%]+\)|[a-z]{3,20})$/i',
    ];
    private const TAG_START = '#<(?=[a-zA-Z/!?])#';
    private const ALLOWED_FORM = '#\G<(?<closing>/?)(?<name>[a-zA-Z][a-zA-Z0-9]*)(?:\s+style\s*=\s*(?<style>"[^"<>]*"|\'[^\'<>]*\'))?\s*/?>#';

    /**
     * @return list<string> problèmes trouvés (vide : texte accepté)
     */
    public static function problems(string $text): array
    {
        $problems = [];
        $offset = 0;
        while (preg_match(self::TAG_START, $text, $start, PREG_OFFSET_CAPTURE, $offset)) {
            $position = $start[0][1];
            if (preg_match(self::ANCHOR_FORM, $text, $anchor, 0, $position)) {
                $offset = $position + strlen($anchor[0]);
                if (($problem = self::urlProblem(html_entity_decode(substr($anchor['href'], 1, -1), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== null) {
                    $problems[] = '<a> : ' . $problem;
                }
                continue;
            }
            if (!preg_match(self::ALLOWED_FORM, $text, $tag, 0, $position)) {
                $problems[] = self::describe(substr($text, $position, 300));
                $offset = $position + 1;
                continue;
            }
            $offset = $position + strlen($tag[0]);
            $name = strtolower($tag['name']);
            $style = $tag['style'] ?? '';
            if ($name === 'a' && $tag['closing'] === '') {
                $problems[] = '<a> : href obligatoire et seul attribut permis (<a href="…">)';
            } elseif (!in_array($name, self::TAGS, true) && $name !== 'a') {
                $problems[] = self::notAllowed($name);
            } elseif ($style !== '' && $tag['closing'] !== '') {
                $problems[] = sprintf('</%s> : une balise fermante ne porte pas d\'attribut', $name);
            } elseif ($style !== '') {
                array_push($problems, ...self::styleProblems($name, substr($style, 1, -1)));
            }
        }

        return array_values(array_unique($problems));
    }

    /** Message pour une séquence qui n'a aucune forme permise */
    private static function describe(string $fragment): string
    {
        if (!preg_match('#^<\s*/?\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)#', $fragment, $m)) {
            return 'commentaire, déclaration ou balise illisible non autorisé (« < » suivi de « ! », « ? » ou « / »)';
        }
        $name = strtolower($m[1]);
        if ($name === 'a') {
            return '<a> : seul l\'attribut href est permis, entre guillemets (<a href="https://…">) ; target et rel sont posés à l\'affichage';
        }
        if (!in_array($name, self::TAGS, true)) {
            return self::notAllowed($name);
        }
        preg_match_all('#[\s/]([^\s=/>"\'<]+)\s*(?==|[\s/>]|$)#', $m[2], $attributes);
        foreach ($attributes[1] as $attribute) {
            if (strtolower($attribute) !== 'style') {
                return sprintf('<%s> : attribut « %s » non autorisé (seul style est permis)', $name, strtolower($attribute));
            }
        }

        return sprintf('<%s> : balise mal formée ou non fermée (formes permises : <%s>, <%s style="…">, </%s>)', $name, $name, $name, $name);
    }

    private static function notAllowed(string $name): string
    {
        return sprintf('balise <%s> non autorisée (autorisées : %s, a)', $name, implode(', ', self::TAGS));
    }

    /** Problème d'une adresse de lien ou de bouton (déjà décodée), ou null si elle est permise */
    public static function urlProblem(string $url): ?string
    {
        if ($url === '' || mb_strlen($url) > self::URL_MAX_LENGTH || preg_match(self::URL_PATTERN, $url) !== 1) {
            return 'adresse non autorisée (https://…, http://…, mailto:…, tel:…, /chemin ou #ancre, sans espace)';
        }

        return null;
    }

    /** @return list<string> */
    private static function styleProblems(string $tag, string $style): array
    {
        $problems = [];
        foreach (array_filter(array_map('trim', explode(';', html_entity_decode($style, ENT_QUOTES | ENT_HTML5)))) as $declaration) {
            [$property, $value] = array_map('trim', explode(':', $declaration, 2) + [1 => '']);
            $property = strtolower($property);
            if (!in_array($property, self::STYLE_PROPERTIES, true)) {
                $problems[] = sprintf('<%s> : style « %s » non autorisé (permis : %s)', $tag, $property, implode(', ', self::STYLE_PROPERTIES));
            } elseif (!preg_match(self::STYLE_VALUES[$property], $value)) {
                $problems[] = sprintf('<%s> : valeur de %s non autorisée', $tag, $property);
            }
        }

        return $problems;
    }
}
