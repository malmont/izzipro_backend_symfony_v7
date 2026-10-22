<?php

namespace App\Services\PosAuditService;

use App\Entity\PosReceipt;
use App\Services\AppProfileService\AppProfileService;
use App\Services\CaisseService\CashRegisterException;
use App\Services\EmailConfigurationService\EmailConfigurationService;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\TenantConnectionManager;
use App\Services\TenantPublicHostResolver;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Reçu numérique (F3, 24/10/2026) : rendu HTML (page publique, courriel) et PDF (rouleau 80 mm, dompdf), envoi par le
 * système de courriel du site (SMTP du site, file « email »), au nom et aux couleurs du profil d'application « pos ».
 * L'adresse du destinataire n'est jamais conservée : elle ne transite que dans la file d'envoi.
 */
class PosReceiptMailer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly EmailSenderService $emails,
        private readonly EmailConfigurationService $emailConfigs,
        private readonly AppProfileService $profiles,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
    ) {
    }

    /** Profil « pos » du site (nom, logo, couleur), hôte public du site */
    public function profile(string $locale = 'fr'): array
    {
        $host = $this->host();

        return $this->profiles->resolve('pos', $host, $locale) + ['host' => $host];
    }

    public function host(): string
    {
        $code = (string) $this->tenants->getCurrentTenantCode();

        return $code !== '' ? ((string) ($this->hosts->describe($code)['host'] ?? $code)) : 'localhost';
    }

    public function html(array $receipt, array $profile, bool $duplicate, string $title = 'Reçu'): string
    {
        return $this->twig->render('pos/receipt_public.html.twig', ['receipt' => $receipt, 'profile' => $profile, 'duplicate' => $duplicate, 'title' => $title]);
    }

    public function pdf(array $receipt, array $profile, bool $duplicate): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $profile['logo'] = null; // pas d'image distante dans le PDF
        $dompdf->loadHtml($this->twig->render('pos/receipt_pdf.html.twig', ['receipt' => $receipt, 'profile' => $profile, 'duplicate' => $duplicate]));
        $dompdf->setPaper([0, 0, 226.77, 1200], 'portrait'); // 80 mm de large
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @throws CashRegisterException EMAIL_NOT_CONFIGURED (503) si le site n'a pas de configuration d'envoi, EMAIL_SEND_FAILED (502) */
    public function send(PosReceipt $receipt, array $block, string $to, string $locale, bool $duplicate): void
    {
        if ($this->emailConfigs->findOneByLocale($locale) === null && $this->emailConfigs->findOneByLocale('fr') === null) {
            throw new CashRegisterException('EMAIL_NOT_CONFIGURED', 'Ce site n\'a pas de configuration d\'envoi de courriels : reçu par courriel indisponible', 503);
        }
        $profile = $this->profile($locale);
        $number = $block['receiptReference'] ?? $block['receiptNumber'];
        $subject = sprintf('%s · %s n° %s', $profile['name'] ?? $block['store']['name'] ?? 'Reçu', $receipt->getKind() === PosReceipt::CREDIT_NOTE ? 'Avoir' : 'Reçu', $number);
        $pdf = $this->pdf($block, $profile, $duplicate);
        $sent = $this->emails->sendTemplatedEmail($to, $subject, 'emails/pos_receipt.html.twig', ['receipt' => $block, 'profile' => $profile, 'duplicate' => $duplicate], $locale, $profile['host'],
            $profile['name'] ?? null, null, [['name' => sprintf('recu-%s.pdf', preg_replace('/[^A-Za-z0-9-]/', '', (string) $number)), 'body' => $pdf, 'contentType' => 'application/pdf']]);
        if (!$sent) {
            throw new CashRegisterException('EMAIL_SEND_FAILED', 'Envoi du reçu impossible pour l\'instant, réessayez', 502);
        }
    }

    /** Adresse masquée pour le journal : première lettre, étoiles, domaine (jamais l'adresse complète ni d'empreinte) */
    public static function mask(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }
}
