<?php
// src/Service/OtpService.php

namespace App\Services;

use App\Entity\OtpCode;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\User;
use DateTime;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Symfony\Component\HttpFoundation\Request;
use App\Services\TenantEntityManagerProvider;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailLogoHelper;

class OtpService
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private MailerInterface $mailer;
    private Environment $twig;
    private EmailConfigurationService $emailConfigService;
    private EmailLogoHelper $emailLogoHelper;


    public function __construct(
        TenantEntityManagerProvider $tenantEmProvider,
        MailerInterface $mailer,
        Environment $twig,
        EmailConfigurationService $emailConfigService,
        EmailLogoHelper $emailLogoHelper
    ) {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->mailer        = $mailer;
        $this->twig          = $twig;
        $this->emailConfigService = $emailConfigService;
        $this->emailLogoHelper = $emailLogoHelper;
    }

    /**
     * Génère un code OTP pour un utilisateur, persiste l'entité OtpCode
     * et envoie l'email avec le code OTP.
     *
     * @param User   $user    L'utilisateur pour lequel générer l’OTP.
     * Doit soit déjà exister en base (avec un ID),
     * soit être une nouvelle entité à persister.
     * @param Request $request Pour obtenir la locale et le domaine.
     * @return void
     *
     * @throws \RuntimeException Si on ne retrouve pas l’utilisateur existant dans ce tenant.
     */
    public function generateAndSendOtp(User $user, Request $request): void
    {
        $em = $this->tenantEmProvider->getEntityManager();
        $locale = $request->getLocale(); // On récupère la locale

        // Cas 1 : utilisateur existant (a déjà un ID)
        if ($user->getId() !== null) {
            $userManaged = $em->getRepository(User::class)->find($user->getId());
            if (!$userManaged) {
                throw new \RuntimeException(sprintf(
                    "Impossible de générer OTP : l’utilisateur ID %s n'existe pas pour ce tenant.",
                    $user->getId()
                ));
            }
        } else {
            // Cas 2 : nouvel utilisateur à persister
            $userManaged = $user;
            $em->persist($userManaged);
            $em->flush();
        }

        // Générer un OTP à 6 chiffres
        $otp = random_int(100000, 999999);

        // Créer et sauvegarder l’entité OtpCode
        $otpCode = new OtpCode();
        $otpCode->setUserOtp($userManaged);
        $otpCode->setCode((string)$otp);
        $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));

        $em->persist($otpCode);
        $em->flush();

        // On récupère la configuration et sa traduction
        $emailConfig = $this->emailConfigService->findOneByLocale($locale);
        $translation = $emailConfig ? $emailConfig->getTranslation($locale) : null;

        $fromEmail = $emailConfig?->getFromEmail() ?? 'no-reply@votredomaine.com';
        $fromName  = $translation?->getFromName()  ?? ($emailConfig?->getFromName() ?? 'Votre Société');
        $signature = $translation?->getSignature() ?? '';

        $baseUrl = $request->getSchemeAndHttpHost();
        $logoUrl = $this->emailLogoHelper->getLogoUrl($emailConfig, $baseUrl);

        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $domain = '';
        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($userManaged->getEmail())
            ->subject('Votre code OTP')
            ->html(
                $this->twig->render('security/2fa_email.html.twig', [
                    'code'       => $otp,
                    'lifetime'   => 300,
                    'entreprise' => $entreprise,
                    'domain'     => $domain,
                    'fromName'   => $fromName,
                    'signature'  => $signature,
                    'logoUrl'    => $logoUrl,
                ])
            );
        $this->mailer->send($emailMessage);
    }
}
