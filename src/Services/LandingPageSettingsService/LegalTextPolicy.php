<?php

namespace App\Services\LandingPageSettingsService;

/**
 * HTML autorisé dans les textes longs de la fiche entreprise (mentions légales, conditions d'utilisation, politique de
 * confidentialité, à propos), modifiables depuis l'éditeur des landing pages et affichés à tous les visiteurs.
 *
 * Même grammaire stricte que RichTextPolicy (tout ce qui ressemble à une balise doit avoir exactement une forme
 * permise, sinon le texte est refusé), avec une liste plus large pour des documents (titres, tableaux) et AUCUN
 * attribut, sauf : href sur <a> (même règle d'adresse que RichTextPolicy), colspan et rowspan (nombres) sur <td> et
 * <th>. Ni style, ni class, ni id, ni gestionnaire d'évènement.
 */
final class LegalTextPolicy
{
    public const TAGS = ['p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup',
        'span', 'div', 'hr', 'blockquote', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'a'];
    public const FIELDS = ['LegalNotice', 'conditionOfUse', 'privacyPolicy', 'apropos'];
    public const MAX_LENGTH = 200000;

    private const TAG_START = '#<(?=[a-zA-Z/!?])#';
    private const CLOSING = '#\G</(?<name>[a-zA-Z][a-zA-Z0-9]*)\s*>#';
    private const OPENING = '#\G<(?<name>[a-zA-Z][a-zA-Z0-9]*)(?<attributes>(?:\s+[a-zA-Z-]+\s*=\s*(?:"[^"<>]*"|\'[^\'<>]*\'))*)\s*/?>#';
    private const ATTRIBUTE = '#\s+(?<name>[a-zA-Z-]+)\s*=\s*(?<value>"[^"<>]*"|\'[^\'<>]*\')#';

    /** @return list<string> problèmes trouvés (vide : texte accepté) */
    public static function problems(string $text): array
    {
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return [sprintf('%d caractères au plus', self::MAX_LENGTH)];
        }
        $problems = [];
        $offset = 0;
        while (preg_match(self::TAG_START, $text, $start, PREG_OFFSET_CAPTURE, $offset)) {
            $position = $start[0][1];
            if (preg_match(self::CLOSING, $text, $tag, 0, $position)) {
                $offset = $position + strlen($tag[0]);
                if (!in_array(strtolower($tag['name']), self::TAGS, true)) {
                    $problems[] = self::notAllowed(strtolower($tag['name']));
                }
                continue;
            }
            if (!preg_match(self::OPENING, $text, $tag, 0, $position)) {
                $problems[] = 'balise mal formée, non fermée, attribut sans guillemets, commentaire ou déclaration : « ' . mb_substr(substr($text, $position, 60), 0, 40) . ' »';
                $offset = $position + 1;
                continue;
            }
            $offset = $position + strlen($tag[0]);
            $name = strtolower($tag['name']);
            if (!in_array($name, self::TAGS, true)) {
                $problems[] = self::notAllowed($name);
                continue;
            }
            preg_match_all(self::ATTRIBUTE, $tag['attributes'], $attributes, PREG_SET_ORDER);
            $seen = [];
            foreach ($attributes as $attribute) {
                $attr = strtolower($attribute['name']);
                $value = html_entity_decode(substr($attribute['value'], 1, -1), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $seen[] = $attr;
                if ($name === 'a' && $attr === 'href') {
                    if (($problem = RichTextPolicy::urlProblem($value)) !== null) {
                        $problems[] = '<a> : ' . $problem;
                    }
                } elseif (in_array($name, ['td', 'th'], true) && in_array($attr, ['colspan', 'rowspan'], true)) {
                    if (!preg_match('/^[1-9][0-9]?$/', $value)) {
                        $problems[] = sprintf('<%s> : %s attend un nombre de 1 à 99', $name, $attr);
                    }
                } else {
                    $problems[] = sprintf('<%s> : attribut « %s » non autorisé%s', $name, $attr, $name === 'a' ? ' (href seul)' : (in_array($name, ['td', 'th'], true) ? ' (colspan, rowspan seuls)' : ' (aucun attribut)'));
                }
            }
            if ($name === 'a' && !in_array('href', $seen, true)) {
                $problems[] = '<a> : href obligatoire';
            }
        }

        return array_values(array_unique($problems));
    }

    private static function notAllowed(string $name): string
    {
        return sprintf('balise <%s> non autorisée (autorisées : %s)', $name, implode(', ', self::TAGS));
    }
}
