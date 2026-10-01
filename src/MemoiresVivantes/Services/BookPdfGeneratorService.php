<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\ChapterPhoto;
use App\Services\TenantEntityManagerProvider;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class BookPdfGeneratorService
{
    private const BLEED_PT = 9.0; // 3.175 mm = 0.125 pouce
    private const WRAP_PT = 54.0; // 19.05 mm = 0.75 pouce (rembordage couverture rigide)
    private const A4_WIDTH_PT = 595.28;  // 210 mm
    private const A4_HEIGHT_PT = 841.89; // 297 mm
    private const MM_PT = 2.8346;
    /** Corps en dessous duquel un titre n'est plus lisible : atteint seulement pour un mot démesuré */
    private const MIN_TITLE_PT = 7;
    private const SCRIPT_TITLE_SCALE = 1.25;

    private ?\Dompdf\FontMetrics $fontMetrics = null;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly Environment $twig,
        private readonly string $projectDir,
        private readonly ChapterTextFormatter $formatter = new ChapterTextFormatter(),
        private ?BookFontCatalog $fonts = null
    ) {
        $this->fonts ??= new BookFontCatalog($projectDir);
    }

    /** Police du rendu : celle demandée si elle est au catalogue (aperçu avant enregistrement), sinon celle du livre */
    public function fontOf(Book $book, ?string $requested = null): string
    {
        return BookFontCatalog::has($requested) ? $requested : BookFontCatalog::resolve($book->getFont());
    }

    /**
     * Calcule la largeur de la tranche (spine) en mm et en points selon le nombre de pages.
     */
    public function calculateSpineWidthPt(int $pageCount): float
    {
        // Formule standard reliure rigide casewrap (Lulu) : page_count * 0.057 mm + 1.5 mm
        $spineMm = ($pageCount * 0.057) + 1.5;
        // Minimum de sécurité pour livre rigide : 6.5 mm
        $spineMm = max(6.5, $spineMm);
        return ($spineMm * 72) / 25.4;
    }

    /**
     * Calcule les dimensions de la couverture dépliée en points.
     *
     * @return array{
     *   cover_width_pt: float,
     *   cover_height_pt: float,
     *   back_cover_width_pt: float,
     *   front_cover_width_pt: float,
     *   spine_width_pt: float
     * }
     */
    public function calculateCoverDimensions(int $pageCount): array
    {
        $spinePt = $this->calculateSpineWidthPt($pageCount);
        $sideWidthPt = self::A4_WIDTH_PT + self::WRAP_PT + self::BLEED_PT;
        $coverWidthPt = ($sideWidthPt * 2) + $spinePt;
        $coverHeightPt = self::A4_HEIGHT_PT + (2 * self::WRAP_PT) + (2 * self::BLEED_PT);

        return [
            'cover_width_pt' => round($coverWidthPt, 2),
            'cover_height_pt' => round($coverHeightPt, 2),
            'back_cover_width_pt' => round($sideWidthPt, 2),
            'front_cover_width_pt' => round($sideWidthPt, 2),
            'spine_width_pt' => round($spinePt, 2),
        ];
    }

    /**
     * Géométrie de la couverture dépliée, en points PDF (1 pt = 1/72 pouce), pour découper la 4e, la tranche et la
     * 1re de couverture sans approximation. Origine en haut à gauche de la page. Un seul gabarit aujourd'hui, quel
     * que soit le format du livre : A4 relié, couverture rigide rembordée.
     *
     * « panels » : les trois zones imprimées, fond perdu et rembordage compris. « visible » : ce qui reste visible une
     * fois le livre relié (un plat de 210 × 297 mm) : c'est la zone à montrer dans un aperçu.
     */
    public function coverGeometry(int $pageCount): array
    {
        $d = $this->calculateCoverDimensions($pageCount);
        $edge = self::WRAP_PT + self::BLEED_PT;
        $frontX = round($d['back_cover_width_pt'] + $d['spine_width_pt'], 2);
        $visibleWidth = round($d['front_cover_width_pt'] - $edge, 2);
        $visibleHeight = round($d['cover_height_pt'] - 2 * $edge, 2);

        return [
            'unit' => 'pt',
            'page_count' => $pageCount,
            'width' => $d['cover_width_pt'],
            'height' => $d['cover_height_pt'],
            'bleed' => self::BLEED_PT,
            'wrap' => self::WRAP_PT,
            'edge' => $edge,
            'panels' => [
                'back' => ['x' => 0.0, 'width' => $d['back_cover_width_pt']],
                'spine' => ['x' => $d['back_cover_width_pt'], 'width' => $d['spine_width_pt']],
                'front' => ['x' => $frontX, 'width' => $d['front_cover_width_pt']],
            ],
            'visible' => [
                'back' => ['x' => $edge, 'y' => $edge, 'width' => $visibleWidth, 'height' => $visibleHeight],
                'front' => ['x' => $frontX, 'y' => $edge, 'width' => $visibleWidth, 'height' => $visibleHeight],
            ],
        ];
    }

    /**
     * Nettoie le texte en décodant les entités HTML (ex: &#39; -> ', &amp; -> &).
     */
    public function cleanText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Détermine le nom de l'auteur à afficher (en évitant le nom Admin User).
     */
    /**
     * Nom d'auteur reçu d'une requête : un texte, ou l'objet « author » du livre que le frontend renvoie tel quel
     * ({firstName, lastName, fullName}). Le libellé par défaut du champ (« Auteur ») n'est pas un nom.
     */
    public static function normalizeAuthorName(mixed $author): ?string
    {
        if (is_array($author)) {
            $author = $author['fullName'] ?? $author['full_name'] ?? $author['name']
                ?? trim(((string) ($author['firstName'] ?? '')) . ' ' . ((string) ($author['lastName'] ?? '')));
        }
        if (!is_string($author)) {
            return null;
        }
        $author = trim($author);

        return $author === '' || in_array(mb_strtolower($author), ['auteur', 'author', 'admin user'], true) ? null : $author;
    }

    public function resolveAuthorName(Book $book, ?string $customAuthorName = null): string
    {
        $authorName = self::normalizeAuthorName($customAuthorName);
        if (empty($authorName)) {
            if ($book->getPerson1FirstName()) {
                $authorName = $book->getPerson1FirstName();
                if ($book->getPerson2FirstName()) {
                    $authorName .= ' & ' . $book->getPerson2FirstName();
                }
            } elseif ($book->getUser()) {
                $name = trim($book->getUser()->getFirstname() . ' ' . $book->getUser()->getLastname());
                $authorName = ($name !== 'Admin User' && !empty($name)) ? $name : 'Danielle Almont';
            } else {
                $authorName = 'Danielle Almont';
            }
        }
        return $this->formatter->plain($authorName);
    }

    /**
     * Structure les photos d'un chapitre en pages selon le gabarit choisi ou calculé automatiquement.
     *
     * @return array<int, array{layout: string, photos: array<int, mixed>}>
     */
    public function resolveChapterPhotoPages(Chapter $chapter): array
    {
        $allPhotos = [];
        foreach ($chapter->getPhotos() as $p) {
            if ($p->getFilePath()) {
                $allPhotos[$p->getId()->toRfc4122()] = $p;
            }
        }

        if (empty($allPhotos)) {
            return [];
        }

        $layoutConfig = $chapter->getPhotoLayout();
        $pages = [];

        // 1. Si une mise en page personnalisée a été configurée par le frontend
        if (!empty($layoutConfig) && is_array($layoutConfig)) {
            $photoPages = $layoutConfig['photo_pages'] ?? $layoutConfig['pages'] ?? $layoutConfig;
            if (is_array($photoPages)) {
                foreach ($photoPages as $pageData) {
                    $layout = $pageData['layout'] ?? 'grid_4';
                    $photoIds = $pageData['photo_ids'] ?? [];
                    $pagePhotos = [];

                    foreach ($photoIds as $pid) {
                        $pidStr = (string)$pid;
                        if (isset($allPhotos[$pidStr])) {
                            $pagePhotos[] = $allPhotos[$pidStr];
                        } else {
                            foreach ($allPhotos as $key => $ph) {
                                if ($key === $pidStr || str_contains($ph->getFilePath(), $pidStr)) {
                                    $pagePhotos[] = $ph;
                                    break;
                                }
                            }
                        }
                    }

                    if (!empty($pagePhotos)) {
                        $pages[] = [
                            'layout' => $layout,
                            'photos' => $pagePhotos,
                        ];
                    }
                }
            }
        }

        // 2. Si aucune mise en page n'est configurée, regroupement automatique de prestige
        if (empty($pages)) {
            $photosList = array_values($allPhotos);
            $count = count($photosList);

            if ($count === 1) {
                $pages[] = [
                    'layout' => 'single',
                    'photos' => [$photosList[0]],
                ];
            } elseif ($count === 2) {
                $isPortrait = ($photosList[0]->getOrientation() === 'portrait' && $photosList[1]->getOrientation() === 'portrait');
                $pages[] = [
                    'layout' => $isPortrait ? 'duo_v' : 'duo_h',
                    'photos' => $photosList,
                ];
            } elseif ($count <= 4) {
                $pages[] = [
                    'layout' => 'grid_4',
                    'photos' => $photosList,
                ];
            } else {
                $chunks = array_chunk($photosList, 4);
                foreach ($chunks as $chunk) {
                    $chunkCount = count($chunk);
                    $layout = $chunkCount === 1 ? 'single' : ($chunkCount === 2 ? 'duo_v' : 'grid_4');
                    $pages[] = [
                        'layout' => $layout,
                        'photos' => $chunk,
                    ];
                }
            }
        }

        return $pages;
    }

    /**
     * Rendu HTML de l'intérieur.
     */
    public function renderInteriorHtml(Book $book, ?string $customAuthorName = null, ?string $font = null): string
    {
        $font = $this->fontOf($book, $font);
        $chaptersData = [];
        foreach ($book->getChapters() as $chapter) {
            $blocks = $this->formatter->blocks($this->cleanText($chapter->getContentFinal() ?: ($chapter->getContentGenerated() ?: '')));
            $photoPages = $this->resolveChapterPhotoPages($chapter);
            // Chapitre pas encore rédigé et sans photo : ni page vide dans le livre, ni ligne au sommaire
            if ($blocks === [] && $photoPages === []) {
                continue;
            }

            $chaptersData[] = [
                'chapter' => $chapter,
                'title' => $this->formatter->chapterTitle($chapter->getTitle()),
                'blocks' => $blocks,
                'photo_pages' => $photoPages,
            ];
        }

        return $this->twig->render('pdf/memoires/interior.html.twig', [
            'book' => $book,
            'clean_title' => $this->formatter->plain($book->getTitle()),
            'clean_subtitle' => $this->formatter->plain($book->getSubtitle()),
            'author_name' => $this->resolveAuthorName($book, $customAuthorName),
            // Largeur utile d'une page : 216,41 mm moins deux marges de 24 mm. Faux-titre en capitales (interlettrage 3 px)
            'half_title_pt' => $this->fitFontSize($this->formatter->plain($book->getTitle()), (216.41 - 48) * self::MM_PT, 20, true, 2.25, $font),
            'main_title_pt' => $this->fitFontSize($this->formatter->plain($book->getTitle()), (216.41 - 48) * self::MM_PT, 32, false, 1.5, $font),
            // Valeur CSS issue du catalogue (constantes), jamais d'une saisie
            'font_css' => BookFontCatalog::cssStack($font),
            'body_pt' => BookFontCatalog::bodySize($font),
            'title_font_css' => BookFontCatalog::titleCssStack($font),
            'script_titles' => BookFontCatalog::hasScriptTitles($font),
            'chapters_data' => $chaptersData,
            'project_dir' => $this->projectDir,
            // Répertoire partagé où ChapterService enregistre les photos (l'ancien public/uploads n'existe plus)
            'photos_dir' => $this->projectDir . '/var/storage/public_bucket/uploads/memoires',
        ]);
    }

    /**
     * Rendu HTML de la couverture dépliée.
     */
    public function renderCoverHtml(Book $book, int $pageCount = 64, string $coverStyle = 'biographic_split', ?string $customAuthorName = null, ?string $bgColor = null, ?string $font = null): string
    {
        $font = $this->fontOf($book, $font);
        $dimensions = $this->calculateCoverDimensions($pageCount);
        $coverImagePath = null;
        $coverImageExists = false;
        if ($book->getCoverPhotoPath()) {
            $coverImagePath = $this->projectDir . '/var/storage/public_bucket/uploads/memoires/' . $book->getCoverPhotoPath();
            $coverImageExists = file_exists($coverImagePath);
        }

        $authorName = $this->resolveAuthorName($book, $customAuthorName);
        $cleanTitle = $this->formatter->plain($book->getTitle());
        $cleanSubtitle = $this->formatter->plain($book->getSubtitle());
        $bgColor = self::normalizeColor($bgColor);

        // Zone repliée autour du carton (rembordage + fond perdu) : hors de la couverture visible une fois le livre relié
        $edge = self::WRAP_PT + self::BLEED_PT;
        $visibleWidth = $dimensions['front_cover_width_pt'] - $edge;

        return $this->twig->render('pdf/memoires/cover.html.twig', array_merge($dimensions, [
            'book' => $book,
            'clean_title' => $cleanTitle,
            'clean_subtitle' => $cleanSubtitle,
            'cover_image_path' => $coverImagePath,
            'cover_image_exists' => $coverImageExists,
            'cover_style' => $coverStyle,
            'author_name' => $authorName,
            'bg_color' => $bgColor,
            'bg_is_dark' => $bgColor !== null && self::isDarkColor($bgColor),
            'classic_bg' => $bgColor ?? '#1b2838',
            'classic_is_dark' => self::isDarkColor($bgColor ?? '#1b2838'),
            'edge_pt' => $edge,
            'visible_width_pt' => round($visibleWidth, 2),
            'visible_height_pt' => round($dimensions['cover_height_pt'] - 2 * $edge, 2),
            // Corps du titre ajusté au mot le plus long, mesuré avec la police : un mot n'est jamais coupé et un titre
            // ne déborde pas de sa colonne. Largeurs : celles des blocs de titre de cover.html.twig (mm = 2,8346 pt).
            'title_pt' => [
                'biographic' => $this->fitFontSize($cleanTitle, $visibleWidth * 0.48 - 28 * self::MM_PT, 40, false, 0.0, $font),
                'full' => $this->fitFontSize($cleanTitle, $visibleWidth - 50 * self::MM_PT, 44, false, 0.0, $font),
                'gallery' => $this->fitFontSize($cleanTitle, $visibleWidth - 50 * self::MM_PT, 36, false, 0.75, $font),
                'banner' => $this->fitFontSize($cleanTitle, $visibleWidth - 50 * self::MM_PT, 38, false, 0.0, $font),
                'classic' => $this->fitFontSize($cleanTitle, $visibleWidth - 70 * self::MM_PT, 40, true, 1.5, $font),
            ],
            // Tranche : titre et auteur sur une seule ligne (jamais coupée), réduite si elle dépasse la hauteur visible
            'spine_pt' => $this->fitLine(
                mb_strtoupper($cleanTitle . ($authorName !== '' ? ' • ' . $authorName : '')),
                $dimensions['cover_height_pt'] - 2 * $edge - 30 * self::MM_PT,
                10.0,
                2.25,
                $font
            ),
            'font_css' => BookFontCatalog::cssStack($font),
            'title_font_css' => BookFontCatalog::titleCssStack($font),
            'script_titles' => BookFontCatalog::hasScriptTitles($font),
            'project_dir' => $this->projectDir,
        ]));
    }

    /** Couleur hexadécimale (#rgb ou #rrggbb) ou null : la valeur vient d'une requête et finit dans une feuille de style */
    public static function normalizeColor(?string $color): ?string
    {
        $color = trim((string) $color);
        if ($color !== '' && $color[0] !== '#') {
            $color = '#' . $color;
        }

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? strtolower($color) : null;
    }

    /** Fond sombre (texte clair par-dessus) ou clair (texte foncé) */
    public static function isDarkColor(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 140;
    }

    /**
     * Plus grand corps (en points entiers) pour que le mot le plus long du titre tienne dans la largeur donnée.
     * La largeur est mesurée avec la police du livre (en gras), pas estimée : le titre ne passe à la ligne
     * qu'entre deux mots, et un mot très long réduit le corps au lieu d'être coupé ou de déborder.
     *
     * @param bool  $uppercase       le gabarit affiche le titre en capitales
     * @param float $letterSpacingPt interlettrage du gabarit, en points (1 px CSS = 0,75 pt)
     */
    public function fitFontSize(string $title, float $widthPt, int $maxPt, bool $uppercase = false, float $letterSpacingPt = 0.0, ?string $fontCode = null): int
    {
        $title = trim($title);
        // Titres manuscrits : ni capitales ni interlettrage, et un corps plus grand (la cursive paraît plus petite)
        if (BookFontCatalog::hasScriptTitles($fontCode)) {
            $uppercase = false;
            $letterSpacingPt = 0.0;
            $maxPt = (int) round($maxPt * self::SCRIPT_TITLE_SCALE);
        }
        if ($uppercase) {
            $title = mb_strtoupper($title);
        }

        // Titre long : plusieurs lignes, donc un corps plus petit pour ne pas chevaucher ce qui suit
        $length = mb_strlen($title);
        if ($length > 28) {
            $maxPt = (int) round($maxPt * ($length > 45 ? 0.62 : 0.75));
        }

        $metrics = $this->fontMetrics();
        $font = $metrics->getFont(BookFontCatalog::titleFamily($fontCode), 'bold');
        $size = $maxPt;
        foreach (preg_split('/\s+/u', $title) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            // Largeur proportionnelle au corps, plus l'interlettrage (fixe) ; 3 % de marge pour les arrondis du rendu
            $unitWidth = (float) $metrics->getTextWidth($word, $font, 100.0) / 100.0;
            $available = $widthPt * 0.97 - $letterSpacingPt * mb_strlen($word);
            if ($unitWidth > 0) {
                $size = min($size, (int) floor($available / $unitWidth));
            }
        }

        return max(self::MIN_TITLE_PT, $size);
    }

    /** Plus grand corps (au demi-point) pour qu'une ligne entière, sans retour à la ligne, tienne dans la largeur donnée */
    private function fitLine(string $text, float $widthPt, float $maxPt, float $letterSpacingPt = 0.0, ?string $fontCode = null): float
    {
        $metrics = $this->fontMetrics();
        $unitWidth = (float) $metrics->getTextWidth($text, $metrics->getFont(BookFontCatalog::family($fontCode), 'normal'), 100.0) / 100.0;
        if ($unitWidth <= 0) {
            return $maxPt;
        }
        $size = floor(2 * ($widthPt * 0.97 - $letterSpacingPt * mb_strlen($text)) / $unitWidth) / 2;

        return max(5.0, min($maxPt, $size));
    }

    private function fontMetrics(): \Dompdf\FontMetrics
    {
        if ($this->fontMetrics === null) {
            $this->fontMetrics = $this->fonts->newDompdf()->getFontMetrics();
        }

        return $this->fontMetrics;
    }

    /**
     * Génère et retourne le binaire PDF de l'intérieur.
     */
    public function generateInteriorBinary(Book $book, ?string $customAuthorName = null, ?string $font = null): string
    {
        $font = $this->fontOf($book, $font);
        $html = $this->renderInteriorHtml($book, $customAuthorName, $font);

        $dompdf = $this->fonts->newDompdf($font);
        $dompdf->loadHtml($html, 'UTF-8');
        // Format A4 portrait + bleed (216.41mm x 303.28mm = 613.44pt x 859.68pt)
        $dompdf->setPaper([0, 0, 613.44, 859.68], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Génère et retourne le binaire PDF de la couverture dépliée.
     */
    public function generateCoverBinary(Book $book, int $pageCount = 64, string $coverStyle = 'biographic_split', ?string $authorName = null, ?string $bgColor = null, ?string $font = null): string
    {
        $font = $this->fontOf($book, $font);
        $html = $this->renderCoverHtml($book, $pageCount, $coverStyle, $authorName, $bgColor, $font);
        $dims = $this->calculateCoverDimensions($pageCount);

        $dompdf = $this->fonts->newDompdf($font);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, $dims['cover_width_pt'], $dims['cover_height_pt']]);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Génère et enregistre les deux fichiers PDF dans le dossier du livre.
     *
     * @return array{interior_path: string, cover_path: string, page_count: int}
     */
    public function generateAndSaveBookPdfs(Book $book, string $coverStyle = 'biographic_split', ?string $authorName = null, ?string $bgColor = null, ?string $font = null): array
    {
        $bookDir = $this->projectDir . '/var/storage/public_bucket/uploads/memoires/books/' . $book->getId()->toRfc4122();
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0775, true);
        }

        // 1. Génération de l'intérieur
        $interiorBinary = $this->generateInteriorBinary($book, $authorName, $font);
        $interiorPath = $bookDir . '/interior.pdf';
        file_put_contents($interiorPath, $interiorBinary);

        // 2. Estimation ou décompte du nombre de pages
        $pageCount = $this->countPdfPages($interiorPath);
        // Les imprimeurs comme Lulu exigent un nombre pair (idéalement multiple de 4)
        if ($pageCount % 2 !== 0) {
            $pageCount++;
        }
        $pageCount = max(24, $pageCount);

        // 3. Génération de la couverture avec la tranche adaptée au nombre de pages
        $coverBinary = $this->generateCoverBinary($book, $pageCount, $coverStyle, $authorName, $bgColor, $font);
        $coverPath = $bookDir . '/cover.pdf';
        file_put_contents($coverPath, $coverBinary);

        return [
            'interior_path' => 'uploads/memoires/books/' . $book->getId()->toRfc4122() . '/interior.pdf',
            'cover_path' => 'uploads/memoires/books/' . $book->getId()->toRfc4122() . '/cover.pdf',
            'page_count' => $pageCount,
            'cover_geometry' => $this->coverGeometry($pageCount),
        ];
    }

    /**
     * Compte le nombre de pages d'un fichier PDF généré.
     */
    public function countPdfPages(string $pdfFilePath): int
    {
        if (!file_exists($pdfFilePath)) {
            return 0;
        }

        $content = file_get_contents($pdfFilePath);
        if (preg_match_all('/\/Type\s*\/Pages.*?\/Count\s+(\d+)/s', $content, $matches)) {
            return (int)max($matches[1]);
        }

        preg_match_all('/\/Type\s*\/Page\b/', $content, $pages);
        return max(1, count($pages[0]));
    }
}
