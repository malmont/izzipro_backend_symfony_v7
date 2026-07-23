<?php


namespace App\Controller\gemsuiteComunicationController;

use App\Services\IiziproJwtService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GemsuiteTestController extends AbstractController
{
    /**
     * Endpoint désactivé en mode Standalone
     */
    #[Route('/test/call-gemsuite', name: 'test_call_gemsuite')]
    public function testCallGemsuite(): Response
    {
        return new JsonResponse(['status' => 'disabled', 'message' => 'GemSuite désactivé en mode Standalone.'], 403);
    }

    /**
     * Endpoint désactivé en mode Standalone
     */
    #[Route('/api/webhook/gemsuite/order-update', name: 'webhook_gemsuite', methods: ['POST'])]
    public function handleGemsuiteWebhook(Request $request): JsonResponse
    {
        return new JsonResponse(['status' => 'disabled', 'message' => 'GemSuite désactivé en mode Standalone.'], 403);
    }
}