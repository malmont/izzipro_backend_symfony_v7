<?php
namespace App\Services\FournisseurService;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Services\TenantEntityManagerProvider; 

class FournisseurService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function createFournisseur(FournisseurInputDTO $inputDTO): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $typeFournisseurRepository = $em->getRepository(TypeFournisseur::class);

        $fournisseur = new Fournisseur();
        $fournisseur->setName($inputDTO->name);
        
        $typeFournisseur = $typeFournisseurRepository->find($inputDTO->typeFournisseur);
        if (!$typeFournisseur) {
            throw new \App\Services\GestionService\GestionConflictException('TYPE_FOURNISSEUR_NOT_FOUND', 'Type de fournisseur inconnu : ' . $inputDTO->typeFournisseur, 404); // lot G4 (09/11/2026)
        }
        $fournisseur->setTypeFournisseur($typeFournisseur);

        $fournisseur->setAdresse($inputDTO->adresse);
        $fournisseur->setVille($inputDTO->ville);
        $fournisseur->setPays($inputDTO->pays);
        $fournisseur->setTel($inputDTO->tel);
        $fournisseur->setCurrency(self::currency($inputDTO->currency)); // devise d'achat (lot G6, 10/11/2026)
        
        $em->persist($fournisseur);
        $em->flush();

        return $fournisseur;
    }

    public function getAllFournisseurs(): array
    {
        $em = $this->emProvider->getEntityManager();
        return $em->getRepository(Fournisseur::class)->findAll();
    }

    /**
     * Liste filtrée et paginable (lot G5, 10/11/2026).
     *
     * @return array{items: list<Fournisseur>, total: int}
     */
    public function searchFournisseurs(?\App\Services\GestionService\GestionListQuery $query): array
    {
        return $this->emProvider->getEntityManager()->getRepository(Fournisseur::class)->search($query?->q, $query?->page, $query?->limit);
    }

    public function deleteFournisseur(Fournisseur $fournisseur): void
    {
        $em = $this->emProvider->getEntityManager();
        if (!$fournisseur->getCommandes()->isEmpty()) {
            throw new \App\Services\GestionService\GestionConflictException('FOURNISSEUR_IN_USE', 'Ce fournisseur a des commandes : il ne peut pas être supprimé.'); // 409 (lot G4, 09/11/2026 ; avant : violation de clé étrangère)
        }
        $em->remove($fournisseur);
        
        $em->flush();
    }

    /** Devise d'achat validée (CAD, EUR, USD : mêmes devises que le site) ; vide → null (devise du site) ; 422 INVALID_CURRENCY sinon */
    public static function currency(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '') {
            return null;
        }
        if (!\App\Services\BoutiqueSettingsService\SiteCurrencyService::isSupported($code)) {
            throw new \App\Services\GestionService\GestionValidationException(['currency' => 'INVALID_CURRENCY'], 'Devise inconnue : ' . $code . ' (devises possibles : ' . implode(', ', \App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED) . ')');
        }

        return $code;
    }
}
