<?php


namespace App\EventSubscriber;

use App\Entity\Adress;
use App\Entity\Caisse;
use App\Entity\Carrier;
use App\Entity\Size;
use App\Entity\Order;
use App\Entity\AdminSettings;
use App\Entity\Entreprise;
use App\Entity\Banniere;
use App\Entity\HomeSlider;
use App\Entity\Product;
use App\Entity\Payments;
use App\Entity\BaniereStatique;
use App\Entity\ExploreCard;
use App\Entity\EmailConfiguration;
use App\Entity\Feature;
use App\Entity\ProductVariant;
use App\Entity\SquareConfig;
use App\Entity\Color;
use App\Entity\Style;
use App\Entity\Collections;
use App\Entity\Commande;
use App\Entity\NoteDeFrais;
use App\Entity\Categories;
use App\Entity\TypeFournisseur;
use App\Entity\FraisDePort;
use App\Entity\Transporteur;
use App\Entity\TypeNoteDeFrais;
use App\Entity\TransactionCaisse;
use App\Entity\Fournisseur;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use App\Services\TenantConnectionProvider;
use App\Entity\Emploi;
use App\Entity\Candidature;
use App\Entity\Marque;
use App\Entity\CategorieMarque;
use App\Entity\Multilien;
use App\Entity\Recherche;
use App\Entity\ServiceOffer;
use App\Entity\Video;
use App\Entity\Contact;
use App\Entity\StatusPayment;
use App\Entity\StatusCommande;
use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\PaymentMethod;
use App\Entity\OrderType;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use App\Entity\Team;


class CacheInvalidationSubscriber implements EventSubscriber
{
    private CacheInterface $cache;
    private TenantConnectionProvider $tcp;


    /**
     * Tableau de correspondance définissant pour chaque groupe d'entités
     * les clés à supprimer et/ou les tags à invalider.
     */
    private const CACHE_INVALIDATIONS = [
        [
            'classes' => [Contact::class],
            'delete' => ['contacts_all'],
            'invalidate_tags' => ['contacts'],
        ],
        [
            'classes' => [Video::class],
            'delete' => ['videos_all'],
            'invalidate_tags' => ['videos'],
        ],
        [
            'classes' => [ServiceOffer::class],
            'delete' => [],
            'invalidate_tags' => ['service_offers_all'],
        ],
        [
            'classes' => [Recherche::class],
            'delete' => [],
            'invalidate_tags' => ['recherches_all'],
        ],
        [
            'classes' => [StatusPayment::class],
            'delete' => [],
            'invalidate_tags' => ['status_payments_all'],
        ],
        [
            'classes' => [StatusCommande::class],
            'delete' => [],
            'invalidate_tags' => ['status_commandes_all'],
        ],
        [
            'classes' => [Presentation::class],
            'delete' => [],
            'invalidate_tags' => ['presentations_all'],
        ],
        [
            'classes' => [PresentationGroup::class],
            'delete' => [],
            'invalidate_tags' => ['presentation_groups_all'],
        ],
        [
            'classes' => [PaymentMethod::class],
            'delete' => [],
            'invalidate_tags' => ['payment_methods_all'],
        ],
        [
            'classes' => [OrderType::class],
            'delete' => [],
            'invalidate_tags' => ['order_types_all'],
        ],
        [
            'classes' => [Marque::class],
            'delete' => [],
            'invalidate_tags' => ['marques_all', 'categories_marque_all'],
        ],
        [
            'classes' => [Multilien::class],
            'delete' => ['multiliens_all'],
            'invalidate_tags' => ['multiliens'],
        ],
        [
            'classes' => [CategorieMarque::class],
            'delete' => [],
            'invalidate_tags' => ['categories_marque_all'],
        ],
        [
            'classes' => [Candidature::class],
            'delete' => ['candidatures_all'],
            'invalidate_tags' => ['candidatures', 'emplois'],
        ],
        [
            'classes' => [Emploi::class],
            'delete' => [''],
            'invalidate_tags' => ['emplois_all'],
        ],
        [
            'classes' => [Banniere::class, BaniereStatique::class],
            'delete' => [],
            'invalidate_tags' => ['bannieres_all', 'bannieres_statiques_all'],
        ],
        [
            'classes' => [TransactionCaisse::class],
            'delete' => ['open_caisse_transactions'],
            'invalidate_tags' => ['caisses_tag'],
        ],
        [
            'classes' => [AdminSettings::class],
            'delete' => ['admin_settings'],
            'invalidate_tags' => [],
        ],
        [
            'classes' => [Carrier::class],
            'delete' => [],
            'invalidate_tags' => ['carriers_all'],
        ],
        [
            'classes' => [HomeSlider::class],
            'delete' => [],
            'invalidate_tags' => ['homeslider_all'],
        ],
        [
            'classes' => [Feature::class],
            'delete' => [],
            'invalidate_tags' => ['features_all'],
        ],
        [
            'classes' => [SquareConfig::class],
            'delete' => ['square_config'],
            'invalidate_tags' => ['square_config'],
        ],
        // Pour Product, Style, ProductVariant, Categories : tag products_by_category
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_by_category'],
        ],
        [
            'classes' => [Categories::class],
            'delete' => ['categories_all'],
            'invalidate_tags' => ['categories_all', 'products_by_category'],
        ],
        // Collections
        [
            'classes' => [Collections::class],
            'delete' => ['collections_all'],
            'invalidate_tags' => [],
        ],
        // Colors
        [
            'classes' => [Color::class],
            'delete' => ['colors_all'],
            'invalidate_tags' => [],
        ],
        [
            'classes' => [ExploreCard::class],
            'delete' => [],
            'invalidate_tags' => ['explore_cards_all'],
        ],
        [
            'classes' => [Entreprise::class],
            'delete' => [],
            'invalidate_tags' => ['entreprise'],
        ],
        [
            'classes' => [EmailConfiguration::class],
            'delete' => [],
            'invalidate_tags' => ['email_configurations_all'],
        ],
        // Pour Commande et Fournisseur : tag commandes_by_collection
        [
            'classes' => [Commande::class, Fournisseur::class],
            'delete' => [],
            'invalidate_tags' => ['commandes_by_collection'],
        ],
        // Pour dashboard_commande : Commande ou Product
        [
            'classes' => [Commande::class, Product::class],
            'delete' => [],
            'invalidate_tags' => ['dashboard_commande'],
        ],
        // Pour dashboard_collection : Commande, Product, Collections, NoteDeFrais, FraisDePort
        [
            'classes' => [Commande::class, Product::class, Collections::class, NoteDeFrais::class, FraisDePort::class],
            'delete' => [],
            'invalidate_tags' => ['dashboard_collection'],
        ],
        // Pour Fournisseur : suppression de la clé
        [
            'classes' => [Fournisseur::class],
            'delete' => ['fournisseurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour FraisDePort et Transporteur : tag frais_de_port
        [
            'classes' => [FraisDePort::class, Transporteur::class],
            'delete' => ['frais_de_port'],
            'invalidate_tags' => [],
        ],
        // Pour Payments
        [
            'classes' => [Payments::class],
            'delete' => [],
            'invalidate_tags' => ['payments'],
        ],
        // Pour products_command
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_command'],
        ],
        // Pour products_all
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_all'],
        ],
        // Pour products_by_offer
        [
            'classes' => [Product::class, Style::class, ProductVariant::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['products_by_offer'],
        ],
        // Pour product_variants (Style, ProductVariant, Categories)
        [
            'classes' => [Style::class, Categories::class],
            'delete' => [],
            'invalidate_tags' => ['product_variants'],
        ],
        // Pour Size
        [
            'classes' => [Size::class],
            'delete' => ['sizes_all'],
            'invalidate_tags' => [],
        ],
        // Pour Style : suppression de styles_all
        [
            'classes' => [Style::class],
            'delete' => ['styles_all'],
            'invalidate_tags' => [],
        ],
        // Pour Transporteur : suppression de transporteurs_all
        [
            'classes' => [Transporteur::class],
            'delete' => ['transporteurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour TypeFournisseur
        [
            'classes' => [TypeFournisseur::class],
            'delete' => ['api_type_fournisseurs_all'],
            'invalidate_tags' => [],
        ],
        // Pour TypeNoteDeFrais
        [
            'classes' => [TypeNoteDeFrais::class],
            'delete' => ['api_type_note_de_frais_all'],
            'invalidate_tags' => [],
        ],
        // Pour Team
        [
            'classes' => [Team::class],
            'delete' => [],
            'invalidate_tags' => ['teams_all'],
        ],
    ];

    public function __construct(CacheInterface $cache, TenantConnectionProvider $tcp)
    {
        $this->cache = $cache;
        $this->tcp = $tcp;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->invalidateCache($args);
    }

    /**
     * Parcourt la table de correspondance pour appliquer les opérations
     * d'invalidation du cache selon le type de l'entité modifiée.
     */
    private function invalidateCache(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        $tenantCode = $this->tcp->getTenantCode() ?: 'master';
        $prefix = $tenantCode . ':';
        $tagPrefix = $tenantCode;

        if ($entity instanceof BaniereStatique) {
            if ($entity->getId() && $this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['baniere_statique_' . $entity->getId()]);
            }
        }
        if ($entity instanceof Banniere) {
            if ($entity->getId() && $this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['banniere_' . $entity->getId()]);
            }
        }
        if ($entity instanceof Carrier) {
            if ($entity->getId() && $this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['carrier_' . $entity->getId()]);
            }
        }
        if ($entity instanceof CategorieMarque) {
            if ($entity->getId() && $this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['categorie_marque_' . $entity->getId()]);
            }
        }
        if ($entity instanceof ExploreCard && $entity->getId()) {
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['explore_card_' . $entity->getId()]);
            }
        }
        if ($entity instanceof Entreprise && $entity->getId()) {
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['entreprise_' . $entity->getId()]);
            }
        }
        if ($entity instanceof Team && $entity->getId()) {
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['team_' . $entity->getId()]);
            }
        }

        if ($entity instanceof Adress) {
            $user = $entity->getUserAdress();
            if ($user) {
                $specificKeyToDelete = $prefix . 'adresses_user_' . $user->getId();
                $this->cache->delete($specificKeyToDelete);
            }
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'adresses_user']);
            }
        }
        if ($entity instanceof Entreprise) {
            $specificKeyToDelete = $prefix . 'entreprise_' . $entity->getId();
            $this->cache->delete($specificKeyToDelete);
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'entreprise']);
            }
        }
        if ($entity instanceof FraisDePort) {
            $commande = $entity->getCommande();
            if ($commande) {
                $specificKeyToDelete = $prefix . 'frais_de_port_commande_' . $commande->getId();
                $this->cache->delete($specificKeyToDelete);
            }

            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'frais_de_port']);
            }
        }
        if ($entity instanceof NoteDeFrais) {
            $collection = $entity->getCollection();
            if ($collection) {
                $specificKeyToDelete = $prefix . 'notes_de_frais_collection_' . $collection->getId();
                $this->cache->delete($specificKeyToDelete);
            }
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'notes_de_frais']);
            }
        }
        if ($entity instanceof Order) {
            /** @var User|null $user */
            $user = $entity->getUserId();
            $tenantCode = $this->tcp->getTenantCode() ?: 'master';
            if ($user !== null && get_class($user) === User::class && $this->cache instanceof TagAwareCacheInterface) {
                $userTagSimple = 'orders_user_' . $user->getId();
                $userTagPrefixed = $tenantCode . $userTagSimple;
                $tagsToInvalidate = [$userTagPrefixed, $tenantCode];
                $this->cache->invalidateTags($tagsToInvalidate);
            }
        }
        if ($entity instanceof ProductVariant || $entity instanceof Product) {

            $productId = null;
            if ($entity instanceof ProductVariant) {
                $product = $entity->getProduct();
                if ($product) {
                    $productId = $product->getId();
                }
            } else {
                $productId = $entity->getId();
            }

            if ($productId) {
                $specificKeyToDelete = $prefix . 'product_variants_' . $productId;
                $this->cache->delete($specificKeyToDelete);
            }
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'product_variants']);
            }
        }

        foreach (self::CACHE_INVALIDATIONS as $operation) {
            foreach ($operation['classes'] as $class) {
                if ($entity instanceof $class) {
                    foreach ($operation['delete'] as $key) {
                        if ($key) {
                            $this->cache->delete($prefix . $key);
                        }
                    }
                    if (!empty($operation['invalidate_tags']) && $this->cache instanceof TagAwareCacheInterface) {
                        $tags = array_map(fn($tag) => $tagPrefix . $tag, $operation['invalidate_tags']);
                        $this->cache->invalidateTags($tags);
                    }
                }
            }
        }
    }
}
