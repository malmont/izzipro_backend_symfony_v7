<?php

namespace App\Gestion\Services\Conversation\Action;

use App\Dto\FraisDePortInputDTO;
use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\PeriodGuard;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\FraisDePortService\FraisDePortService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `shipping.create` : poser LE frais de port d'une commande (commande, transporteur, montant, numéro de suivi). Une commande qui en a déjà
 * un est refusée à la réponse (`SHIPPING_COST_ALREADY_SET`) : pas de cumul ici, ce n'est pas une facture. Annulation : le retirer s'il
 * n'a pas changé et que la commande est encore ouverte.
 */
class ShippingCreateAction extends AbstractGestionAction
{
    public const LISTED = 50;

    public function __construct(TenantEntityManagerProvider $emProvider, TranslatorInterface $translator, TenantCurrencyProvider $currency,
        private readonly FraisDePortService $shipping, private readonly PeriodGuard $guard)
    {
        parent::__construct($emProvider, $translator, $currency);
    }

    public static function key(): string
    {
        return 'shipping.create';
    }

    public function build(array $answers, string $locale): array
    {
        $em = $this->em();
        // Commandes proposées : ouvertes, sans frais de port, les plus récentes ; une autre commande valide peut être donnée par son identifiant
        $ids = $this->conn()->fetchFirstColumn('SELECT c.id FROM commande c WHERE COALESCE(c.is_closed, false) = false AND c.kind = ? AND NOT EXISTS (SELECT 1 FROM frais_de_port f WHERE f.commande_id = c.id) ORDER BY c.date DESC, c.id DESC LIMIT ' . self::LISTED, [Commande::KIND_STANDARD]);
        $commandes = [];
        foreach ($ids as $id) {
            $c = $em->find(Commande::class, (int) $id);
            if ($c !== null && $this->refusal($c) === null) {
                $commandes[] = ['value' => (int) $c->getId(), 'label' => trim((string) $c->getName()) !== '' ? (string) $c->getName() : '#' . $c->getId(), 'date' => $c->getDate()?->format('Y-m-d'), 'supplier' => $c->getFournisseur()?->getName()];
            }
        }
        $carriers = array_map(fn (Transporteur $t) => ['value' => (int) $t->getId(), 'label' => (string) $t->getName()], $em->getRepository(Transporteur::class)->findBy([], ['name' => 'ASC']));
        $issues = [];
        $commande = isset($answers['commandeId']) ? $em->find(Commande::class, (int) $answers['commandeId']) : null;
        $refusal = isset($answers['commandeId']) ? ($commande === null ? 'COMMANDE_NOT_FOUND' : $this->refusal($commande)) : null;
        if ($refusal !== null) {
            $issues[] = ['path' => 'commandeId', 'code' => $refusal];
        }

        return ['questions' => [
            $this->question('commandeId', true, ['type' => 'choice'], $commandes, $answers['commandeId'] ?? null, $locale),
            $this->question('transporteurId', true, ['type' => 'choice'], $carriers, $answers['transporteurId'] ?? null, $locale),
            $this->question('amount', true, ['type' => 'money', 'currency' => strtoupper($commande?->getCurrency() ?? $this->site())], [], $answers['amount'] ?? null, $locale),
            $this->question('tracknumber', false, ['type' => 'text'], [], $answers['tracknumber'] ?? null, $locale),
        ], 'issues' => $issues];
    }

    public function accept(array $question, mixed $value, int $index, array $answers): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($question['id']) {
            case 'commandeId':
                $commande = is_int($value) ? $this->em()->find(Commande::class, $value) : null;
                $refusal = $commande === null ? 'COMMANDE_NOT_FOUND' : $this->refusal($commande);

                return $refusal === null ? $value : $this->fail($index, $refusal);
            case 'transporteurId':
                return is_int($value) && $this->em()->find(Transporteur::class, $value) !== null ? $value : $this->fail($index, 'TRANSPORTEUR_NOT_FOUND');
            case 'amount':
                return $this->cents($value, $index);
            default:
                return $this->text($value, $index, 100);
        }
    }

    public function preview(array $answers, string $locale): array
    {
        $commande = $this->em()->find(Commande::class, (int) $answers['commandeId']);
        $carrier = $this->em()->find(Transporteur::class, (int) $answers['transporteurId']);

        return ['commande' => ['id' => $commande?->getId(), 'name' => $commande?->getName(), 'date' => $commande?->getDate()?->format('Y-m-d')],
            'transporteur' => ['id' => $carrier?->getId(), 'name' => $carrier?->getName()], 'amount' => (int) $answers['amount'],
            'currency' => strtoupper($commande?->getCurrency() ?? $this->site()), 'tracknumber' => $answers['tracknumber'] ?? null];
    }

    public function rows(array $preview, string $locale): array
    {
        return array_values(array_filter([
            $this->row('commandeId', $locale, trim((string) $preview['commande']['name']) !== '' ? $preview['commande']['name'] : '#' . $preview['commande']['id'], 'text'),
            $this->row('transporteurId', $locale, $preview['transporteur']['name'], 'text'),
            $this->row('amount', $locale, $preview['amount'], 'money', ['currency' => $preview['currency']]),
            $preview['tracknumber'] === null ? null : $this->row('tracknumber', $locale, $preview['tracknumber'], 'text'),
        ]));
    }

    public function execute(array $answers, ?string $user): array
    {
        $commande = $this->em()->find(Commande::class, (int) $answers['commandeId']);
        if ($commande === null) {
            throw new GestionConflictException('COMMANDE_NOT_FOUND', 'Commande introuvable.', 404);
        }
        // mêmes refus que la route existante : 409 SHIPPING_COST_ALREADY_SET, COMMANDE_CLOSED, PERIOD_CLOSED ; 404 TRANSPORTEUR_NOT_FOUND
        $shipping = $this->shipping->createFraisDePort($commande, new FraisDePortInputDTO('Frais de port — ' . ($commande->getName() ?? '#' . $commande->getId()), '', (string) ($answers['tracknumber'] ?? ''), (int) $answers['amount'], (int) $answers['transporteurId']));

        return ['result' => ['fraisDePortId' => $shipping->getId(), 'commandeId' => $commande->getId()],
            'undo' => ['id' => $shipping->getId(), 'commandeId' => $commande->getId(), 'price' => $shipping->getPrice(), 'transporteurId' => $shipping->getTransporteur()?->getId(), 'tracknumber' => $shipping->getTracknumber()]];
    }

    public function blockers(array $undo): array
    {
        $row = $this->conn()->fetchAssociative('SELECT price, transporteur_id, tracknumber, commande_id FROM frais_de_port WHERE id = ?', [(int) $undo['id']]);
        if ($row === false) {
            return [['code' => 'SHIPPING_CHANGED', 'message' => 'Le frais de port créé par cette action n\'existe plus.']];
        }
        $reasons = [];
        if ((int) $row['price'] !== (int) $undo['price'] || (int) $row['transporteur_id'] !== (int) $undo['transporteurId'] || ($row['tracknumber'] ?? null) !== ($undo['tracknumber'] ?? null)) {
            $reasons[] = ['code' => 'SHIPPING_CHANGED', 'message' => 'Le frais de port a été modifié depuis sa création.'];
        }
        $commande = $this->em()->find(Commande::class, (int) $undo['commandeId']);
        try {
            if ($commande !== null) {
                $this->guard->assertCommandeWritable($commande);
            }
        } catch (GestionConflictException $e) {
            $reasons[] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }

        return $reasons;
    }

    public function undo(array $undo): array
    {
        $commande = $this->em()->find(Commande::class, (int) $undo['commandeId']);
        if ($commande === null) {
            throw new GestionConflictException('UNDO_BLOCKED', 'La commande n\'existe plus.');
        }
        $commande->setFraisDePort($this->em()->find(FraisDePort::class, (int) $undo['id']));
        $this->shipping->deleteFraisDePort($commande);

        return ['removed' => ['shipping' => (int) $undo['id']]];
    }

    /** Pourquoi cette commande ne peut pas recevoir de frais de port : déjà un, figée, période fermée ; null si elle le peut */
    private function refusal(Commande $commande): ?string
    {
        if ((int) $this->conn()->fetchOne('SELECT COUNT(*) FROM frais_de_port WHERE commande_id = ?', [$commande->getId()]) > 0) {
            return 'SHIPPING_COST_ALREADY_SET';
        }
        try {
            $this->guard->assertCommandeWritable($commande);
        } catch (GestionConflictException $e) {
            return $e->errorCode;
        }

        return null;
    }
}
