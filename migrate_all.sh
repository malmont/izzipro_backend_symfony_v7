#!/bin/bash

# Configuration
PASSWORD="Wipit2017"
HOST="127.0.0.1"
USER="postgres"
PORT="5432"

echo "Recherche des bases de données de type 'db_...' (PostgreSQL)"

# Récupération de la liste des bases de données
# On exclut postgres et les bases système, on se concentre sur celles commençant par 'db_'
DB_LIST=$(PGPASSWORD=$PASSWORD psql -h $HOST -U $USER -t -c "SELECT datname FROM pg_database WHERE datname LIKE 'db_%' AND datname NOT IN ('db_master', 'db_testmessenger');")

for DB in $DB_LIST; do
    # Supprimer les espaces éventuels
    DB=$(echo $DB | xargs)
    
    if [ -z "$DB" ]; then continue; fi

    echo ">>> Migration de la base : $DB"
    
    # Exécution de la migration en mode non-interactif
    DATABASE_URL="postgresql://$USER:$PASSWORD@$HOST:$PORT/$DB" php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    
    if [ $? -eq 0 ]; then
        echo "✅ Migration réussie pour $DB"
    else
        echo "❌ Échec de la migration pour $DB"
    fi
    echo "----------------------------------------------------"
done

echo "Fin de toutes les migrations."
