<?php

namespace App\Controller\Admin;

use App\Abo\Services\AboCarrierService;
use App\Abo\Services\AboException;
use App\Entity\Product;
use App\Security\Entry\AdminEntrySession;
use App\Services\Entry\EntryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Lot ABO-CONF-b : la case « Abonnement » d'une fiche produit ou véhicule d'EasyAdmin (fiche et bascule de la liste) suit les règles de la
 * gestion web (AboCarrierService). Un produit dont une formule a eu un abonné reste porteur ; un véhicule ou le porteur d'une formule branchée
 * ne s'active pas ; un directeur de groupe entré dans une boutique ne la change pas (la route de la gestion web lui est fermée de même).
 * Refus : la case est remise et le motif s'affiche. Le geste accepté est journalisé (`product_subscription`), comme par l'API et l'assistant.
 *
 * Usage : `$changed = $this->guardSubscriptionToggle($em, $entity);` avant l'écriture, puis `$this->recordSubscriptionToggle($entity, $changed);`.
 */
trait SubscriptionToggleGuardTrait
{
    private ?AboCarrierService $subscriptionCarriers = null;
    private ?RequestStack $subscriptionRequests = null;

    #[Required]
    public function setSubscriptionToggleGuard(AboCarrierService $carriers, RequestStack $requests): void
    {
        $this->subscriptionCarriers = $carriers;
        $this->subscriptionRequests = $requests;
    }

    /** @return bool|null la nouvelle valeur acceptée (à journaliser après l'écriture), null si rien ne change */
    private function guardSubscriptionToggle(EntityManagerInterface $em, mixed $product): ?bool
    {
        if ($this->subscriptionCarriers === null || !$product instanceof Product) {
            return null;
        }
        if ($product->getId() === null) {
            $stored = false; // création : la case cochée est un geste d'activation
        } else {
            // l'état chargé, lu dans le gestionnaire qui tient l'entité (jamais une requête sur une base qui pourrait ne pas être la sienne)
            $stored = null;
            foreach ([$em, $this->emProvider->getEntityManager()] as $manager) {
                if ($manager->contains($product)) {
                    $original = $manager->getUnitOfWork()->getOriginalEntityData($product);
                    if (array_key_exists('subscriptionEnabled', $original)) {
                        $stored = (bool) $original['subscriptionEnabled'];
                        break;
                    }
                }
            }
            if ($stored === null) {
                return null;
            }
        }
        $wanted = $product->isSubscriptionEnabled();
        if ($stored === $wanted) {
            return null;
        }
        // l'état enregistré, le temps de juger le geste (les règles lisent le produit tel qu'il est)
        $product->setSubscriptionEnabled($stored);
        $session = $this->subscriptionRequests?->getCurrentRequest()?->hasSession() ? $this->subscriptionRequests->getCurrentRequest()->getSession() : null;
        if ($session !== null && (AdminEntrySession::get($session)['mode'] ?? null) === EntryService::MODE_GROUP) {
            $this->addFlash('danger', 'La case « Abonnement » se règle par l\'administrateur de la boutique.');

            return null;
        }
        try {
            foreach ($this->subscriptionCarriers->assertCanSet($product, $wanted) as $warning) {
                $this->addFlash('warning', sprintf('Ces formules sortent de la vente : %s.', implode(', ', $warning['plans'] ?? [])));
            }
        } catch (AboException $e) {
            $this->addFlash('danger', $e->getMessage()); // la case reste comme elle était

            return null;
        }
        $product->setSubscriptionEnabled($wanted);
        if ($wanted) {
            $this->subscriptionCarriers->restoreWebIfHidden($product); // lot ABO-WEB-2 : la fiche masquée par le nettoyage revient
        }

        return $wanted;
    }

    private function recordSubscriptionToggle(mixed $product, ?bool $changed): void
    {
        if ($changed !== null && $product instanceof Product && $product->getId() !== null) {
            $this->subscriptionCarriers?->recordToggle($product, $changed);
        }
    }
}
