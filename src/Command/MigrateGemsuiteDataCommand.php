<?php

namespace App\Command;

use App\Services\TenantEntityManagerProvider;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-gemsuite',
    description: 'Migre et transforme les données d\'une ancienne base GemSuite vers le nouveau modèle v7 (VehicleProduct & Product).',
)]
class MigrateGemsuiteDataCommand extends Command
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDbName', InputArgument::REQUIRED, 'Nom de la base PostgreSQL GemSuite source (ex: db_larameemarineinc2)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDbName = $input->getArgument('sourceDbName');

        $io->title("Migration GemSuite -> v7 pour la base: $sourceDbName");

        // 1. Connexion DBAL vers la base source GemSuite
        $connectionParams = [
            'dbname' => $sourceDbName,
            'user' => 'postgres',
            'password' => 'Wipit2017',
            'host' => '127.0.0.1',
            'driver' => 'pdo_pgsql',
        ];

        try {
            $sourceConn = DriverManager::getConnection($connectionParams);
            $sourceConn->getNativeConnection();
            $io->success("Connexion établie avec succès à la base source: $sourceDbName");
        } catch (\Exception $e) {
            $io->error("Impossible de se connecter à la base source $sourceDbName: " . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Récupération des données source
        $em = $this->emProvider->getEntityManager();
        $targetConn = $em->getConnection();

        // Ensure discriminator column 'product_type' exists in target table 'product'
        try {
            $targetConn->executeStatement("ALTER TABLE product ADD COLUMN IF NOT EXISTS product_type VARCHAR(255) DEFAULT 'product';");
            $targetConn->executeStatement("UPDATE product SET product_type = 'product' WHERE product_type IS NULL;");
        } catch (\Exception $e) {
            $io->warning("Changement de colonne product_type: " . $e->getMessage());
        }

        // Create vehicle_product table if not exists for CTI
        try {
            $targetConn->executeStatement("
                CREATE TABLE IF NOT EXISTS vehicle_product (
                    id INT NOT NULL,
                    year INT DEFAULT NULL,
                    brand VARCHAR(255) DEFAULT NULL,
                    model VARCHAR(255) DEFAULT NULL,
                    vin VARCHAR(255) DEFAULT NULL,
                    transmission VARCHAR(100) DEFAULT NULL,
                    gas_type VARCHAR(100) DEFAULT NULL,
                    engine_power VARCHAR(100) DEFAULT NULL,
                    hours_or_mileage INT DEFAULT NULL,
                    vehicle_condition VARCHAR(50) DEFAULT NULL,
                    color VARCHAR(100) DEFAULT NULL,
                    PRIMARY KEY(id),
                    CONSTRAINT FK_vehicle_product_id FOREIGN KEY (id) REFERENCES product (id) ON DELETE CASCADE
                );
            ");
        } catch (\Exception $e) {
            $io->warning("Création de la table vehicle_product: " . $e->getMessage());
        }

        // Fetch products and linked vehicles from source DB
        $sql = "
            SELECT 
                p.id as product_id,
                p.name,
                p.price,
                p.quantity,
                v.id as vehicle_id,
                v.title,
                v.year,
                v.color,
                v.transmission,
                v.gas_type
            FROM product p
            LEFT JOIN vehicle v ON v.product_id = p.id
        ";

        try {
            $rows = $sourceConn->fetchAllAssociative($sql);
            $io->info("Trouvé " . count($rows) . " produits dans la base source $sourceDbName.");

            $migratedVehicles = 0;
            $migratedProducts = 0;

            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                $isVehicle = !empty($row['vehicle_id']);
                $productType = $isVehicle ? 'vehicle' : 'product';
                $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $row['name']), '-'));

                // Upsert product in target database
                $targetConn->executeStatement("
                    INSERT INTO product (id, name, price, quantity, slug, isbestseller, product_type, created_at)
                    VALUES (:id, :name, :price, :quantity, :slug, false, :product_type, NOW())
                    ON CONFLICT (id) DO UPDATE SET
                        name = EXCLUDED.name,
                        price = EXCLUDED.price,
                        quantity = EXCLUDED.quantity,
                        product_type = EXCLUDED.product_type
                ", [
                    'id' => $productId,
                    'name' => $row['name'] ?? 'Produit ' . $productId,
                    'price' => $row['price'] ? (float) $row['price'] : 0.0,
                    'quantity' => $row['quantity'] ? (int) $row['quantity'] : 0,
                    'slug' => $slug ?: ('product-' . $productId),
                    'product_type' => $productType,
                ]);

                if ($isVehicle) {
                    // Insert or update in vehicle_product
                    $targetConn->executeStatement("
                        INSERT INTO vehicle_product (id, year, brand, model, transmission, gas_type, color)
                        VALUES (:id, :year, :brand, :model, :transmission, :gas_type, :color)
                        ON CONFLICT (id) DO UPDATE SET
                            year = EXCLUDED.year,
                            transmission = EXCLUDED.transmission,
                            gas_type = EXCLUDED.gas_type,
                            color = EXCLUDED.color
                    ", [
                        'id' => $productId,
                        'year' => $row['year'] ? (int) $row['year'] : null,
                        'brand' => 'Marque',
                        'model' => $row['title'] ?? $row['name'],
                        'transmission' => $row['transmission'] ? (string) $row['transmission'] : null,
                        'gas_type' => $row['gas_type'] ? (string) $row['gas_type'] : null,
                        'color' => $row['color'] ?? null,
                    ]);

                    $migratedVehicles++;
                } else {
                    $migratedProducts++;
                }
            }

            $io->success("Migration terminée avec succès !");
            $io->table(
                ['Métrique', 'Quantité Migrée'],
                [
                    ['Véhicules / Bateaux à Vendre (VehicleProduct)', $migratedVehicles],
                    ['Produits & Accessoires Boutique (Product)', $migratedProducts],
                    ['Total Produits', count($rows)],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Erreur lors de la migration: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
