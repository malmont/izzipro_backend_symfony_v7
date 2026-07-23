#!/bin/bash
# Script: populate_v7_vehicle_discriminators.sh
# Pour chaque base v7_* : 
#   1. Marque les produits liés à un vehicle comme product_type = 'vehicle'
#   2. Peuple la table vehicle_product depuis la table vehicle existante (clone GemSuite)

PGPASSWORD="Wipit2017"
PGUSER="postgres"
PGHOST="127.0.0.1"

export PGPASSWORD

DATABASES=("v7_larameemarine" "v7_caravane201" "v7_expertnautique")

SQL="
-- Marquer les produits liés à un vehicle comme 'vehicle'
UPDATE product SET product_type = 'vehicle'
WHERE id IN (SELECT product_id FROM vehicle WHERE product_id IS NOT NULL);

-- Reset les autres à 'product'
UPDATE product SET product_type = 'product'
WHERE product_type != 'vehicle';

-- Insérer dans vehicle_product depuis la table vehicle (clone GemSuite)
INSERT INTO vehicle_product (id, year, brand, model, transmission, gas_type, color)
SELECT 
    v.product_id,
    v.year,
    'Marque' as brand,
    v.title as model,
    v.transmission::varchar,
    v.gas_type::varchar,
    v.color
FROM vehicle v
WHERE v.product_id IS NOT NULL
ON CONFLICT (id) DO UPDATE SET
    year = EXCLUDED.year,
    model = EXCLUDED.model,
    transmission = EXCLUDED.transmission,
    gas_type = EXCLUDED.gas_type,
    color = EXCLUDED.color;
"

for DB in \"\${DATABASES[@]}\"; do
    echo ""
    echo "⏳ Mise à jour des discriminators vehicule dans: \$DB ..."
    psql -U "\$PGUSER" -h "\$PGHOST" -d "\$DB" -c "\$SQL" 2>&1

    # Vérification
    VEHICLE_COUNT=\$(psql -U "\$PGUSER" -h "\$PGHOST" -d "\$DB" -t -c "SELECT COUNT(*) FROM product WHERE product_type = 'vehicle';" 2>&1 | tr -d ' ')
    VP_COUNT=\$(psql -U "\$PGUSER" -h "\$PGHOST" -d "\$DB" -t -c "SELECT COUNT(*) FROM vehicle_product;" 2>&1 | tr -d ' ')

    echo "✅ \$DB : \$VEHICLE_COUNT produits marqués 'vehicle', \$VP_COUNT entrées dans vehicle_product"
done

echo ""
echo "🎉 Discriminators mis à jour sur toutes les bases v7!"
