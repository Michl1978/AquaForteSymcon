# AquaForte DM Vario WiFi für IP-Symcon

Lokale Anbindung der AquaForte DM Vario WiFi über das Gizwits-LAN-Protokoll
(TCP 12416). Keine Cloud, kein MQTT-Broker. Als Parent dient ein Client Socket,
der vom Modul automatisch angelegt und konfiguriert wird.

Protokoll und Datenpunkt-Layout stammen aus den Projekten
`brix29/ha-aquaforte-dm-vario-local` und `geelenbert/aquaforte-mqtt`.

> Status: Beta, ungetestet an echter Hardware. Auf eigene Verantwortung nutzen.

## Installation

1. Den Ordner `AquaForteSymcon` (mit `library.json` und dem Unterordner `AquaForteDMVario`)
   in das Modulverzeichnis von IP-Symcon kopieren (Linux: `/var/lib/symcon/modules/`),
   den IP-Symcon-Dienst neu starten.
   Alternativ das Repository in der Modulverwaltung per Git-URL hinzufügen.
2. Instanz anlegen: *Gerät hinzufügen* -> **AquaForte DM Vario**.
3. IP-Adresse der Pumpe eintragen (am besten im Router eine feste IP vergeben) und übernehmen.

## Variablen

| Variable | Typ | Steuerbar |
|----------|-----|-----------|
| Pumpe | Boolean | ja |
| Fütterungsmodus | Boolean | ja |
| Betriebsmodus (Abschalten / Automatik / Fütterung) | Integer | ja |
| Drehzahl (30-100 %) | Integer | ja |
| Fütterungsdauer (1-60 s) | Integer | ja |
| 7 Störungsmeldungen (Überstrom, Überspannung, Übertemperatur, Unterspannung, Rotor blockiert, Leerlauf, UART) | Boolean | nein |

Fehlerbehebung: In der Instanz die Debug-Ausgabe öffnen (TX/RX-Pakete als Hex).

## Funktionen

- `AQFDM_ReadStatus(int $InstanceID)` Status sofort lesen
- `AQFDM_Reconnect(int $InstanceID)` Verbindung neu aufbauen
