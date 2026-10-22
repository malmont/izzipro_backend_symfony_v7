<?php

namespace App\Services\PosAuditService;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Idempotence des écritures de caisse (chantier C5, 22/10/2026). En-tête `Idempotency-Key` (UUID du POS), facultatif :
 * sans lui, rien ne change. Cycle d'une clé :
 *  1. begin()  : réservation par insertion sous contrainte d'unicité (statut in_progress) ; clé connue → rejeu de la
 *                réponse gardée (Idempotent-Replayed: true), 409 si encore en cours, 422 si corps, route ou
 *                utilisateur différents ;
 *  2. attach() : appelé par le cas d'usage DANS LA TRANSACTION SQL de l'écriture (même connexion) : statut committed +
 *                objet créé (+ réponse quand elle est connue). Si la transaction est annulée, la clé reste in_progress ;
 *  3. complete(): à la réponse : 2xx → réponse gardée (done) ; refus (4xx, 5xx) ou exception → réservation effacée, la
 *                clé reste réutilisable.
 * Une réservation in_progress de plus de 60 s sans écriture (sinon elle serait committed) est reprise ; une clé
 * committed sans réponse gardée (coupure entre la validation et la réponse) est rejouée par reconstitution.
 * Conservation 7 jours, purge au fil de l'eau.
 */
class PosIdempotencyService implements ResetInterface
{
    public const HEADER = 'Idempotency-Key';
    public const REPLAYED_HEADER = 'Idempotent-Replayed';
    public const ROUTES = ['order_create_multi_payment', 'order_cancel', 'caisse_open', 'caisse_close', 'caisse_deposit', 'caisse_withdraw',
        'caisse_cash_funddeposit', 'caisse_cash_fund_withdraw', 'caisse_drawer_open',
        'pos_terminal_payment_create', 'pos_terminal_payment_cancel', 'pos_terminal_payment_refund'];
    private const STALE_SECONDS = 60;
    private const RETENTION_DAYS = 7;

    private ?int $rowId = null;
    private ?string $currentKey = null;
    private string $locale = 'fr';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PosTerminalService $terminals, private readonly PosWriteResponseBuilder $responses)
    {
    }

    /** Réponse à renvoyer sans exécuter la requête (rejeu, 409, 422), ou null pour laisser passer */
    public function begin(Request $request, User $user): ?Response
    {
        $key = trim((string) $request->headers->get(self::HEADER, ''));
        if ($key === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return $this->error('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key : UUID attendu', 422);
        }
        $key = strtolower($key);
        $this->currentKey = $key;
        $this->locale = preg_match('/^[a-z]{2}$/', (string) $request->query->get('locale', 'fr')) ? (string) $request->query->get('locale') : 'fr';
        $route = (string) $request->attributes->get('_route') . ' ' . $request->getPathInfo();
        $hash = self::bodyHash($request->getContent());
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM pos_idempotency_key WHERE created_at < ?', [(new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s')]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $connection->insert('pos_idempotency_key', [
                    'idem_key' => $key, 'user_id' => $user->getId(), 'terminal_id' => $this->terminals->current()['id'] ?? null,
                    'route' => mb_substr($route, 0, 80), 'body_hash' => $hash, 'status' => 'in_progress', 'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $this->rowId = (int) $connection->lastInsertId();

                return null; // réservation prise : la requête s'exécute
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // clé déjà connue : décision ci-dessous
            }
            $row = $connection->fetchAssociative('SELECT * FROM pos_idempotency_key WHERE idem_key = ?', [$key]);
            if (!$row) {
                continue; // effacée entre-temps (réservation levée) : nouvelle tentative d'insertion
            }
            if ((int) $row['user_id'] !== (int) $user->getId() || $row['route'] !== mb_substr($route, 0, 80) || $row['body_hash'] !== $hash) {
                return $this->error('IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key déjà utilisée pour une autre requête (corps, route ou utilisateur différents)', 422);
            }
            if ($row['status'] === 'done' || $row['status'] === 'committed') {
                return $this->replay($row);
            }
            // in_progress : encore en cours, ou périmée sans écriture (une écriture validée l'aurait passée à committed)
            $taken = $connection->executeStatement(
                "UPDATE pos_idempotency_key SET created_at = ? WHERE id = ? AND status = 'in_progress' AND created_at < ?",
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id'], (new \DateTimeImmutable('-' . self::STALE_SECONDS . ' seconds'))->format('Y-m-d H:i:s')]
            );
            if ($taken === 1) {
                $this->rowId = (int) $row['id'];

                return null; // réservation périmée reprise : la requête s'exécute
            }
            $fresh = $connection->fetchAssociative('SELECT * FROM pos_idempotency_key WHERE id = ?', [$row['id']]);
            if ($fresh && $fresh['status'] !== 'in_progress') {
                return $this->replay($fresh); // l'écriture s'est validée pendant notre attente du verrou de ligne
            }
            $response = $this->error('IDEMPOTENCY_IN_PROGRESS', 'Requête identique en cours de traitement : réessayez dans quelques secondes', 409);
            $response->headers->set('Retry-After', '2');

            return $response;
        }

        return $this->error('IDEMPOTENCY_IN_PROGRESS', 'Requête identique en cours de traitement : réessayez dans quelques secondes', 409);
    }

    /**
     * À appeler par le cas d'usage dans la transaction SQL de l'écriture (même connexion) : la clé devient committed
     * avec l'objet créé ; la réponse, si elle est connue, est gardée dans le même ordre SQL. Sans clé active : rien.
     */
    public function attach(string $subjectType, ?int $subjectId, ?array $response = null, int $status = 201): void
    {
        if ($this->rowId === null) {
            return;
        }
        $this->emProvider->getEntityManager()->getConnection()->executeStatement(
            "UPDATE pos_idempotency_key SET status = ?, subject_type = ?, subject_id = ?, response_status = ?, response_body = ?, finished_at = ? WHERE id = ? AND status = 'in_progress'",
            [$response !== null ? 'done' : 'committed', $subjectType, $subjectId, $response !== null ? $status : null,
                $response !== null ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $this->rowId]
        );
    }

    /** À la réponse : succès gardé, refus effacé (clé réutilisable) */
    public function complete(?Response $response): void
    {
        if ($this->rowId === null) {
            return;
        }
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $id = $this->rowId;
        $this->rowId = null;
        if ($response !== null && $response->isSuccessful() && $response instanceof JsonResponse) {
            $connection->executeStatement(
                "UPDATE pos_idempotency_key SET status = 'done', response_status = ?, response_body = ?, finished_at = COALESCE(finished_at, ?) WHERE id = ? AND response_body IS NULL",
                [$response->getStatusCode(), $response->getContent(), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
            );

            return;
        }
        // Refus ou exception : l'écriture n'a pas eu lieu (une écriture validée aurait passé la clé à committed)
        $connection->executeStatement("DELETE FROM pos_idempotency_key WHERE id = ? AND status = 'in_progress'", [$id]);
    }

    /** Clé POS de la requête en cours (UUID en minuscules), même si la route n'est pas idempotente ; null sans en-tête */
    public function currentKey(): ?string
    {
        return $this->currentKey;
    }

    public function isActive(): bool
    {
        return $this->rowId !== null;
    }

    public function reset(): void
    {
        $this->rowId = null;
        $this->currentKey = null;
        $this->locale = 'fr';
    }

    private function replay(array $row): Response
    {
        if ($row['response_body'] === null) {
            // committed sans réponse gardée (coupure entre validation et réponse) : reconstitution depuis l'objet créé
            $rebuilt = $row['subject_type'] !== null ? $this->responses->rebuild($row['subject_type'], (int) $row['subject_id'], $this->locale) : null;
            if ($rebuilt === null) {
                return $this->error('IDEMPOTENCY_REPLAY_UNAVAILABLE', 'Écriture déjà enregistrée, réponse indisponible : vérifiez dans l\'historique de la caisse', 409);
            }
            $body = json_encode($rebuilt['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->emProvider->getEntityManager()->getConnection()->executeStatement(
                "UPDATE pos_idempotency_key SET status = 'done', response_status = ?, response_body = ? WHERE id = ? AND response_body IS NULL", [$rebuilt['status'], $body, $row['id']]);
            $row = ['response_status' => $rebuilt['status'], 'response_body' => $body];
        }
        // Ré-encodage par JsonResponse : octets identiques à la réponse d'origine (mêmes options d'échappement)
        $decoded = json_decode((string) $row['response_body'], true);
        $response = $decoded !== null ? new JsonResponse($decoded, (int) $row['response_status']) : new JsonResponse($row['response_body'], (int) $row['response_status'], [], true);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse((new CashRegisterException($code, $message, $status))->toBody(), $status);
    }

    /** Empreinte du corps en forme canonique (clés triées), pour tolérer un ordre de clés différent */
    public static function bodyHash(string $content): string
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $sort = function (array &$value) use (&$sort): void {
                if (array_is_list($value)) {
                    foreach ($value as &$item) { if (is_array($item)) { $sort($item); } }
                } else {
                    ksort($value);
                    foreach ($value as &$item) { if (is_array($item)) { $sort($item); } }
                }
            };
            $sort($decoded);
            $content = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }

        return hash('sha256', $content);
    }
}
