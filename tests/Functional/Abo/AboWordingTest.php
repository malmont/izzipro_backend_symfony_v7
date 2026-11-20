<?php

namespace App\Tests\Functional\Abo;

use App\Abo\Entity\Benefit;
use App\Abo\Entity\PlanBenefit;
use App\Abo\Services\AboCatalogService;
use App\Abo\Services\AboWording;
use App\Abo\Services\BenefitService;
use App\Entity\SubscriptionPlan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le rythme d'une formule en toutes lettres (20/11/2026, défaut vu par la gestion web : « 39,00 $ tous les 2 semaines ») : l'accord avec
 * l'unité (semaine : « toutes » ; mois et an : « tous »), une période ou plusieurs, en français et en anglais ; la même règle dans « ce que
 * verra l'abonné » de la formule et de ses prestations. Sans base : les formules sont construites en mémoire.
 */
class AboWordingTest extends KernelTestCase
{
    /** @return iterable<string, array{0: string, 1: int, 2: bool, 3: string, 4: string}> unité, nombre, anglais, rythme, durée */
    public static function rhythms(): iterable
    {
        yield 'semaine, une, fr' => ['week', 1, false, 'par semaine', '1 semaine'];
        yield 'semaines, plusieurs, fr' => ['week', 2, false, 'toutes les 2 semaines', '2 semaines'];
        yield 'mois, un, fr' => ['month', 1, false, 'par mois', '1 mois'];
        yield 'mois, plusieurs, fr' => ['month', 3, false, 'tous les 3 mois', '3 mois'];
        yield 'an, un, fr' => ['year', 1, false, 'par an', '1 an'];
        yield 'ans, plusieurs, fr' => ['year', 2, false, 'tous les 2 ans', '2 ans'];
        yield 'week, one, en' => ['week', 1, true, 'per week', '1 week'];
        yield 'weeks, several, en' => ['week', 2, true, 'every 2 weeks', '2 weeks'];
        yield 'month, one, en' => ['month', 1, true, 'per month', '1 month'];
        yield 'months, several, en' => ['month', 3, true, 'every 3 months', '3 months'];
        yield 'year, one, en' => ['year', 1, true, 'per year', '1 year'];
        yield 'years, several, en' => ['year', 2, true, 'every 2 years', '2 years'];
    }

    /** @dataProvider rhythms */
    public function testTheRhythmAgreesWithItsUnitAndItsNumber(string $interval, int $count, bool $en, string $every, string $duration): void
    {
        $this->assertSame($every, AboWording::every($interval, $count, $en));
        $this->assertSame($duration, AboWording::duration($interval, $count, $en));

        // « ce que verra l'abonné » : la formule, puis une prestation de la formule, avec le même rythme
        $plan = (new SubscriptionPlan())->setNames(['fr' => 'Formule', 'en' => 'Plan'])->setPrice(3900)->setCurrency('CAD')->setInterval($interval)->setIntervalCount($count);
        $summary = static::getContainer()->get(AboCatalogService::class)->summary($plan, $en ? 'en' : 'fr');
        $this->assertStringContainsString(' ' . $every . '.', $summary);
        $benefit = (new Benefit())->setKind(Benefit::KIND_SESSION)->setNames(['fr' => 'Yoga', 'en' => 'Yoga']);
        $line = static::getContainer()->get(BenefitService::class)->summary((new PlanBenefit())->setPlan($plan)->setBenefit($benefit)->setQuantity(4)->setScheduling(PlanBenefit::SCHEDULING_NONE), $en ? 'en' : 'fr');
        $this->assertStringStartsWith(sprintf($en ? '4 sessions Yoga %s' : '4 séances Yoga %s', $every), $line);
        // une carte (une seule période) : la durée, accordée de même
        $once = static::getContainer()->get(AboCatalogService::class)->summary((clone $plan)->setSinglePeriod(true), $en ? 'en' : 'fr');
        $this->assertStringContainsString(($en ? 'valid ' : 'valable ') . $duration . ($en ? ', no renewal.' : ', sans renouvellement.'), $once);
        foreach (['tous les 2 semaines', 'toutes les 3 mois', 'toutes les 2 ans', 'par 2 ', 'per 2 ', 'per 3 ', '1 semaines', '1 ans', '2 an.', 'monthss', 'weekss'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $summary . ' ' . $line . ' ' . $once);
        }
    }
}
