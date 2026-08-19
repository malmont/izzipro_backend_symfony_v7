<?php

namespace App\ESG\Controller;

use App\ESG\UseCase\Referential\ListReferentialsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReferentialController extends AbstractController
{
    #[Route('/api/boussole/referentials', name: 'esg_referentials_list', methods: ['GET'])]
    public function listReferentials(ListReferentialsUseCase $useCase): Response
    {
        $referentials = $useCase->execute();
        
        return $this->json($referentials, Response::HTTP_OK);
    }
}
