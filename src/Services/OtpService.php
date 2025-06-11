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
use App\Services\TenantEntityManagerProvider; // Ajout

class OtpService
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private MailerInterface $mailer;
    private Environment $twig;

    public function __construct(
        TenantEntityManagerProvider $tenantEmProvider,
        MailerInterface $mailer,
        Environment $twig
    ) {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->mailer        = $mailer;
        $this->twig          = $twig;
    }

    /**
     * Génère un code OTP pour un utilisateur, persiste l'entité OtpCode
     * et envoie l'email avec le code OTP.
     *
     * @param User   $user    L'utilisateur pour lequel générer l'OTP.
     * @param Request $request Pour obtenir le schéma et l'hôte (pour le domaine).
     * @return void
     */
    public function generateAndSendOtp(User $user, Request $request): void
    {
        // Générer un OTP à 6 chiffres
        $otp = random_int(100000, 999999);

        // Créer et sauvegarder l'entité OtpCode
        $otpCode = new OtpCode();
        $otpCode->setUserOtp($user);
        $otpCode->setCode((string)$otp);
        $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));

        // Utilisation de l'EM multi-tenant
        $em = $this->tenantEmProvider->getEntityManager();
        $em->persist($otpCode);
        $em->flush();

        // Récupérer la configuration d'email depuis la BDD
        $emailConfig = $em->getRepository(EmailConfiguration::class)
            ->findOneBy([]);
        if (!$emailConfig) {
            $fromEmail = 'no-reply@votredomaine.com';
            $fromName  = 'Votre Société';
        } else {
            $fromEmail = $emailConfig->getFromEmail();
            $fromName  = $emailConfig->getFromName();
        }

        // Récupérer les informations de l'entreprise (supposons une seule entreprise)
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);

        // Construire le domaine pour le logo
        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';

        // Préparer et envoyer l'email OTP avec le template Twig enrichi
        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Votre code OTP')
            ->html(
                $this->twig->render('security/2fa_email.html.twig', [
                    'code'       => $otp,
                    'lifetime'   => 300, // 5 minutes en secondes
                    'entreprise' => $entreprise,
                    'domain'     => $domain,
                ])
            );
        $this->mailer->send($emailMessage);
    }
}
