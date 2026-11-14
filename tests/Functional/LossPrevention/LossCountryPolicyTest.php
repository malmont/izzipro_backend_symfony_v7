<?php

namespace App\Tests\Functional\LossPrevention;

use App\LossPrevention\Services\LossCountryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Prévention des pertes : garde BLOQUANTE fixée par le chef de projet le 14/11/2026. Les règles par personne relisent une fenêtre d'actes sans
 * tenir compte du plancher de la notice aux salariés : elles ne doivent jamais être permises là où cette notice est exigée (France, Québec,
 * pays ou province illisible), tant que ce défaut n'est pas corrigé. Ce test échoue si quelqu'un ouvre ces règles à un tel endroit, que ce
 * soit en allongeant la liste des pays permis ou en retirant la garde.
 */
class LossCountryPolicyTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function places(): iterable
    {
        foreach (['FR', 'MQ', 'GP', 'GF', 'RE', 'YT', 'BE', 'CH', 'LU', 'DE', 'GB', 'MX', 'XX'] as $country) {
            yield $country => [$country, null];
        }
        foreach (['QC', 'ON', 'BC', 'AB', 'NB', 'NS', 'YT', '', 'Quelque part'] as $province) {
            yield 'CA ' . $province => ['CA', $province === '' ? null : $province];
        }
        foreach (['FL', 'TX', 'NY', 'CA', ''] as $state) {
            yield 'US ' . $state => ['US', $state === '' ? null : $state];
        }
        yield 'pays vide' => ['', null];
        yield 'pays nul' => [null, null];
    }

    /** @dataProvider places */
    public function testPersonPatternsAreNeverAllowedWhereTheStaffNoticeIsRequired(?string $country, ?string $region): void
    {
        $required = LossCountryPolicy::staffNoticeRequired($country, $region);
        $allowed = LossCountryPolicy::personPatternsAllowed($country, $region);
        $default = LossCountryPolicy::personPatternsDefault($country, $region);
        $this->assertFalse($required && $allowed, 'notice exigée : règles par personne refusées');
        $this->assertFalse($required && $default, 'et jamais allumées d\'office');
        $this->assertTrue($allowed || !$default, 'allumées d\'office seulement là où elles sont permises');
    }

    public function testTheDecidedPlaces(): void
    {
        // France (départements et régions d'outre-mer compris), Québec, doute : notice exigée, règles par personne refusées
        foreach ([['FR', null], ['MQ', null], ['RE', null], ['CA', 'QC'], ['CA', null], [null, null], ['', null]] as [$country, $region]) {
            $this->assertSame([true, false], [LossCountryPolicy::staffNoticeRequired($country, $region), LossCountryPolicy::personPatternsAllowed($country, $region)], json_encode([$country, $region]));
        }
        // États-Unis : permises ; allumées d'office en Floride seulement ; simple rappel de la notice
        $this->assertSame([false, true, true], [LossCountryPolicy::staffNoticeRequired('US', 'FL'), LossCountryPolicy::personPatternsAllowed('US', 'FL'), LossCountryPolicy::personPatternsDefault('US', 'FL')]);
        $this->assertSame([false, true, false], [LossCountryPolicy::staffNoticeRequired('US', 'TX'), LossCountryPolicy::personPatternsAllowed('US', 'TX'), LossCountryPolicy::personPatternsDefault('US', 'TX')]);
        // Ailleurs : rappel de la notice, règles par personne non permises tant que rien n'est décidé
        $this->assertSame([false, false], [LossCountryPolicy::staffNoticeRequired('CA', 'ON'), LossCountryPolicy::personPatternsAllowed('CA', 'ON')]);
        $this->assertSame(['US'], LossCountryPolicy::PERSON_PATTERNS_COUNTRIES, 'ouvrir un pays est une décision du propriétaire : ce test doit être mis à jour avec elle');
    }

    public function testTheGuardHoldsEvenIfACountryWithARequiredNoticeIsAddedToTheAllowedList(): void
    {
        // La garde ne dépend pas de la liste : chaque pays où la notice est exigée reste refusé, quoi que contienne la liste des pays permis.
        // (Si la garde était retirée et la France ajoutée à la liste, le premier test échouerait.)
        $source = (string) file_get_contents((new \ReflectionClass(LossCountryPolicy::class))->getFileName());
        $this->assertMatchesRegularExpression('/function personPatternsAllowed\([^)]*\): bool\s*\{\s*if \(self::staffNoticeRequired\(\$country, \$region\)\) \{\s*return false;/', $source,
            'la garde « notice exigée ⇒ refusées » est la première instruction de personPatternsAllowed');
        $this->assertSame([], array_values(array_intersect(LossCountryPolicy::PERSON_PATTERNS_COUNTRIES, ['FR', 'MQ', 'GP', 'GF', 'RE', 'YT'])), 'aucun territoire français dans la liste des pays permis');
    }
}
