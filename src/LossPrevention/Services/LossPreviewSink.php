<?php

namespace App\LossPrevention\Services;

/**
 * Puits en mémoire pour l'aperçu d'un réglage (lot L1-b) : il garde les constats que les règles ouvriraient, sans rien écrire en base. Sans
 * doublon, comme l'écrivain : un constat par clé, une preuve par source.
 */
final class LossPreviewSink implements LossFindingSink
{
    /** @var array<string, array<string, mixed>> par clé */
    private array $findings = [];
    /** @var array<int, array<string, bool>> preuves déjà comptées, par constat */
    private array $evidence = [];

    public function record(array $finding, array $evidence): int
    {
        $key = (string) $finding['dedup_key'];
        if (!isset($this->findings[$key])) {
            $this->findings[$key] = ['id' => count($this->findings) + 1, 'decided_at' => null] + $finding;
        } else {
            $this->findings[$key] = ['facts' => $finding['facts'], 'amount_at_stake' => $finding['amount_at_stake'], 'occurred_to' => max((string) $this->findings[$key]['occurred_to'], (string) $finding['occurred_to'])] + $this->findings[$key];
        }
        $id = (int) $this->findings[$key]['id'];
        foreach ($evidence as $e) {
            $this->evidence[$id][$e['refType'] . ':' . $e['refId']] = true;
        }

        return $id;
    }

    public function complete(array $existing, array $facts, array $onDuty, ?int $amountAtStake, \DateTimeImmutable $occurredTo, array $evidence, bool $recent = false): void
    {
        foreach ($this->findings as $key => $f) {
            if ((int) $f['id'] === (int) $existing['id']) {
                $this->findings[$key] = ['facts' => $facts, 'amount_at_stake' => $amountAtStake, 'occurred_to' => max((string) $f['occurred_to'], $occurredTo->format('Y-m-d H:i:s'))] + $f;
                foreach ($evidence as $e) {
                    $this->evidence[(int) $f['id']][$e['refType'] . ':' . $e['refId']] = true;
                }
            }
        }
    }

    public function latestForPerson(string $ruleCode, int $userId, \DateTimeImmutable $since, \DateTimeImmutable $until): ?array
    {
        $found = null;
        foreach ($this->findings as $f) {
            if ($f['rule_code'] === $ruleCode && $f['variant'] === 'person' && $f['subject_type'] === 'user_window' && (int) $f['subject_id'] === $userId
                && (string) $f['occurred_to'] >= $since->format('Y-m-d H:i:s') && (string) $f['occurred_from'] <= $until->format('Y-m-d H:i:s')) {
                $found = $f;
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> constats que les règles ouvriraient, dans l'ordre de leur naissance */
    public function findings(): array
    {
        return array_values($this->findings);
    }
}
