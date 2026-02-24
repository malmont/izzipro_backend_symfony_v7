<?php

namespace App\Tests\Unit\Services\OrderService;

use App\Entity\EmailConfiguration;
use App\Entity\EmailConfigurationTranslation;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\User;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use App\Services\OrderService\OrderMailerService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class OrderMailerServiceTest extends TestCase
{
    private $mailer;
    private $twig;
    private $emailConfigService;
    private $emailLogoHelper;
    private $logger;
    private $emProvider;
    private $orderMailerService;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->emailConfigService = $this->createMock(EmailConfigurationService::class);
        $this->emailLogoHelper = $this->createMock(EmailLogoHelper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->emProvider = $this->createMock(TenantEntityManagerProvider::class);

        $this->orderMailerService = new OrderMailerService(
            $this->mailer,
            $this->twig,
            $this->emailConfigService,
            $this->emailLogoHelper,
            $this->logger,
            $this->emProvider
        );
    }

    public function testSendOrderConfirmationSuccess(): void
    {
        $locale = 'fr';
        $domain = 'example.com';
        $email = 'test@example.com';

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);

        $order = $this->createMock(Order::class);
        $order->method('getUserId')->willReturn($user);
        $order->method('getReference')->willReturn('REF123');
        $order->method('getOrderItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $emailConfigTranslation = $this->createMock(EmailConfigurationTranslation::class);
        $emailConfigTranslation->method('getFromName')->willReturn('My Shop');
        $emailConfigTranslation->method('getSignature')->willReturn('Regards');

        $emailConfig = $this->createMock(EmailConfiguration::class);
        $emailConfig->method('getTranslation')->with($locale)->willReturn($emailConfigTranslation);
        $emailConfig->method('getFromEmail')->willReturn('no-reply@example.com');
        $emailConfig->method('getLogo')->willReturn('logo.png');

        $this->emailConfigService->method('findOneByLocale')->with($locale)->willReturn($emailConfig);

        $this->twig->method('render')->willReturn('<html>Content</html>');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $emailMessage) use ($email) {
                return $emailMessage->getTo()[0]->getAddress() === $email &&
                    $emailMessage->getSubject() === 'Confirmation de votre commande n°REF123';
            }));

        $this->orderMailerService->sendOrderConfirmation($order, $locale, $domain);
    }

    public function testSendOrderConfirmationMissingUser(): void
    {
        $locale = 'fr';
        $domain = 'example.com';

        $order = $this->createMock(Order::class);
        $order->method('getUserId')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Erreur email confirmation'));

        $this->orderMailerService->sendOrderConfirmation($order, $locale, $domain);
    }

    public function testSendShippingNotificationSuccess(): void
    {
        $locale = 'fr';
        $domain = 'example.com';
        $companyEmail = 'company@example.com';

        $entreprise = $this->createMock(Entreprise::class);
        $entreprise->method('getEmail')->willReturn($companyEmail);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($entreprise);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Entreprise::class)->willReturn($repo);

        $this->emProvider->method('getEntityManager')->willReturn($em);

        $order = $this->createMock(Order::class);
        $order->method('getReference')->willReturn('REF456');
        $order->method('getShippingOrder')->willReturn(null); // Simplify for now
        $order->method('getOrderItems')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));


        $emailConfigTranslation = $this->createMock(EmailConfigurationTranslation::class);
        $emailConfigTranslation->method('getFromName')->willReturn('My Shop');
        $emailConfigTranslation->method('getSignature')->willReturn('Regards');

        $emailConfig = $this->createMock(EmailConfiguration::class);
        $emailConfig->method('getTranslation')->with($locale)->willReturn($emailConfigTranslation);
        $emailConfig->method('getFromEmail')->willReturn('no-reply@example.com');
        $emailConfig->method('getLogo')->willReturn('logo.png');

        $this->emailConfigService->method('findOneByLocale')->with($locale)->willReturn($emailConfig);

        $this->twig->method('render')->willReturn('<html>Shipping Content</html>');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $emailMessage) use ($companyEmail) {
                return $emailMessage->getTo()[0]->getAddress() === $companyEmail &&
                    $emailMessage->getSubject() === 'Nouvelle commande à expédier : REF456';
            }));

        $this->orderMailerService->sendShippingNotification($order, $locale, $domain);
    }
}
