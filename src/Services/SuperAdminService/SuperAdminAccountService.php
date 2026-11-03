<?php

namespace App\Services\SuperAdminService;

use App\Entity\User;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Compte du propriétaire de la plateforme (ROLE_SUPER_ADMIN) sur toutes les bases (03/11/2026). Un site n'a que ses propres
 * utilisateurs : le super administrateur existe donc une fois par base, et le modèle `gmasuite` le transmet aux nouveaux sites.
 * Cet écran crée ou met à jour ce compte partout d'un seul geste : même courriel, même mot de passe (haché une fois), rôles
 * ROLE_ADMIN + ROLE_SUPER_ADMIN, compte vérifié, code à usage unique activé (de toute façon imposé aux super administrateurs par
 * TwoFactorPolicy). Chaque base est traitée indépendamment, par PDO (la connexion du site courant n'est pas touchée) ; le mot de
 * passe n'est jamais journalisé.
 */
class SuperAdminAccountService
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private readonly TenantConnectionManager $tenants,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * État par base : comptes super administrateurs présents (courriel, OTP, vérifié).
     * @return list<array{dbname: string, codes: list<string>, accounts: list<array{email: string, otp: bool, verified: bool}>, error: ?string}>
     */
    public function status(): array
    {
        $rows = [];
        foreach ($this->databases() as $db) {
            $row = ['dbname' => $db['dbname'], 'codes' => $db['codes'], 'accounts' => [], 'error' => null];
            try {
                $pdo = $this->pdo($db['dbname']);
                $stmt = $pdo->query('SELECT email, roles, otp_enabled, is_verified FROM "user" WHERE roles::text LIKE \'%ROLE_SUPER_ADMIN%\' ORDER BY email');
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $u) {
                    $row['accounts'][] = ['email' => (string) $u['email'], 'otp' => (bool) $u['otp_enabled'], 'verified' => (bool) $u['is_verified']];
                }
            } catch (\Throwable $e) {
                $row['error'] = 'base injoignable';
                $this->logger->warning(sprintf('[SuperAdmin] état de %s : %s', $db['dbname'], $e->getMessage()));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Crée ou met à jour le compte sur toutes les bases.
     * @return list<array{dbname: string, codes: list<string>, action: string, message: ?string}> action : created | updated | error
     */
    public function apply(string $email, string $password, string $firstname, string $lastname): array
    {
        $hash = $this->hasher->hashPassword(new User(), $password);
        $roles = json_encode(self::ROLES);
        $results = [];
        $created = $updated = 0;
        foreach ($this->databases() as $db) {
            $result = ['dbname' => $db['dbname'], 'codes' => $db['codes'], 'action' => 'error', 'message' => null];
            try {
                $pdo = $this->pdo($db['dbname']);
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT id, roles FROM "user" WHERE lower(email) = lower(:email) LIMIT 1');
                $find->execute(['email' => $email]);
                $existing = $find->fetch(\PDO::FETCH_ASSOC);
                if ($existing) {
                    $merged = array_values(array_unique(array_merge((array) json_decode((string) $existing['roles'], true), self::ROLES)));
                    $stmt = $pdo->prepare('UPDATE "user" SET password = :hash, roles = :roles, firstname = :firstname, lastname = :lastname, is_verified = true, password_set = true, otp_enabled = true WHERE id = :id');
                    $stmt->execute(['hash' => $hash, 'roles' => json_encode($merged), 'firstname' => $firstname, 'lastname' => $lastname, 'id' => (int) $existing['id']]);
                    $result['action'] = 'updated';
                    ++$updated;
                } else {
                    // La colonne id n'a pas de valeur par défaut sur toutes les bases : identifiant posé à la main, séquence réalignée
                    $stmt = $pdo->prepare('INSERT INTO "user" (id, email, roles, password, username, firstname, lastname, is_verified, password_set, otp_enabled, created_at)
                        VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM "user"), :email, :roles, :hash, :email, :firstname, :lastname, true, true, true, NOW())');
                    $stmt->execute(['email' => $email, 'roles' => $roles, 'hash' => $hash, 'firstname' => $firstname, 'lastname' => $lastname]);
                    try {
                        $pdo->exec('SELECT setval(\'user_id_seq\', (SELECT MAX(id) FROM "user"))');
                    } catch (\Throwable) {
                        // base sans séquence : rien à réaligner
                    }
                    $result['action'] = 'created';
                    ++$created;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $result['message'] = 'échec : ' . $this->safeMessage($e);
                $this->logger->error(sprintf('[SuperAdmin] %s : %s', $db['dbname'], $e->getMessage()));
            }
            $results[] = $result;
        }
        $this->logger->info(sprintf('[SuperAdmin] compte super administrateur appliqué : %d base(s) créée(s), %d mise(s) à jour, %d échec(s)', $created, $updated, count($results) - $created - $updated));

        return $results;
    }

    /** @return list<string> erreurs de saisie (vide si tout est acceptable) */
    public static function validate(string $email, string $password, string $confirmation): array
    {
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse de courriel invalide.';
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = sprintf('Le mot de passe doit faire au moins %d caractères.', self::MIN_PASSWORD_LENGTH);
        }
        if ($password !== $confirmation) {
            $errors[] = 'La confirmation ne correspond pas au mot de passe.';
        }

        return $errors;
    }

    /**
     * Bases des sites, chacune une fois (deux codes peuvent partager une base), y compris le modèle des nouveaux sites.
     * @return list<array{dbname: string, codes: list<string>}>
     */
    public function databases(): array
    {
        $byDb = [];
        foreach ($this->tenants->getAllTenants() as $tenant) {
            $db = (string) $tenant['dbname'];
            $byDb[$db] ??= ['dbname' => $db, 'codes' => []];
            $byDb[$db]['codes'][] = (string) $tenant['code'];
        }
        ksort($byDb);

        return array_values($byDb);
    }

    private function pdo(string $dbname): \PDO
    {
        $p = $this->tenants->getTenantParams();

        return new \PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? 'localhost', (int) ($p['port'] ?? 5432), $dbname), (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
    }

    /** Message d'erreur sans détail de connexion */
    private function safeMessage(\Throwable $e): string
    {
        $m = $e->getMessage();
        if (str_contains($m, 'password') || str_contains($m, 'connection')) {
            return 'base injoignable';
        }

        return mb_substr(preg_replace('/\s+/', ' ', $m), 0, 160);
    }
}
