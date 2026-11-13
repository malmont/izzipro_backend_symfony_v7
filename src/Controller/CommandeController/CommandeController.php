<?php

namespace App\Controller\CommandeController;

use App\Entity\Collections;
use App\UseCase\CommandeUseCase\GetCommandesByCollectionUseCase;
use App\UseCase\CommandeUseCase\CreateCommandeUseCase;
use App\Dto\CommandeOutputDTO;
use App\Dto\FournisseurInputDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class CommandeController extends AbstractController
{
    private GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase;
    private CreateCommandeUseCase $createCommandeUseCase;
    private Security $security;
    private TenantCacheService $cache;

    public function __construct(
        GetCommandesByCollectionUseCase $getCommandesByCollectionUseCase,
        CreateCommandeUseCase $createCommandeUseCase,
        Security $security,
        TenantCacheService $cache,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null, // devise du site (lot G1, 09/11/2026)
        private readonly ?\App\Gestion\Services\PeriodGuard $periodGuard = null, // périodes (lot P1, 10/11/2026)
        private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null,
    ) {
        $this->getCommandesByCollectionUseCase = $getCommandesByCollectionUseCase;
        $this->createCommandeUseCase = $createCommandeUseCase;
        $this->security = $security;
        $this->cache = $cache;
    }

    #[Route('/api/collections/{id}/commandes', name: 'get_commandes_by_collection', methods: ['GET'])]
    public function getCommandesByCollection(Collections $collection, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $cacheKey = 'commandes_collection_' . $collection->getId();
        $commandesDTO = $this->cache->get(
            $cacheKey,
            function () use ($collection, $host) {
                $commandes = $this->getCommandesByCollectionUseCase->execute($collection);
                $commandesArray = $commandes->toArray();
                return array_map(function ($commande) use ($host) {
                    $dto = new CommandeOutputDTO($commande, $host, $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT);
                    return method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
                }, $commandesArray);
            },
            600,
            [...CacheTags::itemTags(CacheTags::COLLECTIONS, $collection->getId()), ...CacheTags::listTags(CacheTags::COMMANDES)]
        );

        return $this->json($commandesDTO, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/commandes', name: 'create_commande', methods: ['POST'])]
    public function createCommande(Request $request, Collections $collection): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $currentUser = $this->security->getUser();

        if (!$currentUser) {
            return $this->json(['error' => 'Authentification requise', 'code' => 'UNAUTHENTICATED', 'message' => 'Authentification requise'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $input = new \App\Gestion\Services\GestionInput(is_array($data) ? $data : []);
        $data = ['budget' => $input->cents('budget'), 'name' => $input->text('name'), 'date' => $input->date('date')?->format('Y-m-d H:i:s'), 'exchangeRate' => $input->decimal('exchangeRate', false)]; // taux vers la devise du site (lot G6)
        $fournisseurId = $input->id('fournisseur');
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)
        // lot P1 (10/11/2026) : collection ou période ouverte, date dans une période ouverte ; sur une période, la date doit y tomber
        $this->periodGuard->assertCollectionWritable($collection);
        $date = new \DateTimeImmutable($data['date']);
        $this->periodGuard->assertDateWritable($date);
        if ($collection->isPeriod() && ($date < $collection->getStartDateCollection() || $date->format('Y-m-d') > $collection->getEndDateCollection()->format('Y-m-d'))) {
            throw new \App\Gestion\Services\GestionValidationException(['date' => 'OUTSIDE_PERIOD'], 'La date de la commande est hors de la période choisie');
        }
        $fournisseurDTO = new FournisseurInputDTO(['id' => $fournisseurId]);
        $commande = $this->createCommandeUseCase->execute($data, $collection, $fournisseurDTO);
        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success"})
        return $this->json(new CommandeOutputDTO($commande, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_CREATED);
    }

    /** Commande classée par sa date, sans choix de collection (lot P1, 10/11/2026) : période de la date, créée si manquante ; 409 PERIOD_CLOSED */
    #[Route('/api/commandes', name: 'create_commande_by_date', methods: ['POST'])]
    public function createCommandeByDate(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $input = new \App\Gestion\Services\GestionInput($data);
        $fields = ['budget' => $input->cents('budget'), 'name' => $input->text('name'), 'date' => $input->date('date')?->format('Y-m-d H:i:s'), 'exchangeRate' => $input->decimal('exchangeRate', false)];
        $fournisseurId = $input->id('fournisseur');
        $input->check();
        $period = $this->periodGuard->assertDateWritable(new \DateTimeImmutable($fields['date']));
        if ($period === null) {
            throw new \App\Gestion\Services\GestionValidationException(['date' => 'OUT_OF_CALENDAR'], 'Aucune période du calendrier ne couvre cette date (plus de 24 mois)');
        }
        $commande = $this->createCommandeUseCase->execute($fields, $period, new FournisseurInputDTO(['id' => $fournisseurId]));

        return $this->json(new CommandeOutputDTO($commande, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT), JsonResponse::HTTP_CREATED);
    }
}
