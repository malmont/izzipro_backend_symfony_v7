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

    /** @return int le nombre de transferts annulés */
    public function onSameCompanyRemoved(int $groupId, string $shop): int
    {
        $n = 0;
        foreach ($this->transfers->requestedFor($groupId, $shop) as $id) {
            if ($this->transfers->move($id, ['requested'], 'cancelled', ['cancelled_by' => 'lien « même entreprise » retiré', 'cancelled_at' => 'now'])) {
                $this->transfers->event($id, 'cancelled', ['reason' => 'same_company_removed'], null);
                ++$n;
            }
        }

        return $n;
    }
}
