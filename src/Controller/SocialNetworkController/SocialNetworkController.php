<?php

namespace App\Controller\SocialNetworkController;

use App\UseCase\SocialNetworkUseCase\GetSocialNetworksUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SocialNetworkController extends AbstractController
{
    public function __construct(private GetSocialNetworksUseCase $getSocialNetworksUseCase)
    {
    }

    #[Route('/api/social-networks', name: 'api_social_networks_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $socialNetworks = $this->getSocialNetworksUseCase->execute();

        return $this->json($socialNetworks);
    }
}
