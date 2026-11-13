<?php

namespace App\Gestion\Services;

use App\Entity\Entreprise;
use App\Gestion\Pricing\PriceRounding;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réglages de prix du site (lot IA-ACT-5, 13/11/2026, `docs/prix-gestion.md` § 5) : le coefficient par défaut d'un article acheté (prix de
 * vente = coût × coefficient), le coefficient minimum sous lequel le serveur ne propose jamais, et l'arrondi d'un prix calculé. Trois
 * colonnes de la fiche Entreprise ; une colonne vide vaut la valeur par défaut ci-dessous, et `stored` dit ce que le gérant a réglé.
 * `roundingOptions` (13/11/2026) décrit chaque arrondi pour l'écran de réglage : libellé dans la langue demandée et exemple CALCULÉ par
 * `PriceRounding` sur un prix type, pour que l'écran ne recopie ni la règle ni ses mots.
 */
class GestionPriceSettingsService
{
    public const DEFAULT_COEFFICIENT = 2.0;
    public const MIN_COEFFICIENT = 1.6;
    /** Bornes d'un coefficient réglable : sous 1, on vend à perte ; au-dessus de 20, c'est une faute de saisie */
    public const COEFFICIENT_FROM = 1.0;
    public const COEFFICIENT_TO = 20.0;
    /** Prix type (en cents) sur lequel l'exemple de chaque arrondi est calculé : 54,78 */
    public const EXAMPLE_CENTS = 5478;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit, private readonly TranslatorInterface $translator)
    {
    }

    public function defaultCoefficient(): float
    {
        return $this->entreprise()?->getGestionDefaultCoefficient() ?? self::DEFAULT_COEFFICIENT;
    }

    public function minCoefficient(): float
    {
        return $this->entreprise()?->getGestionMinCoefficient() ?? self::MIN_COEFFICIENT;
    }

    public function rounding(): string
    {
        $mode = $this->entreprise()?->getGestionPriceRounding();

        return in_array($mode, PriceRounding::MODES, true) ? $mode : PriceRounding::DEFAULT;
    }

    /** Prix calculé à partir d'un coût et d'un coefficient, arrondi selon le réglage du site (jamais pour un prix saisi) */
    public function price(int $costCents, float $coefficient): int
    {
        return PriceRounding::apply((int) round($costCents * $coefficient), $this->rounding());
    }

    /**
     * GET /api/gestion/reglages/prix
     *
     * @return array{defaultCoefficient: float, minCoefficient: float, rounding: string, roundings: list<string>, roundingOptions: list<array{code: string, label: string, example: string}>, stored: array{defaultCoefficient: ?float, minCoefficient: ?float, rounding: ?string}}
     */
    public function settings(string $locale = 'fr'): array
    {
        $e = $this->entreprise();

        return ['defaultCoefficient' => $this->defaultCoefficient(), 'minCoefficient' => $this->minCoefficient(), 'rounding' => $this->rounding(), 'roundings' => PriceRounding::MODES,
            'roundingOptions' => $this->roundingOptions($locale),
            'stored' => ['defaultCoefficient' => $e?->getGestionDefaultCoefficient(), 'minCoefficient' => $e?->getGestionMinCoefficient(), 'rounding' => $e?->getGestionPriceRounding()]];
    }

    /**
     * Les arrondis réglables, dans l'ordre de `PriceRounding::MODES` : `code`, `label` dans la langue demandée, `example` calculé sur le
     * prix type (« 54,78 → 54,99 », « 54.78 → 54.99 » en anglais ; sans devise : l'arrondi ne dépend pas d'elle).
     *
     * @return list<array{code: string, label: string, example: string}>
     */
    public function roundingOptions(string $locale = 'fr'): array
    {
        $locale = $locale === 'en' ? 'en' : 'fr';
        $amount = fn (int $cents): string => number_format($cents / 100, 2, $locale === 'en' ? '.' : ',', ' ');

        return array_map(fn (string $mode): array => [
            'code' => $mode,
            'label' => $this->translator->trans("prices.rounding.$mode", [], 'gestion', $locale),
            'example' => $amount(self::EXAMPLE_CENTS) . ' → ' . $amount(PriceRounding::apply(self::EXAMPLE_CENTS, $mode)),
        ], PriceRounding::MODES);
    }

    /**
     * PUT /api/gestion/reglages/prix { defaultCoefficient?, minCoefficient?, rounding? } : seules les clés envoyées changent ; `null` remet la
     * valeur par défaut. 422 `OUT_OF_RANGE`, `MUST_BE_NUMBER`, `INVALID_ROUNDING`, `MIN_ABOVE_DEFAULT` (le minimum dépasse le coefficient par défaut).
     *
     * @param array<string, mixed> $data
     */
    public function put(array $data, string $locale = 'fr'): array
    {
        $e = $this->entreprise();
        if ($e === null) {
            throw new GestionConflictException('ENTREPRISE_NOT_FOUND', 'Fiche entreprise du site introuvable.', 404);
        }
        $errors = [];
        $next = ['defaultCoefficient' => $e->getGestionDefaultCoefficient(), 'minCoefficient' => $e->getGestionMinCoefficient(), 'rounding' => $e->getGestionPriceRounding()];
        foreach (['defaultCoefficient', 'minCoefficient'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if ($v !== null && !is_int($v) && !is_float($v)) {
                $errors[$key] = 'MUST_BE_NUMBER';
            } elseif ($v !== null && ($v < self::COEFFICIENT_FROM || $v > self::COEFFICIENT_TO)) {
                $errors[$key] = 'OUT_OF_RANGE';
            } else {
                $next[$key] = $v === null ? null : round((float) $v, 2);
            }
        }
        if (array_key_exists('rounding', $data)) {
            if ($data['rounding'] !== null && !in_array($data['rounding'], PriceRounding::MODES, true)) {
                $errors['rounding'] = 'INVALID_ROUNDING';
            } else {
                $next['rounding'] = $data['rounding'];
            }
        }
        if ($errors === [] && ($next['minCoefficient'] ?? self::MIN_COEFFICIENT) > ($next['defaultCoefficient'] ?? self::DEFAULT_COEFFICIENT)) {
            $errors['minCoefficient'] = 'MIN_ABOVE_DEFAULT';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Réglages de prix invalides : ' . implode(', ', array_keys($errors)));
        }
        $before = $this->settings()['stored'];
        $e->setGestionDefaultCoefficient($next['defaultCoefficient'])->setGestionMinCoefficient($next['minCoefficient'])->setGestionPriceRounding($next['rounding']);
        $this->emProvider->getEntityManager()->flush();
        $after = $this->settings($locale);
        if ($after['stored'] !== $before) {
            $this->audit->record('gestion-reglages-prix', (int) $e->getId(), 'update', $before, $after['stored']);
        }

        return $after;
    }

    private function entreprise(): ?Entreprise
    {
        return $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
    }
}
