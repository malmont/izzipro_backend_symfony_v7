<?php

namespace App\LossPrevention\Repository;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;

/**
 * Réglages des règles changés par le site (une ligne par règle touchée). SQL direct.
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossRule>
 */
class LossRuleRepository extends EntityRepository
{
    /** @return array<string, array{enabled: bool, params: array<string, int>, personEnabled: ?bool, personParams: array<string, int>}> par code */
    public function all(): array
    {
        $out = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT code, enabled, params, person_enabled, person_params FROM loss_rule') as $r) {
            $out[(string) $r['code']] = [
                'enabled' => (bool) $r['enabled'],
                'params' => self::ints(json_decode((string) $r['params'], true)),
                'personEnabled' => $r['person_enabled'] === null ? null : (bool) $r['person_enabled'],
                'personParams' => self::ints(json_decode((string) ($r['person_params'] ?? 'null'), true)),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, int> $params
     * @param array<string, int> $personParams
     */
    public function save(string $code, bool $enabled, array $params, ?bool $personEnabled, array $personParams, ?int $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO loss_rule (code, enabled, params, person_enabled, person_params, updated_at, updated_by) VALUES (:code, :enabled, :params, :pe, :pp, :at, :by)'
            . ' ON CONFLICT (code) DO UPDATE SET enabled = EXCLUDED.enabled, params = EXCLUDED.params, person_enabled = EXCLUDED.person_enabled, person_params = EXCLUDED.person_params, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            ['code' => $code, 'enabled' => $enabled, 'params' => json_encode((object) $params), 'pe' => $personEnabled, 'pp' => json_encode((object) $personParams),
                'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'by' => $userId],
            ['enabled' => ParameterType::BOOLEAN, 'pe' => $personEnabled === null ? ParameterType::NULL : ParameterType::BOOLEAN]
        );
    }

    /** @return array<string, int> */
    private static function ints(mixed $raw): array
    {
        return is_array($raw) ? array_map('intval', array_filter($raw, 'is_numeric')) : [];
    }
}
