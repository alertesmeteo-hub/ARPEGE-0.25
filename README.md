# ARPEGE Global 0,25° — Météo-France

Chaîne automatique **Météo-France → GitHub Actions → branche `data`** pour les prévisions communales et les cartes ARPEGE 0,25° sur la France métropolitaine et la Corse.

## Données vérifiées

| Élément | Valeur |
|---|---:|
| Modèle | ARPEGE |
| Grille | GLOB025, 0,25° |
| Domaine natif | Globe entier |
| Emprise des cartes | 38–53°N, 8°W–12°E |
| Taille native | 1 440 × 721 points |
| Runs | 00, 06, 12 et 18 UTC |
| Échéances | +0 à +102 h |
| Pas temporel | 1 h jusqu’à +48 h, puis 3 h jusqu’à +102 h |
| Communes | 34 746 |
| Départements | 96 |
| Source | data.gouv.fr / Météo-France |
| Licence | Licence Ouverte 2.0 |

La version 1.0.0 utilise les paquets de surface `SP1` et `SP2`. Les quatre tranches officielles sont `000H024H`, `025H048H`, `049H072H` et `073H102H`. Elles sont téléchargées et supprimées progressivement pour limiter l’espace disque du runner.

## Mise à jour automatique

Le workflow **Mise à jour ARPEGE 0.25** s’exécute toutes les trois heures. Il choisit uniquement un run complet et cohérent, sans mélanger les quatre réseaux, puis publie le résultat sur la branche `data`.

Lancement manuel : **Actions → Mise à jour ARPEGE 0.25 → Run workflow**.

## Sorties publiées

- `index.json` : métadonnées, run, couverture et schéma ;
- `departements/{code}.json` : prévisions communales compactes ;
- `maps/index.json` : catalogue des cartes ;
- `maps/communes.json` : index géographique ;
- images WebP et sondes numériques associées.

URL de base :

```text
https://raw.githubusercontent.com/alertesmeteo-hub/ARPEGE-0.25/data
```

## Exécution locale

```bash
python -m pip install -r requirements.txt
python scripts/update_arpege_france.py \
  --catalog config/communes-france.json \
  --output-dir build/national \
  --forecast-hours 102 \
  --source legacy
```

## Source officielle

- [Jeu de données ARPEGE 0,25°](https://www.data.gouv.fr/datasets/paquets-arpege-resolution-0-25deg)
- [Descriptif technique Météo-France](https://donneespubliques.meteofrance.fr/client/document/descriptiontechnique_paquetsarpege_donneespubliques_v3_20240625_376.pdf)

Les résultats sont des données de modèle numérique et ne constituent pas une vigilance officielle.
