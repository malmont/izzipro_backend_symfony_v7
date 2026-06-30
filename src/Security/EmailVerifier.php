<?php

namespace App\Security;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifier
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private VerifyEmailHelperInterface $verifyEmailHelper;
    private MailerInterface $mailer;

    public function __construct(
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer,
        TenantEntityManagerProvider $tenantEmProvider
    ) {
        $this->verifyEmailHelper = $verifyEmailHelper;
        $this->mailer = $mailer;
        $this->tenantEmProvider = $tenantEmProvider;
    }

    public function sendEmailConfirmation(string $verifyEmailRouteName, User $user, TemplatedEmail $email): void
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()]
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email->context($context);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());

        $user->setIsVerified(true);

        // Utilisation du provider multi-tenant à chaque appel
        $em = $this->tenantEmProvider->getEntityManager();
        $em->persist($user);
        $em->flush();
    }
}
