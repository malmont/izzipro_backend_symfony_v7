<?php
namespace App\Controller\paymentController;

use App\UseCase\PaymentUseCase\CreatePaymentUseCase;
use App\UseCase\PaymentUseCase\GetPaymentsByOrderSourceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class PaymentsController extends AbstractController
{
    private $getPaymentsByOrderSourceUseCase;
    private $createPaymentUseCase;

    public function __construct(GetPaymentsByOrderSourceUseCase $getPaymentsByOrderSourceUseCase,CreatePaymentUseCase $createPaymentUseCase)
    {
        $this->getPaymentsByOrderSourceUseCase = $getPaymentsByOrderSourceUseCase;
        $this->createPaymentUseCase = $createPaymentUseCase;
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
            return $this->json([], JsonResponse::HTTP_OK);
        }

        $paymentData = array_map(fn($dto) => $dto->toArray(), $paymentDTOs);
        return $this->json($paymentData);
    }


    /**
     * @Route("/api/payment", name="process_payment", methods={"POST"})
     */
    public function processPayment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $nonce = $data['nonce'] ?? null;
        $amount = $data['amount'] ?? null;

        if (!$nonce || !$amount) {
            return $this->json(['success' => false, 'error' => 'Données invalides.'], 400);
        }

        // 🟢 Exécution du paiement
        $result = $this->createPaymentUseCase->execute($nonce, $amount * 100);

        if ($result['success']) {
            $payment = $result['payment'];

            // 🔎 Extraction des informations demandées
            $response = [
                'success' => true,
                'payment' => [
                    'squarePaymentId'   => $payment->getId(),
                    'squareOrderId'     => $payment->getOrderId(),
                    'squareReceiptUrl'  => $payment->getReceiptUrl(),
                    'squareStatus'      => $payment->getStatus(),
                    'squareCardBrand'   => $payment->getCardDetails()->getCard()->getCardBrand(),
                    'squareLast4'       => $payment->getCardDetails()->getCard()->getLast4(),
                    'squareRiskLevel'   => $payment->getRiskEvaluation()->getRiskLevel()
                ]
            ];

            return $this->json($response);
        }

        return $this->json(['success' => false, 'errors' => $result['errors']], 500);
    }

}
