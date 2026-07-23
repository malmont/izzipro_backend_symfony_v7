#!/bin/bash
# Script: apply_v7_schema.sh
# Applique le schema v7 (vehicle_product + product_type) sur toutes les bases v7_*

PGPASSWORD="Wipit2017"
PGUSER="postgres"
PGHOST="127.0.0.1"

export PGPASSWORD

DATABASES=("v7_larameemarine" "v7_caravane201" "v7_expertnautique" "db_testmessenger")

V7_SCHEMA_SQL="
-- Ajout colonne discriminateur product_type
ALTER TABLE product ADD COLUMN IF NOT EXISTS product_type VARCHAR(255) NOT NULL DEFAULT 'product';

-- Création table vehicle_product (Doctrine CTI JOINED)
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
    CONSTRAINT fk_vehicle_product_id FOREIGN KEY (id) REFERENCES product (id) ON DELETE CASCADE
);
"

for DB in "${DATABASES[@]}"; do
    echo "⏳ Application du schema v7 sur: $DB ..."
    psql -U "$PGUSER" -h "$PGHOST" -d "$DB" -c "$V7_SCHEMA_SQL" 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Schema v7 appliqué avec succès sur: $DB"
    else
        echo "❌ Erreur sur: $DB"
    fi
done

echo ""
echo "🎉 Schema v7 appliqué sur toutes les bases!"
