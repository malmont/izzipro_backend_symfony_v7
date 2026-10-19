<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Données de caisse non modifiables dans EasyAdmin (chantier C2, 19/10/2026, accord explicite du propriétaire) : ventes
 * au comptoir (orderSource 2) et leurs articles, taxes et paiements, sessions, transactions et détails de caisse.
 * Consultation et création restent possibles ; les commandes web ne changent pas. Une erreur se corrige par une
 * contre-écriture (remboursement, mouvement de caisse), jamais en modifiant ou supprimant la donnée.
 * Boutons Modifier et Supprimer masqués pour ces lignes, et refus côté serveur (403) si on les appelle quand même.
 */
trait CashRecordLockTrait
{
    abstract protected function isCashRecordLocked(object $entity): bool;

    protected static function isCounterSale(?Order $order): bool
    {
        return $order?->getOrderSource()?->getId() === 2;
    }

    protected function lockCashRecordActions(Actions $actions): Actions
    {
        $editable = fn ($entity) => !is_object($entity) || !$this->isCashRecordLocked($entity);
        foreach ([Crud::PAGE_INDEX, Crud::PAGE_DETAIL, Crud::PAGE_EDIT] as $page) {
            foreach ([Action::EDIT, Action::DELETE] as $name) {
                try {
                    $actions->update($page, $name, fn (Action $action) => $action->displayIf($editable));
                } catch (\Throwable) {
                    // action absente de cette page
                }
            }
        }

        return $actions;
    }

    protected function denyLockedCashRecord(object $entity): void
    {
        if ($this->isCashRecordLocked($entity)) {
            throw new AccessDeniedHttpException('Donnée de caisse (vente au comptoir, session, transaction) : ni modification ni suppression. Corriger par un remboursement ou un mouvement de caisse.');
        }
    }
}
