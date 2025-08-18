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
            'delete' => ['service_offers_all'],
            'invalidate_tags' => ['service_offers'],
        ],
        [
            'classes' => [Recherche::class],
            'delete' => ['recherches_all'],
            'invalidate_tags' => ['recherches'],
        ],
        [
            'classes' => [Multilien::class],
            'delete' => ['multiliens_all'],
            'invalidate_tags' => ['multiliens'],
        ],
        [
            'classes' => [Marque::class, CategorieMarque::class], // Gère déjà les deux
            'delete' => ['marques_all', 'categories_marque_all'],
            'invalidate_tags' => ['marques', 'categories_marque'],
        ],
         [
            'classes' => [Marque::class, CategorieMarque::class], 
            'delete' => ['marques_all', 'categories_marque_all'], 
            'invalidate_tags' => ['marques', 'categories_marque'],
        ],
        [
            'classes' => [Candidature::class],
            'delete' => ['candidatures_all'],
            'invalidate_tags' => ['candidatures', 'emplois'], 
        ],
        [
            'classes' => [Emploi::class],
            'delete' => ['emplois_all'],
            'invalidate_tags' => ['emplois'],
        ],
        [
            'classes' => [Banniere::class],
            'delete' => ['bannieres_all'], 
            'invalidate_tags' => ['bannieres'], 
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
            'delete' => ['carriers'],
            'invalidate_tags' => [],
        ],
        [
            'classes' => [HomeSlider::class],
            'delete' => ['homeslider'],
            'invalidate_tags' => [],
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
        'invalidate_tags' => ['products_by_category'], 
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
            'classes' => [Style::class,Categories::class],
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

        if ($entity instanceof Adress) {
            $user = $entity->getUserAdress();
            if ($user) {
                $specificKeyToDelete = $prefix . 'adresses_user_' . $user->getId();
                $this->cache->delete($specificKeyToDelete);
            }
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'adresses_user']);
            }
            return;
        }
        if ($entity instanceof Entreprise) {
            $specificKeyToDelete = $prefix . 'entreprise_' . $entity->getId();
            $this->cache->delete($specificKeyToDelete);
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([$tagPrefix . 'entreprise']);
            }
            return;
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
            return;
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
            return;
        }
        if ($entity instanceof Order) {
            $user = $entity->getUserId();
            if ($user) {
                $specificKeyToDelete = $prefix . 'orders_user_' . $user->getId();
                $this->cache->delete($specificKeyToDelete);
            }
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags([
                    $tagPrefix . 'orders_user',
                    $tagPrefix . 'orders_source'
                ]);
            }
            return;
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
            return;
        }

        foreach (self::CACHE_INVALIDATIONS as $operation) {
            foreach ($operation['classes'] as $class) {
                if ($entity instanceof $class) {
                    foreach ($operation['delete'] as $key) {
                        $this->cache->delete($prefix . $key);
                    }
                    if (!empty($operation['invalidate_tags']) && $this->cache instanceof TagAwareCacheInterface) {
                        $tags = array_map(fn($tag) => $tagPrefix . $tag, $operation['invalidate_tags']);
                        $this->cache->invalidateTags($tags);
                    }
                    break;
                }
            }
        }
    }

}