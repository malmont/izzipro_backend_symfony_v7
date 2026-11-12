<?php
namespace App\Services\FournisseurService;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Services\TenantEntityManagerProvider; 

class FournisseurService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * Modification partielle d'un fournisseur (lot EDIT, 12/11/2026, `docs/gestion-mise-a-niveau.md` § 7) : seules les clés envoyées
     * changent. La devise d'achat est figée dès que le fournisseur a une commande (409 `FOURNISSEUR_CURRENCY_LOCKED`) ; `null` (retour à la
     * devise du site) compte comme un changement. Écrit une entrée au journal des contenus (avant, après).
     *
     * @param array<string, mixed> $data
     */
    public function update(Fournisseur $fournisseur, array $data): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $input = new \App\Gestion\Services\GestionInput($data);
        $before = self::snapshot($fournisseur);
        $name = array_key_exists('name', $data) ? $input->text('name') : null;
        $typeId = array_key_exists('typeFournisseur', $data) ? $input->id('typeFournisseur') : null;
        $errors = $input->errors();
        $currencyAsked = array_key_exists('currency', $data);
        $currency = null;
        if ($currencyAsked) {
            try {
                $currency = self::currency($data['currency']);
            } catch (\App\Gestion\Services\GestionValidationException $e) {
                $errors += $e->fields;
            }
        }
        if ($errors !== []) {
            throw new \App\Gestion\Services\GestionValidationException($errors);
        }
        $type = null;
        if ($typeId !== null && ($type = $em->getRepository(TypeFournisseur::class)->find($typeId)) === null) {
            throw new \App\Gestion\Services\GestionConflictException('TYPE_FOURNISSEUR_NOT_FOUND', 'Type de fournisseur inconnu : ' . $typeId, 404);
        }
        if ($currencyAsked && $currency !== $fournisseur->getCurrency()) {
            $commandes = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM commande WHERE fournisseur_id = ?', [$fournisseur->getId()]);
            if ($commandes > 0) {
                throw new \App\Gestion\Services\GestionConflictException('FOURNISSEUR_CURRENCY_LOCKED', 'Ce fournisseur a déjà des commandes : sa devise d\'achat ne peut plus changer.', 409, ['commandes' => $commandes]);
            }
            $fournisseur->setCurrency($currency);
        }
        if ($name !== null) {
            $fournisseur->setName($name);
        }
        if ($type !== null) {
            $fournisseur->setTypeFournisseur($type);
        }
        foreach (['adresse' => 'setAdresse', 'ville' => 'setVille', 'pays' => 'setPays', 'tel' => 'setTel'] as $field => $setter) {
            if (array_key_exists($field, $data)) {
                $fournisseur->{$setter}($input->text($field, false)); // vide ou null : effacé
            }
        }
        $em->flush();
        $after = self::snapshot($fournisseur);
        if ($after !== $before) {
            $this->audit?->record('fournisseurs', $fournisseur->getId(), 'update', $before, $after, array_keys(array_diff_assoc($after, $before)));
        }

        return $fournisseur;
    }

    /** @return array<string, mixed> */
    private static function snapshot(Fournisseur $f): array
    {
        return ['name' => $f->getName(), 'typeFournisseurId' => $f->getTypeFournisseur()?->getId(), 'adresse' => $f->getAdresse(), 'ville' => $f->getVille(), 'pays' => $f->getPays(), 'tel' => $f->getTel(), 'currency' => $f->getCurrency()];
    }

    public function createFournisseur(FournisseurInputDTO $inputDTO): Fournisseur
    {
        $em = $this->emProvider->getEntityManager();
        $typeFournisseurRepository = $em->getRepository(TypeFournisseur::class);

        $fournisseur = new Fournisseur();
        $fournisseur->setName($inputDTO->name);
        
        $typeFournisseur = $typeFournisseurRepository->find($inputDTO->typeFournisseur);
        if (!$typeFournisseur) {
            throw new \App\Gestion\Services\GestionConflictException('TYPE_FOURNISSEUR_NOT_FOUND', 'Type de fournisseur inconnu : ' . $inputDTO->typeFournisseur, 404); // lot G4 (09/11/2026)
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
    public function searchFournisseurs(?\App\Gestion\Services\GestionListQuery $query): array
    {
        return $this->emProvider->getEntityManager()->getRepository(Fournisseur::class)->search($query?->q, $query?->page, $query?->limit);
    }

    public function deleteFournisseur(Fournisseur $fournisseur): void
    {
        $em = $this->emProvider->getEntityManager();
        if (!$fournisseur->getCommandes()->isEmpty()) {
            throw new \App\Gestion\Services\GestionConflictException('FOURNISSEUR_IN_USE', 'Ce fournisseur a des commandes : il ne peut pas être supprimé.'); // 409 (lot G4, 09/11/2026 ; avant : violation de clé étrangère)
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
            throw new \App\Gestion\Services\GestionValidationException(['currency' => 'INVALID_CURRENCY'], 'Devise inconnue : ' . $code . ' (devises possibles : ' . implode(', ', \App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED) . ')');
        }

        return $code;
    }
}
