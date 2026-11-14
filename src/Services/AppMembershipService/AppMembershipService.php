<?php

namespace App\Services\AppMembershipService;

use App\Entity\PosCustomer;
use App\Entity\PosCustomerConsent;
use App\Entity\User;
use App\Entity\UserAppMembership;
use App\Repository\UserAppMembershipRepository;
use App\Security\AppFamilies;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Appartenances des comptes clients aux familles d'applications (lot APPS-MEMBRES M1, 14/11/2026, `docs/apps-membres.md`).
 * Point d'entrée unique : toute création ou retrait passe ici et s'inscrit au journal du site (content_audit_log, ressource
 * `user_app`, action `grant` ou `revoke`). Jamais d'ajout silencieux : une appartenance naît d'un geste du client (inscription
 * dans la famille, commande en invité suivie du choix d'un mot de passe, consentement de rattachement en caisse) ou d'une
 * invitation de l'équipe, jamais d'une simple connexion.
 *
 * M1 ne refuse rien : `sessionFamily()` note seulement (journal de l'application, repère OBSERVE_MARK) la connexion d'un compte
 * client à une famille dont il n'est pas membre, ce que le mode « exiger » refusera au lot M3.
 *
 * Les points d'entrée appelés depuis une route existante (`registered`, `invited`, `passwordChosen`, `posLinked`,
 * `posUnlinked`, `sessionFamily`) ne lèvent jamais : une appartenance qui ne s'écrit pas est journalisée en erreur et la route
 * continue comme avant ce lot.
 */
final class AppMembershipService
{
    public const ACTION_GRANT = 'grant';
    public const ACTION_REVOKE = 'revoke';
    public const ACTION_ACCEPT = 'accept';

    /** Repère des lignes du journal de l'application en mode « observer » : une ligne par connexion qui serait refusée */
    public const OBSERVE_MARK = '[APPS-MEMBRES] observer';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ContentAuditRecorder $audit,
        private readonly AppTermsService $terms,
        private readonly LoggerInterface $logger,
        private readonly TenantConnectionProvider $tenant,
        private readonly AppJoinTokens $tokens,
    ) {
    }

    // ------------------------------------------------------------------ mode du site, refus, « rejoindre » (lot M3)

    /** Mode du site : « observe » (rien n'est refusé) ou « require » ; « observe » si la lecture échoue : une panne ne ferme jamais la porte */
    public function mode(): string
    {
        try {
            return $this->repository()->mode();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[APPS-MEMBRES] mode du site illisible (%s) : « observe » retenu', $e->getMessage()));

            return AppFamilies::MODE_OBSERVE;
        }
    }

    /**
     * Change le mode du site, au journal (action `mode`). Passer en « require » est refusé tant que la reprise de l'historique n'est
     * pas faite : les clients d'avant n'auraient pas encore leur appartenance. Revenir en « observe » est toujours possible.
     *
     * @return bool vrai si le mode a changé
     *
     * @throws AppMembershipException 422 MODE_INVALID, 409 BACKFILL_REQUIRED, 409 SITE_NOT_SET_UP
     */
    public function changeMode(string $mode): bool
    {
        $before = $this->repository()->mode();
        $this->assertModeAllowed($mode);
        if ($before === $mode) {
            return false;
        }
        if (!$this->repository()->setMode($mode)) {
            throw new AppMembershipException('SITE_NOT_SET_UP', 'Ce site n\'a pas encore de fiche Entreprise : son mode ne peut pas être réglé.', 409);
        }
        $this->journalMode($before, $mode);

        return true;
    }

    /** @throws AppMembershipException 422 MODE_INVALID, 409 BACKFILL_REQUIRED */
    public function assertModeAllowed(string $mode): void
    {
        if (!in_array($mode, AppFamilies::MODES, true)) {
            throw new AppMembershipException('MODE_INVALID', sprintf('Mode inconnu : « %s » (attendu : %s).', $mode, implode(', ', AppFamilies::MODES)), 422, ['params' => ['modes' => AppFamilies::MODES]]);
        }
        if ($mode === AppFamilies::MODE_REQUIRE && $this->repository()->backfilledAt() === null) {
            throw new AppMembershipException('BACKFILL_REQUIRED', 'La reprise de l\'historique des appartenances de ce site doit être faite avant de passer en mode « require » (app:members:backfill).', 409);
        }
    }

    /** Le changement de mode, au journal du site (aussi appelé par la fiche Entreprise d'EasyAdmin, qui enregistre elle-même la valeur) */
    public function journalMode(string $before, string $after): void
    {
        $this->audit->record(AppFamilies::AUDIT_RESOURCE, null, 'mode', ['mode' => $before], ['mode' => $after], [$after]);
    }

    /**
     * Le refus `APP_MEMBERSHIP_REQUIRED`, ou null : la session peut être rendue. Refus seulement en mode « require », pour un compte
     * CLIENT, quand le front a dit son application : (a) le compte n'est pas membre de la famille ; (b) il en est membre par invitation
     * de l'équipe, n'a encore rien accepté, et la famille a des conditions. Le refus porte les conditions et un jeton de passage.
     * Sans `app` en mode « require », la connexion passe comme avant et elle est notée (un mode « strict » la refusera plus tard).
     * Une panne de ce contrôle ne ferme jamais la porte : elle est journalisée en erreur et la session est rendue.
     *
     * @return array<string, mixed>|null corps de la réponse 403
     */
    public function gate(User $user, mixed $app, string $platform, string $context, string $host, mixed $locale): ?array
    {
        try {
            if ($this->mode() !== AppFamilies::MODE_REQUIRE || AppFamilies::isTeam($user)) {
                return null;
            }
            $family = AppFamilies::of($app);
            if ($family === null) {
                $this->logger->info(sprintf('[APPS-MEMBRES] require : connexion sans application du compte %d (%s, site %s) ; session rendue', (int) $user->getId(), $context, $this->tenant->getTenantCode() ?? 'inconnu'));

                return null;
            }
            $membership = $this->repository()->membership((int) $user->getId(), $family);
            $reason = match (true) {
                $membership === null => AppFamilies::REASON_MEMBERSHIP,
                $membership['source'] === AppFamilies::SOURCE_INVITE && $membership['termsVersion'] === null && $this->terms->version($family) !== null => AppFamilies::REASON_TERMS,
                default => null,
            };
            if ($reason === null) {
                return null;
            }
            $token = $this->tokens->issue($user, $family, $platform, $reason);
            $this->logger->info(sprintf('[APPS-MEMBRES] require : le compte %d doit rejoindre « %s » (%s, %s, site %s) ; session refusée', (int) $user->getId(), $family, $reason, $context, $this->tenant->getTenantCode() ?? 'inconnu'));

            return ['error' => 'APP_MEMBERSHIP_REQUIRED', 'code' => 'APP_MEMBERSHIP_REQUIRED',
                'message' => $reason === AppFamilies::REASON_TERMS ? 'Acceptez les conditions de cette application pour continuer.' : 'Rejoignez cette application pour continuer.',
                'app' => $family, 'reason' => $reason, 'terms' => $this->termsOf($family, $host, $locale), 'joinToken' => $token['token'], 'expiresAt' => $token['expiresAt']];
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[APPS-MEMBRES] contrôle d\'appartenance en panne (%s) : session rendue', $e->getMessage()));

            return null;
        }
    }

    /**
     * « Rejoindre » : le client accepte les conditions de la famille. Crée l'appartenance (source `join`) ou, pour un invité de
     * l'équipe, enregistre son acceptation sur l'appartenance qu'il a déjà (action `accept`) ; le jeton de passage ne sert plus.
     * Jamais d'ajout silencieux : sans cet appel, rien n'est créé.
     *
     * @return array{user: User, app: string, platform: string} de quoi rendre la session, comme une connexion de la même plateforme
     *
     * @throws AppMembershipException 401 JOIN_TOKEN_INVALID, 422 TERMS_NOT_ACCEPTED, 409 TERMS_VERSION_CHANGED (avec les nouvelles `terms` ; le jeton reste valable)
     */
    public function join(mixed $token, mixed $termsVersion, mixed $accepted, string $host, mixed $locale): array
    {
        $invalid = new AppMembershipException('JOIN_TOKEN_INVALID', 'Cette étape a expiré : reconnectez-vous.', 401);
        $proof = $this->tokens->read($token) ?? throw $invalid;
        $user = $this->emProvider->getEntityManager()->find(User::class, $proof['userId']);
        if (!$user instanceof User || $user->isDeleted() || !$user->isVerified() || AppFamilies::isTeam($user)) {
            throw $invalid;
        }
        if ($accepted !== true) {
            throw new AppMembershipException('TERMS_NOT_ACCEPTED', 'Les conditions doivent être acceptées pour rejoindre cette application.', 422);
        }
        $family = $proof['app'];
        $version = $this->terms->version($family);
        if ((is_string($termsVersion) && $termsVersion !== '' ? $termsVersion : null) !== $version) {
            throw new AppMembershipException('TERMS_VERSION_CHANGED', 'Les conditions ont changé : relisez-les avant d\'accepter.', 409, ['app' => $family, 'terms' => $this->termsOf($family, $host, $locale)]);
        }
        $userId = (int) $user->getId();
        if ($this->repository()->membership($userId, $family) === null) {
            $this->grant($user, $family, AppFamilies::SOURCE_JOIN, $version);
        } elseif ($version !== null && $this->repository()->accept($userId, $family, $version)) {
            $this->audit->record(AppFamilies::AUDIT_RESOURCE, $userId, self::ACTION_ACCEPT, ['termsVersion' => null], ['app' => $family, 'termsVersion' => $version], [$family, self::ACTION_ACCEPT]);
        }
        if ($this->repository()->membership($userId, $family) === null) {
            throw $invalid; // le compte ne peut pas être membre (compte sans mot de passe choisi) : rien n'a été créé
        }
        $this->tokens->consume((string) $token);

        return ['user' => $user, 'app' => $family, 'platform' => $proof['platform']];
    }

    /** @return array<string, mixed> les conditions de la famille telles que le refus et « rejoindre » les rendent (sans la clé `app`, dite à côté) */
    private function termsOf(string $family, string $host, mixed $locale): array
    {
        return array_diff_key($this->terms->terms($family, $host, AppTermsService::locale($locale)), ['app' => 1]);
    }

    private function repository(): UserAppMembershipRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);
    }

    // ------------------------------------------------------------------ appartenances

    /**
     * Crée l'appartenance si elle manque. Idempotent : une seconde demande ne change rien (ni la date, ni la source, ni la version)
     * et n'écrit rien au journal. Un compte d'équipe, un compte effacé ou sans identifiant ne reçoit jamais d'appartenance ; un compte
     * né d'une commande en invité non plus, tant que le client n'a pas choisi son mot de passe (`passwordSet` faux), quelles que
     * soient ses commandes.
     *
     * @param ?string $termsVersion version des conditions acceptées ; null : aucune acceptation n'est enregistrée
     * @param ?\DateTimeImmutable $joinedAt date d'entrée (maintenant par défaut)
     * @param ?\DateTimeImmutable $acceptedAt date de l'acceptation (celle de l'entrée par défaut) ; ignorée sans version
     * @param list<string> $traces traces d'usage qui fondent une appartenance reprise de l'historique, gardées au journal
     *
     * @return bool vrai si l'appartenance vient d'être créée
     */
    public function grant(User $user, string $family, string $source, ?string $termsVersion = null, ?\DateTimeImmutable $joinedAt = null, ?\DateTimeImmutable $acceptedAt = null, array $traces = []): bool
    {
        if (!in_array($family, AppFamilies::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas une famille d\'applications.', $family));
        }
        if (!in_array($source, AppFamilies::SOURCES, true)) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas une source d\'appartenance.', $source));
        }
        if ($user->getId() === null || $user->isDeleted() || !$user->isPasswordSet() || AppFamilies::isTeam($user)) {
            return false;
        }
        $termsVersion = $termsVersion !== null && trim($termsVersion) !== '' ? mb_substr(trim($termsVersion), 0, 40) : null;
        $joinedAt ??= new \DateTimeImmutable();
        $acceptedAt = $termsVersion === null ? null : ($acceptedAt ?? $joinedAt);
        $row = ['app' => $family, 'source' => $source, 'joinedAt' => $joinedAt->format('Y-m-d H:i:s'), 'termsVersion' => $termsVersion, 'termsAcceptedAt' => $acceptedAt?->format('Y-m-d H:i:s')];
        $created = (int) $this->emProvider->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO user_app_membership (user_id, app, source, joined_at, terms_version, terms_accepted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT (user_id, app) DO NOTHING',
            [(int) $user->getId(), $family, $source, $row['joinedAt'], $termsVersion, $row['termsAcceptedAt']]
        ) === 1;
        if ($created) {
            $this->audit->record(AppFamilies::AUDIT_RESOURCE, $user->getId(), self::ACTION_GRANT, null, $row + ($traces === [] ? [] : ['traces' => array_values($traces)]), [$family, $source]);
        }

        return $created;
    }

    /**
     * Retire l'appartenance d'un compte à une famille ; idempotent. Le client devra « rejoindre » de nouveau pour revenir.
     *
     * @param string $reason repère ajouté aux champs du journal (ex. `pos_customer_erased`)
     *
     * @return bool vrai si une appartenance a été retirée
     */
    public function revoke(int $userId, string $family, string $reason): bool
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $before = $connection->fetchAssociative('SELECT app, source, joined_at AS "joinedAt", terms_version AS "termsVersion", terms_accepted_at AS "termsAcceptedAt" FROM user_app_membership WHERE user_id = ? AND app = ?', [$userId, $family]);
        if ($before === false || (int) $connection->executeStatement('DELETE FROM user_app_membership WHERE user_id = ? AND app = ?', [$userId, $family]) !== 1) {
            return false;
        }
        $this->audit->record(AppFamilies::AUDIT_RESOURCE, $userId, self::ACTION_REVOKE, $before, null, [$family, $reason]);

        return true;
    }

    /**
     * La reprise de l'historique du site est-elle faite (lot M2) ? Tant qu'elle ne l'est pas, les vrais clients d'avant ce chantier
     * n'ont pas encore leur appartenance : aucune règle ne doit les écarter. Ne lève jamais (faux en cas d'erreur).
     */
    public function backfillDone(): bool
    {
        try {
            /** @var UserAppMembershipRepository $repository */
            $repository = $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);

            return $repository->backfilledAt() !== null;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[APPS-MEMBRES] état de la reprise illisible (%s)', $e->getMessage()));

            return false;
        }
    }

    /** Le compte est-il membre de la boutique ou de la caisse ? Seuls ces comptes sont visibles de la caisse une fois la reprise faite */
    public function visibleFromPos(int $userId): bool
    {
        return $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM user_app_membership WHERE user_id = ? AND app IN (?, ?)', [$userId, AppFamilies::BOUTIQUE, AppFamilies::POS]) !== false;
    }

    /** @return list<UserAppMembership> appartenances du compte, de la plus ancienne à la plus récente */
    public function of(User $user): array
    {
        /** @var UserAppMembershipRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(UserAppMembership::class);

        return $user->getId() === null ? [] : $repository->findOfUser((int) $user->getId());
    }

    public function has(User $user, string $family): bool
    {
        return $user->getId() !== null && $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM user_app_membership WHERE user_id = ? AND app = ?', [(int) $user->getId(), $family]) !== false;
    }

    /**
     * Inscription dans une application (`POST /api/register` avec `app`) : le compte entre dans la famille. Sans `app` connu, rien :
     * une inscription qui ne dit pas son application ne crée aucune appartenance. La version des conditions n'est enregistrée que si
     * le front envoie celle qui est en vigueur ; autrement l'appartenance est créée sans acceptation.
     */
    public function registered(User $user, mixed $app, mixed $termsVersion): void
    {
        $family = AppFamilies::of($app);
        if ($family === null) {
            return;
        }
        $this->safely('inscription', fn () => $this->grant($user, $family, AppFamilies::SOURCE_REGISTER, $this->current($family, $termsVersion)));
    }

    /** Compte client créé par l'équipe de Mémoires Vivantes : membre de la famille, sans conditions acceptées (elles le seront à sa première connexion, lot M3) */
    public function invited(User $user): void
    {
        $this->safely('invitation', fn () => $this->grant($user, AppFamilies::MEMOIRES, AppFamilies::SOURCE_INVITE));
    }

    /**
     * Un compte né d'une commande en invité choisit son mot de passe : c'est la « création ultérieure du compte ». Il entre dans la
     * famille boutique à la date de sa première commande en invité, avec la version des conditions gardée sur cette commande.
     *
     * @param bool $hadPassword le compte avait déjà un mot de passe choisi par le client avant ce changement
     */
    public function passwordChosen(User $user, bool $hadPassword): void
    {
        if ($hadPassword) {
            return;
        }
        $this->safely('mot de passe choisi', function () use ($user): void {
            $order = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT order_date, terms_version FROM "order" WHERE user_id_id = ? AND guest_token IS NOT NULL ORDER BY order_date ASC, id ASC LIMIT 1', [(int) $user->getId()]);
            if ($order !== false) {
                $at = new \DateTimeImmutable((string) $order['order_date']);
                $this->grant($user, AppFamilies::BOUTIQUE, AppFamilies::SOURCE_GUEST_CHECKOUT, $order['terms_version'], $at, $at);
            }
        });
    }

    /** Fiche de caisse rattachée à un compte (consentement `account_link`) : le compte entre dans la famille de la caisse, à la version du texte consenti */
    public function posLinked(User $user, PosCustomer $customer): void
    {
        $this->safely('rattachement de caisse', function () use ($user, $customer): void {
            $consent = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT text_version, at FROM pos_customer_consent WHERE customer_id = ? AND purpose = ? AND granted = true ORDER BY at DESC, id DESC LIMIT 1', [(int) $customer->getId(), PosCustomerConsent::ACCOUNT_LINK]);
            $this->grant($user, AppFamilies::POS, AppFamilies::SOURCE_POS_LINK, $consent === false ? null : $consent['text_version'], null, $consent === false ? null : new \DateTimeImmutable((string) $consent['at']));
        });
    }

    /**
     * Un compte perd son rattachement à une fiche de caisse : son appartenance à la famille de la caisse part avec lui. Raisons :
     * `pos_customer_erased` (fiche effacée à la demande du client), `merge` (fusion de deux fiches rattachées à deux comptes différents).
     * Le retrait du consentement `account_link` passe par `revoke()` (raison `consent_withdrawn`), dans la transaction de la fiche.
     */
    public function posUnlinked(?int $userId, string $reason): void
    {
        if ($userId !== null) {
            $this->safely('rattachement de caisse défait', fn () => $this->revoke($userId, AppFamilies::POS, $reason));
        }
    }

    /**
     * Famille de la session demandée (`app` de la connexion, de la vérification du code ou du rafraîchissement), à porter dans le
     * jeton ; null sans `app` connu. Mode « observer » : si le compte est un compte client qui n'est pas membre de cette famille,
     * une ligne est écrite au journal de l'application et la session est rendue quand même.
     */
    public function sessionFamily(User $user, mixed $app, string $context): ?string
    {
        $family = AppFamilies::of($app);
        if ($family === null) {
            return null;
        }
        $this->safely('observation', function () use ($user, $family, $context): void {
            if (!AppFamilies::isTeam($user) && !$this->has($user, $family)) {
                $this->logger->info(sprintf('%s : le compte %d n\'est pas membre de « %s » (%s, site %s) ; session rendue', self::OBSERVE_MARK, (int) $user->getId(), $family, $context, $this->tenant->getTenantCode() ?? 'inconnu'));
            }
        });

        return $family;
    }

    /** La version envoyée par le front, si c'est bien celle en vigueur ; sinon null (on n'enregistre pas l'acceptation d'un autre texte) */
    public function current(string $family, mixed $termsVersion): ?string
    {
        return is_string($termsVersion) && $termsVersion !== '' && $termsVersion === $this->terms->version($family) ? $termsVersion : null;
    }

    /** Version des conditions de la boutique à garder sur une commande en invité : celle envoyée si elle est en vigueur, sinon null ; ne lève jamais */
    public function guestTermsVersion(mixed $termsVersion): ?string
    {
        try {
            return $this->current(AppFamilies::BOUTIQUE, $termsVersion);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[APPS-MEMBRES] commande en invité : version des conditions non lue (%s)', $e->getMessage()));

            return null;
        }
    }

    private function safely(string $what, callable $do): void
    {
        try {
            $do();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[APPS-MEMBRES] %s : appartenance non écrite (%s)', $what, $e->getMessage()));
        }
    }
}
