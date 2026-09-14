# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : 9 — `/healthz`, logs, OPcache, smoke (revue faite, corrigée, preuves locales OK ; ✅ réservé à la CI `docker` verte).
- **Prochaine étape** : pousser puis confirmer le job CI `docker` ; ensuite 9b — k6.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main`.
- **Dernière mise à jour** : 2026-09-14.

## Avancement du plan

Étapes et critères de fin : `docs/conception.md` §12.

| # | Étape | Statut | Preuve |
|---|---|---|---|
| 0–4 | Contrat → CI | ✅ fait | voir journal / commits antérieurs |
| 5 | Domain | ✅ fait | `a99355a`, run `34780267928` |
| 6 | Application | ✅ fait | `9f6a2da`, run `34782724339` |
| 7 | Persistance SQLite | ✅ fait | `af00927`, run `34786638347` |
| 8 | API : DTO, contrôleurs, `HEAD`, erreurs | ✅ fait | `3569ffd`, run `34832451393` |
| 9 | `/healthz`, logs, OPcache, smoke | 🚧 en cours | `make ci` 0 (190/1335) ; revue : smoke prod 0 (71 contrôles, 248 s ; marqueurs par scénario, `window.count` avec témoin, 403/413 journalisés) ; `STATS_WINDOW_SIZE=1.5` → 1 ; logs prod sans dépréciation ni INFO ; `GenerateFizzBuzzQueryCacheWarmer` (option a, 2026-09-14) : 6 warnings `Failed to save key` par appel → 0, mesuré sur la même requête, `make ci` 0 (192/1340) ; 400/404/405 en `log_level: info` : plus journalisés en prod (warning), mesuré ; OPcache preload mesuré sous FPM (prod) : 732 classes, 36 fonctions, ~9 Mo ; actionlint 0 |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Fin d'étape 9** : commit + push faits, CI `docker` à confirmer. Ensuite **9b** (k6).

- Pièges étape 9 : Shared → DBAL seul pour `/healthz` (pas le port) ; fuite `str1`/`str2` dans logs Symfony debug → `RedactQueryStringProcessor` ; BusyBox wget sans HEAD → HEAD `/healthz` via PHP sur le réseau de confiance ; php arrêté → souvent **504** (IP amont figée) plutôt que 502 sur Docker Desktop — smoke accepte 502\|504 ; preuve OPcache jamais en CLI : le fichier de preload généré par Symfony s'arrête en CLI (0 classe même avec `opcache.enable_cli=1`) ; mesurer `opcache_get_status()` sous FPM (client FastCGI vers `127.0.0.1:9000`, script dans `/tmp`) ; `make smoke` exige la stack prod (refuse la dev) et la laisse démarrée ; `docker compose -f compose.yaml ps` affiche aussi la stack dev (même projet) : vérifier `APP_ENV` ; ne pas confondre le mot `zend_extension` dans un commentaire d'`app.prod.ini`.
- Plans d'agent (`docs/superpowers/`) exclus via `.git/info/exclude`.
- Sorties : `rtk proxy` ; codes : `$?` sans pipe. Push HTTPS.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` adapté ; `"php": "^8.5"` ; `src/Controller/` supprimé.
- **2026-09-13** — Pas de `.env.dev.local` versionné ; dépôt privé (D4) ; `make` sur l'hôte + `EXEC` ; Deptrac `Shared` / `PsrLog` / `--fail-on-uncovered` ; Docker prod+override, `read_only` ; Q15 élargie 403/413 ; CI SHA + Composer 2.10.3.
- **2026-09-13** — Étapes 5–6 : pas d'`equals()` ; messages entiers ; `psr/log` direct ; warning classes only ; fenêtre vide côté adaptateur.
- **2026-09-14** — Étape 7 : entrypoint php-fpm seulement ; `STATS_WINDOW_SIZE` strict ; DBAL seul ; transaction manuelle + codes SQLite.
- **2026-09-14** — Étape 8 : packages validator/serializer/property-* + browser-kit ; monolog → étape 9 ; `JsonErrorFormatSubscriber` + rewrite Content-Type ; `JsonEncoder` UTF-8 substitute. §3.3, §5.7, §9.3.
- **2026-09-14** — Étape 9 : monolog-bundle 4.1.0 ; `RedactQueryStringProcessor` (§8.1) ; `fastcgi_connect_timeout 3s` ; smoke 502\|504 si IP amont figée ; dépréciations non écrites en prod (seuil `warning`, handler dédié retiré) ; smoke réservé à la stack prod ; cache système du DTO précalculé au build plutôt que rendu inscriptible, pour ne pas rouvrir la lecture seule de `var/cache/prod` (D validée §7.9). §5.3, §7.2, §7.3, §7.6, §8.1, §9.1, §9.3, §10.

## Journal des sessions

- **2026-09-14 (après-midi)** — Revue de l'étape 9 faite par agent : smoke durci (contrôles vides corrigés, garde anti-stack dev), preuve OPcache refaite sous FPM, dépréciations hors logs prod, docs sync ; `make ci` 0 (190/1335), smoke prod 0. Warnings de cache RO et niveau des 4xx à trancher.
- **2026-09-14 (midi)** — Étape 9 locale (agent) : HealthController, Monolog+processors, HEALTHCHECK, smoke complet, `make ci` 0 (190/1335).
- **2026-09-14 (matin)** — Étape 8 faite : `3569ffd`, CI `34832451393`. Prochaine : 9.
- **2026-09-14 (nuit, suite)** — Étape 8 prête à committer.
- **2026-09-14 (nuit)** — Étape 7 faite : `af00927`, CI `34786638347`.
