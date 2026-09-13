#!/bin/sh
# Séquence de démarrage (docs/conception.md §7.6), arrêt à la première erreur (D16).
# Pour PHP-FPM seulement : migrations, puis app:statistics:apply-window (STATS_WINDOW_SIZE), puis exec.
# Une autre commande (docker compose run --rm php bin/console …) s'exécute sans elles, pour diagnostiquer (§11.3).
set -eu

if [ "${1:-}" = php-fpm ]; then
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console app:statistics:apply-window
fi

exec "$@"
