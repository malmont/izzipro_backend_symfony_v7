<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\StripeConfig;
use App\MemoiresVivantes\Entity\Book;
use App\Services\EmailConfigurationService\TenantMailerFactory;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class BookPaymentService
{
    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantMailerFactory $tenantMailerFactory,
        private readonly MailerInterface $defaultMailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir
    ) {}

    private function getEm(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }

    /**
     * Crée une session Stripe Checkout sur le compte connecté du tenant pour un livre donné.
     */
    public function createPaymentSession(
        Book $book,
        ?string $recipientEmail = null,
        ?float $amount = 49.00,
        ?string $currency = 'cad',
        ?string $successUrl = null,
        ?string $cancelUrl = null
    ): array {
        if (empty($this->stripeSecretKey)) {
            throw new \RuntimeException('La clé Stripe secrète (STRIPE_SECRET_KEY) n\'est pas configurée.');
        }

        $stripeConfig = $this->getEm()->getRepository(StripeConfig::class)->findOneBy([]);
        if (!$stripeConfig || !$stripeConfig->getAccountId() || !$stripeConfig->isActive()) {
            throw new \RuntimeException('Le compte Stripe du tenant n\'est pas encore connecté ou actif.');
        }

        Stripe::setApiKey($this->stripeSecretKey);

        $currency = strtolower($currency ?: 'cad');
        $amount = $amount ?: 49.00;
        $unitAmount = (int) round($amount * 100);

        $recipientEmail = $recipientEmail ?: ($book->getUser() ? $book->getUser()->getEmail() : null);

        $defaultHost = rtrim(
            $_ENV['MEMOIRES_FRONTEND_URL'] ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com')),
            '/'
        );
        $successUrl = $successUrl ?: ($defaultHost . '/payment-success?book_id=' . $book->getId());
        $cancelUrl = $cancelUrl ?: ($defaultHost . '/books/' . $book->getId());

        $sessionParams = [
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'invoice_creation' => [
                'enabled' => true,
            ],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Livre Relié - ' . ($book->getTitle() ?: 'Mon Livre'),
                        'description' => 'Commande et impression du livre relié (' . $book->getFormat() . ')',
                    ],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'book_id' => (string) $book->getId(),
                'tenant_code' => $this->connectionManager->getCurrentTenantCode() ?? 'memoiresvivantes',
                'recipient_email' => (string) $recipientEmail,
                'book_title' => (string) $book->getTitle(),
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];

        if (!empty($recipientEmail)) {
            $sessionParams['customer_email'] = $recipientEmail;
        }

        // Appel direct sur le compte Stripe Connect du tenant
        $session = Session::create($sessionParams, [
            'stripe_account' => $stripeConfig->getAccountId(),
        ]);

        // Mise à jour de l'entité Book
        $book->setPaymentStatus('pending');
        $book->setPaymentLinkUrl($session->url);
        $book->setPaymentAmount($amount);
        $book->setPaymentCurrency($currency);
        $book->setStripeSessionId($session->id);
        $this->getEm()->flush();

        return [
            'success' => true,
            'url' => $session->url,
            'session_id' => $session->id,
            'amount' => $amount,
            'currency' => $currency,
            'payment_status' => $book->getPaymentStatus(),
        ];
    }

    /**
     * Envoie par email le lien de paiement Stripe au client.
     */
    public function sendPaymentLinkEmail(
        Book $book,
        string $recipientEmail,
        string $paymentLinkUrl,
        ?float $amount = null,
        ?string $currency = 'cad',
        ?string $customMessage = null
    ): void {
        $emailConfig = $this->getEm()->getRepository(EmailConfiguration::class)->findOneBy([]);
        $entreprise = $this->getEm()->getRepository(Entreprise::class)->findOneBy([]);

        $fromEmail = $emailConfig?->getFromEmail()
            ?: ($entreprise?->getEmail() ?: 'contact@memoiresvivantes.com');

        $fromName = $emailConfig?->getFromName()
            ?: ($entreprise?->getName() ?: 'Mémoires Vivantes');

        // Résolution du logo (CID inline + fallback URL absolue)
        $logoFileName = $emailConfig?->getLogo();
        $logoPathOnDisk = null;
        $logoCid = null;
        $logoUrl = null;

        if (!empty($logoFileName)) {
            if (str_starts_with($logoFileName, 'http://') || str_starts_with($logoFileName, 'https://')) {
                $logoUrl = $logoFileName;
            } else {
                $localPath = rtrim($this->projectDir, '/') . '/public/assets/uploads/email-logos/' . ltrim($logoFileName, '/');
                if (file_exists($localPath) && is_readable($localPath)) {
                    $logoPathOnDisk = $localPath;
                    $logoCid = 'email_logo';
                }
                $publicBase = !empty($_ENV['STORAGE_PUBLIC_URL'])
                    ? rtrim($_ENV['STORAGE_PUBLIC_URL'], '/')
                    : ('https://' . ($_ENV['BACKEND_BASE_DOMAIN'] ?? 'backend-strapi.online'));
                $logoUrl = $publicBase . '/assets/uploads/email-logos/' . ltrim($logoFileName, '/');
            }
        }

        $currencySymbol = match (strtolower((string) $currency)) {
            'eur' => '€',
            'usd' => '$',
            'cad' => 'CAD $',
            default => strtoupper((string) $currency),
        };

        $html = $this->twig->render('emails/memoires_payment_link.html.twig', [
            'book' => $book,
            'recipientEmail' => $recipientEmail,
            'paymentLinkUrl' => $paymentLinkUrl,
            'amount' => $amount ?: $book->getPaymentAmount(),
            'currencySymbol' => $currencySymbol,
            'customMessage' => $customMessage,
            'fromName' => $fromName,
            'logoCid' => $logoCid,
            'logoUrl' => $logoUrl,
        ]);

        $mailer = ($emailConfig && $this->tenantMailerFactory)
            ? $this->tenantMailerFactory->createMailer($emailConfig)
            : $this->defaultMailer;

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($recipientEmail)
            ->subject('Votre lien de règlement pour votre livre « ' . $book->getTitle() . ' »')
            ->html($html);

        if ($logoPathOnDisk && $logoCid) {
            $email->embedFromPath($logoPathOnDisk, $logoCid);
        }

        $mailer->send($email);

        $this->logger->info(sprintf(
            '[BookPaymentService] Lien de paiement envoyé avec succès à %s pour le livre %s',
            $recipientEmail,
            $book->getId()
        ));
    }

    /**
     * Traite la notification de paiement réussi reçue via le Webhook Stripe.
     */
    public function handleCheckoutCompleted(string $sessionId, array $sessionData = []): ?Book
    {
        $bookRepo = $this->getEm()->getRepository(Book::class);
        $book = null;

        // 1. Recherche par metadata book_id
        $bookId = $sessionData['metadata']['book_id'] ?? null;
        if ($bookId) {
            try {
                $book = $bookRepo->find(\Symfony\Component\Uid\Uuid::fromString($bookId));
            } catch (\Throwable $e) {
                $this->logger->warning('[BookPaymentService] UUID invalide dans metadata: ' . $bookId);
            }
        }

        // 2. Recherche par stripeSessionId si non trouvé
        if (!$book) {
            $book = $bookRepo->findOneBy(['stripeSessionId' => $sessionId]);
        }

        if (!$book) {
            $this->logger->error('[BookPaymentService] Aucun livre correspondant trouvé pour la session: ' . $sessionId);
            return null;
        }

        // 3. Mise à jour de l'état du livre
        $book->setPaymentStatus('paid');
        $book->setStatus('paid');
        $book->setPaidAt(new \DateTimeImmutable());
        $this->getEm()->flush();

        $this->logger->info(sprintf(
            '[BookPaymentService] 🎉 Livre %s (%s) marqué comme PAYÉ avec succès !',
            $book->getId(),
            $book->getTitle()
        ));

        // 4. Envoi d'une notification email à l'administrateur
        try {
            $this->sendPaymentReceivedAdminEmail($book, $sessionData);
        } catch (\Throwable $e) {
            $this->logger->error('[BookPaymentService] Erreur lors de l\'envoi de l\'email de notification admin: ' . $e->getMessage());
        }

        // 5. Envoi de la facture acquittée par email au client
        try {
            $this->sendCustomerInvoiceEmail($book, $sessionData);
        } catch (\Throwable $e) {
            $this->logger->error('[BookPaymentService] Erreur lors de l\'envoi de la facture au client: ' . $e->getMessage());
        }

        return $book;
    }

    /**
     * Vérifie en direct auprès de Stripe si la session est payée et synchronise le statut du livre.
     */
    public function syncPaymentStatus(Book $book): void
    {
        if ($book->getPaymentStatus() === 'paid') {
            return;
        }

        $sessionId = $book->getStripeSessionId();
        if (!$sessionId) {
            return;
        }

        $stripeConfig = $this->getEm()->getRepository(StripeConfig::class)->findOneBy([]);
        if (!$stripeConfig || !$stripeConfig->getAccountId()) {
            return;
        }

        try {
            Stripe::setApiKey($this->stripeSecretKey);
            $session = Session::retrieve($sessionId, [
                'stripe_account' => $stripeConfig->getAccountId(),
            ]);

            if ($session && $session->payment_status === 'paid') {
                $this->logger->info(sprintf(
                    '[BookPaymentService] Session Stripe %s payée confirmée via Stripe API directe, synchro...',
                    $sessionId
                ));
                $this->handleCheckoutCompleted($sessionId, $session->toArray());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[BookPaymentService] Impossible de vérifier le statut Stripe en direct: ' . $e->getMessage());
        }
    }

    /**
     * Notifie Danielle ou l'administrateur qu'un paiement a été reçu.
     */
    private function sendPaymentReceivedAdminEmail(Book $book, array $sessionData): void
    {
        $emailConfig = $this->getEm()->getRepository(EmailConfiguration::class)->findOneBy([]);
        $entreprise = $this->getEm()->getRepository(Entreprise::class)->findOneBy([]);

        $adminEmail = $entreprise?->getEmail()
            ?: ($emailConfig?->getFromEmail() ?: 'contact@memoiresvivantes.com');

        $fromEmail = $emailConfig?->getFromEmail() ?: 'noreply@memoiresvivantes.com';
        $fromName = $emailConfig?->getFromName() ?: 'Mémoires Vivantes - Alertes';

        $customerEmail = $sessionData['customer_details']['email'] 
            ?? $sessionData['customer_email'] 
            ?? ($book->getUser() ? $book->getUser()->getEmail() : 'Client');

        $amountPaid = isset($sessionData['amount_total']) ? ($sessionData['amount_total'] / 100) : $book->getPaymentAmount();
        $currency = strtoupper($sessionData['currency'] ?? $book->getPaymentCurrency() ?? 'CAD');

        $html = $this->twig->render('emails/memoires_payment_received_admin.html.twig', [
            'book' => $book,
            'customerEmail' => $customerEmail,
            'amountPaid' => $amountPaid,
            'currency' => $currency,
            'paidAt' => $book->getPaidAt(),
            'fromName' => $fromName,
        ]);

        $mailer = ($emailConfig && $this->tenantMailerFactory)
            ? $this->tenantMailerFactory->createMailer($emailConfig)
            : $this->defaultMailer;

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($adminEmail)
            ->subject('🎉 Paiement reçu pour le livre « ' . $book->getTitle() . ' » (' . $amountPaid . ' ' . $currency . ')')
            ->html($html);

        $mailer->send($email);
    }

    /**
     * Envoie la facture acquittée au client avec logo et détails de l'achat.
     */
    private function sendCustomerInvoiceEmail(Book $book, array $sessionData): void
    {
        $customerEmail = $sessionData['customer_details']['email'] 
            ?? $sessionData['customer_email'] 
            ?? ($book->getUser() ? $book->getUser()->getEmail() : null);

        if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('[BookPaymentService] Aucun email client valide pour l\'envoi de la facture.');
            return;
        }

        $customerName = $sessionData['customer_details']['name']
            ?? ($book->getUser() ? ($book->getUser()->getFirstname() ? $book->getUser()->getFirstname() . ' ' . $book->getUser()->getLastname() : $book->getUser()->getUsername()) : null);

        $emailConfig = $this->getEm()->getRepository(EmailConfiguration::class)->findOneBy([]);
        $entreprise = $this->getEm()->getRepository(Entreprise::class)->findOneBy([]);

        $fromEmail = $emailConfig?->getFromEmail() 
            ?: ($entreprise?->getEmail() ?: 'contact@memoiresvivantes.com');

        $fromName = $emailConfig?->getFromName() 
            ?: ($entreprise?->getName() ?: 'Mémoires Vivantes');

        $companyAddress = $entreprise?->getAdress() ?: null;

        // Résolution du logo (CID inline + fallback URL absolue)
        $logoFileName = $emailConfig?->getLogo();
        $logoPathOnDisk = null;
        $logoCid = null;
        $logoUrl = null;

        if (!empty($logoFileName)) {
            if (str_starts_with($logoFileName, 'http://') || str_starts_with($logoFileName, 'https://')) {
                $logoUrl = $logoFileName;
            } else {
                $localPath = rtrim($this->projectDir, '/') . '/public/assets/uploads/email-logos/' . ltrim($logoFileName, '/');
                if (file_exists($localPath) && is_readable($localPath)) {
                    $logoPathOnDisk = $localPath;
                    $logoCid = 'invoice_logo';
                }
                $publicBase = !empty($_ENV['STORAGE_PUBLIC_URL'])
                    ? rtrim($_ENV['STORAGE_PUBLIC_URL'], '/')
                    : ('https://' . ($_ENV['BACKEND_BASE_DOMAIN'] ?? 'backend-strapi.online'));
                $logoUrl = $publicBase . '/assets/uploads/email-logos/' . ltrim($logoFileName, '/');
            }
        }

        $amountPaid = isset($sessionData['amount_total']) ? ($sessionData['amount_total'] / 100) : ($book->getPaymentAmount() ?: 49.00);
        $currency = strtoupper($sessionData['currency'] ?? $book->getPaymentCurrency() ?? 'CAD');
        $paidAt = $book->getPaidAt() ?: new \DateTimeImmutable();

        // Référence facture unique et propre
        $invoiceNumber = sprintf('FAC-%s-%s', $paidAt->format('Ymd'), strtoupper(substr((string) $book->getId(), 0, 8)));
        $frontendHost = rtrim(
            $_ENV['MEMOIRES_FRONTEND_URL'] ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com')),
            '/'
        );
        $bookUrl = sprintf('%s/books/%s', $frontendHost, $book->getId());

        $html = $this->twig->render('emails/memoires_invoice_client.html.twig', [
            'book' => $book,
            'customerEmail' => $customerEmail,
            'customerName' => $customerName,
            'amountPaid' => $amountPaid,
            'currency' => $currency,
            'paidAt' => $paidAt,
            'invoiceNumber' => $invoiceNumber,
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'companyAddress' => $companyAddress,
            'logoCid' => $logoCid,
            'logoUrl' => $logoUrl,
            'bookUrl' => $bookUrl,
        ]);

        $mailer = ($emailConfig && $this->tenantMailerFactory)
            ? $this->tenantMailerFactory->createMailer($emailConfig)
            : $this->defaultMailer;

        $email = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($customerEmail)
            ->subject('Facture acquittée pour votre commande « ' . $book->getTitle() . ' » (' . $invoiceNumber . ')')
            ->html($html);

        if ($logoPathOnDisk && $logoCid) {
            $email->embedFromPath($logoPathOnDisk, $logoCid);
        }

        $mailer->send($email);

        $this->logger->info(sprintf(
            '[BookPaymentService] Facture d\'achat envoyée avec succès au client %s pour le livre %s (%s)',
            $customerEmail,
            $book->getId(),
            $invoiceNumber
        ));
    }
}
