> **Historique : ne pas exécuter.** Cette review a été intégrée le 2026-09-12 dans `docs/conception.md` et `docs/openapi.yaml`. Les consignes ci-dessous ne sont plus à appliquer.

# Corrections de la conception et du contrat OpenAPI

Date : 12 septembre 2026.
Statut : consignes de correction issues de la review, à transmettre à un agent.

## Mission et périmètre

Corriger les incohérences et garanties excessives relevées dans les deux documents suivants, puis vérifier leur cohérence mutuelle :

- [conception.md](/Users/charliebelier/Documents/dev/projects/leboncoin-test/docs/conception.md)
- [openapi.yaml](/Users/charliebelier/Documents/dev/projects/leboncoin-test/docs/openapi.yaml)

**Travail documentaire uniquement. Ne pas implémenter l’application, créer de contrôleurs, migrations, configuration de déploiement ou tests applicatifs exécutables.** Les esquisses déjà présentes dans la conception peuvent être corrigées. Décrire les futurs tests nécessaires ; ne pas présenter ces tests comme déjà exécutés.

Ne pas modifier l’architecture générale ni rouvrir les arbitrages sans nécessité : Symfony moderne, organisation DDD/hexagonale, DBAL, SQLite, fenêtre glissante, limitation avant PHP, statistiques publiques et génération en mode dégradé sont le cadre de cette correction. Les trois derniers choix ne sont pas des bugs en eux-mêmes.

Pour chaque correction, mettre à jour les passages dépendants : résumé des garanties, décisions, exemples, esquisses, contrat du port, OpenAPI, matrice de tests et plan d’implémentation. Éviter une correction locale laissant une affirmation contradictoire ailleurs.

Les lignes ci-dessous concernent la version relue ; rechercher aussi les expressions citées si les fichiers ont évolué. Ne pas écraser de changements intervenus depuis la review.

### Version de référence

Empreintes SHA-256 vérifiées lors de la préparation de ce document :

- `conception.md` : `3c15cd62c313adb1c5035b007677c1e398083c0fb607222183b25d1882ef6df6`
- `openapi.yaml` : `b61d5b8934ca0f1ec8ebf842c1e5cfcb49c9f21f1122370cb3e3f2563056af0a`

P1 désigne une correction prioritaire de comportement ou de garantie de disponibilité ; P2 une correction nécessaire de performance, exploitation ou contrat. Aucun point n’est une simple préférence de style.

## R01 — P1 — Rejeter effectivement les chaînes vides

Références : `conception.md` lignes 457–459, 162 et 474 ; `openapi.yaml` paramètres `Str1` et `Str2`, lignes 281–307.

### Problème

Le DTO proposé utilise `NotNull`, `Length(max: 50)` et `Regex`. Pour un type `?string`, le normalizer conserve la chaîne vide : `NotNull` l’accepte, aucune longueur minimale ne l’interdit, et `RegexValidator` ignore les chaînes vides. Une requête contenant `str1=` pourrait donc réussir alors que le contrat impose au moins un point de code.

L’affirmation selon laquelle toute chaîne vide est convertie en `null` pour un type nullable est trop générale : ne pas extrapoler le comportement des entiers aux chaînes.

### Correction attendue

- Décrire une contrainte explicite de non-vacuité des chaînes, par exemple `NotBlank`, avec le message prévu par le contrat.
- Conserver l’acceptation de la chaîne `"0"`, des espaces autorisés et des chaînes valides de 1 à 50 points de code.
- Corriger le tableau des comportements natifs et l’esquisse du DTO, sans remplacer inutilement `MapQueryString` par un resolver maison.

### Critère de vérification

La spécification prévoit des tests distincts pour paramètre absent, chaîne vide, `"0"`, espace et chaîne normale. Absence et vide produisent le 400 documenté ; `"0"` reste valide.

Sources consultées : [AbstractObjectNormalizer Symfony 8.1.6](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Component/Serializer/Normalizer/AbstractObjectNormalizer.php), [RegexValidator Symfony 8.1.6](https://github.com/symfony/symfony/blob/v8.1.6/src/Symfony/Component/Validator/Constraints/RegexValidator.php).

## R02 — P2 — Rejeter les caractères de contrôle jusqu’à la fin réelle de la chaîne

Références : `conception.md` lignes 208 et 458 ; `openapi.yaml` lignes 293 et 306.

### Problème

La regex PHP `/^\P{Cc}+$/u` accepte `"fizz\n"`. Le résultat a été reproduit avec PHP 8.5.2 : `preg_match` retourne 1. En PCRE, `$` peut correspondre avant le dernier saut de ligne.

### Correction attendue

- Utiliser une fin de chaîne stricte côté PHP, par exemple une ancre `\z`, tout en conservant le contrôle UTF-8.
- Vérifier séparément l’expression du contrat OpenAPI : les expressions JSON Schema suivent la syntaxe ECMA-262. Ne pas recopier une ancre PCRE incompatible.
- Garder la même règle fonctionnelle dans les deux documents, même si les expressions techniques diffèrent.

### Critère de vérification

Prévoir les cas saut de ligne final, saut de ligne interne, retour chariot, tabulation et NUL : ils doivent être refusés. Les chaînes Unicode autorisées restent acceptées. Les exemples valides et invalides doivent être confrontés aux deux mécanismes de validation.

Sources : [ancres PCRE dans PHP](https://www.php.net/manual/en/regexp.reference.anchors.php), [expressions régulières JSON Schema](https://json-schema.org/understanding-json-schema/reference/regular_expressions).

## R03 — P2 — Supprimer le parcours complet du journal à la lecture

Références : `conception.md` lignes 614–623 et conclusions de performance du §6.1.

### Problème

La sous-requête `coalesce(max(id) - min(id) + 1, 0)` parcourt tout le journal lorsqu’elle combine ces deux agrégats. Le gagnant utilise son index, mais le calcul de `window_count` réintroduit un coût linéaire.

Contrôle isolé réalisé avec SQLite 3.51 : la forme actuelle donne `SCAN journal` ; des recherches séparées de `MAX` et `MIN` donnent chacune `SEARCH journal`.

### Correction attendue

- Séparer les accès à `MIN` et `MAX` en sous-requêtes scalaires indexables.
- Les conserver dans une même instruction de lecture afin que le gagnant et les métadonnées proviennent du même état.
- Conserver le traitement de la fenêtre vide et expliciter la dépendance à la contiguïté des identifiants du journal.
- Ne plus attribuer la performance du seul classement à la réponse statistique complète.

### Critère de vérification

Prévoir une vérification du plan de la requête complète et une mesure sur fenêtre pleine. Le calcul de taille ne doit pas parcourir toutes les occurrences. Les benchmarks existants doivent être requalifiés si leur requête exacte n’est pas disponible.

Source : [optimisation MIN/MAX SQLite](https://www.sqlite.org/optoverview.html#the_min_max_optimization).

## R04 — P1 — Ne pas présenter `busy_timeout` comme un délai maximal d’enregistrement

Références : `conception.md` lignes 50–51, 513–517, 634 et justification du circuit breaker au §15.5.

### Problème

Les 200 ms concernent l’attente sur verrou SQLite. Ce réglage ne fixe pas une durée maximale pour toute l’opération : accès disque, synchronisation durable, checkpoint ou lenteur du système peuvent prendre plus longtemps.

La génération utilise toujours les mêmes processus et ressources système que l’écriture des statistiques. L’affirmation d’indépendance absolue vis-à-vis du stockage et le calcul de seulement deux processus occupés pendant toute panne sont donc trop forts.

### Correction attendue

- Annoncer une « attente de verrou configurée à 200 ms », pas un « surcoût maximal de panne de 200 ms ».
- Distinguer contention, erreur d’accès rapide et lenteur d’entrée-sortie.
- Décrire le rôle des limites FPM et du timeout de traitement, sans prétendre qu’ils garantissent une réponse 200 dégradée dans tous les cas.
- Conserver le choix de ne pas ajouter de circuit breaker si souhaité ; corriger sa justification plutôt qu’introduire automatiquement ce mécanisme.

### Critère de vérification

Les garanties et scénarios de tests distinguent délai de verrou et durée totale. Les chiffres de concurrence sont présentés comme hypothèses conditionnelles, non comme une preuve couvrant toute panne.

Source : [SQLite busy timeout](https://www.sqlite.org/c3ref/busy_timeout.html).

## R05 — P2 — Définir le comportement au démarrage lorsque SQLite est indisponible

Références : `conception.md` lignes 50, 639 et 851.

### Problème

L’entrypoint impose les migrations avant de lancer PHP-FPM. Si elles échouent parce que SQLite est inaccessible, la génération ne démarre pas non plus, contrairement à l’indépendance annoncée.

### Correction attendue

Documenter séparément :

- Le fonctionnement dégradé après un démarrage réussi.
- Le comportement lors d’un démarrage ou redéploiement avec stockage inaccessible.

La correction minimale consiste à accepter et expliciter cette dépendance initiale. Si un démarrage dégradé est réellement souhaité, le traiter comme un arbitrage supplémentaire à confirmer, pas comme une permission d’ignorer toutes les erreurs de migration.

### Critère de vérification

Le runbook et la future matrice de tests incluent le redémarrage avec SQLite inaccessible. Le comportement annoncé correspond au séquencement de démarrage retenu.

## R06 — P2 — Distinguer volume mesuré, borne logique et budget disque

Références : `conception.md` lignes 49, 536–548 et 638.

### Problème

« ~11 Mo quel que soit le trafic » ne découle pas d’une fenêtre de 100 000 appels. Deux chaînes de 50 emojis représentent 400 octets UTF-8 par combinaison. Pour 100 000 combinaisons distinctes, cela fait déjà 40 000 000 octets de chaînes dans la table, avant l’index unique qui reprend les clés, le journal et les autres coûts.

Les pages libres peuvent être réutilisées, mais le checkpoint automatique ne garantit pas à lui seul un WAL toujours borné : des lecteurs prolongés peuvent empêcher sa réutilisation.

### Correction attendue

- Présenter les 11 Mo comme une mesure associée à un jeu de données précis, si ce dernier est disponible.
- Maintenir la garantie logique : au plus N occurrences et N combinaisons après une transaction normale à configuration constante.
- Décrire un budget physique comprenant tables, index, pages libres, WAL et marge d’exploitation.
- Prévoir la surveillance du volume et du WAL ; retirer l’affirmation absolue selon laquelle aucune maintenance ne sera nécessaire.

### Critère de vérification

Le scénario de qualification inclut des combinaisons toutes distinctes avec chaînes maximales, une fenêtre pleine renouvelée plusieurs fois et des lectures concurrentes. Aucune taille physique universelle n’est déduite du seul nombre de lignes.

Source : [gestion et croissance du WAL SQLite](https://www.sqlite.org/wal.html).

## R07 — P2 — Corriger le traitement Nginx des URI trop longues

Références : `conception.md` lignes 734 et 758–759 ; `openapi.yaml` réponse `UriTooLong`, ligne 532.

### Problème

Une ligne de requête trop longue peut être rejetée avant l’initialisation de l’URI Nginx. Une redirection vers une location nommée, telle que `@uri_too_long`, échoue alors avec une URI vide et peut transformer le 414 attendu en 500.

Ce point a été vérifié dans le code source Nginx, pas par un smoke test de la future stack.

### Correction attendue

- Décrire une destination interne d’erreur adaptée à ce rejet précoce, sans passage par PHP.
- Préserver le statut 414, le format problem+json et les en-têtes annoncés.
- Vérifier ce comportement sur la version Nginx effectivement retenue lors de l’implémentation.

### Critère de vérification

Prévoir une vraie requête HTTP dépassant le buffer de ligne de requête, et non un simple test applicatif qui renvoie artificiellement 414. Le résultat attendu est 414 JSON, pas 500 ou HTML.

Sources : [traitement des requêtes Nginx](https://github.com/nginx/nginx/blob/master/src/http/ngx_http_request.c), [redirection vers une location nommée](https://github.com/nginx/nginx/blob/master/src/http/ngx_http_core_module.c).

## R08 — P2 — Étendre la politique de confidentialité aux logs d’erreur Nginx

Références : `conception.md` lignes 714–717, 881 et §8.1.

### Problème

Le format utilisant `$uri` protège le log d’accès, pas le log d’erreur. Un rejet de quota est journalisé par défaut au niveau `error`. Le contexte HTTP peut y ajouter la ligne de requête complète, avec les paramètres.

La promesse « paramètres absents de tous les logs » n’est donc pas satisfaite par l’esquisse actuelle.

### Correction attendue

- Décrire une politique explicite pour le log d’erreur et le contexte sensible, en plus du log d’accès.
- Étudier suppression ciblée ou masquage, et documenter le compromis de diagnostic. Ne pas désactiver aveuglément toute visibilité sur les incidents.
- Si une garantie ne peut pas être tenue dans ce périmètre, la reformuler explicitement au lieu de conserver une promesse absolue.

### Critère de vérification

Prévoir des marqueurs de test non sensibles dans `str1` et `str2`, puis examiner les deux flux de logs après succès, rejet 429 et incident FastCGI. Vérifier l’absence des marqueurs selon la politique annoncée.

Source : [contexte d’erreur HTTP Nginx](https://github.com/nginx/nginx/blob/master/src/http/ngx_http_request.c).

## R09 — P2 — Réconcilier une réduction de N avant la reprise du trafic

Références : `conception.md` lignes 588 et 641–645 ; `openapi.yaml` modèle `StatisticsWindow`.

### Problème

Après un passage de N = 100 000 à N = 10 000, le nettoyage n’a lieu qu’au prochain appel de génération. Une lecture préalable peut annoncer `size: 10000`, `count: 100000` et un gagnant de l’ancienne fenêtre. Le premier client supporte ensuite la purge complète.

### Correction attendue

- Décrire une réduction contrôlée avant réouverture du trafic : éviction du surplus et ajustement cohérent des compteurs.
- Maintenir une valeur identique de N pour tous les processus.
- Conserver la règle d’augmentation : aucun historique supprimé n’est reconstitué.
- Mettre à jour le runbook et retirer la purge massive du chemin normal de la première génération après redéploiement.

### Critère de vérification

Prévoir une lecture immédiatement après réduction, sans génération intermédiaire : `count ≤ size` et gagnant conforme à la nouvelle fenêtre.

## R10 — P2 — Compléter les opérations et erreurs du contrat OpenAPI

Références : `openapi.yaml` lignes 110–147, 149 et 208 ; `conception.md` §4.4.

### Problème

- Le 413 prévu par Nginx n’est pas décrit dans les réponses concernées.
- HEAD est admis par les règles communes, mais les opérations HEAD de `/v1/stats` et `/healthz` ne sont pas déclarées.
- Les réponses HEAD ne couvrent pas toutes les erreurs possibles de leur parcours, notamment celles produites par le proxy.

### Correction attendue

- Ajouter ou référencer les réponses manquantes sur les opérations concernées.
- Décrire les opérations HEAD conformément au comportement retenu, avec les restrictions réseau et garanties applicables.
- Ne pas attribuer de corps aux réponses HEAD, y compris d’erreur.
- Vérifier la cohérence de la matrice HTTP globale, des opérations et des réponses réutilisables.

### Critère de vérification

Un lecteur du contrat peut déterminer les méthodes admises et les erreurs prévues sans reconstituer les règles depuis la conception. Les futurs tests HTTP couvrent cette matrice.

## R11 — P2 — Aligner la promesse `Cache-Control: no-store`

Références : `openapi.yaml` ligne 18 ; `conception.md` lignes 775–780.

### Problème

OpenAPI annonce `no-store` pour toutes les réponses. L’esquisse des en-têtes Nginx ne le pose pas sur les erreurs produites par le proxy.

### Correction attendue

- Définir de façon cohérente où cet en-tête est produit pour les succès, erreurs Symfony, erreurs Nginx et HEAD.
- Tenir compte de l’héritage des en-têtes dans les locations et éviter des valeurs contradictoires ou doublonnées entre PHP et Nginx.
- Aligner la description globale et les en-têtes des réponses OpenAPI.

### Critère de vérification

Prévoir une vérification de `no-store` sur un succès, un 400 applicatif et les erreurs proxy représentatives, ainsi que sur HEAD.

## R12 — P2 — Ne pas déduire l’absence de comptage d’un 502 ou 504

Référence : `conception.md` ligne 303 ; descriptions du comptage et des reprises dans les deux documents.

### Problème

La transaction peut être confirmée avant que PHP ou la communication FastCGI échoue. Le client reçoit alors un 502/504 alors que l’appel est déjà compté.

### Correction attendue

- Remplacer « non comptabilisé » pour les 502/504 par une formulation dépendant de la confirmation de la transaction.
- Distinguer le cas d’indisponibilité avant traitement du cas d’échec après commit.
- Préciser que le client ne peut pas déterminer le comptage à partir de ce seul statut ; une répétition n’est pas dédupliquée.

### Critère de vérification

La documentation et les futurs tests distinguent les échecs avant et après confirmation. Aucune garantie de réception ou d’exactement-une-fois n’est introduite implicitement.

## Correction transversale — Qualifier les preuves et les chiffres

Références : `conception.md` ligne 6, §1.2, §3.3, §6.1 et §9.4.

Le dépôt relu contient les documents et la configuration Redocly, mais pas les scripts ni les résultats permettant de reproduire les benchmarks annoncés. Cela ne prouve pas qu’aucune mesure n’a été réalisée ; cela empêche de la vérifier depuis les livrables présents.

Séparer explicitement :

- **Comportement vérifié dans une source** : version et lien de référence.
- **Mesure locale rapportée** : périmètre, données, machine, méthode et résultats disponibles.
- **Objectif de performance** : seuil à atteindre lors d’un futur test.
- **Garantie de production** : propriété démontrée dans un environnement et sous des hypothèses définis.

Ne pas présenter un test k6 futur comme une preuve déjà livrée. Ne pas extrapoler une mesure du générateur ou de l’encodage à la mémoire totale d’une requête Symfony/PHP-FPM. Ne pas inventer de nouveaux résultats pour remplacer les chiffres remis en question.

## Livrable attendu de l’agent

1. Corriger `docs/conception.md` et `docs/openapi.yaml`, sans implémentation applicative.
2. Relire leurs références croisées, exemples, garanties et critères de tests.
3. Valider la syntaxe et les références OpenAPI si l’outillage disponible le permet ; signaler les vérifications non exécutées. Un lint réussi ne prouve pas le comportement futur du serveur.
4. Rendre un compte rendu associant R01 à R12 à leur correction ou à une question restante explicitement motivée.
5. Lister séparément les mesures ou décisions qui nécessitent encore une qualification. Ne pas annoncer une validation de production complète.

Ce document transmet une review ; il ne demande ni de créer un autre agent, ni de publier le dépôt, ni de modifier un environnement de production.
