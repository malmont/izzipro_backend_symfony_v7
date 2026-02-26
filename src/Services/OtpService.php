<?php
// src/Service/OtpService.php

namespace App\Services;

use App\Entity\OtpCode;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\User;
use DateTime;
use Symfony\Component\HttpFoundation\Request;
use App\Services\TenantEntityManagerProvider;
use App\Services\EmailConfigurationService\EmailSenderService;

class OtpService
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private EmailSenderService $emailSenderService;

    public function __construct(
        TenantEntityManagerProvider $tenantEmProvider,
        EmailSenderService $emailSenderService
    ) {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->emailSenderService = $emailSenderService;
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

        $baseUrl = $request->getSchemeAndHttpHost();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);

        $this->emailSenderService->sendTemplatedEmail(
            $userManaged->getEmail(),
            'Votre code OTP',
            'security/2fa_email.html.twig',
            [
                'code'       => $otp,
                'lifetime'   => 300,
                'entreprise' => $entreprise,
                'domain'     => '',
            ],
            $locale,
            $baseUrl
        );
    }
}
