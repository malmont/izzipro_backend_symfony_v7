<?php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\EmailConfiguration;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\EmailConfigurationService\EmailLogoHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;

class ResetPasswordController extends AbstractController
{
    public function __construct(
        private TenantEntityManagerProvider $tenantEmProvider,
        private TenantConnectionManager $tenantManager,
        private EmailSenderService $emailSenderService,
        private LoggerInterface $logger,
        private EmailLogoHelper $emailLogoHelper
    ) {}

    // --- ÉTAPE 1 : DEMANDE DE RESET (API POST) ---
    #[Route('/api/password-reset/request', name: 'app_password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(
        Request $request,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse {
        $locale = $request->query->get('locale', $request->getLocale());
        $data = json_decode($request->getContent(), true);
        $emailInput = $data['email'] ?? null;

        if (!$emailInput) {
            return $this->json(['error' => 'Email is required.'], Response::HTTP_BAD_REQUEST);
        }

        // 1. Détection du Host Client (Frontend)
        $clientHost = $request->headers->get('x-tenant-host');
        if (!$clientHost) {
            $origin = $request->headers->get('origin') ?? $request->headers->get('referer');
            if ($origin) {
                $clientHost = parse_url($origin, PHP_URL_HOST);
            }
        }
        // Fallback
        if (!$clientHost) {
            $clientHost = $request->getHttpHost();
        }

        // 2. Bascule sur le bon Tenant pour trouver l'user
        if ($clientHost) {
            try {
                $tenantConfig = $this->tenantManager->findTenantConfigByHost($clientHost);
                if ($tenantConfig) {
                    $this->tenantManager->switchToTenant($tenantConfig);
                }
            } catch (\Exception $e) {
                // On log mais on continue (au cas où on serait sur le default)
                $this->logger->warning("[Pwd Reset] Tenant non trouvé pour host: " . $clientHost);
            }
        }

        // 3. Recherche User
        $em = $this->tenantEmProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $emailInput]);

        if (!$user) {
            return $this->json(['message' => 'Link sent if email exists.']);
        }

        // 4. Génération Token
        $resetToken = bin2hex(random_bytes(32));
        $user->setResetToken($resetToken);
        $user->setResetTokenExpiresAt(new DateTimeImmutable('+1 hour'));
        $em->flush();

        // 5. Génération URL (avec tenant_host pour que le lien cliquable sache où aller)
        $resetUrl = $urlGenerator->generate(
            'app_password_reset_confirm_form',
            ['token' => $resetToken, 'tenant_host' => $clientHost],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // CORRECTION ASSETS : Toujours utiliser le domaine du Backend (API)
        $baseUrl = $request->getSchemeAndHttpHost();

        $this->emailSenderService->sendTemplatedEmail(
            $user->getEmail(),
            'Password Reset',
            'reset_password/reset.html.twig',
            [
                'resetUrl'  => $resetUrl,
                'user'      => $user,
            ],
            $locale,
            $baseUrl
        );

        return $this->json(['message' => 'Link sent if email exists.']);
    }

    // --- ÉTAPE 2 : FORMULAIRE D'AFFICHAGE (GET) ---
    #[Route('/password-reset/form', name: 'app_password_reset_confirm_form', methods: ['GET'])]
    public function resetPasswordForm(Request $request): Response
    {
        $token = $request->query->get('token');
        $targetHost = $request->query->get('tenant_host');
        $locale = $request->getLocale();

        // 1. Switch de base de données (Tenant)
        if ($targetHost) {
            try {
                $tenantConfig = $this->tenantManager->findTenantConfigByHost($targetHost);
                if ($tenantConfig) {
                    $this->tenantManager->switchToTenant($tenantConfig);
                }
            } catch (\Exception $e) {
                $this->logger->error("[Pwd Form] Erreur switch tenant : " . $e->getMessage());
            }
        }

        // 2. Récupération Config (Via Repository direct pour bypasser le cache du service)
        $em = $this->tenantEmProvider->getEntityManager();
        /** @var EmailConfiguration|null $emailConfig */
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);

        // 3. Traduction
        $translation = ($emailConfig) ? $emailConfig->getTranslation($locale) : null;

        $fromName  = $emailConfig?->getFromName() ?? 'Support';
        $signature = $translation?->getSignature() ?? '';
        $fromEmail = $emailConfig?->getFromEmail() ?? 'no-reply@gem-portal.com';

        // 4. CORRECTION ASSETS : On force le domaine du backend
        $baseUrl = $request->getSchemeAndHttpHost();
        $logoUrl = $this->emailLogoHelper->getLogoUrl($emailConfig, $baseUrl);

        return $this->render('reset_password/form.html.twig', [
            'token'       => $token,
            'tenant_host' => $targetHost,
            'fromName'    => $fromName,
            'signature'   => $signature,
            'logoUrl'     => $logoUrl,
            'fromEmail'   => $fromEmail,
            'domain'      => '',
        ]);
    }

    // --- ÉTAPE 3 : CONFIRMATION DU CHANGEMENT (POST) ---
    #[Route('/password-reset/confirm', name: 'app_password_reset_confirm', methods: ['POST'])]
    public function confirmPasswordReset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $content = json_decode($request->getContent(), true);

        $token = $content['token'] ?? $request->request->get('token');
        $newPassword = $content['newPassword'] ?? $request->request->get('newPassword') ?? $request->request->get('password');
        $targetHost = $request->query->get('tenant_host');

        // 1. Switch Tenant
        if ($targetHost) {
            try {
                $tenantConfig = $this->tenantManager->findTenantConfigByHost($targetHost);
                if ($tenantConfig) {
                    $this->tenantManager->switchToTenant($tenantConfig);
                }
            } catch (\Exception $e) {
                $this->logger->error("[Pwd Confirm] Erreur switch tenant : " . $e->getMessage());
            }
        }

        if (!$token || !$newPassword) {
            return $this->json(['error' => 'Missing data'], 400);
        }

        $em = $this->tenantEmProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['resetToken' => $token]);

        if (!$user || $user->getResetTokenExpiresAt() < new \DateTime()) {
            return $this->json(['error' => 'Invalid or expired token.'], 400);
        }

        // Hash & Save
        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $em->flush();

        // 2. Récupération Config (Via Repository direct)
        /** @var EmailConfiguration|null $emailConfig */
        $emailConfig = $em->getRepository(EmailConfiguration::class)->findOneBy([]);

        $translation = ($emailConfig) ? $emailConfig->getTranslation($request->getLocale()) : null;

        // CORRECTION ASSETS
        $baseUrl = $request->getSchemeAndHttpHost();
        $logoUrl = $this->emailLogoHelper->getLogoUrl($emailConfig, $baseUrl);

        $fromName = $emailConfig?->getFromName() ?? 'Support';
        $signature = $translation?->getSignature() ?? '';

        if ($content) {
            return $this->json(['message' => 'Success']);
        }

        return $this->render('reset_password/success.html.twig', [
            'message'   => 'Password reset successfully.',
            'domain'    => '',
            'fromName'  => $fromName,
            'signature' => $signature,
            'logoUrl'   => $logoUrl,
            'fromEmail' => $emailConfig?->getFromEmail(),
            'user'      => $user
        ]);
    }
}
