=== ARPEGE Global 0,25° — Tableaux et cartes ===
Contributors: alertesmeteo-hub
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Cartes interactives et prévisions communales ARPEGE Global 0,25° de Météo-France.

== Installation ==

1. Téléversez le ZIP dans Extensions > Ajouter une extension.
2. Activez ARPEGE Global 0,25°.
3. Insérez le shortcode : [arpege_025_meteo]

Exemple Paris :
[arpege_025_meteo code="75056" departement="75" ville="Paris" heures="102"]

Exemple sans sélecteur :
[arpege_025_meteo code="66136" departement="66" ville="Perpignan" heures="102" selecteur="non"]

== Données ==

Les données sont chargées depuis la branche data du dépôt :
https://github.com/alertesmeteo-hub/ARPEGE-0.25

Couverture : 34 746 communes, 96 départements de France métropolitaine et Corse.
Grille native : GLOB025, 1 440 × 721 points, résolution 0,25° (environ 25 km).
Échéances : 67 pas, horaires jusqu’à +48 h puis toutes les 3 h jusqu’à +102 h.
Runs : 00, 06, 12 et 18 UTC.

Source : Météo-France via data.gouv.fr, Licence Ouverte 2.0.
Les diagnostics ne constituent pas une vigilance officielle.

== Version 1.0.0 ==

Première version autonome du module ARPEGE Global 0,25°.
Namespace, réglages, assets et shortcode distincts du module ARPEGE Europe 0,1°.
La version du module est affichée directement en bas de la page météo.
