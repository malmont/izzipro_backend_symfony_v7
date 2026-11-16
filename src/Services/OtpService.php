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
    public function generateAndSendOtp(User $user, Request $request, string $purpose = OtpCode::PURPOSE_LOGIN): void
    {
        $em = $this->tenantEmProvider->getEntityManager();

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

        // Un nouveau code invalide les codes non utilisés du même compte et du même usage (01/11/2026)
        foreach ($em->getRepository(OtpCode::class)->findBy(['userOtp' => $userManaged, 'purpose' => $purpose]) as $previous) {
            $em->remove($previous);
        }

        // Générer un OTP à 6 chiffres
        $otp = random_int(100000, 999999);

        // Créer et sauvegarder l’entité OtpCode
        $otpCode = new OtpCode();
        $otpCode->setUserOtp($userManaged);
        $otpCode->setPurpose($purpose);
        $otpCode->setCode((string)$otp);
        $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));

        $em->persist($otpCode);
        $em->flush();

        $baseUrl = $request->getSchemeAndHttpHost();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);

        $context = [
            'code'       => $otp,
            'lifetime'   => 300,
            'entreprise' => $entreprise,
            'domain'     => '',
        ];
        // Serveur d'envoi du site ; à défaut (site sans configuration d'envoi, ou envoi en échec), serveur de la
        // plateforme : sans code, l'administrateur ne pourrait plus se connecter. Langue du client (03/11/2026) : `?locale=`
        // (fr, en), sinon celle du site ; avant, $request->getLocale() valait toujours `en` (aucun écouteur ne le fixe)
        // Train de sécurité du 16/11/2026 : le code du COMPTE DE LA PLATEFORME part par le serveur de la PLATEFORME, jamais par le
        // serveur d'envoi du site. Ce serveur-là est réglé par l'administrateur du site : en le pointant vers une machine à lui, il
        // aurait reçu le code de connexion du compte de la plateforme, qui n'aurait plus été un second verrou.
        if (\App\Services\Platform\PlatformAccessJournal::isPlatformAccount($userManaged->getRoles())) {
            $language = $request->query->get('locale') === 'en' ? 'en' : 'fr';
            $this->emailSenderService->sendPlatformEmail($userManaged->getEmail(), $this->emailSenderService->subject('otp.subject_platform', [], $language), 'security/2fa_email.html.twig',
                $context + ['locale' => $language, 'intlLocale' => $language]);

            return;
        }
        $this->emailSenderService->sendCustomerEmail($userManaged->getEmail(), 'otp.subject', [], 'security/2fa_email.html.twig', $context, $request->query->get('locale'), $baseUrl, 'otp.subject_platform');
    }
}
