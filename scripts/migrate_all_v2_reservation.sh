#!/bin/bash
# Script: migrate_all_v2_reservation.sh
# Applique la table reservation sur toutes les bases de données V2 (sauf master)

CONTAINER="symfony_db_v2"
PGUSER="postgres"

# Liste de toutes les bases V2 (hors master, postgres, template0, template1)
DATABASES=(
    "db_lintendantprive"
    "db_esgboost"
    "db_karaandb"
    "db_memoiresvivantes"
    "db_boussoleesg"
    "gmasuite"
    "app_v2_db"
)

SCHEMA_SQL="
CREATE TABLE IF NOT EXISTS reservation (
    id SERIAL PRIMARY KEY,
    entreprise_id INT DEFAULT NULL,
    tenant_id VARCHAR(100) DEFAULT NULL,
    service_id VARCHAR(100) DEFAULT NULL,
    service_name VARCHAR(255) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_slot VARCHAR(100) DEFAULT NULL,
    client_name VARCHAR(255) NOT NULL,
    client_email VARCHAR(255) NOT NULL,
    client_phone VARCHAR(50) NOT NULL,
    number_of_guests INT NOT NULL DEFAULT 1,
    notes TEXT DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_reservation_date ON reservation (reservation_date);
CREATE INDEX IF NOT EXISTS idx_reservation_status ON reservation (status);
CREATE INDEX IF NOT EXISTS idx_reservation_tenant ON reservation (tenant_id);
CREATE INDEX IF NOT EXISTS idx_reservation_client_email ON reservation (client_email);
"

for DB in "${DATABASES[@]}"; do
    echo "=================================================="
    echo "⏳ Application de la table reservation sur : $DB ..."
    docker exec "$CONTAINER" psql -U "$PGUSER" -d "$DB" -c "$SCHEMA_SQL" > /dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "✅ Table reservation prête sur : $DB"
    else
        echo "❌ Erreur de création sur : $DB"
    fi
done

echo "=================================================="
echo "🎉 Migration Reservation terminée sur toutes les bases V2 (hors master) !"
