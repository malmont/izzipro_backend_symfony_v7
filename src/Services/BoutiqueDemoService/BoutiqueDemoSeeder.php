<?php

namespace App\Services\BoutiqueDemoService;

use App\Entity\Adress;
use App\Entity\BookingConfiguration;
use App\Entity\Categories;
use App\Entity\CategoriesTranslation;
use App\Entity\Color;
use App\Entity\ColorTranslation;
use App\Entity\Entreprise;
use App\Entity\ExploreCard;
use App\Entity\ExploreCardTranslation;
use App\Entity\HomeSlider;
use App\Entity\HomeSliderTranslation;
use App\Entity\Product;
use App\Entity\ProductCustomizationImage;
use App\Entity\ProductOption;
use App\Entity\ProductOptionTranslation;
use App\Entity\ProductOptionValue;
use App\Entity\ProductOptionValueTranslation;
use App\Entity\ProductPicture;
use App\Entity\ProductTranslation;
use App\Entity\ProductVariant;
use App\Entity\RentalPack;
use App\Entity\RentalPackTranslation;
use App\Entity\SaleUnit;
use App\Entity\Size;
use App\Entity\SizeTranslation;
use App\Entity\User;
use App\Entity\VehicleProduct;
use App\Services\TenantCacheService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Remplit la boutique du site courant avec les données de BoutiqueDemoCatalog. Rejouable : chaque élément est repéré
 * (code produit DEMO-…, nom, code d'option, identifiant fixe des données de référence) et n'est créé que s'il manque ;
 * un élément déjà présent n'est pas modifié. N'efface rien. Le choix du site (demo seulement) est fait par
 * SeedBoutiqueDemoUseCase, qui bascule l'EntityManager du tenant avant l'appel.
 */
final class BoutiqueDemoSeeder
{
    /** Caches des lectures publiques de la boutique (contrôleurs) */
    public const CACHE_TAGS = ['products_all', 'products_by_category', 'products_by_offer', 'product_variants', 'categories_all', 'carriers_all',
        'homeslider_all', 'explore_cards_all', 'sizes_all', 'colors_all'];

    /** @var list<string> */
    private array $report = [];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly DemoImageGenerator $images,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TenantCacheService $cache
    ) {
    }

    /**
     * @return array{report: list<string>, customerPassword: ?string} lignes du compte rendu ; mot de passe du client de
     *         démonstration s'il vient d'être créé ou réinitialisé (jamais enregistré ailleurs qu'en base, haché)
     */
    public function seed(string $customerEmail, bool $resetPassword, bool $otp): array
    {
        $this->report = [];
        $em = $this->em();
        $this->reference();
        $this->carriers();
        $categories = $this->categories();
        $this->rentalPacks($categories);
        [$options, $values] = $this->options();
        [$sizes, $colors] = $this->sizesAndColors();
        $saleUnit = $this->saleUnit();
        $em->flush();

        foreach (BoutiqueDemoCatalog::products() as $definition) {
            $this->product($definition, $categories, $values, $sizes, $colors, $saleUnit);
        }
        $this->slides();
        $this->cards();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        if ($entreprise && !$entreprise->isBoutiqueActive()) {
            $entreprise->setIsBoutiqueActive(true);
            $this->report[] = 'Boutique activée sur la fiche entreprise';
        }
        $em->flush();
        $password = $this->customer($customerEmail, $resetPassword, $otp);
        $em->flush();
        // Les lectures publiques gardent leur réponse en cache (jusqu'à 1 h) : une liste vide resterait servie. Certains
        // contrôleurs étiquettent sans le préfixe du site (CarrierControleur) : leurs clés sont aussi supprimées une à une.
        $this->cache->invalidateTags(self::CACHE_TAGS);
        foreach (['carriers_', 'categories_all_', 'homeslider_all_', 'explore_cards_all_', 'all_products_'] as $key) {
            foreach (['fr', 'en'] as $locale) {
                $this->cache->delete($key . $locale);
            }
        }

        return ['report' => $this->report, 'customerPassword' => $password];
    }

    /** Données de référence du tunnel de commande et taxes : insérées avec leurs identifiants, si absentes */
    private function reference(): void
    {
        $connection = $this->em()->getConnection();
        foreach (BoutiqueDemoCatalog::REFERENCE as $table => $rows) {
            $added = 0;
            foreach ($rows as $row) {
                $added += $connection->executeStatement("INSERT INTO $table (id, name) VALUES (?, ?) ON CONFLICT (id) DO NOTHING", [$row[0], $row[1]]);
                if (isset($row[2])) {
                    foreach (['fr' => $row[1], 'en' => $row[2]] as $language => $name) {
                        $connection->executeStatement(
                            "INSERT INTO {$table}_translation ({$table}_id, language, name) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM {$table}_translation WHERE {$table}_id = ? AND language = ?)",
                            [$row[0], $language, $name, $row[0], $language]
                        );
                    }
                }
            }
            $connection->executeStatement("SELECT setval(pg_get_serial_sequence('$table', 'id'), GREATEST((SELECT MAX(id) FROM $table), 1))");
            $added && $this->report[] = sprintf('%s : %d ligne(s) ajoutée(s)', $table, $added);
        }
        $added = 0;
        foreach (BoutiqueDemoCatalog::TAXES as [$id, $name, $rate, $type, $province]) {
            $added += $connection->executeStatement('INSERT INTO tax (id, name, rate, type, province) VALUES (?, ?, ?, ?, ?) ON CONFLICT (id) DO NOTHING', [$id, $name, $rate, $type, $province]);
        }
        $connection->executeStatement("SELECT setval(pg_get_serial_sequence('tax', 'id'), GREATEST((SELECT MAX(id) FROM tax), 1))");
        $added && $this->report[] = "Taxes : $added ajoutée(s) (TPS 5 %, TVQ 9,975 %)";
    }

    /** Transporteurs à prix fixe, avec leurs identifiants (6 = gratuit) */
    private function carriers(): void
    {
        $connection = $this->em()->getConnection();
        $added = 0;
        foreach (BoutiqueDemoCatalog::CARRIERS as $carrier) {
            $inserted = $connection->executeStatement(
                'INSERT INTO carrier (id, name, description, price, estimated_days, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON CONFLICT (id) DO NOTHING',
                [$carrier['id'], $carrier['fr'][0], $carrier['fr'][1], $carrier['price'], $carrier['days']]
            );
            $connection->executeStatement('UPDATE carrier SET estimated_days = ? WHERE id = ? AND estimated_days IS NULL', [$carrier['days'], $carrier['id']]);
            if ($inserted) {
                foreach (['fr', 'en'] as $language) {
                    $connection->executeStatement('INSERT INTO carrier_translation (carrier_id, language, name, description) VALUES (?, ?, ?, ?)',
                        [$carrier['id'], $language, $carrier[$language][0], $carrier[$language][1]]);
                }
            }
            $added += $inserted;
        }
        $connection->executeStatement("SELECT setval(pg_get_serial_sequence('carrier', 'id'), GREATEST((SELECT MAX(id) FROM carrier), 1))");
        $added && $this->report[] = "Transporteurs : $added ajouté(s) (dont « Livraison gratuite », id 6)";
    }

    /** @return array<string, Categories> */
    private function categories(): array
    {
        $repository = $this->em()->getRepository(Categories::class);
        $categories = [];
        foreach (BoutiqueDemoCatalog::CATEGORIES as $key => [$fr, $en, $descriptionFr, $descriptionEn, $rental, $color]) {
            $category = $repository->findOneBy(['name' => $fr]);
            if ($category === null) {
                $category = (new Categories())->setName($fr)->setDescription($descriptionFr)->setIsRentalCategory($rental)->setSyncWeb(true)->setIsVisible(true)
                    ->setImage($this->images->generate('categories', "categorie-$key", $fr, $descriptionFr, $color, 1200, 800));
                foreach (['fr' => [$fr, $descriptionFr], 'en' => [$en, $descriptionEn]] as $language => [$name, $description]) {
                    $category->addTranslation((new CategoriesTranslation())->setLanguage($language)->setName($name)->setDescription($description));
                }
                $this->em()->persist($category);
                $this->report[] = "Catégorie : $fr";
            }
            $categories[$key] = $category;
        }

        return $categories;
    }

    /** @param array<string, Categories> $categories */
    private function rentalPacks(array $categories): void
    {
        $repository = $this->em()->getRepository(RentalPack::class);
        foreach (BoutiqueDemoCatalog::RENTAL_PACKS as [$fr, $en, $category, $hour, $halfDay, $day, $week, $month]) {
            if ($repository->findOneBy(['name' => $fr]) !== null) {
                continue;
            }
            $pack = (new RentalPack())->setName($fr)->setHourRate($hour)->setHalfDayRate($halfDay)->setDayRate($day)->setWeekRate($week)->setMonthRate($month)
                ->addCategory($categories[$category]);
            foreach (['fr' => $fr, 'en' => $en] as $language => $name) {
                $pack->addTranslation((new RentalPackTranslation())->setLanguage($language)->setName($name));
            }
            $this->em()->persist($pack);
            $this->report[] = "Forfait de location : $fr";
        }
    }

    /** @return array{0: array<string, ProductOption>, 1: array<string, array<string, ProductOptionValue>>} */
    private function options(): array
    {
        $optionRepository = $this->em()->getRepository(ProductOption::class);
        $options = $values = [];
        foreach (BoutiqueDemoCatalog::OPTIONS as $code => [$fr, $en, $optionValues]) {
            $option = $optionRepository->findOneBy(['code' => $code]);
            if ($option === null) {
                $option = (new ProductOption())->setName($fr)->setCode($code);
                foreach (['fr' => $fr, 'en' => $en] as $language => $name) {
                    $option->addTranslation((new ProductOptionTranslation())->setLanguage($language)->setName($name));
                }
                $this->em()->persist($option);
                $this->report[] = "Option : $fr";
            }
            $options[$code] = $option;
            foreach ($optionValues as $valueCode => [$valueFr, $valueEn, $delta, $hexa]) {
                $value = null;
                foreach ($option->getProductOptionValues() as $existing) {
                    if ($existing->getCode() === "$code-$valueCode") {
                        $value = $existing;
                    }
                }
                if ($value === null) {
                    $value = (new ProductOptionValue())->setValue($valueFr)->setCode("$code-$valueCode")->setPriceDelta((float) $delta)->setImagePreview($hexa);
                    foreach (['fr' => $valueFr, 'en' => $valueEn] as $language => $name) {
                        $value->addTranslation((new ProductOptionValueTranslation())->setLanguage($language)->setValue($name));
                    }
                    $option->addProductOptionValue($value);
                    $this->em()->persist($value);
                }
                $values[$code][$valueCode] = $value;
            }
        }

        return [$options, $values];
    }

    /** @return array{0: array<string, Size>, 1: array<string, Color>} */
    private function sizesAndColors(): array
    {
        $sizes = $colors = [];
        foreach (BoutiqueDemoCatalog::SIZES as $code => [$fr, $en]) {
            $size = $this->em()->getRepository(Size::class)->findOneBy(['code' => "demo-$code"]);
            if ($size === null) {
                $size = (new Size())->setName($fr)->setCode("demo-$code");
                foreach (['fr' => $fr, 'en' => $en] as $language => $name) {
                    $size->addTranslation((new SizeTranslation())->setLanguage($language)->setName($name));
                }
                $this->em()->persist($size);
            }
            $sizes[$code] = $size;
        }
        foreach (BoutiqueDemoCatalog::COLORS as $code => [$fr, $en, $hexa]) {
            $color = $this->em()->getRepository(Color::class)->findOneBy(['code' => "demo-$code"]);
            if ($color === null) {
                $color = (new Color())->setName($fr)->setCode("demo-$code")->setCodeHexa($hexa);
                foreach (['fr' => $fr, 'en' => $en] as $language => $name) {
                    $color->addTranslation((new ColorTranslation())->setLanguage($language)->setName($name));
                }
                $this->em()->persist($color);
            }
            $colors[$code] = $color;
        }

        return [$sizes, $colors];
    }

    private function saleUnit(): SaleUnit
    {
        $unit = $this->em()->getRepository(SaleUnit::class)->findOneBy(['name' => 'kg']);
        if ($unit === null) {
            $nextId = (int) $this->em()->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM sale_unit');
            $unit = (new SaleUnit())->setId($nextId)->setName('kg');
            $this->em()->persist($unit);
            $this->report[] = 'Unité de vente : kg';
        }

        return $unit;
    }

    /**
     * @param array<string, mixed> $d définition du catalogue
     * @param array<string, Categories> $categories
     * @param array<string, array<string, ProductOptionValue>> $values
     * @param array<string, Size> $sizes
     * @param array<string, Color> $colors
     */
    private function product(array $d, array $categories, array $values, array $sizes, array $colors, SaleUnit $saleUnit): void
    {
        $code = BoutiqueDemoCatalog::CODE_PREFIX . $d['code'];
        if ($this->em()->getRepository(Product::class)->findOneBy(['code' => $code]) !== null) {
            return;
        }
        // Slugger de Symfony : iconv dépend de la locale du processus (« à » disparaissait sous PHPUnit)
        $slug = fn (string $name) => (new AsciiSlugger('fr'))->slug($name)->lower()->toString();
        $key = strtolower($d['code']);

        $product = isset($d['vehicle']) ? new VehicleProduct() : new Product();
        $product->setName($d['fr'][0])->setDescription($d['fr'][1])->setMoreinformations($d['more'])->setPrice($d['price'])->setCode($code)
            ->setSlug($slug($d['fr'][0]))->setTags('démo')->setIsWeb(true)->setIsPos(false)->setIsPreOrder(false)->setIsAccessory(in_array('isAccessory', $d['flags'] ?? [], true))
            ->setIsbestseller(in_array('isbestseller', $d['flags'] ?? [], true))->setIsnewarrival(in_array('isnewarrival', $d['flags'] ?? [], true))
            ->setIsfeatured(in_array('isfeatured', $d['flags'] ?? [], true))->setIsspecialoffer(in_array('isspecialoffer', $d['flags'] ?? [], true))
            ->setSaleEnabled($d['sale'] ?? true)->setRentalEnabled($d['rental'] ?? false)->setSubscriptionEnabled($d['subscription'] ?? false)
            ->setCustomizable($d['customizable'] ?? false)
            ->setImage($this->images->generate('products', $key, $d['fr'][0], $d['fr'][1], $d['color']))
            ->addCategory($categories[$d['category']]);
        if (isset($d['special'])) {
            [$amount, $from, $to] = $d['special'];
            $product->setSpecialPrice($amount)->setSpecialPriceFrom($from)->setSpecialPriceTo($to);
        }
        if (($d['saleUnit'] ?? null) === 'kg') {
            $product->setSaleUnit($saleUnit);
        }
        foreach (['fr' => $d['fr'], 'en' => $d['en']] as $locale => [$name, $description]) {
            $product->addTranslation((new ProductTranslation())->setLocale($locale)->setName($name)->setDescription($description)
                ->setSlug($slug($name))->setTags($locale === 'fr' ? 'démo' : 'demo')->setMoreinformations($locale === 'fr' ? $d['more'] : null));
        }
        for ($i = 1; $i <= ($d['photos'] ?? 0); $i++) {
            $shade = sprintf('#%02x%02x%02x', ...array_map(fn ($c) => (int) min(255, $c + 18 * $i), sscanf($d['color'], '#%02x%02x%02x')));
            $product->addPicture((new ProductPicture())->setImageUrl($this->images->generate('products', "$key-photo-$i", $d['fr'][0], sprintf('Vue %d sur %d', $i, $d['photos']), $shade)));
        }
        if ($product instanceof VehicleProduct) {
            $v = $d['vehicle'];
            $product->setBrand($v['brand'])->setModel($v['model'])->setYear($v['year'])->setVehicleCondition($v['condition'])->setColor($v['color'] ?? null)
                ->setGasType($v['gasType'] ?? null)->setTransmission($v['transmission'] ?? null)->setEnginePower($v['enginePower'] ?? null)->setHoursOrMileage($v['hours'] ?? null);
        }

        foreach ($d['variants'] as $definition) {
            $variant = (new ProductVariant())->setStockQuantity($definition['stock'])->setPrice($definition['price'] ?? null);
            foreach ($definition['options'] ?? [] as $option => $value) {
                $variant->addOptionValue($values[$option][$value]);
                $option === 'taille' && $variant->setSize($sizes[$value]);
                $option === 'couleur' && $variant->setColor($colors[$value]);
            }
            foreach ($definition['customization'] ?? [] as $i => [$combination, $stock]) {
                $image = (new ProductCustomizationImage())->setNumberOfPieces($stock)->setImagePath($this->images->generate(
                    'products', "$key-combinaison-" . ($i + 1), $d['fr'][0], implode(' · ', array_map(fn ($o, $v) => BoutiqueDemoCatalog::OPTIONS[$o][2][$v][0], array_keys($combination), $combination)),
                    $d['color']
                ));
                foreach ($combination as $option => $value) {
                    $image->addOptionValue($values[$option][$value]);
                }
                $variant->addProductCustomizationImage($image);
                $this->em()->persist($image);
            }
            $product->addVariant($variant);
            $this->em()->persist($variant);
        }
        $product->updateQuantity();

        if (isset($d['booking'])) {
            $b = $d['booking'];
            $config = (new BookingConfiguration())->setProduct($product)->setGranularity($b['granularity'])->setStockQuantity($b['stock'])
                ->setMinDuration($b['min'])->setMaxDuration($b['max'] ?? null)->setBufferTime($b['buffer'])
                ->setOpeningStart($b['open'][0] ?? null)->setOpeningEnd($b['open'][1] ?? null)->setHalfDays($b['halfDays'] ?? null)
                ->setAllowedDates($b['allowedDates'] ?? null)->setEveningSlot($b['evening'] ?? null)->setMinDaysStandard($b['minDays'] ?? null)
                ->setDeposit($b['deposit'] ?? null)->setExtraPassengerFee($b['passenger'] ?? null)->setArrivalLeadMinutes($b['lead'] ?? null)
                ->setCancellationPolicy($b['cancellation'] ?? null)->setIncluded($b['included'] ?? null)->setExcluded($b['excluded'] ?? null)->setNotes($b['notes'] ?? null);
            $product->setBookingConfiguration($config);
            $this->em()->persist($config);
        }

        $this->em()->persist($product);
        $this->em()->flush();
        $this->report[] = sprintf('Produit : %s (%s) — %s', $d['fr'][0], $code, $d['case']);
    }

    private function slides(): void
    {
        if ($this->em()->getRepository(HomeSlider::class)->count([]) > 0) {
            return;
        }
        foreach (BoutiqueDemoCatalog::SLIDES as $i => [$titleFr, $titleEn, $textFr, $textEn, $buttonFr, $buttonEn, $url, $color]) {
            $slide = (new HomeSlider())->setTitle($titleFr)->setDescription($textFr)->setButtonMessage($buttonFr)->setButtonUrl($url)->setIsDiplayed(true)
                ->setImage($this->images->generate('slider', 'diapositive-' . ($i + 1), $titleFr, $textFr, $color, 1920, 900));
            foreach (['fr' => [$titleFr, $textFr, $buttonFr], 'en' => [$titleEn, $textEn, $buttonEn]] as $language => [$title, $text, $button]) {
                $slide->addTranslation((new HomeSliderTranslation())->setLanguage($language)->setTitle($title)->setDescription($text)->setButtonMessage($button)->setButtonUrl($url));
            }
            $this->em()->persist($slide);
        }
        $this->report[] = sprintf('Diaporama de l\'accueil : %d diapositives', count(BoutiqueDemoCatalog::SLIDES));
    }

    private function cards(): void
    {
        if ($this->em()->getRepository(ExploreCard::class)->count([]) > 0) {
            return;
        }
        foreach (BoutiqueDemoCatalog::CARDS as $i => [$titleFr, $titleEn, $subtitleFr, $subtitleEn, $textFr, $textEn, $link, $color]) {
            $card = (new ExploreCard())->setIsDifferent($i === 1)->setStandardTitle($titleFr)->setDifferentTitle($subtitleFr)->setDescription($textFr)->setLink($link)
                ->setImagePath($this->images->generate('explore', 'carte-' . ($i + 1), $titleFr, $subtitleFr, $color, 900, 1200));
            foreach (['fr' => [$titleFr, $subtitleFr, $textFr], 'en' => [$titleEn, $subtitleEn, $textEn]] as $language => [$title, $subtitle, $text]) {
                $card->addTranslation((new ExploreCardTranslation())->setLanguage($language)->setStandardTitle($title)->setDifferentTitle($subtitle)->setDescription($text));
            }
            $this->em()->persist($card);
        }
        $this->report[] = sprintf('Cartes « explorer » : %d', count(BoutiqueDemoCatalog::CARDS));
    }

    /** Client de démonstration avec une adresse (principale) ; mot de passe tiré au hasard à la création */
    private function customer(string $email, bool $resetPassword, bool $otp): ?string
    {
        $em = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $password = null;
        if ($user === null) {
            $user = (new User())->setEmail($email)->setUsername($email)->setFirstname('Camille')->setLastname('Démo')
                ->setRoles(['ROLE_USER', 'ROLE_USER_INTERNET'])->setIsVerified(true);
            $em->persist($user);
            $resetPassword = true;
            $this->report[] = "Client de démonstration : $email";
        }
        if ($resetPassword) {
            $password = 'Demo-' . bin2hex(random_bytes(6));
            $user->setPassword($this->hasher->hashPassword($user, $password));
        }
        $user->setOtpEnabled($otp);
        if ($user->getAdresses()->isEmpty()) {
            $address = (new Adress())->setFirstname('Camille')->setLastname('Démo')->setFullname('Camille Démo')->setAddress('1000, rue Sherbrooke Ouest')
                ->setComplement('Bureau 200')->setCity('Montréal')->setCodepostal('H3A 3G4')->setProvince('QC')->setCountry('CA')->setPhone('+1 514 555 0100')
                ->setUserAdress($user);
            $user->addAdress($address);
            $user->setPrimaryAddress($address);
            $em->persist($address);
            $this->report[] = 'Adresse du client : Montréal (QC)';
        }

        return $password;
    }

    private function em(): EntityManagerInterface
    {
        return $this->emProvider->getEntityManager();
    }
}
