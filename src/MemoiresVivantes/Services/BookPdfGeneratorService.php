<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
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
        // Formule standard papier 80-100g : (pages * 0.055mm) + 3.5mm charnière/carton
        $spineMm = ($pageCount * 0.055) + 3.5;
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
     * Rendu HTML de l'intérieur.
     */
    public function renderInteriorHtml(Book $book): string
    {
        return $this->twig->render('pdf/memoires/interior.html.twig', [
            'book' => $book,
            'project_dir' => $this->projectDir,
        ]);
    }

    /**
     * Rendu HTML de la couverture dépliée.
     */
    public function renderCoverHtml(Book $book, int $pageCount = 64): string
    {
        $dimensions = $this->calculateCoverDimensions($pageCount);
        $coverImagePath = null;
        $coverImageExists = false;
        if ($book->getCoverPhotoPath()) {
            $coverImagePath = $this->projectDir . '/public/uploads/memoires/' . $book->getCoverPhotoPath();
            $coverImageExists = file_exists($coverImagePath);
        }

        return $this->twig->render('pdf/memoires/cover.html.twig', array_merge($dimensions, [
            'book' => $book,
            'cover_image_path' => $coverImagePath,
            'cover_image_exists' => $coverImageExists,
            'project_dir' => $this->projectDir,
        ]));
    }

    /**
     * Génère et retourne le binaire PDF de l'intérieur.
     */
    public function generateInteriorBinary(Book $book): string
    {
        $html = $this->renderInteriorHtml($book);

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
    public function generateCoverBinary(Book $book, int $pageCount = 64): string
    {
        $html = $this->renderCoverHtml($book, $pageCount);
        $dims = $this->calculateCoverDimensions($pageCount);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Serif');
        $options->set('chroot', $this->projectDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, $dims['cover_width_pt'], $dims['cover_height_pt']], 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Génère et enregistre les deux fichiers PDF dans le dossier du livre.
     *
     * @return array{interior_path: string, cover_path: string, page_count: int}
     */
    public function generateAndSaveBookPdfs(Book $book): array
    {
        $bookDir = $this->projectDir . '/public/uploads/memoires/books/' . $book->getId()->toRfc4122();
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0775, true);
        }

        // 1. Génération de l'intérieur
        $interiorBinary = $this->generateInteriorBinary($book);
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
        $coverBinary = $this->generateCoverBinary($book, $pageCount);
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
