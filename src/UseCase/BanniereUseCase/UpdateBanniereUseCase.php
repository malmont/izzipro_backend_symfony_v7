<?php
namespace App\UseCase\BanniereUseCase;

use App\Dto\BanniereInputDto;
use App\Entity\Banniere;
use App\Services\BanniereService\BanniereService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateBanniereUseCase
{
    private BanniereService $banniereService;

    public function __construct(BanniereService $banniereService)
    {
        $this->banniereService = $banniereService;
    }

    public function execute(int $id, BanniereInputDto $dto): Banniere
    {
        $banniere = $this->banniereService->findBanniere($id);
        if (!$banniere) {
            throw new NotFoundHttpException('Bannière non trouvée.');
        }
        return $this->banniereService->updateBanniere($banniere, $dto);
    }
}
