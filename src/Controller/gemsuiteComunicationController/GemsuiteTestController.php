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
     * Endpoint pour tester l'appel sortant : Iizipro -> Simulateur
     */
    #[Route('/test/call-gemsuite', name: 'test_call_gemsuite')]
    public function testCallGemsuite(HttpClientInterface $gemsuiteClient, IiziproJwtService $jwtService): Response
    {
        try {
            $jwt = $jwtService->generateForGemsuite();
            
            $response = $gemsuiteClient->request('GET', '/', [
                'auth_bearer' => $jwt,
            ]);

            return new JsonResponse($response->toArray());

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Endpoint pour tester l'appel entrant : Simulateur -> Iizipro
     * La sécurité est gérée en amont par GemsuiteJwtAuthenticator.
     */
    #[Route('/api/webhook/gemsuite/order-update', name: 'webhook_gemsuite', methods: ['POST'])]
    public function handleGemsuiteWebhook(Request $request): JsonResponse
    {
        $data = $request->toArray();
        // Logique métier : traiter la mise à jour de la commande...
        // ...

        return new JsonResponse(['status' => 'OK', 'message' => '✅ Iizipro: Webhook traité avec succès.']);
    }
}