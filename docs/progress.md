# Suivi du projet

> Chargé à chaque session par `CLAUDE.md`. **Source unique de l'avancement.**
> Mis à jour quand une étape démarre, se termine ou bloque, et en fin de session (skill `feature-workflow`, étape 7).
> Taille cible : moins de 80 lignes. Le journal ne garde que les 5 dernières entrées.

## Où on en est

- **Phase** : implémentation terminée (plan §12).
- **Étape en cours** : aucune — étape 10 terminée.
- **Prochaine étape** : aucune (dernière étape du plan). Livraison / commit à la demande.
- **Bloquants** : aucun.
- **Dépôt** : GitHub privé `chlie22/leboncoin-test`, remote `origin` en HTTPS, branche `main` ; étape 9b poussée (`66c6291`) ; étape 10 non commitée.
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
| 10 | README et runbook | ✅ fait | revue : `sqlite3` absent de l'image → sauvegarde corrigée en `VACUUM INTO` (§11.3, README, §6.8), testée en direct ; README relu |

Statuts : ⏳ à faire · 🚧 en cours · ✅ fait · ⛔ bloqué. Une étape n'est ✅ qu'avec une **preuve** (commande et code de sortie, ou hash du commit).

## Prochaine action

Plan §12 terminé. Commit / push de l'étape 10 à la demande du développeur.

- Sauvegarde SQLite : pas de CLI `sqlite3` dans l'image (minimalisme, §7.9) → `VACUUM INTO` via PDO depuis le conteneur PHP, sans rien ajouter à l'image ; vérifié en direct sur la base de prod (compte de lignes identique).
- Sorties : `rtk proxy` ; codes : `$?` sans pipe. Push HTTPS.

## Décisions et écarts pris pendant l'implémentation

- **2026-09-12** — `AGENTS.md` adapté ; `"php": "^8.5"` ; `src/Controller/` supprimé.
- **2026-09-13** — Pas de `.env.dev.local` versionné ; dépôt privé (D4) ; `make` sur l'hôte + `EXEC` ; Deptrac `Shared` / `PsrLog` / `--fail-on-uncovered` ; Docker prod+override, `read_only` ; Q15 élargie 403/413 ; CI SHA + Composer 2.10.3.
- **2026-09-13–14** — Étapes 5–8 : messages entiers ; DBAL seul ; validator/serializer ; monolog → étape 9.
- **2026-09-14** — Étape 9 : monolog 4.1.0 ; `RedactQueryStringProcessor` ; smoke prod ; cache DTO au build.
- **2026-09-14** — Étape 9b : k6 ; load-test ; `chmod 777` dir k6. Étape 10 : README + runbook ; sauvegarde SQLite sans `sqlite3` (`VACUUM INTO` via PDO, image inchangée) plutôt que rouvrir la minimalisation du §7.9.

## Journal des sessions

- **2026-09-14 (soir, 10 suite)** — Revue : `sqlite3` absente de l'image (constaté par l'agent) → `VACUUM INTO` via PDO retenu plutôt qu'ajouter la CLI (surface d'attaque, §7.9) ou garder l'écart flou ; corrigé et testé en direct dans README + conception.md (§11.3, §6.8).
- **2026-09-14 (soir, 10)** — README complet (§11.3) + section charge 9b conservée ; `docs/progress.md` à jour. Preuve : relecture. Pas de commit.
- **2026-09-14 (soir, 9b suite)** — Correctif `chmod 777` k6 ; étape 9b faite : `66c6291`, run `34864015335`.
- **2026-09-14 (soir, 9b)** — Load-test local nominal OK ; `make ci` 0 ; actionlint 0.
- **2026-09-14 (soir)** — Étape 9 faite : `fe59cfd`, CI `34850124962`.
