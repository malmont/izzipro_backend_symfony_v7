<?php
namespace App\UseCase\BaniereStatiqueUseCase;

use App\Dto\BaniereStatiqueOutputDto;
use App\Services\BaniereStatiqueService\BaniereStatiqueService;

class GetBaniereStatiqueByIdUseCase
{
    public function __construct(private BaniereStatiqueService $service) {}

    public function execute(int $id, string $locale, string $baseImageUrl): ?BaniereStatiqueOutputDto
    {
        $entity = $this->service->findByIdAndLocale($id, $locale);
        if (!$entity) {
            return null;
        }
        return new BaniereStatiqueOutputDto($entity, $baseImageUrl, $locale);
    }
}