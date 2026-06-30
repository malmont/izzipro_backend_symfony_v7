<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\EmailVerifier;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifierTest extends TestCase
{
    private $verifyEmailHelper;
    private $mailer;
    private $tenantEmProvider;
    private $entityManager;
    private $emailVerifier;

    protected function setUp(): void
    {
        $this->verifyEmailHelper = $this->getMockBuilder(VerifyEmailHelperInterface::class)
            ->onlyMethods(['generateSignature', 'validateEmailConfirmation'])
            ->addMethods(['validateEmailConfirmationFromRequest'])
            ->getMock();
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Configure TenantEntityManagerProvider to return our mocked EntityManager
        $this->tenantEmProvider->method('getEntityManager')
            ->willReturn($this->entityManager);

        $this->emailVerifier = new EmailVerifier(
            $this->verifyEmailHelper,
            $this->mailer,
            $this->tenantEmProvider
        );
    }

    public function testSendEmailConfirmation(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);
        $user->method('getEmail')->willReturn('test@example.com');

        $email = new TemplatedEmail();

        $signatureComponents = new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+1 hour'),
            '/verify?signature=123',
            123
        );

        $this->verifyEmailHelper->expects($this->once())
            ->method('generateSignature')
            ->with(
                'app_verify_email',
                '123',
                'test@example.com',
                ['id' => 123]
            )
            ->willReturn($signatureComponents);

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($email);

        $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user, $email);

        $context = $email->getContext();
        $this->assertArrayHasKey('signedUrl', $context);
        $this->assertEquals('/verify?signature=123', $context['signedUrl']);
    }

    public function testHandleEmailConfirmation(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(456);
        $user->method('getEmail')->willReturn('user@example.com');

        $request = $this->createMock(Request::class);

        $this->verifyEmailHelper->expects($this->once())
            ->method('validateEmailConfirmationFromRequest')
            ->with($request, '456', 'user@example.com');

        $user->expects($this->once())->method('setIsVerified')->with(true);

        $this->entityManager->expects($this->once())->method('persist')->with($user);
        $this->entityManager->expects($this->once())->method('flush');

        $this->emailVerifier->handleEmailConfirmation($request, $user);
    }
}
