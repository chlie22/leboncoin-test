# Mesures de conception

Scripts qui étayent les chiffres de [`docs/conception.md`](../conception.md).
Ce n'est **pas** le code de l'application : ils utilisent PDO directement, sans Symfony ni Doctrine DBAL, avec le schéma et le SQL de la spécification (`lib.php`).

## Scripts

| Script | Ce qu'il mesure ou vérifie | Sections de la spec |
|---|---|---|
| `01-stockage.php` | historique complet contre fenêtre glissante ; enregistrement et lecture sur fenêtre pleine ; plans d'exécution ; réduction de N au démarrage ; pire cas disque ; ancienne et nouvelle forme de lecture | §6.1, §6.3 à §6.6, §15.1 |
| `02-fenetre-exactitude.php` | compare, après **chaque** opération, le résultat SQL (gagnant, hits, taille retenue) à un modèle en mémoire écrit indépendamment, avec changements de N et fenêtre de taille 1 ; échoue en cas d'écart | §3.4, §6.3, §6.4, §6.6 |
| `03-json-reponse.php` | taille des caractères en JSON, texte « Zalgo », taille, compression et mémoire de la réponse au pire cas | §3.3 |

## Exécution

```bash
php docs/benchmarks/02-fenetre-exactitude.php
php docs/benchmarks/03-json-reponse.php
php -d memory_limit=1G docs/benchmarks/01-stockage.php
```

Les bases temporaires sont créées dans le répertoire temporaire du système et supprimées à la fin. `01-stockage.php` utilise jusqu'à environ 1 Go de disque pendant la mesure à 10 millions de lignes, et dure quelques minutes.

## Résultats de référence

Sortie complète : [`results/2026-09-12-apple-m2-pro.txt`](results/2026-09-12-apple-m2-pro.txt)
(Apple M2 Pro, 32 Go, macOS 26.6.2, SSD APFS, PHP 8.5.2, SQLite 3.51.2, `WAL` + `synchronous=FULL`).

| Mesure | Résultat |
|---|---|
| Exactitude de la fenêtre | 13 504 comparaisons SQL / modèle, **0 écart** ; invariants respectés dans les 4 phases |
| Historique complet, 10 M combinaisons (chaînes courtes) | lecture du top 0,002 ms ; écriture 0,045 ms ; **856 Mo** |
| Fenêtre N = 100 000 pleine (chaînes courtes) | enregistrement 0,091 ms ; lecture complète 0,002 ms ; **10,9 Mo** |
| Fenêtre N = 100 000 pleine (2 × 50 emojis, combinaisons toutes distinctes, renouvelée 2 fois) | **101,0 Mo** ; invariants respectés |
| Réduction de N 100 000 → 10 000 | 278 ms ; lecture immédiate : `window_count` = 10 000 |
| Lecture : `max` et `min` combinés (ancienne forme) | 3,376 ms, parcours complet du journal |
| Lecture : `max` et `min` séparés (forme retenue) | 0,002 ms, recherches indexées uniquement |
| Réponse JSON au pire cas retenu | 6,03 Mo ; 21,9 Ko compressée ; 14,1 Mo de mémoire pour la génération et l'encodage |

## Limites de ces mesures

- **Machine de développement, pas l'infrastructure cible.** Sous macOS, SQLite n'utilise pas `F_FULLFSYNC` par défaut : les écritures y sont plus rapides qu'avec une synchronisation complète sous Linux. Les temps d'écriture sont donc des **ordres de grandeur optimistes**.
- **Un seul processus**, sans concurrence : aucune mesure de contention entre processus PHP-FPM.
- **PHP CLI**, sans Symfony ni PHP-FPM : la mémoire mesurée couvre la génération et l'encodage JSON, **pas** la mémoire totale d'une requête.
- Ces mesures ne remplacent ni le test de charge k6 (§9.4), ni une qualification sur l'infrastructure de production.
