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
        ?string $shippingLevel = null,
        int $quantity = 1
    ): array {
        // Normalisation complète et conforme de l'adresse selon le pays de destination
        $normalizedAddress = LuluPrintService::normalizeAddress($shippingAddress);
        $countryCode = $normalizedAddress['country_code'];

        if (empty($normalizedAddress['street1']) || empty($normalizedAddress['city']) || empty($normalizedAddress['country_code'])) {
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

        // 2. Détermination du mode de livraison par défaut adapté au pays
        $defaultLevel = match ($countryCode) {
            'CA' => 'PRIORITY_MAIL', // Au Canada, PRIORITY_MAIL avec suivi est idéal (EXPEDITED n'existe pas chez Lulu pour CA)
            'US' => 'GROUND',
            default => 'PRIORITY_MAIL',
        };

        $requestedLevel = !empty($shippingLevel) ? $shippingLevel : $defaultLevel;
        $effectiveShippingLevel = LuluPrintService::sanitizeShippingLevel($requestedLevel, $countryCode);

        $estimate = $this->luluPrintService->calculatePrintCost(
            $book,
            $normalizedAddress,
            $effectiveShippingLevel,
            $quantity,
            $pageCount
        );

        // 3. Calcul comparatif des options de livraison réelles et supportées par Lulu selon le pays
        $shippingOptions = [];
        $supportedLevels = match ($countryCode) {
            'CA' => [
                'MAIL' => ['label' => 'Postes Canada Standard (5-9 jours ouvrables)', 'carrier' => 'Postes Canada'],
                'PRIORITY_MAIL' => ['label' => 'Postes Canada Prioritaire avec suivi (2-4 jours ouvrables)', 'carrier' => 'Postes Canada'],
                'EXPRESS' => ['label' => 'FedEx Express (1-2 jours ouvrables)', 'carrier' => 'FedEx Express'],
            ],
            'US' => [
                'MAIL' => ['label' => 'USPS Media Mail (5-8 jours)', 'carrier' => 'USPS'],
                'GROUND' => ['label' => 'FedEx Ground (3-5 jours)', 'carrier' => 'FedEx Ground'],
                'EXPEDITED' => ['label' => 'FedEx Expedited (2-3 jours)', 'carrier' => 'FedEx'],
                'EXPRESS' => ['label' => 'FedEx Priority Overnight (1-2 jours)', 'carrier' => 'FedEx Priority'],
            ],
            default => [
                'MAIL' => ['label' => 'Courrier International Standard (7-14 jours)', 'carrier' => 'Poste Internationale'],
                'PRIORITY_MAIL' => ['label' => 'Courrier Prioritaire Suivi (4-7 jours)', 'carrier' => 'Poste Prioritaire'],
                'EXPRESS' => ['label' => 'Express International (2-3 jours)', 'carrier' => 'DHL / FedEx'],
            ],
        };

        foreach ($supportedLevels as $lvl => $info) {
            $optEstimate = $this->luluPrintService->calculatePrintCost(
                $book,
                $normalizedAddress,
                $lvl,
                $quantity,
                $pageCount
            );
            $shippingOptions[$lvl] = [
                'label' => $info['label'],
                'carrier' => $info['carrier'],
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
            'selected_shipping_level' => $effectiveShippingLevel,
            'print_cost' => $estimate['print_cost'],
            'shipping_cost' => $estimate['shipping_cost'],
            'tax_cost' => $estimate['tax_cost'],
            'total_cost' => $estimate['total_cost'],
            'currency' => $estimate['currency'],
            'is_simulated' => $estimate['is_simulated'],
            'shipping_options' => $shippingOptions,
            'shipping_address' => $normalizedAddress,
        ];
    }
}
