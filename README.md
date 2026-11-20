# Meldungszentrale (IP-Symcon-Modul)

Sammelt Meldungen zentral an einer Stelle und liefert sie fertig für Visualisierungen (IPSView-Laufschrift, Kachel, WebFront).

## Stufen

| Wert | Stufe | Farbe |
|---|---|---|
| 0 | Keine Meldung | grau |
| 1 | Information | gelb `#F2C14E` |
| 2 | Hinweis | orange `#F08A24` |
| 3 | Warnung | rot `#EF5A4C` |

Das Profil `MZ.Stufe` wird vom Modul angelegt.

## Einstellungen

- **Quellen**: Liste von Variablen. Je Zeile: Variable, Text, Stufe, Bedingung (aktiv wenn Wert `=` oder `≠`), Vergleichswert.
  - Im Text wird `{wert}` durch den angezeigten Wert der Variable ersetzt (GetValueFormatted).
  - Boolean: Vergleichswert `true`/`false`. Zahlen: z. B. `0`. Text: genauer Vergleich, leer = leerer Text.
  - Mehrere Vergleichswerte mit `|` trennen: `|-` heißt „leer oder -“.
- **Klartexte für Codes**: Liste Code → Text, im Text per `{klartext}` (z. B. Buderus-Wartungscodes).
- **Trennzeichen** (Standard ` · `), **Text wenn keine Meldung** (Standard leer).
- **Liste (HTMLBox)** optional, **Stummschaltung bei neuer Meldung aufheben** optional.

## Variablen der Instanz

| Ident | Name | Typ | Profil |
|---|---|---|---|
| Laufschrift | Laufschrift | String | – |
| HoechsteStufe | Höchste Stufe | Integer | MZ.Stufe |
| Anzahl | Anzahl | Integer | – |
| Stumm | Stumm | Boolean, mit Aktion | ~Switch |
| Liste | Liste (optional) | String | ~HTMLBox |

Sortierung: Warnung vor Hinweis vor Information, innerhalb einer Stufe in Listenreihenfolge.
Neu berechnet wird bei jeder Wertänderung einer Quelle (VM_UPDATE) und bei jedem Aufruf der Funktionen.

## Funktionen

```php
MZ_Hinzufuegen(int $InstanzID, string $ID, string $Text, int $Stufe); // Stufe 1..3, gleiche ID = ersetzen
MZ_Entfernen(int $InstanzID, string $ID);                            // true, wenn es die Meldung gab
MZ_HoleMeldungen(int $InstanzID);                                    // Array aller aktiven Meldungen
MZ_Aktualisieren(int $InstanzID);
```

Beispiel: `MZ_Hinzufuegen(12345, 'waschmaschine', 'Waschmaschine fertig', 1);` und später `MZ_Entfernen(12345, 'waschmaschine');`

## Installation

Über die Modulverwaltung (Kern-Instanz „Modules“) mit der Git-URL des Repositorys hinzufügen, danach eine Instanz „Meldungszentrale“ anlegen.
