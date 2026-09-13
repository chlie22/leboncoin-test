#!/bin/sh
# Séquence de démarrage (docs/conception.md §7.6), arrêt à la première erreur.
# Les migrations et app:statistics:apply-window s'insèrent avant l'exec à l'étape 7.
set -eu

exec "$@"
