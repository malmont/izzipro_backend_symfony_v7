<?php

namespace App\MemoiresVivantes\Services;

/**
 * Prépare le texte d'un chapitre pour la mise en page du livre (PDF).
 *
 * L'IA rend un texte brut : paragraphes séparés par une ligne vide, sous-titres sous la forme ===Titre===, parfois du
 * balisage Markdown (**gras**, ## titre) malgré les consignes. Imprimé tel quel, ce balisage apparaissait dans le
 * livre. Le texte est découpé en blocs typés (sous-titre, paragraphe), sans aucun HTML : le gabarit reste seul
 * responsable de l'échappement.
 */
class ChapterTextFormatter
{
    /**
     * @return array<int, array{type: 'heading'|'paragraph', text: string, dropCap: string, first: bool}>
     *         dropCap : lettrine à détacher (premier paragraphe seulement, vide sinon) ; first : paragraphe sans
     *         alinéa (début de chapitre ou suite d'un sous-titre)
     */
    public function blocks(?string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", (string) $content);
        $blocks = [];
        $paragraph = [];
        $flush = function () use (&$blocks, &$paragraph): void {
            $text = trim(implode("\n", $paragraph));
            $paragraph = [];
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'text' => $text];
            }
        };

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                $flush();
                continue;
            }
            // Filets Markdown (---, ***, ___) : séparateurs sans contenu
            if (preg_match('/^([-*_])\1{2,}$/', $line)) {
                $flush();
                continue;
            }
            $heading = $this->headingOf($line);
            if ($heading !== null) {
                $flush();
                if ($heading !== '') {
                    $blocks[] = ['type' => 'heading', 'text' => $heading];
                }
                continue;
            }
            $paragraph[] = $this->inline($line);
        }
        $flush();

        $firstParagraphSeen = false;
        $afterHeading = true;
        foreach ($blocks as &$block) {
            $block += ['dropCap' => '', 'first' => false];
            if ($block['type'] === 'heading') {
                $afterHeading = true;
                continue;
            }
            $block['first'] = $afterHeading;
            $afterHeading = false;
            if (!$firstParagraphSeen) {
                $firstParagraphSeen = true;
                // Lettrine seulement sur une lettre : jamais sur un guillemet, un tiret de dialogue ou un chiffre
                if (preg_match('/^\p{Lu}/u', $block['text']) && mb_strlen($block['text']) > 40) {
                    $block['dropCap'] = mb_substr($block['text'], 0, 1);
                    $block['text'] = mb_substr($block['text'], 1);
                }
            }
        }
        unset($block);

        return $blocks;
    }

    /** Le chapitre a-t-il un texte à imprimer ? */
    public function hasText(?string $content): bool
    {
        foreach ($this->blocks($content) as $block) {
            if ($block['type'] === 'paragraph') {
                return true;
            }
        }

        return false;
    }

    /**
     * Titre de chapitre pour le livre : sans emoji (absents des polices du PDF) ni préfixe « Chapitre 3 — », que la
     * mise en page ajoute elle-même.
     */
    public function chapterTitle(?string $title): string
    {
        $title = $this->plain($title);
        $stripped = preg_replace('/^chapitre\s+\d+\s*[—–\-:.]\s*/iu', '', $title);

        return trim((string) $stripped) !== '' ? trim((string) $stripped) : $title;
    }

    /** Texte d'une ligne (titre du livre, auteur…) : entités décodées, emojis et balisage retirés */
    public function plain(?string $text): string
    {
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->inline($text);
    }

    /** Sous-titre porté par la ligne : ===Titre===, ## Titre ou **Titre** seul sur sa ligne ; null sinon */
    private function headingOf(string $line): ?string
    {
        if (preg_match('/^={2,}\s*(.*?)\s*={2,}$/u', $line, $m)
            || preg_match('/^#{1,6}\s+(.*?)\s*#*$/u', $line, $m)
            || preg_match('/^\*\*([^*]+)\*\*\s*:?$/u', $line, $m)
            || preg_match('/^__([^_]+)__\s*:?$/u', $line, $m)) {
            return $this->inline($m[1]);
        }

        return null;
    }

    /** Retire le balisage Markdown et les caractères que les polices du PDF n'ont pas */
    private function inline(string $text): string
    {
        // Gras et italique : on garde le texte, pas les marques
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text);
        $text = preg_replace('/__(.+?)__/us', '$1', (string) $text);
        $text = preg_replace('/(?<![\p{L}\p{N}*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\p{L}\p{N}*])/u', '$1', (string) $text);
        $text = preg_replace('/(?<![\p{L}\p{N}_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\p{L}\p{N}_])/u', '$1', (string) $text);
        // Marque de gras restée seule (jamais refermée)
        $text = str_replace('**', '', (string) $text);
        // Marques de sous-titre restées dans une phrase
        $text = preg_replace('/={3,}/', '', (string) $text);
        // Titres Markdown en début de ligne
        $text = preg_replace('/^#{1,6}\s+/u', '', (string) $text);
        // Emojis, pictogrammes, sélecteurs de variante : les polices DejaVu les rendent par des carrés
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', (string) $text);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', (string) $text));
    }
}
