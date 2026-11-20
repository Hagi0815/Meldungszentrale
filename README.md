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
  - `{var:ID}` = angezeigter Wert einer anderen Variable (z. B. Klartext aus einem Auswerte-Skript). „Unbekannt…“, „Kein gültiger…“ oder „-“ werden samt Strich weggelassen.
  - `{dwd:ID}` = einzelne Warnungen aus der HTML-Tabelle des Moduls „Weather Warning“ (Variable „Warnmeldungen“), z. B. `Frost, Glätte, Markante Glätte bis Sa 09:00`.
  - `{code}` = nur der Code vom Wertanfang, z. B. `H08(1018) 13.11.2026 12:21 - now` → `H08 (1018)`.
  - Boolean: Vergleichswert `true`/`false`. Zahlen: z. B. `0`. Text: genauer Vergleich, leer = leerer Text.
  - Bedingungen: `=`, `≠`, `enthält`, `enthält nicht`, `passt (nicht) zu Muster` (regulärer Ausdruck, z. B. `/^\s*H\d+.*now/i`) (Text, ohne Groß/Klein). Beispiel EMS-ESP-„lastcode“: aktiv, wenn er `now` enthält (Störung steht noch an).
  - Mehrere Vergleichswerte mit `|` trennen: `|-` heißt „leer oder -“.
- **Klartexte für Codes**: Liste Code → Text, im Text per `{klartext}`. Code wird vom Wertanfang gelesen (`H08(1018) …` → `H08/1018`, sonst `H08`). Unbekannter Code → Klartext entfällt.
- **Detailfelder je Quelle (optional)**: `Gruppe` (z. B. `Heizung`), `Titel` (Chip/Überschrift, z. B. `Wartung Kessel {code}`), `Meldung`, `Ursache`, `Abhilfe` (z. B. `{var:59039}`). Platzhalter werden hier ungefiltert ersetzt; leer oder `-` wird als `–` angezeigt.
- **Gruppe für Detail-HTML** (Standard `Heizung`, leer = aus): legt `<Gruppe> Chips HTML` und `<Gruppe> Meldungen HTML` an.
- **Trennzeichen** (Standard ` · `), **Text wenn keine Meldung** (Standard leer).
- **Liste (HTMLBox)** optional, **Stummschaltung bei neuer Meldung aufheben** optional.

## Variablen der Instanz

| Ident | Name | Typ | Profil |
|---|---|---|---|
| Laufschrift | Laufschrift | String | – |
| LaufschriftHTML | Laufschrift HTML | String | ~HTMLBox (jede Meldung in ihrer Stufenfarbe, läuft per CSS) |
| HoechsteStufe | Höchste Stufe | Integer | MZ.Stufe |
| Anzahl | Anzahl | Integer | – |
| Stumm | Stumm | Boolean, mit Aktion | ~Switch |
| Liste | Liste (optional) | String | ~HTMLBox |
| GruppeChipsHTML | <Gruppe> Chips HTML | String | ~HTMLBox (je aktive Meldung der Gruppe ein Chip in Stufenfarbe, eine Zeile, Schrift folgt der Boxhöhe) |
| GruppeMeldungenHTML | <Gruppe> Meldungen HTML | String | ~HTMLBox (je Meldung ein Block mit farbigem Rand: Titel, Meldung, Ursache/Abhilfe als Laufschrift; sonst „Keine Meldungen“) |

Sortierung: Warnung vor Hinweis vor Information, innerhalb einer Stufe in Listenreihenfolge.
Neu berechnet wird bei jeder Wertänderung einer Quelle (VM_UPDATE) und bei jedem Aufruf der Funktionen.

## Funktionen

```php
MZ_Hinzufuegen(int $InstanzID, string $ID, string $Text, int $Stufe); // Stufe 1..3, gleiche ID = ersetzen
MZ_Entfernen(int $InstanzID, string $ID);                            // true, wenn es die Meldung gab
MZ_HoleMeldungen(int $InstanzID);                                    // JSON-Text aller aktiven Meldungen (json_decode)
MZ_Aktualisieren(int $InstanzID);
```

Beispiel: `MZ_Hinzufuegen(12345, 'waschmaschine', 'Waschmaschine fertig', 1);` und später `MZ_Entfernen(12345, 'waschmaschine');`

## Installation

Über die Modulverwaltung (Kern-Instanz „Modules“) mit der Git-URL des Repositorys hinzufügen, danach eine Instanz „Meldungszentrale“ anlegen.
