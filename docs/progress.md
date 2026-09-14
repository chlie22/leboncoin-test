# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation.
- **Étape en cours** : aucune — étape 9b terminée.
- **Prochaine étape** : 10 — README et runbook.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; étape 9b poussée (`66c6291`).
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
| 9 | `/healthz`, logs, OPcache, smoke | ✅ fait | `fe59cfd`, run `34850124962` |
| 9b | Test de charge k6 | ✅ fait | `66c6291`, run `34864015335` (job `load-test`, `workflow_dispatch`) |
| 10 | README et runbook | ⏳ à faire | section charge déjà dans README |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

**Étape 10** : README et runbook complet (§11.3), en gardant la section « Test de charge » déjà présente.

- Pièges 9b : stack prod only (`-f compose.yaml`, `APP_ENV`) ; `compose cp` impossible (rootfs RO) → holder SQLite via stdin ; pas de `ps` dans l'image → RSS via `/proc` ; quotas relevés 100 r/s puis restore nginx ; concurrency CI = `event_name` dans le groupe workflow + groupe job `load-test-*` ; ✅ 9b = run CI, pas seulement local ; **`grafana/k6` tourne en `uid=12345` non-root** — `mktemp -d` (0700) bloque la lecture du script et l'écriture de `summary.json` sur le runner Linux (« permission denied »), invisible en local car le pont de montage de Docker Desktop macOS n'applique pas les permissions Unix de l'hôte : `chmod 777` le répertoire temporaire avant de monter le volume k6.
- Sorties : `rtk proxy` ; codes : `$?` sans pipe. Push HTTPS.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` adapté ; `"php": "^8.5"` ; `src/Controller/` supprimé.
- **2026-09-13** — Pas de `.env.dev.local` versionné ; dépôt privé (D4) ; `make` sur l'hôte + `EXEC` ; Deptrac `Shared` / `PsrLog` / `--fail-on-uncovered` ; Docker prod+override, `read_only` ; Q15 élargie 403/413 ; CI SHA + Composer 2.10.3.
- **2026-09-13–14** — Étapes 5–8 : messages entiers ; DBAL seul ; validator/serializer ; monolog → étape 9.
- **2026-09-14** — Étape 9 : monolog 4.1.0 ; `RedactQueryStringProcessor` ; smoke prod ; cache DTO au build.
- **2026-09-14** — Étape 9b : k6 `grafana/k6:1.3.0` ; quotas load-test 100 r/s ; `pm.max_children=8` conservé (~27–30 MiB/worker) ; contention réelle `BEGIN IMMEDIATE` ; README section charge ; correctif `chmod 777` du répertoire temporaire monté dans le conteneur k6 (uid 12345 non-root), bug invisible sur macOS. §1.2, §5.10, §7.5, §9.4, §10, §11.1–11.2.

## Journal des sessions

- **2026-09-14 (soir, 9b suite)** — Revue : rejeu indépendant nominal + quotas (résultats confirmés), `make ci` 0, actionlint 0 ; commit + push ; CI `load-test` en `workflow_dispatch` **échoue** sur le runner Linux (`k6` uid 12345, `mktemp -d` 0700 illisible) ; correctif `chmod 777`, repoussé, re-déclenché : vert (p95 6,03 ms). Étape 9b faite : `66c6291`, run `34864015335`. Prochaine : 10.
- **2026-09-14 (soir, 9b)** — Load-test local : nominal p50 8,45 / p95 11,51 / p99 15,52 ms, 0 % échec, exit 0 ; worst/ramp/contention/quotas OK ; `make ci` 0 ; actionlint 0.
- **2026-09-14 (soir)** — Étape 9 faite : `fe59cfd`, CI `34850124962`. Prochaine : 9b.
- **2026-09-14 (après-midi)** — Revue étape 9 : smoke durci, OPcache FPM, docs sync.
- **2026-09-14 (midi)** — Étape 9 locale : HealthController, Monolog, smoke.
