<?php

namespace App\Tests\Unit\Services\StripePaymentService;

use App\Entity\StripeConfig;
use App\Services\StripePaymentService\StripePaymentService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

class StripePaymentServiceTest extends TestCase
{
    private $emProvider;
    private $logger;
    private $connectionManager;

    protected function setUp(): void
    {
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->connectionManager = $this->createMock(TenantConnectionManager::class);
    }

    private function createServiceWithMockedRetrieve(string $stripeKey, ?object $paymentIntentOrException): StripePaymentService&MockObject
    {
        // On crée un mock partiel pour surcharger SEULEMENT la méthode protégée
        $service = $this->getMockBuilder(StripePaymentService::class)
            ->setConstructorArgs([$stripeKey, $this->emProvider, $this->logger, $this->connectionManager])
            ->onlyMethods(['retrieveStripePaymentIntent'])
            ->getMock();

        if ($paymentIntentOrException instanceof \Exception) {
            $service->method('retrieveStripePaymentIntent')
                ->willThrowException($paymentIntentOrException);
        } elseif ($paymentIntentOrException !== null) {
            $service->method('retrieveStripePaymentIntent')
                ->willReturn($paymentIntentOrException);
        }

        return $service;
    }

    public function testCreatePaymentFailsIfTenantUnknown(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn(null);

        $service = new StripePaymentService('sk_test', $this->emProvider, $this->logger, $this->connectionManager);
        $result = $service->createPayment('pi_test');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Tenant inconnu', $result['errors'][0]);
    }

    public function testCreatePaymentInternalTenant(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn('v2v');
        $this->connectionManager->method('isTenantInternal')->willReturn(true);

        $paymentIntent = new PaymentIntent('pi_123');
        $paymentIntent->status = 'succeeded';

        $service = $this->createServiceWithMockedRetrieve('sk_test', $paymentIntent);

        // Verification : l'appel doit se faire sans options de compte connecté
        $service->expects($this->once())
            ->method('retrieveStripePaymentIntent')
            ->with('pi_123', [])
            ->willReturn($paymentIntent);

        $result = $service->createPayment('pi_123');

        $this->assertTrue($result['success']);
        $this->assertSame($paymentIntent, $result['payment']);
    }

    public function testCreatePaymentExternalTenantSuccess(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn('client_shop');
        $this->connectionManager->method('isTenantInternal')->willReturn(false);

        // Mock de la config Stripe du client
        $stripeConfig = $this->createMock(StripeConfig::class);
        $stripeConfig->method('getAccountId')->willReturn('acct_12345');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($stripeConfig);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $paymentIntent = new PaymentIntent('pi_external');
        $paymentIntent->status = 'succeeded';

        $service = $this->createServiceWithMockedRetrieve('sk_test', $paymentIntent);

        $service->expects($this->once())
            ->method('retrieveStripePaymentIntent')
            ->with('pi_external', ['stripe_account' => 'acct_12345'])
            ->willReturn($paymentIntent);

        $result = $service->createPayment('pi_external');

        $this->assertTrue($result['success']);
    }

    public function testCreatePaymentExternalTenantNoConfig(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn('client_shop');
        $this->connectionManager->method('isTenantInternal')->willReturn(false);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null); // Pas de config

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $this->emProvider->method('getEntityManager')->willReturn($em);

        $service = new StripePaymentService('sk_test', $this->emProvider, $this->logger, $this->connectionManager);
        $result = $service->createPayment('pi_fail');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pas actif', $result['errors'][0]);
    }

    public function testCreatePaymentFailsIfStatusNotSucceeded(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn('v2v');
        $this->connectionManager->method('isTenantInternal')->willReturn(true);

        $paymentIntent = new PaymentIntent('pi_123');
        $paymentIntent->status = 'requires_payment_method';

        $service = $this->createServiceWithMockedRetrieve('sk_test', $paymentIntent);

        $result = $service->createPayment('pi_123');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Statut : requires_payment_method', $result['errors'][0]);
    }

    public function testCreatePaymentStripeError(): void
    {
        $this->connectionManager->method('getCurrentTenantCode')->willReturn('v2v');
        $this->connectionManager->method('isTenantInternal')->willReturn(true);

        $exception = new \Stripe\Exception\InvalidRequestException('Stripe is down', 0);
        $service = $this->createServiceWithMockedRetrieve('sk_test', $exception);

        $result = $service->createPayment('pi_error');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Stripe is down', $result['errors'][0]);
    }
}
