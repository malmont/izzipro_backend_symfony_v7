<?php

namespace App\MemoiresVivantes\UseCase\Print;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\MemoiresVivantes\Services\LuluPrintService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class EstimateBookPrintUseCase
{
    public function __construct(
        private readonly LuluPrintService $luluPrintService,
        private readonly BookPdfGeneratorService $pdfGeneratorService
    ) {}

    /**
     * Calcule le devis d'impression et les options de livraison pour un livre.
     */
    public function execute(
        Book $book,
        array $shippingAddress,
        string $shippingLevel = 'EXPEDITED',
        int $quantity = 1
    ): array {
        if (empty($shippingAddress['street1']) || empty($shippingAddress['city']) || empty($shippingAddress['country_code'])) {
            throw new BadRequestHttpException('L\'adresse de livraison doit contenir au minimum la rue, la ville et le pays (code ISO 2 lettres).');
        }

        // 1. Calcul du nombre de pages estimé ou réel
        $bookDir = '/public/uploads/memoires/books/' . $book->getId()->toRfc4122();
        $interiorPdf = $bookDir . '/interior.pdf';
        
        $pageCount = 64;
        if (file_exists($interiorPdf)) {
            $pageCount = $this->pdfGeneratorService->countPdfPages($interiorPdf);
        } else {
            // Estimation basée sur le nombre de chapitres
            $chapterCount = $book->getChapters()->count();
            $pageCount = max(24, 6 + ($chapterCount * 8));
        }

        if ($pageCount % 2 !== 0) {
            $pageCount++;
        }

        // 2. Calcul du coût pour le niveau de livraison demandé (Défaut FedEx EXPEDITED)
        $shippingLevel = strtoupper($shippingLevel ?: 'EXPEDITED');
        $estimate = $this->luluPrintService->calculatePrintCost(
            $book,
            $shippingAddress,
            $shippingLevel,
            $quantity,
            $pageCount
        );

        // 3. Calcul comparatif des options FedEx & standard
        $shippingOptions = [];
        $levelLabels = [
            'EXPEDITED' => 'FedEx Express / Accéléré (2-3 jours ouvrables)',
            'GROUND' => 'FedEx Ground (3-5 jours ouvrables)',
            'EXPRESS' => 'FedEx Express Prioritaire (1-2 jours ouvrables)',
            'MAIL' => 'Standard Postal',
        ];

        foreach ($levelLabels as $level => $label) {
            $optEstimate = $this->luluPrintService->calculatePrintCost(
                $book,
                $shippingAddress,
                $level,
                $quantity,
                $pageCount
            );
            $shippingOptions[$level] = [
                'label' => $label,
                'carrier' => str_starts_with($level, 'MAIL') ? 'Postes Canada' : 'FedEx Canada',
                'shipping_cost' => $optEstimate['shipping_cost'],
                'total_cost' => $optEstimate['total_cost'],
                'currency' => $optEstimate['currency'],
            ];
        }

        return [
            'book_id' => $book->getId()->toRfc4122(),
            'book_title' => $book->getTitle(),
            'page_count' => $pageCount,
            'quantity' => $quantity,
            'selected_shipping_level' => strtoupper($shippingLevel),
            'print_cost' => $estimate['print_cost'],
            'shipping_cost' => $estimate['shipping_cost'],
            'tax_cost' => $estimate['tax_cost'],
            'total_cost' => $estimate['total_cost'],
            'currency' => $estimate['currency'],
            'is_simulated' => $estimate['is_simulated'],
            'shipping_options' => $shippingOptions,
        ];
    }
}
