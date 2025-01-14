#!/bin/bash

if [ ! -f /var/www/.env ]; then
  echo "📄 Création sécurisée du fichier .env..."

  cat <<EOL > /var/www/.env
POSTGRES_USER=${DB_USER}
POSTGRES_PASSWORD=${DB_PASSWORD}
POSTGRES_DB=${DB_NAME}
POSTGRES_HOST=${DB_HOST}
POSTGRES_PORT=${DB_PORT}
APP_ENV=${APP_ENV}
APP_SECRET=${APP_SECRET}
DATABASE_URL=pgsql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}
EOL

  echo "✅ Fichier .env généré."
else
  echo "ℹ️ Le fichier .env existe déjà."
fi
