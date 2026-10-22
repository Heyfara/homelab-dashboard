#!/bin/sh
set -e

# nginx (container partagé) sert les fichiers statiques : on lui copie le
# dossier public/ de cette image dans le dossier monté PUBLIC_EXPORT_DIR.
# Une nouvelle image remplace ainsi aussi le front, sans étape de plus.
if [ -n "$PUBLIC_EXPORT_DIR" ]; then
    rm -rf "$PUBLIC_EXPORT_DIR/build"
    cp -R /app/public/. "$PUBLIC_EXPORT_DIR/"
fi

exec docker-php-entrypoint "$@"
