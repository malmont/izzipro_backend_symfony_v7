<?php

namespace App\Gestion\Controller;

use App\Entity\Collections;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\PeriodResultCalculator;
use App\Services\Group\AllShops\AllShopsListMerger;
use App\Services\Group\AllShops\AllShopsReader;
use App\Services\Group\AllShops\GroupScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot G-3 (18/11/2026, contrat validé par le chef, `docs/ecrans-et-contextes.md` § 14) : le résultat du GROUPE sur des dates. Une période de
 * gestion est propre à un site (son identifiant, son découpage) : le directeur choisit des dates, et chaque boutique calcule son résultat sur
 * ces dates avec une période NON ENREGISTRÉE (`PeriodResultCalculator::compute`, jamais figé), dans la transaction READ ONLY de « Toutes
 * les boutiques ». Totaux par devise, taux recalculés sur les sommes. Route du groupe seulement : sans l'en-tête `X-Group-Scope: all`,
 * 400 `GROUP_SCOPE_REQUIRED`. `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion.
 */
class GestionGroupResultController extends AbstractController
{
    /** Plus longue plage (jours), comme les statistiques */
    public const MAX_DAYS = 366;

    public function __construct(private readonly PeriodResultCalculator $calculator, private readonly AllShopsReader $allShops)
    {
    }

    #[Route('/api/gestion/groupe/resultat', name: 'gestion_groupe_resultat', methods: ['GET'])]
    public function result(Request $request): JsonResponse
    {
        if (!$request->attributes->has(GroupScope::ATTRIBUTE)) {
            return $this->json(['error' => GroupScope::REQUIRED, 'code' => GroupScope::REQUIRED, 'message' => 'Route du groupe : envoyez l\'en-tête X-Group-Scope: all depuis le siège.'], 400);
        }
        [$from, $to] = self::range($request->query->get('from'), $request->query->get('to'));
        $group = $this->allShops->forRequest($request, 'gestion_groupe_resultat', ['from' => $from, 'to' => $to], function () use ($from, $to): array {
            $period = (new Collections())->setNomCollection($from . ' – ' . $to)
                ->setStartDateCollection(new \DateTime($from))->setEndDateCollection(new \DateTime($to));

            $data = $this->calculator->compute($period, null);
            // les remarques des frais fixes citent des notes de frais par leur nom (« Salaire … »), dans `notes` ET dans le texte : jamais au
            // directeur ; il reçoit le code et le nombre de notes, sans texte
            foreach ($data['fixedCosts']['caveats'] ?? [] as $i => $caveat) {
                if (array_key_exists('notes', $caveat)) {
                    $data['fixedCosts']['caveats'][$i] = ['code' => $caveat['code'] ?? null, 'text' => null, 'count' => count((array) $caveat['notes'])];
                }
            }

            return ['data' => $data];
        }, AllShopsListMerger::result(...));

        return $this->json($group);
    }

    /** @return array{0: string, 1: string} jours du magasin Y-m-d ; 422 sinon */
    private static function range(mixed $from, mixed $to): array
    {
        $day = static fn (mixed $v): string|false|null => $v === null || $v === '' ? null
            : (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v ? $v : false);
        $f = $day($from);
        $t = $day($to);
        $errors = array_filter(['from' => $f === null ? 'REQUIRED' : ($f === false ? 'INVALID_DATE' : null), 'to' => $t === null ? 'REQUIRED' : ($t === false ? 'INVALID_DATE' : null)]);
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        if ($t < $f) {
            throw new GestionValidationException(['to' => 'BEFORE_START']);
        }
        if ((new \DateTimeImmutable($f))->diff(new \DateTimeImmutable($t))->days + 1 > self::MAX_DAYS) {
            throw new GestionValidationException(['to' => 'RANGE_TOO_LONG']);
        }

        return [$f, $t];
    }
}
