<?php

namespace App\MemoiresVivantes\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;

/**
 * Polices proposées pour la mise en page d'un livre (PDF intérieur et couverture).
 *
 * Le livre porte un code de ce catalogue (mv_book.font) ; un code inconnu ou absent donne la police par défaut.
 * Les fichiers sont dans resources/fonts/memoires (licence OFL, voir son README) : ils sont enregistrés une fois
 * auprès de Dompdf, qui les incorpore au PDF (exigé par l'imprimeur).
 */
class BookFontCatalog
{
    public const DEFAULT = 'dejavu_serif';

    /** Police de secours, fournie avec Dompdf : elle a tous les caractères (ornements compris) */
    private const FALLBACK_FAMILY = 'DejaVu Serif';

    /**
     * code => [label, family (nom CSS), description, category, files (préfixe des fichiers, null = fournie par Dompdf)]
     * bodyPt : corps du texte courant, ajusté à l'œil de chaque police (une Garamond paraît plus petite à corps égal)
     * titleFamily / titleFiles (facultatifs) : police des titres quand elle diffère de celle du texte (catégorie « script »)
     */
    public const FONTS = [
        'dejavu_serif' => ['label' => 'Classique', 'family' => 'DejaVu Serif', 'description' => 'Police d\'origine des livres : large et très lisible.', 'category' => 'serif', 'files' => null, 'bodyPt' => 11],
        'eb_garamond' => ['label' => 'Garamond', 'family' => 'EB Garamond', 'description' => 'Élégante et littéraire, dans la tradition de l\'édition française.', 'category' => 'serif', 'files' => 'EBGaramond', 'bodyPt' => 12.5],
        'lora' => ['label' => 'Lora', 'family' => 'Lora', 'description' => 'Contemporaine et chaleureuse, aux courbes douces.', 'category' => 'serif', 'files' => 'Lora', 'bodyPt' => 11.5],
        'merriweather' => ['label' => 'Merriweather', 'family' => 'Merriweather', 'description' => 'Robuste et confortable pour les longues lectures.', 'category' => 'serif', 'files' => 'Merriweather', 'bodyPt' => 10.5],
        'playfair_display' => ['label' => 'Playfair', 'family' => 'Playfair Display', 'description' => 'Raffinée, aux contrastes marqués : des titres de caractère.', 'category' => 'serif', 'files' => 'PlayfairDisplay', 'bodyPt' => 11],
        'lato' => ['label' => 'Lato', 'family' => 'Lato', 'description' => 'Sans empattement, sobre et moderne.', 'category' => 'sans-serif', 'files' => 'Lato', 'bodyPt' => 11],
        // Écriture manuscrite : réservée aux titres (un livre entier en cursive serait illisible), texte courant en Lora
        'dancing_script' => ['label' => 'Manuscrite', 'family' => 'Lora', 'description' => 'Titres en écriture manuscrite, texte courant en Lora.', 'category' => 'script', 'files' => 'Lora', 'bodyPt' => 11.5, 'titleFamily' => 'Dancing Script', 'titleFiles' => 'DancingScript'],
    ];

    /** Fichier de chaque variante : [suffixe, graisse CSS, style CSS] */
    private const VARIANTS = [
        ['Regular', 'normal', 'normal'],
        ['Italic', 'normal', 'italic'],
        ['Bold', 'bold', 'normal'],
        ['BoldItalic', 'bold', 'italic'],
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /** Code valide du catalogue : celui demandé s'il existe, sinon la police par défaut */
    public static function resolve(?string $code): string
    {
        return $code !== null && isset(self::FONTS[$code]) ? $code : self::DEFAULT;
    }

    public static function has(?string $code): bool
    {
        return $code !== null && isset(self::FONTS[$code]);
    }

    /** Nom de famille CSS de la police */
    public static function family(?string $code): string
    {
        return self::FONTS[self::resolve($code)]['family'];
    }

    /** Police des titres (couverture, pages de titre, titres de chapitre, sous-titres) : celle du texte, sauf catégorie « script » */
    public static function titleFamily(?string $code): string
    {
        $font = self::FONTS[self::resolve($code)];

        return $font['titleFamily'] ?? $font['family'];
    }

    /** Titres en écriture manuscrite : ni capitales ni interlettrage, et un corps plus grand (voir les gabarits) */
    public static function hasScriptTitles(?string $code): bool
    {
        return self::FONTS[self::resolve($code)]['category'] === 'script';
    }

    /** Valeur CSS « font-family » des titres */
    public static function titleCssStack(?string $code): string
    {
        $families = array_unique([self::titleFamily($code), self::family($code), self::FALLBACK_FAMILY]);

        return implode(', ', array_map(fn (string $family) => "'" . $family . "'", $families)) . ', serif';
    }

    /** Corps du texte courant du livre, en points */
    public static function bodySize(?string $code): float
    {
        return (float) self::FONTS[self::resolve($code)]['bodyPt'];
    }

    /** Valeur CSS « font-family » : la police choisie, puis la police de secours. Constantes du catalogue seulement. */
    public static function cssStack(?string $code): string
    {
        $font = self::FONTS[self::resolve($code)];
        $families = array_unique([$font['family'], self::FALLBACK_FAMILY]);

        return implode(', ', array_map(fn (string $family) => "'" . $family . "'", $families)) . ', ' . ($font['category'] === 'sans-serif' ? 'sans-serif' : 'serif');
    }

    /**
     * Liste pour le frontend.
     *
     * @return array<int, array{code: string, label: string, family: string, titleFamily: string, description: string, category: string, isDefault: bool}>
     */
    public static function all(): array
    {
        $list = [];
        foreach (self::FONTS as $code => $font) {
            $list[] = [
                'code' => $code,
                'label' => $font['label'],
                'family' => $font['family'],
                'titleFamily' => $font['titleFamily'] ?? $font['family'],
                'description' => $font['description'],
                'category' => $font['category'],
                'isDefault' => $code === self::DEFAULT,
            ];
        }

        return $list;
    }

    /**
     * Options Dompdf communes aux PDF des livres : aucune ressource distante, et un dossier de polices inscriptible
     * (celui de Dompdf, dans vendor/, ne l'est pas) où sont rangées les polices du catalogue et leurs métriques.
     */
    public function options(?string $code = null): Options
    {
        $fontDir = $this->projectDir . '/var/dompdf-fonts';
        if (!is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }

        $options = new Options();
        // Aucune ressource distante : le texte des chapitres vient des utilisateurs (pas de requête serveur vers une URL injectée)
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', self::family($code));
        $options->set('chroot', $this->projectDir);
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        return $options;
    }

    /** Dompdf prêt à l'emploi, polices du catalogue enregistrées */
    public function newDompdf(?string $code = null): Dompdf
    {
        $dompdf = new Dompdf($this->options($code));
        $this->register($dompdf);

        return $dompdf;
    }

    /**
     * Enregistre auprès de Dompdf les polices du catalogue qui ne le sont pas encore (fait une fois : Dompdf les
     * mémorise dans son dossier de polices). Une police qui ne s'enregistre pas est journalisée ; le livre sort alors
     * dans la police de secours plutôt que d'échouer.
     */
    public function register(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();
        $known = $metrics->getFontFamilies();

        // Familles à enregistrer : police du texte et, pour les polices de titres, la police manuscrite
        $families = [];
        foreach (self::FONTS as $font) {
            if ($font['files'] !== null) {
                $families[$font['family']] = $font['files'];
            }
            if (isset($font['titleFamily'], $font['titleFiles'])) {
                $families[$font['titleFamily']] = $font['titleFiles'];
            }
        }

        foreach ($families as $family => $prefix) {
            $entry = $known[mb_strtolower($family)] ?? [];
            $directory = $this->projectDir . '/resources/fonts/memoires/' . $prefix . '-';
            foreach (self::VARIANTS as [$suffix, $weight, $style]) {
                $key = ($weight === 'bold' ? 'bold' : '') . ($weight === 'bold' && $style === 'italic' ? '_' : '') . ($style === 'italic' ? 'italic' : '') ?: 'normal';
                if (isset($entry[$key]) && is_file($entry[$key] . '.ufm')) {
                    continue;
                }
                // Une police manuscrite n'a pas d'italique : le romain en tient lieu, plutôt qu'un repli sur une autre police
                $file = $directory . $suffix . '.ttf';
                if (!is_file($file)) {
                    $file = $directory . ($weight === 'bold' ? 'Bold' : 'Regular') . '.ttf';
                }
                try {
                    if (!is_file($file) || !$metrics->registerFont(['family' => $family, 'weight' => $weight, 'style' => $style], $file)) {
                        $this->logger?->error("BookFontCatalog : police non enregistrée : $family $suffix");
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error("BookFontCatalog : police non enregistrée : $family $suffix : " . $e->getMessage());
                }
            }
        }
    }
}
