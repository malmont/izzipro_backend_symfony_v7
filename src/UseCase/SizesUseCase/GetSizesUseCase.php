<?php

namespace App\UseCase\SizesUseCase;

use App\Services\SizesService\SizeService;

class GetSizesUseCase
{
    private SizeService $sizeService;

    public function __construct(SizeService $sizeService)
    {
        $this->sizeService = $sizeService;
    }

    public function execute(): array
    {
        $sizes = $this->sizeService->getAllSizes();

        // Convertir les objets Size en tableau
        $sizesArray = [];
        foreach ($sizes as $size) {
            $sizesArray[] = [
                'id' => $size->getId(),
                'name' => $size->getName(),
            ];
        }

        return $sizesArray;
    }
}
