<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\EmailConfiguration;
use App\Services\TenantEntityManagerProvider; // Ajouté
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ResetPasswordController extends AbstractController
{
    private TenantEntityManagerProvider $tenantEmProvider;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider)
    {
        $this->tenantEmProvider = $tenantEmProvider;
    }

    #[Route('/api/password-reset/request', name: 'app_password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(
        Request $request,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $locale = $request->getLocale();


        $data = json_decode($request->getContent(), true);
        if (!isset($data['email'])) {
            return $this->json(['error' => 'Email is required.'], Response::HTTP_BAD_REQUEST);
        }

        $emailInput = $data['email'];
        $em = $this->tenantEmProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $emailInput]);

        if (!$user) {
            return $this->json(['message' => 'If your email exists in our system, you will receive a password reset link.']);
        }

        $resetToken = bin2hex(random_bytes(32));
        $user->setResetToken($resetToken);
        $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
        $em->persist($user);
        $em->flush();

        $resetUrl = $urlGenerator->generate(
            'app_password_reset_confirm_form',
            ['token' => $resetToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );


        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
        $translation = $emailConfig ? $emailConfig->getTranslation($locale) : null;
        
        $fromEmail = $emailConfig?->getFromEmail() ?? 'no-reply@votredomaine.com';
        $fromName  = $translation?->getFromName()  ?? ($emailConfig?->getFromName() ?? 'Votre Société');
        $signature = $translation?->getSignature() ?? '';
        $logoUrl   = $emailConfig?->getLogo();


        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';


        $emailContent = $this->renderView('reset_password/reset.html.twig', [
            'resetUrl'    => $resetUrl,
            'user'        => $user,
            'fromName'    => $fromName, // On passe les variables traduites
            'signature'   => $signature,
            'logoUrl'     => $logoUrl,
            'domain'      => $domain,
        ]);


        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe')
            ->html($emailContent);

        $mailer->send($emailMessage);

        return $this->json([
            'message' => 'If your email exists in our system, you will receive a password reset link.'
        ], Response::HTTP_CREATED);
    }

    #[Route('/password-reset/confirm', name: 'app_password_reset_confirm', methods: ['POST'])]
    public function confirmPasswordReset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $locale = $request->getLocale();
        $data = json_decode($request->getContent(), true) ?: $request->request->all();

        if (!isset($data['token'], $data['newPassword'])) {
            return $this->json(['error' => 'Token and new password are required.'], Response::HTTP_BAD_REQUEST);
        }

        $token = $data['token'];
        $newPassword = $data['newPassword'];
        $em = $this->tenantEmProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if (!$user) {
            return $this->json(['error' => 'Invalid token.'], Response::HTTP_BAD_REQUEST);
        }

        if ($user->getResetTokenExpiresAt() < new \DateTime()) {
            return $this->json(['error' => 'The token has expired.'], Response::HTTP_BAD_REQUEST);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $em->persist($user);
        $em->flush();

        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
        $translation = $emailConfig ? $emailConfig->getTranslation($locale) : null;
        $fromName  = $translation?->getFromName()  ?? ($emailConfig?->getFromName() ?? 'Votre Société');
        $signature = $translation?->getSignature() ?? '';
        $logoUrl   = $emailConfig?->getLogo();

        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';


        return $this->render('reset_password/success.html.twig', [
            'message' => 'Password reset successfully.',
            'domain' => $domain,
            'fromName' => $fromName,
            'signature' => $signature,
            'logoUrl' => $logoUrl,
            'fromEmail' => $fromEmail,
            'user' => $user
        ]);
    }

    #[Route('/password-reset/form', name: 'app_password_reset_confirm_form', methods: ['GET'])]
    public function resetPasswordForm(Request $request): Response
    {

        $locale = $request->getLocale();

        $token = $request->query->get('token');
        $em = $this->tenantEmProvider->getEntityManager();
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);
        $translation = $emailConfig ? $emailConfig->getTranslation($locale) : null;
        $fromName  = $translation?->getFromName()  ?? ($emailConfig?->getFromName() ?? 'Votre Société');
        $signature = $translation?->getSignature() ?? '';
        $logoUrl   = $emailConfig?->getLogo();


        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';

        return $this->render('reset_password/form.html.twig', [
            'token' => $token,
            'fromName' => $fromName,
            'signature' => $signature,
            'logoUrl' => $logoUrl,
            'fromEmail' => $fromEmail,
            'domain' => $domain,
        ]);
    }
}