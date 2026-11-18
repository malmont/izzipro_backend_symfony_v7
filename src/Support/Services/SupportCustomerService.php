<?php

namespace App\Support\Services;

use App\Entity\Order;
use App\Entity\User;
use App\Security\AppFamilies;
use App\Services\CaisseService\SiteLocaleResolver;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\FeatureAccess\ModuleAccessService;
use App\Services\Platform\PlatformModeService;
use App\Services\TenantEntityManagerProvider;
use App\Support\Entity\SupportAttachment;
use App\Support\Entity\SupportMessage;
use App\Support\Entity\SupportTicket;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * « Aide et support » des applications du client (lot AIDE-APP, 18/11/2026, `docs/assistance.md`) : le client connecté de Living Memory
 * ou du commerce mobile envoie une demande, qui tombe dans la boîte de l'Assistance du site (canal `app`, application d'origine lue dans
 * le jeton, catégorie) ; il suit SES demandes (état simplifié, point « réponse non lue »), lit le fil sans les notes internes et répond.
 * Aucune donnée personnelle au-delà de son compte (nom, courriel) et de ce qu'il écrit ou joint.
 */
class SupportCustomerService
{
    public const PER_ACCOUNT_DAILY = 5;
    public const OPEN_MAX = 3;
    public const PER_SITE_DAILY = 200;
    public const SUBJECT_MIN = 3;
    public const SUBJECT_MAX = 150;
    public const MESSAGE_MIN = 10;
    public const MESSAGE_MAX = 5000;
    /** État simplifié lu par le client */
    private const CUSTOMER_STATUS = ['new' => 'in_progress', 'open' => 'in_progress', 'waiting_customer' => 'answered', 'closed' => 'closed'];

    private RateLimiterFactory $sends;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SupportSettingsService $settings,
        private readonly SupportAttachmentStorage $storage,
        private readonly SupportTicketService $tickets,
        private readonly ContentAuditRecorder $audit,
        private readonly ModuleAccessService $modules,
        private readonly PlatformModeService $platform,
        private readonly SiteLocaleResolver $locales,
        #[Autowire(service: 'cache.rate_limiter')] CacheItemPoolInterface $pool,
    ) {
        $this->sends = new RateLimiterFactory(['id' => 'support_customer_send', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'], new CacheStorage($pool));
    }

    /** @return array{available: bool, reason: ?string} le module Assistance est-il ouvert sur ce site ? (l'application cache l'écran sinon) */
    public function availability(): array
    {
        $open = $this->platform->isDemo() || $this->modules->isEnabled(ModuleAccessService::SUPPORT);

        return ['available' => $open, 'reason' => $open ? null : 'MODULE_INACTIVE'];
    }

    /**
     * @param array<string, mixed>  $body  `{ subject, message, category, orderId?, appVersion?, locale? }`
     * @param list<UploadedFile>    $files
     *
     * @return array{request: array<string, mixed>}
     */
    public function create(User $customer, array $body, array $files, ?string $app): array
    {
        $this->throttle($customer);
        $errors = [];
        $subject = is_string($body['subject'] ?? null) ? trim($body['subject']) : '';
        if ($subject === '') {
            $errors['subject'] = 'REQUIRED';
        } elseif (mb_strlen($subject) < self::SUBJECT_MIN || mb_strlen($subject) > self::SUBJECT_MAX) {
            $errors['subject'] = 'INVALID_LENGTH';
        }
        $text = is_string($body['message'] ?? null) ? trim($body['message']) : '';
        if ($text === '') {
            $errors['message'] = 'REQUIRED';
        } elseif (mb_strlen($text) < self::MESSAGE_MIN || mb_strlen($text) > self::MESSAGE_MAX) {
            $errors['message'] = 'INVALID_LENGTH';
        }
        $category = $body['category'] ?? null;
        if ($category === null || $category === '') {
            $errors['category'] = 'REQUIRED';
        } elseif (!in_array($category, SupportTicket::CATEGORIES, true)) {
            $errors['category'] = 'INVALID_VALUE';
        }
        $em = $this->emProvider->getEntityManager();
        $orderId = $body['orderId'] ?? null;
        if (is_string($orderId) && ctype_digit($orderId)) {
            $orderId = (int) $orderId;
        }
        if ($orderId !== null && $orderId !== '') {
            $order = is_int($orderId) ? $em->find(Order::class, $orderId) : null;
            if ($order === null || $order->getUserId()?->getId() !== $customer->getId()) {
                $errors['orderId'] = 'ORDER_NOT_FOUND'; // la commande d'un autre client se refuse comme une commande inconnue
            }
        } else {
            $orderId = null;
        }
        $version = $body['appVersion'] ?? null;
        if ($version !== null && $version !== '' && (!is_string($version) || !preg_match('/^[\w.+\-]{1,40}$/', $version))) {
            $errors['appVersion'] = 'INVALID_VALUE';
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && $locale !== '' && !in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'] = 'INVALID_LOCALE';
        }
        $mimes = $this->checkedFiles($files, $errors);
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        $conn = $em->getConnection();
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        if ((int) $conn->fetchOne("SELECT COUNT(*) FROM support_ticket WHERE customer_user_id = ? AND channel = 'app' AND created_at >= ?", [$customer->getId(), $today]) >= self::PER_ACCOUNT_DAILY) {
            throw new SupportException('TOO_MANY_REQUESTS_TODAY', 'Trop de demandes aujourd\'hui : réessayez demain.', 429, ['scope' => 'account']);
        }
        if ((int) $conn->fetchOne("SELECT COUNT(*) FROM support_ticket WHERE customer_user_id = ? AND status <> 'closed'", [$customer->getId()]) >= self::OPEN_MAX) {
            throw new SupportException('TOO_MANY_OPEN_REQUESTS', 'Vous avez déjà plusieurs demandes en cours : répondez-y ou attendez leur réponse.', 409, ['max' => self::OPEN_MAX]);
        }
        if ((int) $conn->fetchOne("SELECT COUNT(*) FROM support_ticket WHERE channel = 'app' AND created_at >= ?", [$today]) >= self::PER_SITE_DAILY) {
            throw new SupportException('TOO_MANY_REQUESTS_TODAY', 'Trop de demandes aujourd\'hui sur cette boutique : réessayez demain.', 429, ['scope' => 'site']);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $name = trim($customer->getFirstname() . ' ' . $customer->getLastname()) ?: null;
        $source = AppFamilies::of($app);
        $ticket = (new SupportTicket())->setReference('AS-' . bin2hex(random_bytes(6)))->setSubject($subject)->setChannel('app')->setPriority('normal')
            ->setCustomerUserId($customer->getId())->setCustomerName($name)->setCustomerEmail($customer->getEmail())
            ->setCustomerLocale(is_string($locale) && $locale !== '' ? $locale : (in_array($this->locales->siteLocale(), ['fr', 'en'], true) ? $this->locales->siteLocale() : 'fr'))
            ->setOrderId($orderId)->setCreatedBy('client')->setDueAt($this->settings->due($now))->setLastMessageAt($now)->setLastCustomerMessageAt($now)
            ->setSourceApp(in_array($source, [AppFamilies::LIVINGMEMORY, AppFamilies::BOUTIQUE], true) ? $source : null)->setCategory((string) $category);
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference(sprintf('AS-%06d', (int) $ticket->getId()));
        $suffix = is_string($version) && $version !== '' ? "\n\n(version de l'application : $version)" : '';
        $message = (new SupportMessage())->setTicket($ticket)->setAuthorType('customer')->setAuthorName($name ?? $customer->getEmail())->setBody($text . $suffix)->setCreatedAt($now);
        $em->persist($message);
        $em->flush();
        $this->attach($ticket, $message, $files, $mimes, $now);
        $em->flush();
        $this->audit->record('support_ticket', (int) $ticket->getId(), 'create', null, ['reference' => $ticket->getReference(), 'channel' => 'app', 'sourceApp' => $ticket->getSourceApp(), 'category' => $category], ['reference', 'channel', 'sourceApp', 'category']);

        return ['request' => $this->summary($ticket)];
    }

    /** @return array{requests: list<array<string, mixed>>} les demandes du client connecté, les plus récentes d'abord */
    public function mine(User $customer): array
    {
        $repo = $this->emProvider->getEntityManager()->getRepository(SupportTicket::class);
        $tickets = $repo->findBy(['customerUserId' => $customer->getId(), 'anonymizedAt' => null], ['id' => 'DESC'], 50);

        return ['requests' => array_map(fn (SupportTicket $t): array => $this->summary($t), $tickets)];
    }

    /** Le fil d'une demande du client, sans les notes internes ; la lecture remet `unread` à faux */
    public function detail(User $customer, string $reference): array
    {
        $ticket = $this->own($customer, $reference);
        $em = $this->emProvider->getEntityManager();
        $messages = [];
        foreach ($em->getRepository(SupportMessage::class)->findBy(['ticket' => $ticket, 'internal' => false], ['createdAt' => 'ASC', 'id' => 'ASC']) as $m) {
            $messages[] = ['id' => (int) $m->getId(), 'from' => $m->getAuthorType() === 'customer' ? 'customer' : 'team', 'body' => $m->getBody(),
                'createdAt' => self::iso($m->getCreatedAt()),
                'attachments' => array_map(fn (SupportAttachment $a): array => ['id' => (int) $a->getId(), 'name' => $a->getOriginalName(), 'mime' => $a->getMime(), 'size' => $a->getSize()],
                    $em->getRepository(SupportAttachment::class)->findBy(['message' => $m], ['id' => 'ASC']))];
        }
        $summary = $this->summary($ticket);
        $this->markRead((int) $ticket->getId(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return ['request' => ['unread' => false] + $summary, 'messages' => $messages];
    }

    /** @param list<UploadedFile> $files */
    public function reply(User $customer, string $reference, array $body, array $files): array
    {
        $this->throttle($customer);
        $ticket = $this->own($customer, $reference);
        $text = is_string($body['message'] ?? null) ? trim($body['message']) : '';
        $errors = [];
        if ($text === '') {
            $errors['message'] = 'REQUIRED';
        } elseif (mb_strlen($text) > self::MESSAGE_MAX) {
            $errors['message'] = 'INVALID_LENGTH';
        }
        $this->checkedFiles($files, $errors);
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        if ($ticket->getStatus() === 'closed') {
            throw new SupportException('REQUEST_CLOSED', 'Cette demande est fermée : envoyez une nouvelle demande.', 409);
        }
        // le chemin commun des messages du client : nouveau délai de réponse, la demande repasse « en cours »
        $this->tickets->addMessage((int) $ticket->getId(), ['body' => $text, 'fromCustomer' => true], $files, $customer, '');

        return $this->detail($customer, $reference);
    }

    /** @return array{0: string, 1: string, 2: string} une pièce jointe d'une demande du client, hors notes internes */
    public function attachment(User $customer, string $reference, int $attachmentId): array
    {
        $ticket = $this->own($customer, $reference);
        $a = $this->emProvider->getEntityManager()->find(SupportAttachment::class, $attachmentId);
        if ($a === null || $a->getMessage()?->getTicket()?->getId() !== $ticket->getId() || $a->getMessage()?->isInternal()) {
            throw new SupportException('ATTACHMENT_NOT_FOUND', 'Pièce jointe introuvable.', 404);
        }

        return $this->tickets->attachment((int) $ticket->getId(), $attachmentId);
    }

    private function own(User $customer, string $reference): SupportTicket
    {
        $ticket = $this->emProvider->getEntityManager()->getRepository(SupportTicket::class)->findOneBy(['reference' => $reference, 'customerUserId' => $customer->getId()]);
        if ($ticket === null || $ticket->getAnonymizedAt() !== null) {
            throw new SupportException('REQUEST_NOT_FOUND', 'Demande introuvable.', 404); // celle d'un autre client se refuse comme une inconnue
        }

        return $ticket;
    }

    /** @return array<string, mixed> ce que lit le client d'une demande */
    private function summary(SupportTicket $t): array
    {
        return ['reference' => $t->getReference(), 'subject' => $t->getSubject(), 'category' => $t->getCategory(), 'status' => self::CUSTOMER_STATUS[$t->getStatus()] ?? 'in_progress',
            'unread' => $this->unread($t), 'createdAt' => self::iso($t->getCreatedAt()), 'lastMessageAt' => self::iso($t->getLastMessageAt())];
    }

    /** L'équipe a-t-elle répondu (message non interne) depuis la dernière lecture du client ? */
    private function unread(SupportTicket $t): bool
    {
        return (bool) $this->emProvider->getEntityManager()->getConnection()->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM support_message m JOIN support_ticket t ON t.id = m.ticket_id WHERE t.id = ? AND m.author_type = 'agent' AND m.internal = false"
            . ' AND (t.customer_read_at IS NULL OR m.created_at > t.customer_read_at))', [(int) $t->getId()]);
    }

    /** Directement en base : la lecture du client ne change pas la date de mise à jour de la demande */
    private function markRead(int $ticketId, \DateTimeImmutable $at): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('UPDATE support_ticket SET customer_read_at = ? WHERE id = ?', [$at->format('Y-m-d H:i:s'), $ticketId]);
    }

    private function throttle(User $customer): void
    {
        $limit = $this->sends->create('u' . $customer->getId())->consume(1);
        if (!$limit->isAccepted()) {
            throw new SupportException('RATE_LIMITED', 'Trop d\'envois : réessayez dans quelques minutes.', 429, ['retryAfterSeconds' => max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }
    }

    /**
     * @param list<UploadedFile>    $files
     * @param array<string, string> $errors
     *
     * @return array<int, string> types vérifiés par le contenu
     */
    private function checkedFiles(array $files, array &$errors): array
    {
        if (count($files) > SupportAttachmentStorage::MAX_FILES) {
            $errors['attachments'] = 'TOO_MANY';
        }
        $mimes = [];
        foreach ($files as $i => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors["attachments[$i]"] = 'UPLOAD_FAILED';
                continue;
            }
            if ($file->getSize() > SupportAttachmentStorage::MAX_BYTES) {
                throw new SupportException('ATTACHMENT_TOO_LARGE', sprintf('Pièce jointe de plus de %d Mo.', SupportAttachmentStorage::MAX_BYTES / 1024 / 1024), 413, ['index' => $i]);
            }
            $mime = SupportAttachmentStorage::realMime($file);
            if ($mime === null) {
                $errors["attachments[$i]"] = 'ATTACHMENT_TYPE';
            }
            $mimes[$i] = (string) $mime;
        }

        return $mimes;
    }

    /**
     * @param list<UploadedFile> $files
     * @param array<int, string> $mimes
     */
    private function attach(SupportTicket $ticket, SupportMessage $message, array $files, array $mimes, \DateTimeImmutable $now): void
    {
        $em = $this->emProvider->getEntityManager();
        foreach ($files as $i => $file) {
            $stored = $this->storage->store((int) $ticket->getId(), $file, $mimes[$i]);
            $em->persist((new SupportAttachment())->setMessage($message)->setStoredName($stored)->setOriginalName($file->getClientOriginalName())->setMime($mimes[$i])
                ->setSize((int) filesize($this->storage->path((int) $ticket->getId(), $stored)))->setCreatedAt($now));
        }
    }

    private static function iso(?\DateTimeImmutable $d): ?string
    {
        return $d === null ? null : (new \DateTimeImmutable($d->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
