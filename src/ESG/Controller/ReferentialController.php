<?php

namespace App\ESG\Controller;

use App\ESG\Entity\EsgUser;
use App\ESG\UseCase\Referential\ListReferentialsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReferentialController extends AbstractController
{
    #[Route('/api/boussole/referentials', name: 'esg_referentials_list', methods: ['GET'])]
    public function listReferentials(ListReferentialsUseCase $useCase): Response
    {
        // Route publique : alreadyHeld n'est calculé que si un utilisateur ESG est connecté
        $user = $this->getUser();
        $referentials = $useCase->execute($user instanceof EsgUser ? $user->getCompany() : null);
        
        return $this->json($referentials, Response::HTTP_OK);
    }
}
