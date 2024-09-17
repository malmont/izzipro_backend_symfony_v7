<?php
namespace App\Controller\paymentController;

use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class PaymentsController extends AbstractController
{
    private $getPaymentsByOrderSourceUseCase;

    public function __construct(GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase)
    {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
    }

    #[Route('api/payments', name: 'get_payments', methods: ['GET'])]
    public function getPayments(Request $request): JsonResponse
    {
        $orderSourceId = $request->query->get('orderSource');
        if (!$orderSourceId) {
            return $this->json(['error' => 'orderSource parameter is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $days = $request->query->get('days');
        $host = $request->getSchemeAndHttpHost();

        $paymentDTOs = $this->getPaymentsByOrderSourceUseCase->execute((int)$orderSourceId, $host, $days ? (int)$days : null);

        if (empty($paymentDTOs)) {
            return $this->json(['message' => 'No payments found for the given order source'], JsonResponse::HTTP_NOT_FOUND);
        }

        $paymentData = array_map(fn($dto) => $dto->toArray(), $paymentDTOs);
        return $this->json($paymentData);
    }
}
