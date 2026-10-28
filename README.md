# LoxBerry-Plugin Xiaomi Licht

Bindet Deckenleuchten von Xiaomi und Yeelight an Loxone an und meldet den **echten Zustand** zurück – auch wenn jemand
die Lampe über die Mi-Home- oder Yeelight-App, Alexa oder den Wandschalter verändert.

Version 0.9.1 · Lizenz MIT · LoxBerry ab 3.0.0 · PHP 7.4 und 8.x

---

## Neu in 0.9.1

Erste Fassung (Stufe A – das Plugin beobachtet; geschaltet wird erst mit zwei Haken, beide ab Werk aus).

* **Zustand je Lampe über das MQTT-Gateway V2**: `xiaomilicht/lampe<n>/ein|hell|ct|modus` (zurückbehalten),
  `erreichbar` und `weg` (wie das Plugin den Zustand erfährt), Lebenszeichen `xiaomilicht/status/zaehler` (alle 30 s)
  und `xiaomilicht/status/ok` – beide unter Gateway V2 mit „Noncached“ anhaken. -1 = keine Aussage; ist eine Lampe
  nicht erreichbar, gehen alle ihre Werte auf -1 (ein stromloser Ausgang ist kein „an, 80 %“).
* **Rückmeldung in Echtzeit über Yeelight-LAN** (TCP 55443, „LAN-Steuerung“ in der Yeelight-App einschalten): eine
  gehaltene Verbindung je Lampe, jede Änderung kommt sofort. Ohne LAN fragt das Plugin über **miIO** (UDP 54321, Token)
  im Takt ab (Werk 10 s).
* **Befehle über die Befehlsbrücke 1.0.1**: `xiaomilicht/befehl/lampe<n>/schalten|umschalten|hell|ct|nacht|szene|abruf`
  (oder `…/alle/…`), Quittung „angenommen“ erst nach dem Code der Lampe. Zwei Haken: „Befehle über MQTT annehmen“
  (Reiter MQTT) und „Lampen aus Loxone schalten“ (Reiter Einstellungen). Gleichwert-Bremse 60 s gegen das Echo eines
  Dimmers, dessen Set-Eingang die Rückmeldung bekommt. ceiling1 schaltet bevorzugt über die Yeelight-LAN-Verbindung
  (höchstens 50 Befehle je Minute, darüber miIO), sonst über miIO.
* **Token automatisch** aus der Xiaomi-Cloud (gemeinsames Modul `xiaomi_cloud.php` 1.0.1, Captcha-Bild und
  Zweitfaktor im Plugin; mit einer Anmeldung alle Lampen auf einmal: Kästchen, „Ausgewählte übernehmen“) oder von
  Hand; **Gerätesuche** nur auf Knopfdruck (Yeelight-Suche und miIO-Gruß).
* Reiter Test mit Selbstprüfung und je Lampe Gruß, Token, Steckbrief, Yeelight-LAN, Altbefehle und Fähigkeiten;
  `bin/healthcheck` für den LoxBerry-Healthcheck; HTTP-Lesezweig für eigene Projekte ohne MQTT (ab Werk aus).

## Modelle

| Modell | Bezeichnung | Werte (MIoT) | Yeelight-LAN |
|---|---|---|---|
| `yeelink.light.ceiling3` | Yeelight LED-Deckenleuchte | ein 2/1, Helligkeit 2/2, Modus Tag/Nacht 2/3, Farbtemperatur 2/4 (2700–6500 K) | ja, wenn in der App eingeschaltet |
| `yilai.light.ceiling1` | Yilai LED-Deckenleuchte 480 | ein, Helligkeit, Farbtemperatur (2700–6500 K) über die Yeelight-Befehle get_prop/set_power/set_bright/set_ct_abx – MIoT lehnt die Lampe ab (Firmware 1.3.2_0024) | ja (Port 55443 gemessen, Push) |
| andere | Grundform | ein 2/1, Helligkeit 2/2 – mit Warnung | – |

Die Belegung stammt aus der amtlichen MIoT-Spezifikation (home.miot-spec.com); das Modell liest der Dienst aus der
Lampe (miIO.info). Achtung: bei ceiling3 ist 2/3 der Modus, bei ceiling1 die Farbtemperatur – deshalb eine Tabelle je
Modell. Am Gerät gemessen (28.10.): ceiling1 lesen und schalten über miIO mit den Yeelight-Befehlen; Schalten über
Yeelight-LAN und ceiling3 noch nicht.

## Wichtig: Strom

Schaltet Loxone den Netzstrom der Lampe (Relais, Dimmer-Ausgang), ist die Lampe danach stromlos und kann nicht
antworten: das Plugin meldet „nicht erreichbar“. Befehle über das Plugin wirken nur, solange die Lampe Strom hat. Eine
WLAN-Leuchte mit eigenem Netzteil hinter einem Phasendimmer kann flackern oder neu starten.

## Datenschutz

Token der Lampen, Aktionstoken und Kennwort des Mi-Kontos stehen nie im Protokoll, nie auf der Seite und nie auf einer
Befehlszeile. Das Kennwort wird nie gespeichert; der Cloud-Abruf schickt nur an Adressen von Xiaomi (`*.xiaomi.com`,
`*.mi.com`). Die Token liegen in der Konfiguration (Rechte 0600) und in der Sicherung – die Sicherung wie ein Passwort
behandeln.

## Quellen

Nur gelesen, kein fremder Quelltext übernommen: MIoT-Spezifikation (home.miot-spec.com / miot-spec.org), Yeelight WiFi
Light Inter-Operation Specification (Yeelight), python-miio (GPL-3.0, nur Methodennamen als Beleg). Der miIO-Transport
ist derselbe eigenständig geschriebene wie im Plugin Saugroboter; der Cloud-Ablauf folgt dem
Xiaomi-cloud-tokens-extractor (MIT, nur die Schritte der öffentlichen Schnittstelle).

## Installation

Bis zum ersten Release von Hand: Archiv in LoxBerry unter „Plugins installieren“ hochladen. Das Repository
`timanders22/LoxBerry-Plugin-Xiaomi-Licht` entsteht nach dem Gerätetest; bis dahin findet das Auto-Update nichts.
