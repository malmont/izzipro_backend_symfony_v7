<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Services\LuluPrintService;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/webhooks')]
class LuluWebhookController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LuluPrintService $luluPrintService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Réception des événements et changements d'état envoyés par Lulu.com.
     */
    #[Route('/lulu', methods: ['POST'])]
    public function handleLuluWebhook(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $this->logger->info('Lulu Webhook payload received: ' . $content);

        $payload = json_decode($content, true);
        if (!$payload || !is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Supporte les payloads directs et les payloads avec wrapper 'data'
        $data = $payload['data'] ?? $payload;
        $jobId = (string)($data['id'] ?? $data['print_job_id'] ?? '');

        if (empty($jobId)) {
            $this->logger->warning('Lulu Webhook: Aucun ID de travail trouvé dans le payload.');
            return $this->json(['status' => 'ignored', 'reason' => 'No job id found']);
        }

        $em = $this->emProvider->getEntityManager();
        /** @var BookPrintOrder|null $order */
        $order = $em->getRepository(BookPrintOrder::class)->findOneBy(['luluPrintJobId' => $jobId]);

        if (!$order) {
            $this->logger->warning(sprintf('Lulu Webhook: Commande introuvable pour le job Lulu %s', $jobId));
            return $this->json(['status' => 'ignored', 'reason' => 'Order not found for job ' . $jobId]);
        }

        // Synchronisation complète
        $this->luluPrintService->syncOrderStatus($order);

        $this->logger->info(sprintf('Lulu Webhook: Commande %s mise à jour au statut %s', $order->getId()->toRfc4122(), $order->getStatus()));

        return $this->json([
            'success' => true,
            'order_id' => $order->getId()->toRfc4122(),
            'status' => $order->getStatus(),
        ]);
    }
}
