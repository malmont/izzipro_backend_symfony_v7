<?php

namespace App\Services\Group\Transfer;

/**
 * Lot G-5 + G-1d : le lien « même entreprise » d'une boutique retiré, ses transferts seulement DEMANDÉS (dans un sens ou l'autre) sont annulés ;
 * un transfert envoyé se termine normalement ; les nouveaux sont refusés par la relecture de la règle à chaque geste. Classe à part (elle ne
 * dépend que du dépôt) : `GroupCompanyService` l'appelle sans dépendre du service des transferts.
 */
class GroupTransferLinkGuard
{
    public function __construct(private readonly GroupTransferRepository $transfers)
    {
    }

    /**
     * Lot G-CONSENT : `requested` ET `send_failed` sont annulés. Un `send_failed` a déjà rendu son stock à la source (la compensation précède
     * le passage à cet état) : c'est une simple fermeture, sans écriture dans les sites. `sending` et `sent` restent intacts (l'envoi se termine,
     * le transfert envoyé se reçoit). Le passage est conditionnel à l'état lu : un envoi relancé entre-temps (passé à `sending`) n'est pas touché.
     *
     * @return int le nombre de transferts annulés
     */
    public function onSameCompanyRemoved(int $groupId, string $shop): int
    {
        $n = 0;
        foreach ($this->transfers->cancellableOnLinkRemoval($groupId, $shop) as $transfer) {
            if ($this->transfers->move($transfer['id'], [$transfer['state']], 'cancelled', ['cancelled_by' => 'lien « même entreprise » retiré', 'cancelled_at' => 'now'])) {
                $this->transfers->event($transfer['id'], 'cancelled', ['reason' => 'same_company_removed', 'from' => $transfer['state']], null);
                ++$n;
            }
        }

        return $n;
    }
}
