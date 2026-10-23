<?php

namespace App\Services\PosCustomerService;

use App\Entity\Caisse;
use App\Entity\Entreprise;
use App\Entity\Order;
use App\Entity\PosCustomer;
use App\Entity\PosCustomerConsent;
use App\Entity\PosGiftCard;
use App\Entity\PosLoyaltyTransaction;
use App\Entity\PosReturn;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosApprovalService;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;

/**
 * Fiche client de caisse (lot F8, 28/10/2026). Fiche distincte du compte web, rattachable à un compte existant avec le
 * consentement du client (finalité account_link) ; rien n'est écrit sur `user`, aucun compte ni courriel n'est créé.
 * Recherche : fiches de caisse par nom (3 caractères au moins), téléphone, courriel ou code de fidélité ; comptes web
 * seulement sur correspondance EXACTE du courriel ou du téléphone (jamais de parcours du fichier client de la boutique).
 * Données minimales, masquage pour le caissier, journal des recherches et consultations (C2), effacement = anonymisation.
 */
class PosCustomerService
{
    public const LOYALTY_PREFIX = 'FD';
    public const LOYALTY_BARCODE_PREFIX = 'IZ-F-';
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const SEARCH_LIMIT = 20;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PosAuditService $audit, private readonly PosApprovalService $approvals, private readonly PosLoyaltyService $loyalty)
    {
    }

    // ------------------------------------------------------------------ réglages, normalisation

    public function settings(): array
    {
        return self::settingsOf($this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]));
    }

    public static function settingsOf(?Entreprise $e): array
    {
        return [
            'enabled' => $e?->isPosCustomersEnabled() ?? true,
            'linkEnabled' => $e?->isPosCustomerLinkEnabled() ?? true,
            'searchPerMinute' => $e?->getPosCustomerSearchPerMinute() ?? 30,
            'phoneFormat' => 'E.164',
            'consentTextVersion' => $e?->getPosConsentTextVersion(),
            'purposes' => PosCustomerConsent::PURPOSES,
            'searchMinChars' => 3,
            'loyaltyBarcodePrefix' => self::LOYALTY_BARCODE_PREFIX,
            'offline' => 'queued', // vente hors ligne mise en file avec customerId ou loyaltyCode connus ; aucune création hors ligne
        ];
    }

    /**
     * Téléphone au format E.164 (« +15145551234ʼ) : 514-555-1234, (514) 555-1234 et +1 514 555 1234 donnent le même numéro.
     * International si le numéro commence par + ou 00 ; sinon numéro national du pays du site (CA/US : 10 chiffres, ou 11 avec le 1 ;
     * FR : 10 chiffres dont le 0 initial, etc.). Null si le format est invalide. Les adresses web existantes ne sont pas touchées.
     */
    public static function normalizePhone(mixed $raw, string $country = 'CA'): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($trimmed, '+') || str_starts_with($digits, '00')) {
            $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

            return strlen($digits) >= 7 && strlen($digits) <= 15 ? '+' . $digits : null;
        }
        $code = self::COUNTRY_CODES[strtoupper($country)] ?? null;
        if ($code === '1') {
            if (strlen($digits) === 11 && $digits[0] === '1') {
                return '+' . $digits;
            }

            return strlen($digits) === 10 ? '+1' . $digits : (strlen($digits) >= 7 && strlen($digits) <= 15 ? '+' . $digits : null);
        }
        if ($code !== null) {
            $national = ltrim($digits, '0');

            return strlen($national) >= 6 && strlen($national) <= 14 ? '+' . $code . $national : null;
        }

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? '+' . $digits : null;
    }

    /** Indicatifs des pays du site les plus courants ; autre pays : numéro gardé tel quel avec un + */
    public const COUNTRY_CODES = ['CA' => '1', 'US' => '1', 'FR' => '33', 'BE' => '32', 'CH' => '41', 'LU' => '352', 'MC' => '377', 'GB' => '44', 'DE' => '49', 'ES' => '34', 'IT' => '39', 'PT' => '351', 'MA' => '212', 'TN' => '216', 'DZ' => '213', 'SN' => '221', 'CI' => '225', 'CM' => '237', 'HT' => '509', 'MX' => '52', 'BR' => '55'];

    /** Pays du site (adresse de l'entreprise, ISO 2 lettres), CA par défaut */
    public function siteCountry(): string
    {
        $e = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([]);
        $address = \App\Services\OrderService\TaxEngine::normalizeAddress(['country' => $e?->getAddressEntreprise()?->getCountry()]);

        return $address['country'] ?? 'CA';
    }

    public function phone(mixed $raw): ?string
    {
        return self::normalizePhone($raw, $this->siteCountry());
    }

    public static function normalizeEmail(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $email = mb_strtolower(trim($raw));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 180 ? $email : null;
    }

    public static function normalizeLoyaltyCode(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $code = strtoupper(preg_replace('/[\s\-]+/', '', trim($raw)) ?? '');
        if (str_starts_with($code, 'IZF')) {
            $code = substr($code, 3);
        }
        if (!preg_match('/^FD([' . self::ALPHABET . ']{8})$/', $code, $m)) {
            return null;
        }

        return 'FD-' . substr($m[1], 0, 4) . '-' . substr($m[1], 4, 4);
    }

    public static function maskPhone(?string $phone): ?string
    {
        return $phone === null ? null : '•••• ' . substr(preg_replace('/\D+/', '', $phone) ?? '', -4);
    }

    public static function maskEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1) . '***@' . $domain;
    }

    public static function isManager(User $user): bool
    {
        return PosApprovalService::isManager($user);
    }

    // ------------------------------------------------------------------ lecture

    /** Fiche active par identifiant : 404 CUSTOMER_NOT_FOUND (ou fusionnée : contexte mergedIntoId), 410 CUSTOMER_ERASED */
    public function find(mixed $id, bool $allowErased = false): PosCustomer
    {
        $customer = is_numeric($id) ? $this->emProvider->getEntityManager()->find(PosCustomer::class, (int) $id) : null;
        if ($customer === null) {
            throw new CashRegisterException('CUSTOMER_NOT_FOUND', 'Fiche client introuvable', 404, [], ['customerId' => is_numeric($id) ? (int) $id : null]);
        }
        if ($customer->getMergedIntoId() !== null) {
            throw new CashRegisterException('CUSTOMER_MERGED', 'Fiche fusionnée dans une autre', 409, [], ['customerId' => $customer->getId(), 'mergedIntoId' => $customer->getMergedIntoId()]);
        }
        if ($customer->isErased() && !$allowErased) {
            throw new CashRegisterException('CUSTOMER_ERASED', 'Fiche client effacée', 410, [], ['customerId' => $customer->getId()]);
        }

        return $customer;
    }

    /** Fiche par code de fidélité (hors ligne, scan) ; null si inconnu */
    public function findByLoyaltyCode(mixed $raw): ?PosCustomer
    {
        $code = self::normalizeLoyaltyCode($raw);
        $customer = $code !== null ? $this->emProvider->getEntityManager()->getRepository(PosCustomer::class)->findOneBy(['loyaltyCode' => $code]) : null;

        return $customer && $customer->isActive() ? $customer : null;
    }

    public function consentGranted(PosCustomer $customer, string $purpose): bool
    {
        $last = $this->emProvider->getEntityManager()->getRepository(PosCustomerConsent::class)->findOneBy(['customer' => $customer, 'purpose' => $purpose], ['id' => 'DESC']);

        return $last !== null && $last->isGranted();
    }

    /** @return array<string, array{granted: bool, at: ?string, textVersion: ?string}> état effectif par finalité */
    public function consents(PosCustomer $customer): array
    {
        $out = [];
        foreach (PosCustomerConsent::PURPOSES as $p) {
            $out[$p] = ['granted' => false, 'at' => null, 'textVersion' => null];
        }
        foreach ($this->emProvider->getEntityManager()->getRepository(PosCustomerConsent::class)->findBy(['customer' => $customer], ['id' => 'ASC']) as $c) {
            $out[$c->getPurpose()] = ['granted' => $c->isGranted(), 'at' => $c->getAt()->format(\DateTimeInterface::ATOM), 'textVersion' => $c->getTextVersion()];
        }

        return $out;
    }

    /** Fiche pour l'API : téléphone et courriel masqués pour un caissier, complets pour un responsable */
    public function toArray(PosCustomer $customer, User $viewer, bool $withConsents = true): array
    {
        return $this->toArrayFor($customer, self::isManager($viewer), $withConsents);
    }

    public function toArrayFor(PosCustomer $customer, bool $manager, bool $withConsents = true): array
    {
        $this->loyalty->applyExpiry($customer);

        return [
            'id' => $customer->getId(), 'source' => 'pos',
            'firstName' => $customer->getFirstName(), 'lastName' => $customer->getLastName(), 'displayName' => $customer->displayName(),
            'phone' => $manager ? $customer->getPhone() : self::maskPhone($customer->getPhone()),
            'email' => $manager ? $customer->getEmail() : self::maskEmail($customer->getEmail()),
            'linkedAccount' => $customer->getUserId() !== null, 'userId' => $manager ? $customer->getUserId() : null,
            'loyalty' => ['code' => $customer->getLoyaltyCode(), 'barcode' => $customer->getLoyaltyCode() ? self::LOYALTY_BARCODE_PREFIX . $customer->getLoyaltyCode() : null, 'points' => $customer->getLoyaltyPoints(), 'lastActivityAt' => $customer->getLoyaltyLastActivityAt()?->format(\DateTimeInterface::ATOM)],
            'erased' => $customer->isErased(), 'createdAt' => $customer->getCreatedAt()->format(\DateTimeInterface::ATOM), 'updatedAt' => $customer->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ] + ($withConsents ? ['consents' => $this->consents($customer)] : []);
    }

    /** Bloc client d'un ticket : prénom et initiale, points de la vente et solde */
    public function receiptBlock(Order $order): ?array
    {
        if ($order->getCustomerId() === null) {
            return null;
        }
        $customer = $this->emProvider->getEntityManager()->find(PosCustomer::class, $order->getCustomerId());
        if ($customer === null) {
            return null;
        }
        $points = $this->loyalty->forOrder($order);

        return ['id' => $customer->getId(), 'name' => trim(($customer->getFirstName() ?? '') . ' ' . mb_substr((string) $customer->getLastName(), 0, 1) . ($customer->getLastName() ? '.' : '')) ?: $customer->displayName(),
            'loyalty' => $points + ['balance' => $customer->getLoyaltyPoints(), 'code' => $customer->getLoyaltyCode()]];
    }

    // ------------------------------------------------------------------ recherche

    /**
     * Recherche : code de fidélité (exact), courriel (exact, fiches et comptes web), téléphone (exact, fiches et comptes web),
     * sinon nom (3 caractères au moins, fiches de caisse seulement). Journalisée (requête masquée si courriel ou téléphone).
     */
    public function search(mixed $raw, User $actor): array
    {
        $this->assertEnabled();
        $q = is_string($raw) ? trim($raw) : '';
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $settings = $this->settings();
        // Limite par utilisateur et par minute (anti-énumération des comptes web) : comptée sur le journal des recherches
        if ($settings['searchPerMinute'] > 0) {
            $recent = (int) $conn->fetchOne("SELECT COUNT(*) FROM pos_audit_log WHERE type = ? AND user_id = ? AND occurred_at > ? AND payload NOT LIKE '%\"kind\":\"rate_limited\"%'",
                [PosAuditService::CUSTOMER_SEARCHED, $actor->getId(), (new \DateTimeImmutable('-60 seconds'))->format('Y-m-d H:i:s')]);
            if ($recent >= $settings['searchPerMinute']) {
                $this->audit->record(PosAuditService::CUSTOMER_SEARCHED, ['kind' => 'rate_limited', 'limit' => $settings['searchPerMinute']], subjectType: 'customer', user: $actor);
                throw new CashRegisterException('CUSTOMER_SEARCH_RATE_LIMITED', 'Trop de recherches de clients : réessayez dans une minute', 429, [], ['limit' => $settings['searchPerMinute'], 'retryAfterSeconds' => 60]);
            }
        }
        $pos = [];
        $web = [];
        $kind = 'name';
        $logged = $q;
        if (($code = self::normalizeLoyaltyCode($q)) !== null) {
            $kind = 'loyaltyCode';
            $pos = $conn->fetchFirstColumn('SELECT id FROM pos_customer WHERE loyalty_code = ? AND erased_at IS NULL AND merged_into_id IS NULL', [$code]);
        } elseif (str_contains($q, '@')) {
            $kind = 'email';
            $email = self::normalizeEmail($q);
            $logged = self::maskEmail($email ?? $q);
            if ($email !== null) {
                $pos = $conn->fetchFirstColumn('SELECT id FROM pos_customer WHERE email = ? AND erased_at IS NULL AND merged_into_id IS NULL ORDER BY id', [$email]);
                if ($settings['linkEnabled']) {
                    $web = $conn->fetchAllAssociative('SELECT u.id, u.firstname, u.lastname, u.email FROM "user" u WHERE LOWER(u.email) = ? AND u.deleted_at IS NULL AND u.roles::text LIKE \'%ROLE_USER_INTERNET%\' AND NOT EXISTS (SELECT 1 FROM pos_customer c WHERE c.user_id = u.id AND c.erased_at IS NULL AND c.merged_into_id IS NULL) LIMIT 5', [$email]);
                }
            }
        } elseif (preg_match('/^[\d\s().+\-]+$/', $q) && ($phone = $this->phone($q)) !== null) {
            $kind = 'phone';
            $logged = self::maskPhone($phone);
            $last10 = substr(preg_replace('/\D+/', '', $phone), -10);
            $pos = $conn->fetchFirstColumn('SELECT id FROM pos_customer WHERE (phone = ? OR RIGHT(phone, 10) = ?) AND erased_at IS NULL AND merged_into_id IS NULL ORDER BY id LIMIT ?', [$phone, $last10, self::SEARCH_LIMIT]);
            if ($settings['linkEnabled']) {
                $web = $conn->fetchAllAssociative('SELECT DISTINCT u.id, u.firstname, u.lastname, u.email FROM "user" u JOIN adress a ON a.user_adress_id = u.id WHERE RIGHT(regexp_replace(COALESCE(a.phone, \'\'), \'\D\', \'\', \'g\'), 10) = ? AND u.deleted_at IS NULL AND u.roles::text LIKE \'%ROLE_USER_INTERNET%\' AND NOT EXISTS (SELECT 1 FROM pos_customer c WHERE c.user_id = u.id AND c.erased_at IS NULL AND c.merged_into_id IS NULL) LIMIT 5', [$last10]);
            }
        } else {
            if (mb_strlen($q) < $settings['searchMinChars']) {
                throw new CashRegisterException('VALIDATION_FAILED', 'q : 3 caractères au moins pour une recherche par nom', 422, [['path' => 'q', 'message' => '3 caractères au moins']]);
            }
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)) . '%';
            $pos = $conn->fetchFirstColumn('SELECT id FROM pos_customer WHERE erased_at IS NULL AND merged_into_id IS NULL AND (LOWER(COALESCE(first_name, \'\')) LIKE ? OR LOWER(COALESCE(last_name, \'\')) LIKE ? OR LOWER(COALESCE(first_name, \'\') || \' \' || COALESCE(last_name, \'\')) LIKE ?) ORDER BY last_name, first_name, id LIMIT ?', [$like, $like, $like, self::SEARCH_LIMIT]);
        }
        $results = [];
        foreach ($pos as $id) {
            $customer = $em->find(PosCustomer::class, (int) $id);
            if ($customer) {
                $results[] = $this->toArray($customer, $actor, false);
            }
        }
        foreach ($web as $u) {
            $results[] = ['id' => null, 'source' => 'web', 'userId' => (int) $u['id'], 'firstName' => $u['firstname'], 'lastName' => $u['lastname'], 'displayName' => trim($u['firstname'] . ' ' . $u['lastname']),
                'email' => self::isManager($actor) ? $u['email'] : self::maskEmail($u['email']), 'phone' => null, 'linkedAccount' => false, 'loyalty' => null,
                'hint' => 'Compte de la boutique en ligne : créez la fiche puis rattachez-la avec le consentement du client (account_link)'];
        }
        $this->audit->record(PosAuditService::CUSTOMER_SEARCHED, ['kind' => $kind, 'query' => $logged, 'posResults' => count($pos), 'webResults' => count($web)], subjectType: 'customer', user: $actor);

        return ['query' => $q, 'kind' => $kind, 'results' => $results];
    }

    // ------------------------------------------------------------------ écriture

    /**
     * Création rapide : {firstName?, lastName?, phone?, email?, consents?: {loyalty, receipts, marketing}} ; un nom et un téléphone
     * ou un courriel au moins ; 409 CUSTOMER_EXISTS si le téléphone ou le courriel existe déjà sur une fiche active.
     */
    public function create(User $actor, array $body): PosCustomer
    {
        $this->assertEnabled();
        $em = $this->emProvider->getEntityManager();
        $customer = new PosCustomer();
        $this->applyFields($customer, $body, true);
        if ($customer->getFirstName() === null && $customer->getLastName() === null) {
            throw new CashRegisterException('VALIDATION_FAILED', 'firstName ou lastName requis', 422, [['path' => 'lastName', 'message' => 'nom requis']]);
        }
        if ($customer->getPhone() === null && $customer->getEmail() === null) {
            throw new CashRegisterException('VALIDATION_FAILED', 'phone ou email requis', 422, [['path' => 'phone', 'message' => 'téléphone ou courriel requis']]);
        }
        $this->assertNoDuplicate($customer, null);
        $customer->setCreatedById($actor->getId());
        $em->persist($customer);
        $em->flush();
        $consents = $this->applyConsents($customer, $body['consents'] ?? null, $actor);
        if ($this->consentGranted($customer, PosCustomerConsent::LOYALTY)) {
            $this->ensureLoyaltyCode($customer);
        }
        $em->flush();
        $this->audit->record(PosAuditService::CUSTOMER_CREATED, ['customerId' => $customer->getId(), 'hasPhone' => $customer->getPhone() !== null, 'hasEmail' => $customer->getEmail() !== null, 'consents' => $consents], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return $customer;
    }

    /** Mise à jour partielle des coordonnées et des consentements ; journal des clés modifiées */
    public function update(User $actor, PosCustomer $customer, array $body): PosCustomer
    {
        $before = [$customer->getFirstName(), $customer->getLastName(), $customer->getPhone(), $customer->getEmail()];
        $this->applyFields($customer, $body, false);
        if ($customer->getPhone() === null && $customer->getEmail() === null) {
            throw new CashRegisterException('VALIDATION_FAILED', 'phone ou email requis', 422, [['path' => 'phone', 'message' => 'téléphone ou courriel requis']]);
        }
        $this->assertNoDuplicate($customer, $customer->getId());
        $changed = array_keys(array_filter(['firstName' => $before[0] !== $customer->getFirstName(), 'lastName' => $before[1] !== $customer->getLastName(), 'phone' => $before[2] !== $customer->getPhone(), 'email' => $before[3] !== $customer->getEmail()]));
        $consents = $this->applyConsents($customer, $body['consents'] ?? null, $actor);
        if ($this->consentGranted($customer, PosCustomerConsent::LOYALTY)) {
            $this->ensureLoyaltyCode($customer);
        }
        $customer->touch();
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record(PosAuditService::CUSTOMER_UPDATED, ['customerId' => $customer->getId(), 'changed' => $changed, 'consents' => $consents], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return $customer;
    }

    /** Rattachement à un compte web : consentement account_link exigé ; 409 CONSENT_REQUIRED, USER_ALREADY_LINKED, CUSTOMER_LINK_DISABLED ; 404 USER_NOT_FOUND */
    public function link(User $actor, PosCustomer $customer, mixed $userId): PosCustomer
    {
        if (!$this->settings()['linkEnabled']) {
            throw new CashRegisterException('CUSTOMER_LINK_DISABLED', 'Le rattachement d\'un compte web est désactivé sur ce site', 409);
        }
        if (!$this->consentGranted($customer, PosCustomerConsent::ACCOUNT_LINK)) {
            throw new CashRegisterException('CONSENT_REQUIRED', 'Le client doit consentir à réunir ses achats en magasin et en ligne (consentement account_link)', 409, [], ['customerId' => $customer->getId(), 'purpose' => PosCustomerConsent::ACCOUNT_LINK]);
        }
        $em = $this->emProvider->getEntityManager();
        $user = is_numeric($userId) ? $em->find(User::class, (int) $userId) : null;
        if (!$user instanceof User || $user->isDeleted() || !in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
            throw new CashRegisterException('USER_NOT_FOUND', 'Compte client de la boutique introuvable', 404, [], ['userId' => is_numeric($userId) ? (int) $userId : null]);
        }
        $other = $em->getRepository(PosCustomer::class)->findOneBy(['userId' => $user->getId(), 'erasedAt' => null, 'mergedIntoId' => null]);
        if ($other !== null && $other->getId() !== $customer->getId()) {
            throw new CashRegisterException('USER_ALREADY_LINKED', 'Ce compte est déjà rattaché à une autre fiche de caisse', 409, [], ['customerId' => $other->getId()]);
        }
        $customer->setUserId($user->getId())->touch();
        $em->flush();
        $this->audit->record(PosAuditService::CUSTOMER_LINKED, ['customerId' => $customer->getId(), 'userId' => $user->getId()], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return $customer;
    }

    /** Fusion de doublons (responsable) : ventes, cartes, points et consentements accordés passent sur la fiche conservée */
    public function merge(User $actor, PosCustomer $source, mixed $intoId): PosCustomer
    {
        $this->assertManager($actor);
        $target = $this->find($intoId);
        if ($target->getId() === $source->getId()) {
            throw new CashRegisterException('VALIDATION_FAILED', 'intoId : fiche différente attendue', 422, [['path' => 'intoId', 'message' => 'fiche différente']]);
        }
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $conn->executeStatement('UPDATE "order" SET customer_id = ? WHERE customer_id = ?', [$target->getId(), $source->getId()]);
        $conn->executeStatement('UPDATE pos_gift_card SET customer_id = ? WHERE customer_id = ?', [$target->getId(), $source->getId()]);
        foreach (PosCustomerConsent::PURPOSES as $p) {
            if ($this->consentGranted($source, $p) && !$this->consentGranted($target, $p)) {
                $em->persist(new PosCustomerConsent($target, $p, true, $actor->getId(), $this->settings()['consentTextVersion']));
            }
        }
        if ($target->getUserId() === null && $source->getUserId() !== null) {
            $target->setUserId($source->getUserId());
        }
        if ($target->getPhone() === null) {
            $target->setPhone($source->getPhone());
        }
        if ($target->getEmail() === null) {
            $target->setEmail($source->getEmail());
        }
        $points = $source->getLoyaltyPoints();
        if ($points > 0) {
            $source->setLoyaltyPoints(0);
            $em->persist((new PosLoyaltyTransaction($source, PosLoyaltyTransaction::MERGE_OUT, -$points))->setUserId($actor->getId())->setReason('fusion vers #' . $target->getId()));
            $target->setLoyaltyPoints($target->getLoyaltyPoints() + $points)->setLoyaltyLastActivityAt(new \DateTimeImmutable());
            $em->persist((new PosLoyaltyTransaction($target, PosLoyaltyTransaction::MERGE_IN, $points))->setUserId($actor->getId())->setReason('fusion de #' . $source->getId()));
        }
        if ($target->getLoyaltyCode() === null && $source->getLoyaltyCode() !== null) {
            $code = $source->getLoyaltyCode();
            $source->setLoyaltyCode(null);
            $em->flush();
            $target->setLoyaltyCode($code);
        }
        $source->setMergedIntoId($target->getId())->setUserId(null)->touch();
        $target->touch();
        $em->flush();
        $this->audit->record(PosAuditService::CUSTOMER_MERGED, ['customerId' => $source->getId(), 'intoId' => $target->getId(), 'points' => $points], subjectType: 'customer', subjectId: $target->getId(), user: $actor);

        return $target;
    }

    /** Effacement (responsable, validation C3 customer_erase) : anonymisation ; les ventes restent rattachées à la fiche anonymisée */
    public function erase(User $actor, PosCustomer $customer): PosCustomer
    {
        $this->assertManager($actor);
        $this->approvals->requireFor(PosApprovalService::CUSTOMER_ERASE, $actor, ['customerId' => (int) $customer->getId()]);
        $em = $this->emProvider->getEntityManager();
        $points = $customer->getLoyaltyPoints();
        if ($points > 0) {
            $customer->setLoyaltyPoints(0);
            $em->persist((new PosLoyaltyTransaction($customer, PosLoyaltyTransaction::ADJUST, -$points))->setUserId($actor->getId())->setReason('effacement de la fiche')->setApprovedBy($this->approvals->approvedBy()));
        }
        foreach (PosCustomerConsent::PURPOSES as $p) {
            if ($this->consentGranted($customer, $p)) {
                $em->persist(new PosCustomerConsent($customer, $p, false, $actor->getId(), $this->settings()['consentTextVersion']));
            }
        }
        $customer->setFirstName(null)->setLastName(null)->setPhone(null)->setEmail(null)->setUserId(null)->setLoyaltyCode(null)->setLoyaltyLastActivityAt(null)->setErasedAt(new \DateTimeImmutable())->touch();
        $em->flush();
        $this->audit->record(PosAuditService::CUSTOMER_ERASED, ['customerId' => $customer->getId(), 'pointsCanceled' => $points], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return $customer;
    }

    // ------------------------------------------------------------------ historique et export

    /** Historique (journalisé CUSTOMER_VIEWED) : ventes et retours de caisse, commandes web du compte lié, cartes et avoirs, points */
    public function history(User $actor, PosCustomer $customer): array
    {
        $em = $this->emProvider->getEntityManager();
        $conn = $em->getConnection();
        $manager = self::isManager($actor);
        $orders = $conn->fetchAllAssociative('SELECT o.id, o.reference, o.order_date, o.order_type_id, o.total_amount, o.gift_card_applied, o.credit_applied, o.status_id FROM "order" o WHERE o.customer_id = ? ORDER BY o.order_date DESC, o.id DESC LIMIT 100', [$customer->getId()]);
        $ids = array_map(fn ($o) => (int) $o['id'], $orders);
        $receipts = $ids ? $em->getRepository(\App\Entity\PosReceipt::class)->forOrders($ids) : [];
        $sales = [];
        foreach ($orders as $o) {
            $kind = match ((int) $o['order_type_id']) { 2 => 'return', 3 => 'gift_card', default => 'sale' };
            $r = $receipts[(int) $o['id']] ?? [];
            $receipt = $r[\App\Entity\PosReceipt::TICKET] ?? $r[\App\Entity\PosReceipt::CREDIT_NOTE] ?? $r[\App\Entity\PosReceipt::GIFT_CARD] ?? null;
            $sales[] = ['orderId' => (int) $o['id'], 'kind' => $kind, 'reference' => $o['reference'], 'date' => $o['order_date'] ? (new \DateTimeImmutable($o['order_date']))->format(\DateTimeInterface::ATOM) : null,
                'total' => (int) round((float) $o['total_amount']), 'giftCardApplied' => (int) ($o['gift_card_applied'] ?? 0), 'creditApplied' => (int) ($o['credit_applied'] ?? 0), 'statusId' => (int) $o['status_id'],
                'receiptKind' => $receipt?->getKind(), 'receiptNumber' => $receipt?->getNumber(), 'receiptReference' => $receipt ? $receipt->getCashRegister()->receiptReference($receipt->getNumber()) : null];
        }
        $returns = $ids ? array_map(fn ($r) => ['returnId' => (int) $r['id'], 'orderId' => (int) $r['order_id'], 'returnOrderId' => (int) $r['return_order_id'], 'amount' => (int) $r['amount'], 'refundMethod' => $r['refund_method'], 'storeCreditId' => $r['store_credit_id'] !== null ? (int) $r['store_credit_id'] : null, 'createdAt' => (new \DateTimeImmutable($r['created_at']))->format(\DateTimeInterface::ATOM)],
            $conn->fetchAllAssociative('SELECT id, order_id, return_order_id, amount, refund_method, store_credit_id, created_at FROM pos_return WHERE return_order_id IN (' . implode(',', $ids) . ') ORDER BY id DESC')) : [];
        $cards = array_map(fn (PosGiftCard $c) => ['id' => $c->getId(), 'kind' => $c->getKind(), 'last4' => $c->getLast4(), 'balance' => $c->getBalance(), 'status' => $c->getStatus(), 'expiresAt' => $c->getExpiresAt()?->format(\DateTimeInterface::ATOM), 'issuedAt' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            $em->getRepository(PosGiftCard::class)->findBy(['customerId' => $customer->getId()], ['id' => 'DESC'], 50));
        $loyalty = array_map(fn (PosLoyaltyTransaction $t) => ['kind' => $t->getKind(), 'points' => $t->getPoints(), 'balanceAfter' => $t->getBalanceAfter(), 'amount' => $t->getAmount(), 'orderId' => $t->getOrderId(), 'reason' => $t->getReason(), 'at' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            $em->getRepository(PosLoyaltyTransaction::class)->findBy(['customer' => $customer], ['id' => 'DESC'], 50));
        $web = null;
        if ($customer->getUserId() !== null) {
            $rows = $conn->fetchAllAssociative('SELECT id, reference, order_date, total_amount, status_id FROM "order" WHERE user_id_id = ? AND order_source_id = 1 ORDER BY order_date DESC LIMIT 50', [$customer->getUserId()]);
            $web = ['count' => count($rows), 'total' => (int) array_sum(array_map(fn ($r) => (int) round((float) $r['total_amount']), $rows)),
                'orders' => $manager ? array_map(fn ($r) => ['orderId' => (int) $r['id'], 'reference' => $r['reference'], 'date' => $r['order_date'] ? (new \DateTimeImmutable($r['order_date']))->format(\DateTimeInterface::ATOM) : null, 'total' => (int) round((float) $r['total_amount']), 'statusId' => (int) $r['status_id']], $rows) : null];
        }
        $this->audit->record(PosAuditService::CUSTOMER_VIEWED, ['customerId' => $customer->getId(), 'scope' => 'history'], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return ['customer' => $this->toArray($customer, $actor), 'sales' => $sales, 'returns' => $returns, 'giftCards' => $cards, 'loyalty' => $loyalty, 'web' => $web,
            'totals' => ['salesCount' => count(array_filter($sales, fn ($s) => $s['kind'] === 'sale')), 'salesTotal' => (int) array_sum(array_map(fn ($s) => $s['kind'] === 'sale' ? $s['total'] : 0, $sales))]];
    }

    /** Droit d'accès (responsable) : toutes les données de la fiche, en clair */
    public function export(User $actor, PosCustomer $customer): array
    {
        $this->assertManager($actor);
        $em = $this->emProvider->getEntityManager();
        $history = $this->history($actor, $customer);
        $consents = array_map(fn (PosCustomerConsent $c) => ['purpose' => $c->getPurpose(), 'granted' => $c->isGranted(), 'at' => $c->getAt()->format(\DateTimeInterface::ATOM), 'textVersion' => $c->getTextVersion(), 'source' => $c->getSource()],
            $em->getRepository(PosCustomerConsent::class)->findBy(['customer' => $customer], ['id' => 'ASC']));
        $this->audit->record(PosAuditService::CUSTOMER_EXPORTED, ['customerId' => $customer->getId()], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);

        return ['exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM), 'customer' => ['id' => $customer->getId(), 'firstName' => $customer->getFirstName(), 'lastName' => $customer->getLastName(), 'phone' => $customer->getPhone(), 'email' => $customer->getEmail(), 'userId' => $customer->getUserId(), 'loyaltyCode' => $customer->getLoyaltyCode(), 'loyaltyPoints' => $customer->getLoyaltyPoints(), 'createdAt' => $customer->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            'consentHistory' => $consents] + array_diff_key($history, ['customer' => 1]);
    }

    public function viewed(User $actor, PosCustomer $customer): void
    {
        $this->audit->record(PosAuditService::CUSTOMER_VIEWED, ['customerId' => $customer->getId(), 'scope' => 'profile'], subjectType: 'customer', subjectId: $customer->getId(), user: $actor);
    }

    // ------------------------------------------------------------------ aides

    public function assertEnabled(): void
    {
        if (!$this->settings()['enabled']) {
            throw new CashRegisterException('CUSTOMERS_DISABLED', 'La fiche client est désactivée sur ce site', 409);
        }
    }

    private function assertManager(User $actor): void
    {
        if (!self::isManager($actor)) {
            throw new CashRegisterException('FORBIDDEN', 'Réservé au responsable de caisse', 403);
        }
    }

    private function applyFields(PosCustomer $customer, array $body, bool $create): void
    {
        foreach (['firstName' => 'setFirstName', 'lastName' => 'setLastName'] as $key => $setter) {
            if (array_key_exists($key, $body) && ($body[$key] === null || is_string($body[$key]))) {
                $customer->$setter($body[$key]);
            }
        }
        if (array_key_exists('phone', $body)) {
            if ($body['phone'] !== null && $body['phone'] !== '' && $this->phone($body['phone']) === null) {
                throw new CashRegisterException('VALIDATION_FAILED', 'phone : numéro invalide (national du pays du site, ou international avec +)', 422, [['path' => 'phone', 'message' => 'téléphone invalide']]);
            }
            $customer->setPhone($this->phone($body['phone']));
        }
        if (array_key_exists('email', $body)) {
            if ($body['email'] !== null && $body['email'] !== '' && self::normalizeEmail($body['email']) === null) {
                throw new CashRegisterException('VALIDATION_FAILED', 'email : adresse invalide', 422, [['path' => 'email', 'message' => 'adresse invalide']]);
            }
            $customer->setEmail(self::normalizeEmail($body['email']));
        }
    }

    private function assertNoDuplicate(PosCustomer $customer, ?int $exceptId): void
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        foreach ([['phone', $customer->getPhone()], ['email', $customer->getEmail()]] as [$column, $value]) {
            if ($value === null) {
                continue;
            }
            $id = $column === 'phone'
                ? $conn->fetchOne('SELECT id FROM pos_customer WHERE (phone = ? OR RIGHT(phone, 10) = ?) AND erased_at IS NULL AND merged_into_id IS NULL AND id <> ? ORDER BY id LIMIT 1', [$value, substr(preg_replace('/\D+/', '', $value), -10), $exceptId ?? 0])
                : $conn->fetchOne("SELECT id FROM pos_customer WHERE $column = ? AND erased_at IS NULL AND merged_into_id IS NULL AND id <> ? ORDER BY id LIMIT 1", [$value, $exceptId ?? 0]);
            if ($id !== false) {
                throw new CashRegisterException('CUSTOMER_EXISTS', 'Une fiche active porte déjà ce ' . ($column === 'phone' ? 'téléphone' : 'courriel'), 409, [], ['customerId' => (int) $id, 'field' => $column]);
            }
        }
    }

    /** @return array<string, bool> consentements enregistrés par cet appel */
    private function applyConsents(PosCustomer $customer, mixed $raw, User $actor): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $em = $this->emProvider->getEntityManager();
        $version = $this->settings()['consentTextVersion'];
        $out = [];
        foreach ($raw as $purpose => $granted) {
            if (!in_array($purpose, PosCustomerConsent::PURPOSES, true) || !is_bool($granted)) {
                throw new CashRegisterException('VALIDATION_FAILED', 'consents : {loyalty|receipts|marketing|account_link: bool}', 422, [['path' => "consents.$purpose", 'message' => 'finalité inconnue ou valeur non booléenne']]);
            }
            if ($this->consentGranted($customer, $purpose) === $granted && $em->getRepository(PosCustomerConsent::class)->count(['customer' => $customer, 'purpose' => $purpose]) > 0) {
                continue; // inchangé
            }
            $em->persist(new PosCustomerConsent($customer, $purpose, $granted, $actor->getId(), $version));
            $out[$purpose] = $granted;
        }
        $em->flush();

        return $out;
    }

    private function ensureLoyaltyCode(PosCustomer $customer): void
    {
        if ($customer->getLoyaltyCode() !== null) {
            return;
        }
        $repo = $this->emProvider->getEntityManager()->getRepository(PosCustomer::class);
        do {
            $chars = '';
            for ($i = 0; $i < 8; $i++) {
                $chars .= self::ALPHABET[random_int(0, 31)];
            }
            $code = 'FD-' . substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
        } while ($repo->findOneBy(['loyaltyCode' => $code]) !== null);
        $customer->setLoyaltyCode($code);
    }
}
