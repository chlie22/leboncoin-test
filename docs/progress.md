# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune — étape 8 terminée.
- **Prochaine étape** : 9 — `/healthz`, logs, OPcache, smoke.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; étape 8 poussée (`3569ffd`).
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
| 9 | `/healthz`, logs, OPcache, smoke | ⏳ à faire | — |
| 9b | Test de charge k6 | ⏳ à faire | — |
| 10 | README et runbook | ⏳ à faire | — |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Étape 9** : `HealthController`, Monolog + `RequestIdProcessor`, OPcache/preload, smoke restant (`/healthz` 200, `HEAD`, deux IP, 502, marqueurs de logs dont 413, démarrage) ; `HEALTHCHECK` Nginx (`up --wait` fiable) ; 404 journalisés en `error` avec la query string (§8.1).

- Pièges étape 8 vérifiés : `WebTestCase` + connexion du conteneur (pas `kernelConnection()`) ; messages tableau `int|null` / `null|string` ; `JsonEncoder` + `JSON_INVALID_UTF8_SUBSTITUTE` pour `%FF` ; Content-Type problem+json via subscriber ; `detail` des erreurs dépend de `kernel.debug` (message d'exception en test, texte du statut en prod) ; rate limit : attendre 1 s après smoke avant un appel manuel.
- Plans d'agent (`docs/superpowers/`) exclus via `.git/info/exclude`, jamais committés.
- Chaque push sur `main` lance la CI. Sorties : `rtk proxy` ; codes : `$?` sans pipe. Push HTTPS.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` adapté ; `"php": "^8.5"` ; `src/Controller/` supprimé.
- **2026-09-13** — Pas de `.env.dev.local` versionné ; dépôt privé (D4) ; `make` sur l'hôte + `EXEC` ; Deptrac `Shared` / `PsrLog` / `--fail-on-uncovered` ; Docker prod+override, `read_only` ; Q15 élargie 403/413 ; CI SHA + Composer 2.10.3.
- **2026-09-13** — Étapes 5–6 : pas d'`equals()` ; messages entiers ; `psr/log` direct ; warning classes only ; fenêtre vide côté adaptateur.
- **2026-09-14** — Étape 7 : entrypoint php-fpm seulement ; `STATS_WINDOW_SIZE` strict ; DBAL seul ; transaction manuelle + codes SQLite.
- **2026-09-14** — Étape 8 : packages validator/serializer/property-* + browser-kit ; monolog → étape 9 ; `JsonErrorFormatSubscriber` + rewrite Content-Type ; `JsonEncoder` UTF-8 substitute ; messages conversion tableaux = unions Symfony. §3.3, §5.7, §9.3.

## Journal des sessions

- **2026-09-14 (matin)** — Étape 8 faite : contrôle post-agent (spec, contrat, appels réels via Nginx) ; tests durcis (messages exacts, problem+json, `HEAD` 503) ; `make ci` 0 (179/1309) ; `3569ffd`, CI `34832451393`. Prochaine : 9.
- **2026-09-14 (nuit, suite)** — Étape 8 prête à committer : TDD (49 tests fonctionnels), `make ci` 0 (176/1184), smoke prod+dev 0, docs sync ; revue d'architecture sans bloquant.
- **2026-09-14 (nuit)** — Étape 7 faite : `af00927`, CI `34786638347`. Prochaine : 8.
- **2026-09-13 (nuit, suite)** — Étape 6 faite. Prochaine : 7.
- **2026-09-13 (fin de soirée)** — Étape 5 faite. Prochaine : 6.
