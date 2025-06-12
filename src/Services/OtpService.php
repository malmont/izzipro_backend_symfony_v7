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
     * @param User   $user    L'utilisateur pour lequel générer l’OTP.
     *                        Doit soit déjà exister en base (avec un ID),
     *                        soit être une nouvelle entité à persister.
     * @param Request $request Pour obtenir le schéma et l'hôte (pour le domaine).
     * @return void
     *
     * @throws \RuntimeException Si on ne retrouve pas l’utilisateur existant dans ce tenant.
     */
    public function generateAndSendOtp(User $user, Request $request): void
    {
        // Récupérer l’EntityManager du tenant
        $em = $this->tenantEmProvider->getEntityManager();

        // Cas 1 : utilisateur existant (a déjà un ID)
        if ($user->getId() !== null) {
            // Recharger l’utilisateur dans le contexte du EM courant
            $userManaged = $em->getRepository(User::class)->find($user->getId());
            if (!$userManaged) {
                throw new \RuntimeException(sprintf(
                    "Impossible de générer OTP : l’utilisateur ID %s n'existe pas pour ce tenant.",
                    $user->getId()
                ));
            }
        } else {
            // Cas 2 : nouvel utilisateur à persister
            // Il faudra le persister avant de générer l’OTP
            $userManaged = $user;
            $em->persist($userManaged);
            // On peut flush ici ou attendre après la création de l’OTP selon besoins.
            // Si on flush maintenant, on s'assure que l’utilisateur a un ID valide :
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

        // Récupérer la configuration d’email depuis la BDD
        $emailConfig = $em->getRepository(EmailConfiguration::class)
            ->findOneBy([]);
        if (!$emailConfig) {
            $fromEmail = 'no-reply@votredomaine.com';
            $fromName  = 'Votre Société';
        } else {
            $fromEmail = $emailConfig->getFromEmail();
            $fromName  = $emailConfig->getFromName();
        }

        // Récupérer les informations de l’entreprise (supposons une seule entreprise)
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);

        // Construire le domaine pour le logo
        $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';

        // Préparer et envoyer l’email OTP avec le template Twig
        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($userManaged->getEmail())
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
