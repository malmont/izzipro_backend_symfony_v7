<?php

namespace App\Import;

use App\Entity\User;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\Connection;

/**
 * Les comptes « invités par import » du site courant (lot IMP-2, `docs/import-boutique.md` § 9) : table `import_customer_invitation`,
 * une ligne par compte créé depuis le fichier des clients d'une ancienne boutique.
 *
 * Un invité EN ATTENTE (`imported`, puis `invited` quand le commerçant lui a envoyé son lien) n'est pas un client : mot de passe
 * inutilisable, compte non vérifié, aucun rôle de client, aucune appartenance. Il ne se connecte par AUCUN chemin : chaque route
 * publique qui pose un mot de passe ou envoie un lien demande d'abord `isPending()`. Il devient un client (`accepted`) par son lien
 * seulement. Sans acceptation, il est effacé `ERASE_DAYS` jours après l'import ; s'il a passé une commande entre-temps, son compte
 * est gardé et devient un compte d'achat sans compte ordinaire (`kept`).
 *
 * Seule l'empreinte du jeton d'un lien est gardée ; le lien ne sert qu'une fois et n'est envoyé qu'une fois (aucune relance).
 */
class ImportGuests
{
    public const IMPORTED = 'imported';
    public const INVITED = 'invited';
    public const ACCEPTED = 'accepted';
    public const KEPT = 'kept';
    public const PENDING = [self::IMPORTED, self::INVITED];
    public const STATES = [self::IMPORTED, self::INVITED, self::ACCEPTED, self::KEPT];
    public const ERASE_DAYS = 90;
    public const LINK_DAYS = 30;
    /** Tables qui tiennent un compte : un invité qu'une de ces lignes désigne n'est jamais effacé (table => colonne) */
    private const HOLDERS = ['"order"' => 'user_id_id', '"Cart"' => 'user_cart_id', 'checkout_session' => 'user_id', 'subscription' => 'user_id', 'mv_book' => 'user_id',
        'pos_customer' => 'user_id', 'reviews_product' => 'user_review_id', 'transaction_caisse' => 'user_caisse_id', 'collections' => 'user_collections_id'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantCacheService $cache)
    {
    }

    /** Ce compte est-il un invité par import qui n'a pas (encore) accepté ? */
    public function isPending(?User $user): bool
    {
        return $user !== null && $user->getId() !== null
            && $this->conn()->fetchOne("SELECT 1 FROM import_customer_invitation WHERE user_id = ? AND state IN ('imported', 'invited')", [(int) $user->getId()]) !== false;
    }

    /** L'invité en attente qui porte cette adresse (comparée sans la casse), ou null */
    public function pendingIdByEmail(string $email): ?int
    {
        $id = $this->conn()->fetchOne(
            "SELECT i.user_id FROM import_customer_invitation i JOIN \"user\" u ON u.id = i.user_id WHERE LOWER(u.email) = ? AND i.state IN ('imported', 'invited') ORDER BY i.id LIMIT 1",
            [mb_strtolower(trim($email))]
        );

        return $id === false ? null : (int) $id;
    }

    /** Inscrit le compte qu'un import vient de créer : il sera effacé dans `ERASE_DAYS` jours s'il n'accepte pas */
    public function register(int $userId, int $jobId, string $locale, bool $marketingClaimed): void
    {
        $this->conn()->executeStatement(
            "INSERT INTO import_customer_invitation (user_id, job_id, state, locale, marketing_claimed, erase_at) VALUES (?, ?, 'imported', ?, ?, NOW() + make_interval(days => ?))",
            [$userId, $jobId, $locale === 'en' ? 'en' : 'fr', $marketingClaimed ? 'true' : 'false', self::ERASE_DAYS]
        );
    }

    /**
     * Pose le jeton du lien d'un invité qui n'a jamais été invité : valable `LINK_DAYS` jours, jamais au-delà de son effacement. Rend
     * le jeton en clair (il n'est gardé nulle part) et l'instant où le lien expire, ou null si ce compte n'est pas à inviter (déjà
     * invité, accepté, effacé, ou à moins d'un jour de son effacement).
     *
     * @return array{token: string, expiresAt: string, eraseAt: string}|null
     */
    public function invite(int $userId): ?array
    {
        $token = bin2hex(random_bytes(32));
        $row = $this->conn()->fetchAssociative(
            "UPDATE import_customer_invitation SET state = 'invited', token_hash = ?, invited_at = NOW(), expires_at = LEAST(NOW() + make_interval(days => ?), erase_at)
             WHERE user_id = ? AND state = 'imported' AND erase_at > NOW() + INTERVAL '1 day' RETURNING expires_at, erase_at",
            [hash('sha256', $token), self::LINK_DAYS, $userId]
        );

        return $row === false ? null : ['token' => $token, 'expiresAt' => (string) $row['expires_at'], 'eraseAt' => (string) $row['erase_at']];
    }

    /** L'envoi du courriel a échoué : l'invité redevient « repris », il pourra être invité à l'envoi suivant (rien n'est parti) */
    public function uninvite(int $userId): void
    {
        $this->conn()->executeStatement("UPDATE import_customer_invitation SET state = 'imported', token_hash = NULL, invited_at = NULL, expires_at = NULL WHERE user_id = ? AND state = 'invited'", [$userId]);
    }

    /**
     * L'invitation que ce jeton ouvre : invité, lien non expiré, compte toujours là. Null pour tout le reste, sans dire pourquoi.
     *
     * @return array<string, mixed>|null { id, user_id, job_id, locale, marketing_claimed, expires_at, erase_at }
     */
    public function byToken(mixed $token): ?array
    {
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->conn()->fetchAssociative(
            "SELECT i.id, i.user_id, i.job_id, i.locale, i.marketing_claimed, i.expires_at, i.erase_at FROM import_customer_invitation i JOIN \"user\" u ON u.id = i.user_id
             WHERE i.token_hash = ? AND i.state = 'invited' AND i.expires_at > NOW() AND u.deleted_at IS NULL",
            [hash('sha256', $token)]
        );
        if ($row === false) {
            return null;
        }
        $row['marketing_claimed'] = in_array($row['marketing_claimed'], [true, 't', 'true', 1, '1'], true);

        return $row;
    }

    /** Ferme l'invitation par l'acceptation ; vrai si c'est bien ce pas qui a été fait (le lien ne sert qu'une fois) */
    public function accept(int $invitationId): bool
    {
        return $this->conn()->executeStatement("UPDATE import_customer_invitation SET state = 'accepted', accepted_at = NOW(), token_hash = NULL WHERE id = ? AND state = 'invited' AND expires_at > NOW()", [$invitationId]) === 1;
    }

    /**
     * Efface un invité en attente : son compte, ses adresses, sa ligne d'invitation, la trace de l'import. Rien d'autre n'est touché
     * (ni une inscription à l'infolettre, ni une réservation faites par la même personne hors de l'import). Un invité qu'une commande,
     * un panier, un abonnement ou un livre tient n'est PAS effacé : il est gardé comme un compte d'achat sans compte ordinaire.
     *
     * @return string `erased`, `kept`, ou `none` (ce compte n'est pas un invité en attente)
     */
    public function erase(int $userId): string
    {
        $conn = $this->conn();
        if ($conn->fetchOne("SELECT 1 FROM import_customer_invitation WHERE user_id = ? AND state IN ('imported', 'invited')", [$userId]) === false) {
            return 'none';
        }
        if ($this->held($userId)) {
            return $this->keep($userId);
        }
        $conn->beginTransaction();
        try {
            // de nouveau sous verrou : l'invité a pu accepter entre-temps
            if ($conn->fetchOne("SELECT 1 FROM import_customer_invitation WHERE user_id = ? AND state IN ('imported', 'invited') FOR UPDATE", [$userId]) === false) {
                $conn->rollBack();

                return 'none';
            }
            $conn->executeStatement('UPDATE "user" SET primary_address_id = NULL WHERE id = ?', [$userId]);
            $conn->executeStatement('DELETE FROM adress WHERE user_adress_id = ?', [$userId]);
            $conn->executeStatement("DELETE FROM import_item WHERE kind = 'customer' AND entity_id = ?", [$userId]);
            $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [$userId]); // sa ligne d'invitation suit (suppression en cascade)
            $conn->commit();
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
            $conn->rollBack();

            return $this->keep($userId); // une donnée que la liste ne connaît pas tient ce compte : on ne force rien
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        $this->cache->invalidateTenant();

        return 'erased';
    }

    /**
     * Efface les invités en attente dont le délai est passé (passage horaire) ; rend leur nombre par issue.
     *
     * @return array{erased: int, kept: int}
     */
    public function eraseDue(int $limit = 500): array
    {
        return $this->eraseAll($this->conn()->fetchFirstColumn("SELECT user_id FROM import_customer_invitation WHERE state IN ('imported', 'invited') AND erase_at <= NOW() ORDER BY id LIMIT " . max(1, $limit)));
    }

    /** Efface les invités en attente d'un import (annulation). @return array{erased: int, kept: int} */
    public function eraseOfJob(int $jobId): array
    {
        return $this->eraseAll($this->conn()->fetchFirstColumn("SELECT user_id FROM import_customer_invitation WHERE job_id = ? AND state IN ('imported', 'invited') ORDER BY id", [$jobId]));
    }

    /** @return array<string, int> le nombre d'invités d'un import par état */
    public function countsOf(int $jobId): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($this->conn()->fetchAllKeyValue('SELECT state, COUNT(*) FROM import_customer_invitation WHERE job_id = ? GROUP BY state', [$jobId]) as $state => $n) {
            $counts[(string) $state] = (int) $n;
        }

        return $counts;
    }

    /** @return list<int> les invités d'un import qui n'ont jamais été invités */
    public function toInvite(int $jobId): array
    {
        return array_map('intval', $this->conn()->fetchFirstColumn("SELECT user_id FROM import_customer_invitation WHERE job_id = ? AND state = 'imported' AND erase_at > NOW() + INTERVAL '1 day' ORDER BY id", [$jobId]));
    }

    /**
     * Les clients repris par un import, du plus récent au plus ancien, avec leur état et leurs trois dates.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(int $jobId, ?string $state, int $page, int $perPage): array
    {
        $where = ' WHERE i.job_id = ?' . ($state !== null ? ' AND i.state = ?' : '');
        $args = $state !== null ? [$jobId, $state] : [$jobId];
        $total = (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM import_customer_invitation i' . $where, $args);
        $rows = $this->conn()->fetchAllAssociative(
            'SELECT i.user_id, i.state, i.marketing_claimed, i.imported_at, i.invited_at, i.expires_at, i.erase_at, i.accepted_at, u.email, u.firstname, u.lastname
             FROM import_customer_invitation i JOIN "user" u ON u.id = i.user_id' . $where . ' ORDER BY i.id DESC LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $args
        );

        return [$rows, $total];
    }

    /** L'état d'invité d'un compte, pour l'administration : `imported`, `invited`, `accepted`, `kept`, ou null (pas un compte repris) */
    public function stateOf(int $userId): ?string
    {
        $state = $this->conn()->fetchOne('SELECT state FROM import_customer_invitation WHERE user_id = ?', [$userId]);

        return $state === false ? null : (string) $state;
    }

    private function held(int $userId): bool
    {
        foreach (self::HOLDERS as $table => $column) {
            if ($this->conn()->fetchOne("SELECT 1 FROM $table WHERE $column = ? LIMIT 1", [$userId]) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Le compte reste : il devient un compte d'achat sans compte ordinaire (vérifié, rôle de client, toujours sans mot de passe choisi) */
    private function keep(int $userId): string
    {
        $em = $this->emProvider->getEntityManager();
        $this->conn()->executeStatement("UPDATE import_customer_invitation SET state = 'kept', token_hash = NULL WHERE user_id = ? AND state IN ('imported', 'invited')", [$userId]);
        $user = $em->find(User::class, $userId);
        if ($user instanceof User) {
            $user->setIsVerified(true)->setRoles(['ROLE_USER_INTERNET']);
            $em->flush();
        }

        return 'kept';
    }

    /** @param list<int|string> $ids @return array{erased: int, kept: int} */
    private function eraseAll(array $ids): array
    {
        $out = ['erased' => 0, 'kept' => 0];
        foreach ($ids as $id) {
            $done = $this->erase((int) $id);
            if (isset($out[$done])) {
                ++$out[$done];
            }
        }

        return $out;
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }
}
