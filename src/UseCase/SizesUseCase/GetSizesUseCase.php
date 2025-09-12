<?php
namespace App\UseCase\SizesUseCase;

use App\Dto\SizeDTO;
use App\Services\SizesService\SizeService;

class GetSizesUseCase
{
    private SizeService $sizeService;

    public function __construct(SizeService $sizeService)
    {
        $this->sizeService = $sizeService;
    }

    /**
     * @return SizeDTO[]
     */
    public function execute(string $locale): array
    {
        $sizes = $this->sizeService->getAllSizes();
        return array_map(
            fn($size) => SizeDTO::fromEntity($size, $locale),
            $sizes
        );
    }
}