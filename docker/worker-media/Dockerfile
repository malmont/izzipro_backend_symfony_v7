# Worker Messenger « media » : l'image de l'application, plus ffmpeg (préparation des vidéos pour les scènes au
# défilement). ffmpeg n'est installé qu'ici : le conteneur du site n'encode jamais rien.
FROM app_v2-app:latest

# l'image de l'application se termine sur l'utilisateur www-data : installation en root, puis retour
USER root
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
 && apt-get clean && rm -rf /var/lib/apt/lists/*
USER www-data
