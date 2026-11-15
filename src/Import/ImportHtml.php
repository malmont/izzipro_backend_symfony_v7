<?php

namespace App\Import;

/**
 * Lot IMP-1 : nettoie le HTML d'une description venue d'un fichier d'import. Liste de balises permises, AUCUN attribut gardé (ni
 * lien, ni style, ni gestionnaire d'événement), contenu des balises `script` et `style` retiré. Sans dépendance : le paquet
 * `symfony/html-sanitizer` n'est pas installé sur ce projet.
 */
final class ImportHtml
{
    public const MAX_CHARS = 20000;
    private const ALLOWED = '<p><br><strong><b><em><i><u><ul><ol><li><blockquote><h2><h3><h4>';

    public static function clean(?string $html): ?string
    {
        $html = (string) $html;
        // le contenu de ces balises n'est pas du texte à garder
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED);
        // aucune balise gardée ne garde d'attribut : <p onclick="…"> devient <p>
        $html = preg_replace('#<(/?)([a-zA-Z0-9]+)\b[^>]*?(/?)>#', '<$1$2$3>', $html) ?? '';
        $html = trim($html);
        if (mb_strlen($html) > self::MAX_CHARS) {
            $html = preg_replace('#<[^>]*$#', '', mb_substr($html, 0, self::MAX_CHARS)) ?? ''; // jamais une balise coupée en deux
        }

        return $html === '' ? null : $html;
    }
}
