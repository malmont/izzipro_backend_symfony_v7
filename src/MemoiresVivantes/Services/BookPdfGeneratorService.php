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

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly Environment $twig,
        private readonly string $projectDir
    ) {}

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
    public function resolveAuthorName(Book $book, ?string $customAuthorName = null): string
    {
        $authorName = $customAuthorName;
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
        return $this->cleanText($authorName);
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
    public function renderInteriorHtml(Book $book, ?string $customAuthorName = null): string
    {
        $chaptersData = [];
        foreach ($book->getChapters() as $chapter) {
            $title = $this->cleanText($chapter->getTitle());
            $theme = $this->cleanText($chapter->getTheme());
            $rawContent = $chapter->getContentFinal() ?: ($chapter->getContentGenerated() ?: '');
            $cleanContent = $this->cleanText($rawContent);

            $chaptersData[] = [
                'chapter' => $chapter,
                'clean_title' => $title,
                'clean_theme' => $theme,
                'clean_content' => $cleanContent,
                'photo_pages' => $this->resolveChapterPhotoPages($chapter),
            ];
        }

        return $this->twig->render('pdf/memoires/interior.html.twig', [
            'book' => $book,
            'clean_title' => $this->cleanText($book->getTitle()),
            'clean_subtitle' => $this->cleanText($book->getSubtitle()),
            'author_name' => $this->resolveAuthorName($book, $customAuthorName),
            'chapters_data' => $chaptersData,
            'project_dir' => $this->projectDir,
        ]);
    }

    /**
     * Rendu HTML de la couverture dépliée.
     */
    public function renderCoverHtml(Book $book, int $pageCount = 64, string $coverStyle = 'biographic_split', ?string $customAuthorName = null, ?string $bgColor = null): string
    {
        $dimensions = $this->calculateCoverDimensions($pageCount);
        $coverImagePath = null;
        $coverImageExists = false;
        if ($book->getCoverPhotoPath()) {
            $coverImagePath = $this->projectDir . '/public/uploads/memoires/' . $book->getCoverPhotoPath();
            $coverImageExists = file_exists($coverImagePath);
        }

        $authorName = $this->resolveAuthorName($book, $customAuthorName);
        $cleanTitle = $this->cleanText($book->getTitle());
        $cleanSubtitle = $this->cleanText($book->getSubtitle());

        return $this->twig->render('pdf/memoires/cover.html.twig', array_merge($dimensions, [
            'book' => $book,
            'clean_title' => $cleanTitle,
            'clean_subtitle' => $cleanSubtitle,
            'cover_image_path' => $coverImagePath,
            'cover_image_exists' => $coverImageExists,
            'cover_style' => $coverStyle,
            'author_name' => $authorName,
            'bg_color' => $bgColor,
            'project_dir' => $this->projectDir,
        ]));
    }

    /**
     * Génère et retourne le binaire PDF de l'intérieur.
     */
    public function generateInteriorBinary(Book $book, ?string $customAuthorName = null): string
    {
        $html = $this->renderInteriorHtml($book, $customAuthorName);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Serif');
        $options->set('chroot', $this->projectDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        // Format A4 portrait + bleed (216.41mm x 303.28mm = 613.44pt x 859.68pt)
        $dompdf->setPaper([0, 0, 613.44, 859.68], 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Génère et retourne le binaire PDF de la couverture dépliée.
     */
    public function generateCoverBinary(Book $book, int $pageCount = 64, string $coverStyle = 'biographic_split', ?string $authorName = null, ?string $bgColor = null): string
    {
        $html = $this->renderCoverHtml($book, $pageCount, $coverStyle, $authorName, $bgColor);
        $dims = $this->calculateCoverDimensions($pageCount);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Serif');
        $options->set('chroot', $this->projectDir);

        $dompdf = new Dompdf($options);
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
    public function generateAndSaveBookPdfs(Book $book, string $coverStyle = 'biographic_split', ?string $authorName = null, ?string $bgColor = null): array
    {
        $bookDir = $this->projectDir . '/public/uploads/memoires/books/' . $book->getId()->toRfc4122();
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0775, true);
        }

        // 1. Génération de l'intérieur
        $interiorBinary = $this->generateInteriorBinary($book, $authorName);
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
        $coverBinary = $this->generateCoverBinary($book, $pageCount, $coverStyle, $authorName, $bgColor);
        $coverPath = $bookDir . '/cover.pdf';
        file_put_contents($coverPath, $coverBinary);

        return [
            'interior_path' => 'uploads/memoires/books/' . $book->getId()->toRfc4122() . '/interior.pdf',
            'cover_path' => 'uploads/memoires/books/' . $book->getId()->toRfc4122() . '/cover.pdf',
            'page_count' => $pageCount,
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
