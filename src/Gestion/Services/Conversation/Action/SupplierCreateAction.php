<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Dto\FournisseurInputDTO;
use App\Entity\Fournisseur;
use App\Entity\TypeFournisseur;
use App\Gestion\Services\GestionConflictException;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\FournisseurService\FournisseurService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `supplier.create` : créer un fournisseur (nom, type, devise d'achat). Le type « Autre » et la devise du site sont proposés. Annulation :
 * le retirer s'il n'a pas changé et qu'aucune commande ne l'utilise (seules les commandes portent un fournisseur).
 */
class SupplierCreateAction extends AbstractGestionAction
{
    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency, private readonly FournisseurService $suppliers)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'supplier.create';
    }

    public function build(array $answers, string $locale): array
    {
        $repo = $this->em()->getRepository(TypeFournisseur::class);
        $types = array_map(fn (TypeFournisseur $t) => ['value' => (int) $t->getId(), 'label' => (string) $t->getName()], $repo->findBy([], ['name' => 'ASC']));
        $default = ($repo->findOneBy(['name' => 'Autre']) ?? $repo->findOneBy([], ['id' => 'ASC']))?->getId();
        $currencies = array_map(fn (string $c) => ['value' => $c, 'label' => $c], SiteCurrencyService::SUPPORTED);

        return ['questions' => [
            $this->question('name', true, ['type' => 'text'], [], $answers['name'] ?? null, $locale),
            $this->question('typeFournisseurId', true, ['type' => 'choice'], $types, $answers['typeFournisseurId'] ?? $default, $locale, [], !isset($answers['typeFournisseurId']) && $default !== null),
            $this->question('currency', true, ['type' => 'choice'], $currencies, $answers['currency'] ?? $this->site(), $locale, [], !isset($answers['currency'])),
        ], 'issues' => []];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($question['id']) {
            'name' => $this->text($value, $index),
            'typeFournisseurId' => is_int($value) && $this->em()->find(TypeFournisseur::class, $value) !== null ? $value : $this->fail($index, 'TYPE_FOURNISSEUR_NOT_FOUND'),
            default => is_string($value) && in_array(strtoupper($value), SiteCurrencyService::SUPPORTED, true) ? strtoupper($value) : $this->fail($index, 'INVALID_CURRENCY'),
        };
    }

    public function preview(array $answers, string $locale): array
    {
        $values = $this->values($answers);
        $type = $this->em()->find(TypeFournisseur::class, (int) $values['typeFournisseurId']);

        return ['name' => $values['name'], 'type' => ['id' => $type?->getId(), 'name' => $type?->getName()], 'currency' => $values['currency']];
    }

    public function rows(array $preview, string $locale): array
    {
        return [$this->row('name', $locale, $preview['name'], 'text'), $this->row('typeFournisseurId', $locale, $preview['type']['name'], 'text'), $this->row('currency', $locale, $preview['currency'], 'text')];
    }

    public function execute(array $answers, ?string $user): array
    {
        $values = $this->values($answers);
        $supplier = $this->suppliers->createFournisseur(new FournisseurInputDTO(['name' => $values['name'], 'typeFournisseur' => $values['typeFournisseurId'], 'currency' => $values['currency']]));

        return ['result' => ['fournisseurId' => $supplier->getId()],
            'undo' => ['id' => $supplier->getId(), 'name' => $supplier->getName(), 'typeFournisseurId' => $supplier->getTypeFournisseur()?->getId(), 'currency' => $supplier->getCurrency()]];
    }

    public function blockers(array $undo): array
    {
        $supplier = $this->em()->find(Fournisseur::class, (int) $undo['id']);
        if ($supplier === null) {
            return [['code' => 'SUPPLIER_CHANGED', 'message' => 'Le fournisseur créé par cette action n\'existe plus.']];
        }
        $reasons = [];
        if ((int) $this->conn()->fetchOne('SELECT COUNT(*) FROM commande WHERE fournisseur_id = ?', [$supplier->getId()]) > 0) {
            $reasons[] = ['code' => 'SUPPLIER_USED', 'message' => 'Ce fournisseur est utilisé par une commande.'];
        }
        if ($supplier->getName() !== $undo['name'] || $supplier->getTypeFournisseur()?->getId() !== $undo['typeFournisseurId'] || $supplier->getCurrency() !== $undo['currency']) {
            $reasons[] = ['code' => 'SUPPLIER_CHANGED', 'message' => 'Le fournisseur a été modifié depuis sa création.'];
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $supplier = $this->em()->find(Fournisseur::class, (int) $undo['id']);
        if ($supplier === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'Le fournisseur n\'existe plus.');
        }
        $this->suppliers->deleteFournisseur($supplier);

        return ['removed' => ['supplier' => (int) $undo['id']]];
    }

    /** Paramètres complets : les réponses, sinon les valeurs proposées (type « Autre », devise du site) */
    private function values(array $answers): array
    {
        $repo = $this->em()->getRepository(TypeFournisseur::class);

        return ['name' => (string) $answers['name'],
            'typeFournisseurId' => $answers['typeFournisseurId'] ?? ($repo->findOneBy(['name' => 'Autre']) ?? $repo->findOneBy([], ['id' => 'ASC']))?->getId(),
            'currency' => $answers['currency'] ?? $this->site()];
    }
}
