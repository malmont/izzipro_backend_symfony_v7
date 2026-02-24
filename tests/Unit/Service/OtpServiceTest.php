<?php

namespace App\Tests\Unit\Service;

use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\OtpCode;
use App\Entity\Translation\EmailConfigurationTranslation;
use App\Entity\User;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use App\Services\OtpService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class OtpServiceTest extends TestCase
{
    private $tenantEmProvider;
    private $mailer;
    private $twig;
    private $emailConfigService;
    private $emailLogoHelper;
    private $em;
    private $otpService;

    protected function setUp(): void
    {
        $this->tenantEmProvider = $this->createMock(TenantEntityManagerProvider::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->emailConfigService = $this->createMock(EmailConfigurationService::class);
        $this->emailLogoHelper = $this->createMock(EmailLogoHelper::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->tenantEmProvider->method('getEntityManager')->willReturn($this->em);

        $this->otpService = new OtpService(
            $this->tenantEmProvider,
            $this->mailer,
            $this->twig,
            $this->emailConfigService,
            $this->emailLogoHelper
        );
    }

    public function testGenerateAndSendOtpPersistsNewUser(): void
    {
        $user = new User(); // No ID
        $user->setEmail('newuser@example.com');
        $request = new Request([], [], [], [], [], [], []);
        $request->setLocale('fr');

        // Expect User persist
        $this->em->expects($this->exactly(2))
            ->method('persist')
            ->withConsecutive(
                [$this->equalTo($user)], // 1. Persist User
                [$this->isInstanceOf(OtpCode::class)] // 2. Persist OTP
            );

        $this->em->expects($this->exactly(2))->method('flush');

        // Expect Mail Sent
        $this->mailer->expects($this->once())->method('send');

        // Mock basics for email config to avoid null errors if handled loosely
        $this->emailConfigService->method('findOneByLocale')->willReturn(null);
        // Mock Repository
        $entrepriseRepo = $this->createMock(EntityRepository::class);
        $entrepriseRepo->method('findOneBy')->willReturn(new Entreprise());

        $this->em->method('getRepository')->with(Entreprise::class)->willReturn($entrepriseRepo);
        $this->twig->method('render')->willReturn('<body>Content</body>');

        $this->otpService->generateAndSendOtp($user, $request);
    }

    public function testGenerateAndSendOtpUsesExistingUser(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);
        $user->method('getEmail')->willReturn('test@example.com');

        $request = new Request();
        $request->setLocale('en');

        // Mock Repository finding the user
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->expects($this->once())->method('find')->with(123)->willReturn($user);

        $entrepriseRepo = $this->createMock(EntityRepository::class);
        $entrepriseRepo->method('findOneBy')->willReturn(new Entreprise());

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Entreprise::class, $entrepriseRepo]
        ]);

        // Expect ONLY OtpCode persist, NOT User persist
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(OtpCode::class));

        $this->mailer->expects($this->once())->method('send');
        $this->twig->method('render')->willReturn('<body>Content</body>');

        $this->otpService->generateAndSendOtp($user, $request);
    }

    public function testGenerateAndSendOtpThrowsExceptionIfUserNotFound(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(999);

        $request = new Request();

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->with(999)->willReturn(null);

        $this->em->method('getRepository')->with(User::class)->willReturn($userRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Impossible de générer OTP : l’utilisateur ID 999 n'existe pas pour ce tenant.");

        $this->otpService->generateAndSendOtp($user, $request);
    }
}
