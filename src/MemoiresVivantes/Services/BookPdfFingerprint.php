<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;

/**
 * Empreinte du contenu imprimé d'un livre (lot 3 bis, 03/11/2026) : tout ce qui entre dans le rendu PDF (titres, personnes, format,
 * police, auteur résolu, style et couleur de couverture, photo de couverture, chapitres imprimables avec leur texte et leurs photos).
 * Enregistrée sur le livre à chaque génération (`mv_book.pdf_source_hash`) : la commande d'impression réutilise les PDF existants
 * seulement si l'empreinte actuelle est identique, donc jamais un PDF périmé. Aucune date : un livre modifié puis remis à l'identique
 * reprend ses PDF.
 */
class BookPdfFingerprint
{
    public function __construct(private readonly BookPdfGeneratorService $pdfService)
    {
    }

    public function compute(Book $book, string $coverStyle, ?string $authorName, ?string $bgColor, ?string $font): string
    {
        $chapters = [];
        foreach ($book->getChapters() as $chapter) {
            if (!BookPdfGeneratorService::isPrintable($book, $chapter)) {
                continue;
            }
            $photos = [];
            foreach ($chapter->getPhotos() as $photo) {
                $photos[] = (string) $photo->getFilePath();
            }
            $chapters[] = [
                'id' => (string) $chapter->getId(),
                'position' => $chapter->getPosition(),
                'title' => (string) $chapter->getTitle(),
                'text' => (string) ($chapter->getContentFinal() ?: $chapter->getContentGenerated()),
                'photos' => $photos,
            ];
        }
        usort($chapters, fn ($a, $b) => ($a['position'] <=> $b['position']) ?: strcmp($a['id'], $b['id']));

        return hash('sha256', json_encode([
            'v' => 1,
            'title' => (string) $book->getTitle(),
            'subtitle' => (string) $book->getSubtitle(),
            'birthplace' => (string) $book->getBirthplace(),
            'person1' => (string) $book->getPerson1FirstName(),
            'person2' => (string) $book->getPerson2FirstName(),
            'format' => (string) $book->getFormat(),
            'channel' => $book->getChannel(),
            'font' => $this->pdfService->fontOf($book, $font),
            'author' => $this->pdfService->resolveAuthorName($book, $authorName),
            'coverStyle' => $coverStyle,
            'bgColor' => BookPdfGeneratorService::normalizeColor($bgColor ?: $book->getBgColor()),
            'coverPhoto' => (string) $book->getCoverPhotoPath(),
            'chapters' => $chapters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
