<?php
namespace App\UseCase\BanniereUseCase;

use App\Services\BanniereService\BanniereService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteBanniereUseCase
{
    private BanniereService $banniereService;

    public function __construct(BanniereService $banniereService)
    {
        $this->banniereService = $banniereService;
    }

    public function execute(int $id): void
    {
        $banniere = $this->banniereService->findBanniere($id);
        if (!$banniere) {
            throw new NotFoundHttpException('Bannière non trouvée.');
        }
        $this->banniereService->deleteBanniere($banniere);
    }
}
