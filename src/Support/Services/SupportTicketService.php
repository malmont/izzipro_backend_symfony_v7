<?php

namespace App\Support\Services;

use App\Entity\User;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\Support\Entity\SupportAttachment;
use App\Support\Entity\SupportMessage;
use App\Support\Entity\SupportTicket;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Boîte de demandes d'assistance du site (lot 1, 12/11/2026, `docs/assistance.md` § Lot 1, contrat validé par l'application) : créer un ticket
 * (agent : appel, comptoir, courriel recopié ; depuis une commande ou une vente), lister, lire (messages, pièces jointes, résumé de commande en
 * lecture), changer l'état, la priorité ou l'agent, répondre (courriel au client dans sa langue, ou journal seul), noter (note interne),
 * consigner un message du client. Délai de réponse en heures ouvrées. Écritures journalisées (content_audit_log).
 */
class SupportTicketService
{
    public const AGENT_CHANNELS = ['agent', 'phone', 'counter', 'email'];
    public const MAX_BODY = 20000;
    public const PAGE_MAX = 100;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SupportSettingsService $settings,
        private readonly SupportOrderSummary $orders,
        private readonly SupportAttachmentStorage $storage,
        private readonly SupportMailer $mailer,
        private readonly ContentAuditRecorder $audit,
    ) {
    }

    /** GET /api/support/tickets */
    public function list(array $q, int $userId): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['status' => SupportTicket::STATUSES, 'priority' => SupportTicket::PRIORITIES, 'channel' => SupportTicket::CHANNELS] as $field => $allowed) {
            if (($q[$field] ?? '') !== '') {
                $values = array_values(array_intersect(explode(',', (string) $q[$field]), $allowed));
                if ($values === []) {
                    throw SupportException::validation([$field => 'INVALID_' . strtoupper($field)]);
                }
                $where[] = "t.$field IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
        }
        $assigned = (string) ($q['assigned'] ?? '');
        if ($assigned === 'me') {
            $where[] = 't.assigned_user_id = ?';
            $params[] = $userId;
        } elseif ($assigned === 'none') {
            $where[] = 't.assigned_user_id IS NULL';
        } elseif ($assigned !== '') {
            if (!ctype_digit($assigned)) {
                throw SupportException::validation(['assigned' => 'INVALID_ASSIGNED']);
            }
            $where[] = 't.assigned_user_id = ?';
            $params[] = (int) $assigned;
        }
        if (($search = trim((string) ($q['q'] ?? ''))) !== '') {
            $where[] = '(t.reference ILIKE ? OR t.subject ILIKE ? OR t.customer_name ILIKE ? OR t.customer_email ILIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . addcslashes($search, '%_\\') . '%'));
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $size = max(1, min(self::PAGE_MAX, (int) ($q['pageSize'] ?? 25)));
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM support_ticket t WHERE $sqlWhere", $params);
        $ids = $conn->fetchFirstColumn("SELECT t.id FROM support_ticket t WHERE $sqlWhere ORDER BY (t.status = 'closed'), t.due_at ASC NULLS LAST, t.id DESC LIMIT $size OFFSET " . (($page - 1) * $size), $params);
        $repo = $this->emProvider->getEntityManager()->getRepository(SupportTicket::class);

        return ['items' => array_map(fn ($id) => $this->row($repo->find((int) $id)), $ids), 'total' => $total, 'page' => $page, 'pageSize' => $size];
    }

    /** POST /api/support/tickets */
    public function create(array $body, User $agent): array
    {
        $errors = [];
        $subject = trim((string) ($body['subject'] ?? ''));
        if ($subject === '') {
            $errors['subject'] = 'REQUIRED';
        } elseif (mb_strlen($subject) > 200) {
            $errors['subject'] = 'TOO_LONG';
        }
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            $errors['body'] = 'REQUIRED';
        } elseif (mb_strlen($text) > self::MAX_BODY) {
            $errors['body'] = 'TOO_LONG';
        }
        $channel = $body['channel'] ?? 'agent';
        if (!in_array($channel, self::AGENT_CHANNELS, true)) {
            $errors['channel'] = 'INVALID_CHANNEL';
        }
        $priority = $body['priority'] ?? 'normal';
        if (!in_array($priority, SupportTicket::PRIORITIES, true)) {
            $errors['priority'] = 'INVALID_PRIORITY';
        }
        $orderId = $body['orderId'] ?? null;
        if ($orderId !== null && (!is_int($orderId) || !$this->orders->exists($orderId))) {
            $errors['orderId'] = is_int($orderId) ? 'ORDER_NOT_FOUND' : 'INVALID_ID';
        }
        $em = $this->emProvider->getEntityManager();
        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $user = null;
        if (isset($customer['userId'])) {
            $user = is_int($customer['userId']) ? $em->find(User::class, $customer['userId']) : null;
            if ($user === null) {
                $errors['customer.userId'] = 'CUSTOMER_NOT_FOUND';
            }
        }
        $order = $orderId !== null && !isset($errors['orderId']) ? $em->find(\App\Entity\Order::class, $orderId) : null;
        $user ??= $order?->getUserId();
        $email = trim((string) ($customer['email'] ?? '')) ?: $user?->getEmail();
        $name = trim((string) ($customer['name'] ?? '')) ?: ($user !== null ? trim($user->getFirstname() . ' ' . $user->getLastname()) : null);
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['customer.email'] = 'INVALID_EMAIL';
        }
        if (($email === null || $email === '') && ($name === null || $name === '')) {
            $errors['customer'] = 'REQUIRED';
        }
        $locale = $body['locale'] ?? 'fr';
        if (!in_array($locale, ['fr', 'en'], true)) {
            $errors['locale'] = 'INVALID_LOCALE';
        }
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        $now = $this->now();
        $ticket = (new SupportTicket())->setReference('AS-' . bin2hex(random_bytes(6)))->setSubject($subject)->setChannel($channel)->setPriority($priority)
            ->setCustomerUserId($user?->getId())->setCustomerName($name ?: null)->setCustomerEmail($email ?: null)->setCustomerLocale($locale)
            ->setOrderId($orderId)->setCashRegisterId($orderId !== null ? $this->orders->cashRegisterId($orderId) : null)->setCreatedBy($agent->getUserIdentifier())
            ->setDueAt($this->settings->due($now))->setLastMessageAt($now)->setLastCustomerMessageAt($now);
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference(sprintf('AS-%06d', (int) $ticket->getId()));
        // la demande telle que le client l'a faite (appel, comptoir, courriel recopié)
        $em->persist((new SupportMessage())->setTicket($ticket)->setAuthorType('customer')->setAuthorName($name ?: $email)->setBody($text)->setCreatedAt($now));
        $em->flush();
        $this->audit->record('support_ticket', (int) $ticket->getId(), 'create', null, ['reference' => $ticket->getReference(), 'channel' => $channel, 'orderId' => $orderId], ['reference', 'channel', 'orderId']);

        return $this->detail((int) $ticket->getId());
    }

    /** GET /api/support/tickets/{id} */
    public function detail(int $id): array
    {
        $ticket = $this->ticket($id);
        $em = $this->emProvider->getEntityManager();
        $messages = [];
        foreach ($em->getRepository(SupportMessage::class)->findBy(['ticket' => $ticket], ['createdAt' => 'ASC', 'id' => 'ASC']) as $m) {
            $messages[] = ['id' => (int) $m->getId(), 'authorType' => $m->getAuthorType(), 'author' => ['id' => $m->getAuthorUserId(), 'name' => $m->getAuthorName()], 'body' => $m->getBody(),
                'internal' => $m->isInternal(), 'emailStatus' => $m->getEmailStatus(), 'createdAt' => $m->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'attachments' => array_map(fn (SupportAttachment $a) => ['id' => (int) $a->getId(), 'name' => $a->getOriginalName(), 'mime' => $a->getMime(), 'size' => $a->getSize()],
                    $em->getRepository(SupportAttachment::class)->findBy(['message' => $m], ['id' => 'ASC']))];
        }

        return $this->row($ticket) + ['messages' => $messages, 'orderSummary' => $this->orders->summary($ticket->getOrderId())];
    }

    /** PATCH /api/support/tickets/{id} */
    public function patch(int $id, array $body, User $agent): array
    {
        $ticket = $this->ticket($id);
        $em = $this->emProvider->getEntityManager();
        $before = ['status' => $ticket->getStatus(), 'priority' => $ticket->getPriority(), 'assignedTo' => $ticket->getAssignedUserId(), 'subject' => $ticket->getSubject()];
        $errors = [];
        if (array_key_exists('priority', $body) && !in_array($body['priority'], SupportTicket::PRIORITIES, true)) {
            $errors['priority'] = 'INVALID_PRIORITY';
        }
        if (array_key_exists('status', $body) && !in_array($body['status'], SupportTicket::STATUSES, true)) {
            $errors['status'] = 'INVALID_STATUS';
        }
        if (array_key_exists('subject', $body) && (!is_string($body['subject']) || trim($body['subject']) === '' || mb_strlen($body['subject']) > 200)) {
            $errors['subject'] = 'INVALID_SUBJECT';
        }
        $assignee = null;
        if (array_key_exists('assignedTo', $body) && $body['assignedTo'] !== null) {
            $assignee = is_int($body['assignedTo']) ? $em->find(User::class, $body['assignedTo']) : null;
            if ($assignee === null || !array_intersect(['ROLE_SUPPORT', 'ROLE_ADMIN'], $assignee->getRoles())) {
                $errors['assignedTo'] = 'INVALID_AGENT';
            }
        }
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        if (isset($body['status']) && $body['status'] !== $ticket->getStatus()) {
            if (!in_array($body['status'], SupportTicket::TRANSITIONS[$ticket->getStatus()] ?? [], true)) {
                throw new SupportException('INVALID_STATUS_TRANSITION', sprintf('Passage de « %s » à « %s » impossible.', $ticket->getStatus(), $body['status']), 409, ['from' => $ticket->getStatus(), 'to' => $body['status']]);
            }
            $now = $this->now();
            if ($body['status'] === 'closed') {
                $ticket->setClosedAt($now)->setDueAt(null);
            } elseif ($ticket->getStatus() === 'closed') { // réouverture : la conservation repartira de la prochaine fermeture
                $ticket->setClosedAt(null)->setDueAt($this->settings->due($now));
            }
            $ticket->setStatus($body['status']);
        }
        if (isset($body['priority'])) {
            $ticket->setPriority($body['priority']);
        }
        if (array_key_exists('assignedTo', $body)) {
            $ticket->setAssignedUserId($assignee?->getId());
        }
        if (isset($body['subject'])) {
            $ticket->setSubject(trim($body['subject']));
        }
        $em->flush();
        $after = ['status' => $ticket->getStatus(), 'priority' => $ticket->getPriority(), 'assignedTo' => $ticket->getAssignedUserId(), 'subject' => $ticket->getSubject()];
        $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->audit->record('support_ticket', $id, 'update', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), $changed);
        }

        return $this->detail($id);
    }

    /**
     * POST /api/support/tickets/{id}/messages : réponse au client (courriel ou journal), note interne, ou message du client consigné par l'agent.
     *
     * @param list<UploadedFile> $files
     */
    public function addMessage(int $id, array $body, array $files, User $agent, string $host): array
    {
        $ticket = $this->ticket($id);
        $text = trim((string) ($body['body'] ?? ''));
        $internal = filter_var($body['internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $fromCustomer = filter_var($body['fromCustomer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $errors = [];
        if ($text === '') {
            $errors['body'] = 'REQUIRED';
        } elseif (mb_strlen($text) > self::MAX_BODY) {
            $errors['body'] = 'TOO_LONG';
        }
        if ($internal && $fromCustomer) {
            $errors['internal'] = 'INTERNAL_FROM_CUSTOMER';
        }
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
                $errors["attachments[$i]"] = 'ATTACHMENT_TYPE'; // vérifié par le contenu, pas par l'extension
            }
            $mimes[$i] = $mime;
        }
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        if ($ticket->getStatus() === 'closed' && !$internal) {
            throw new SupportException('TICKET_CLOSED', 'Demande fermée : rouvrez-la pour répondre.', 409);
        }
        $em = $this->emProvider->getEntityManager();
        $now = $this->now();
        $name = trim($agent->getFirstname() . ' ' . $agent->getLastname()) ?: $agent->getUserIdentifier();
        $message = (new SupportMessage())->setTicket($ticket)->setBody($text)->setInternal($internal)->setCreatedAt($now)
            ->setAuthorType($fromCustomer ? 'customer' : 'agent')->setAuthorUserId($fromCustomer ? null : $agent->getId())->setAuthorName($fromCustomer ? ($ticket->getCustomerName() ?? $ticket->getCustomerEmail()) : $name);
        $em->persist($message);
        $em->flush();
        foreach ($files as $i => $file) {
            $stored = $this->storage->store((int) $ticket->getId(), $file, $mimes[$i]);
            $em->persist((new SupportAttachment())->setMessage($message)->setStoredName($stored)->setOriginalName($file->getClientOriginalName())->setMime($mimes[$i])->setSize((int) filesize($this->storage->path((int) $ticket->getId(), $stored)))->setCreatedAt($now));
        }
        $ticket->setLastMessageAt($now);
        if ($fromCustomer) { // le client a écrit (ou appelé) : nouveau délai de réponse
            $ticket->setLastCustomerMessageAt($now)->setDueAt($this->settings->due($now));
            if ($ticket->getStatus() === 'waiting_customer') {
                $ticket->setStatus('open');
            }
        } elseif (!$internal) { // réponse de l'agent : courriel (ou journal), le ticket attend le client
            $message->setEmailStatus($this->mailer->reply($ticket, $message, $this->settings->current()->getOutboundMode(), $host));
            $ticket->setFirstResponseAt($ticket->getFirstResponseAt() ?? $now)->setDueAt(null)->setStatus('waiting_customer');
        }
        $em->flush();

        return $this->detail($id);
    }

    /** @return array{0: string, 1: string, 2: string} chemin, nom d'origine, type */
    public function attachment(int $ticketId, int $attachmentId): array
    {
        $ticket = $this->ticket($ticketId);
        $a = $this->emProvider->getEntityManager()->find(SupportAttachment::class, $attachmentId);
        if ($a === null || $a->getMessage()?->getTicket()?->getId() !== $ticket->getId()) {
            throw new SupportException('ATTACHMENT_NOT_FOUND', 'Pièce jointe introuvable.', 404);
        }
        $path = $this->storage->path((int) $ticket->getId(), $a->getStoredName());
        if (!is_file($path)) {
            throw new SupportException('ATTACHMENT_NOT_FOUND', 'Pièce jointe introuvable.', 404);
        }

        return [$path, $a->getOriginalName(), $a->getMime()];
    }

    // ----- interne -----

    private function ticket(int $id): SupportTicket
    {
        $t = $this->emProvider->getEntityManager()->find(SupportTicket::class, $id);
        if ($t === null) {
            throw new SupportException('TICKET_NOT_FOUND', 'Demande d\'assistance introuvable.', 404, ['ticketId' => $id]);
        }

        return $t;
    }

    private function row(SupportTicket $t): array
    {
        $em = $this->emProvider->getEntityManager();
        $assignee = $t->getAssignedUserId() === null ? null : $em->find(User::class, $t->getAssignedUserId());
        $iso = fn (?\DateTimeImmutable $d) => $d === null ? null : (new \DateTimeImmutable($d->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $now = $this->now();
        $due = $t->getDueAt() === null ? null : new \DateTimeImmutable($t->getDueAt()->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        return [
            'id' => (int) $t->getId(), 'reference' => $t->getReference(), 'subject' => $t->getSubject(), 'status' => $t->getStatus(), 'priority' => $t->getPriority(), 'channel' => $t->getChannel(),
            'customer' => ['userId' => $t->getCustomerUserId(), 'name' => $t->getCustomerName(), 'email' => $t->getCustomerEmail(), 'locale' => $t->getCustomerLocale()],
            'order' => $this->orders->brief($t->getOrderId()), 'cashRegisterId' => $t->getCashRegisterId(),
            'assignedTo' => $assignee === null ? null : ['id' => (int) $assignee->getId(), 'name' => trim($assignee->getFirstname() . ' ' . $assignee->getLastname()) ?: $assignee->getUserIdentifier()],
            'dueAt' => $iso($t->getDueAt()), 'overdue' => $due !== null && $t->getStatus() !== 'closed' && $now > $due,
            'awaitingReply' => $t->getStatus() !== 'closed' && $t->getLastCustomerMessageAt() !== null && ($t->getFirstResponseAt() === null || $t->getLastCustomerMessageAt() > $t->getFirstResponseAt()) && $t->getDueAt() !== null,
            'firstResponseAt' => $iso($t->getFirstResponseAt()), 'lastMessageAt' => $iso($t->getLastMessageAt()), 'closedAt' => $iso($t->getClosedAt()),
            'anonymized' => $t->getAnonymizedAt() !== null, 'createdAt' => $iso($t->getCreatedAt()), 'updatedAt' => $iso($t->getUpdatedAt()),
        ];
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
