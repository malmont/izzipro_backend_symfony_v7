<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossJournalEntry;
use App\LossPrevention\Entity\LossRule;
use App\Services\TenantEntityManagerProvider;

/**
 * Règles telles que le site les a réglées : valeurs par défaut du catalogue, remplacées par les choix du gérant (`loss_rule`). Changer un
 * seuil ne ferme ni ne supprime aucun constat : il ne vaut que pour les analyses suivantes (`docs/prevention-pertes.md` § 4).
 */
class LossRuleService
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossSettingsService $settings, private readonly LossSite $site, private readonly LossTexts $texts)
    {
    }

    /**
     * Réglage en vigueur d'une règle, lu par l'analyse.
     *
     * @return array{enabled: bool, params: array<string, int>, personEnabled: bool, personParams: array<string, int>}
     */
    public function effective(string $code): array
    {
        $stored = $this->stored()[$code] ?? null;
        $hasPerson = LossRuleCatalog::RULES[$code]['person'] !== null;

        return [
            'enabled' => $stored['enabled'] ?? true,
            'params' => array_intersect_key($stored['params'] ?? [], LossRuleCatalog::defaults($code)) + LossRuleCatalog::defaults($code),
            'personEnabled' => $hasPerson && $this->settings->personPatternsOn() && ($stored['personEnabled'] ?? true),
            'personParams' => $hasPerson ? array_intersect_key($stored['personParams'] ?? [], LossRuleCatalog::defaults($code, true)) + LossRuleCatalog::defaults($code, true) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function list(string $locale): array
    {
        return array_map(fn (string $code) => $this->view($code, $locale), array_keys(LossRuleCatalog::RULES));
    }

    /** @return array<string, mixed> */
    public function view(string $code, string $locale): array
    {
        $this->mustExist($code);
        $def = LossRuleCatalog::RULES[$code];
        $eff = $this->effective($code);
        $place = $this->site->place();
        $params = fn (array $defs, array $values, string $prefix) => array_map(fn (string $key, array $d) => [
            'key' => $key, 'label' => $this->texts->t("$prefix.$key", $locale), 'type' => $d['type'],
            'value' => $values[$key], 'default' => $d['default'], 'min' => $d['min'], 'max' => $d['max'],
        ], array_keys($defs), $defs);

        return [
            'code' => $code,
            'label' => $this->texts->t("rules.$code.label", $locale),
            'description' => $this->texts->t("rules.$code.description", $locale),
            'wave' => $def['wave'],
            'enabled' => $eff['enabled'],
            'params' => $params($def['params'], $eff['params'], "rules.$code.params"),
            'personVariant' => $def['person'] === null ? null : [
                'allowed' => LossCountryPolicy::personPatternsAllowed($place['country'], $place['region']),
                'enabled' => $eff['personEnabled'],
                'label' => $this->texts->t("rules.$code.person.label", $locale),
                'description' => $this->texts->t("rules.$code.person.description", $locale),
                'params' => $params($def['person'], $eff['personParams'], "rules.$code.person.params"),
            ],
        ];
    }

    /**
     * `{ enabled?: bool, params?: { clé: entier }, personVariant?: { enabled?: bool, params?: { clé: entier } } }` : seules les clés envoyées changent.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> la règle après enregistrement
     */
    public function put(string $code, array $body, int $userId, string $locale): array
    {
        $this->mustExist($code);
        $def = LossRuleCatalog::RULES[$code];
        $stored = $this->stored()[$code] ?? ['enabled' => true, 'params' => [], 'personEnabled' => null, 'personParams' => []];
        $errors = [];
        $read = function (mixed $given, array $defs, string $prefix) use (&$errors): array {
            $out = [];
            if ($given === null) {
                return $out;
            }
            if (!is_array($given)) {
                $errors[$prefix] = 'MUST_BE_OBJECT';

                return $out;
            }
            foreach ($given as $key => $value) {
                $d = $defs[$key] ?? null;
                if ($d === null) {
                    $errors["$prefix.$key"] = 'UNKNOWN_PARAM';
                } elseif (!is_int($value)) {
                    $errors["$prefix.$key"] = 'MUST_BE_INTEGER';
                } elseif ($value < $d['min'] || $value > $d['max']) {
                    $errors["$prefix.$key"] = 'PARAM_OUT_OF_RANGE';
                } else {
                    $out[(string) $key] = $value;
                }
            }

            return $out;
        };
        if (array_key_exists('enabled', $body) && !is_bool($body['enabled'])) {
            $errors['enabled'] = 'MUST_BE_BOOLEAN';
        }
        $params = $read($body['params'] ?? null, $def['params'], 'params');
        $personParams = [];
        $personEnabled = $stored['personEnabled'];
        if (array_key_exists('personVariant', $body)) {
            $variant = $body['personVariant'];
            if ($def['person'] === null) {
                $errors['personVariant'] = 'NO_PERSON_VARIANT';
            } elseif (!is_array($variant)) {
                $errors['personVariant'] = 'MUST_BE_OBJECT';
            } else {
                $place = $this->site->place();
                if (array_key_exists('enabled', $variant)) {
                    if (!is_bool($variant['enabled'])) {
                        $errors['personVariant.enabled'] = 'MUST_BE_BOOLEAN';
                    } elseif ($variant['enabled'] && !LossCountryPolicy::personPatternsAllowed($place['country'], $place['region'])) {
                        $errors['personVariant.enabled'] = 'NOT_ALLOWED_IN_COUNTRY';
                    } else {
                        $personEnabled = $variant['enabled'];
                    }
                }
                $personParams = $read($variant['params'] ?? null, $def['person'], 'personVariant.params');
            }
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $em = $this->emProvider->getEntityManager();
        $em->getRepository(LossRule::class)->save(
            $code,
            array_key_exists('enabled', $body) ? $body['enabled'] : $stored['enabled'],
            $params + $stored['params'],
            $personEnabled,
            $personParams + $stored['personParams'],
            $userId
        );
        // Au journal (inaltérable) : seulement ce qui a été validé, jamais le corps reçu
        $em->getRepository(LossJournalEntry::class)->append(LossJournalEntry::RULE_CHANGED, $userId, null, ['code' => $code] + array_filter([
            'enabled' => array_key_exists('enabled', $body) ? $body['enabled'] : null,
            'params' => $params !== [] ? $params : null,
            'personEnabled' => is_array($body['personVariant'] ?? null) && array_key_exists('enabled', $body['personVariant']) ? $personEnabled : null,
            'personParams' => $personParams !== [] ? $personParams : null,
        ], fn ($v) => $v !== null));

        return $this->view($code, $locale);
    }

    private function mustExist(string $code): void
    {
        if (!LossRuleCatalog::exists($code)) {
            throw new GestionConflictException('RULE_NOT_FOUND', 'Règle inconnue.', 404);
        }
    }

    /** Lu à chaque appel, sans mémoire : une commande parcourt plusieurs sites avec le même service @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        return $this->emProvider->getEntityManager()->getRepository(LossRule::class)->all();
    }
}
