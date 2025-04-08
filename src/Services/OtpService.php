<?php
// src/Service/OtpService.php

namespace App\Services;

use App\Entity\OtpCode;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Symfony\Component\HttpFoundation\Request;

class OtpService
{
    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;
    private Environment $twig;

    public function __construct(
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        Environment $twig
    ) {
        $this->entityManager = $entityManager;
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
        // En fonction de votre entité, utilisez ici setUserOtp() ou setUser()
        $otpCode->setUserOtp($user);
        $otpCode->setCode((string)$otp);
        // Le code est valable 5 minutes
        $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));

        $this->entityManager->persist($otpCode);
        $this->entityManager->flush();

        // Récupérer la configuration d'email depuis la BDD
        $emailConfig = $this->entityManager
            ->getRepository(EmailConfiguration::class)
            ->findOneBy([]);
        if (!$emailConfig) {
            $fromEmail = 'no-reply@votredomaine.com';
            $fromName  = 'Votre Société';
        } else {
            $fromEmail = $emailConfig->getFromEmail();
            $fromName  = $emailConfig->getFromName();
        }

        // Récupérer les informations de l'entreprise (supposons une seule entreprise)
        $entreprise = $this->entityManager
            ->getRepository(Entreprise::class)
            ->findOneBy([]);

        // Construire le domaine pour le logo, par exemple : https://backend-strapi.online/assets/uploads/email-logos/
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
