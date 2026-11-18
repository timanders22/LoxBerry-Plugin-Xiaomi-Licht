# LoxBerry-Plugin Xiaomi Licht

Bindet Deckenleuchten von Xiaomi und Yeelight an Loxone an und meldet den **echten Zustand** zurück – auch wenn jemand
die Lampe über die Mi-Home- oder Yeelight-App, Alexa oder den Wandschalter verändert.

Version 0.9.11 · Lizenz MIT · LoxBerry ab 3.0.0 · PHP 7.4 und 8.x

---

## Neu in 0.9.11

- **Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.11 statt 1.2.9:** Neu in 1.2.10 ist, dass ein leerer Wert auch in einem Teil der Sicherung, der nur aus einem einzelnen Wert besteht (etwa einem Zugangsschlüssel in einer eigenen Datei), einen gesetzten Wert nicht mehr überschreibt; er bleibt stehen und wird in der Meldung genannt. Dieses Plugin führt keinen solchen Teil; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4 (3 Vorfassungen zurückgespielt). Am Gerät nicht gemessen.
- **Sicherungsmodul 1.2.11:** Wandelt ein Plugin beim Zurückspielen eine Sicherung aus einer Textdatei um, sagt die Meldung das jetzt mit einem gemeinsamen Satz, und Mängel darin werden in allen Plugins mit denselben Bezeichnungen genannt. Dieses Plugin wandelt keine Textdateien um; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4. Am Gerät nicht gemessen.

## Neu in 0.9.10

- **Verhalten geändert – Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.9 statt 1.2.3:**
  - Einträge der geltenden Einstellungen, die diese Fassung nicht kennt (etwa aus einer Bearbeitung von Hand), fallen beim Zurückspielen weiter weg, werden jetzt aber mit ihrem Namen genannt, nie mit ihrem Wert. Bis 0.9.9 fielen sie still weg.
  - Jede unbekannte Einstellung wird in der Meldung genau einmal genannt.
  - Die übrigen Neuerungen der Modulfassungen bis 1.2.9 betreffen Fähigkeiten, die dieses Plugin nicht nutzt; an ihm ändert sich dadurch nichts.
- **Knopf „Dokumentation“:** `plugin.cfg` nennt jetzt `WEBSITE=` mit diesem Repository; LoxBerry übernimmt die Adresse bei der Installation und zeigt sie in der Plugin-Verwaltung als Knopf.
- Sicherungen der früheren Fassungen werden weiter angenommen wie bisher; gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt und 3 Vorfassungen zurückspielt. Am Gerät nicht gemessen.

## Neu in 0.9.9

Am Gerät nicht gemessen: Diese Fassung wurde vor der Veröffentlichung nicht auf einem LoxBerry installiert. Gemessen wurde mit einem Prüfstand unter PHP 7.4 und 8.4 auf dem Arbeitsrechner, der über einen Webserver die echten Knöpfe drückt.

- **Verhalten geändert – Einstellungen sichern und zurückspielen über das gemeinsame Sicherungsmodul 1.2.3:** Die Knöpfe „Sicherung herunterladen“ und „Sicherung einspielen“ arbeiten jetzt mit demselben Sicherungsmodul wie die übrigen Plugins dieser Reihe, die schon umgestellt sind.
  - Die Sicherungsdatei trägt im Kopf das Kennzeichen des Plugins (`_plugin` = `LoxBerry-Plugin-Xiaomi-Licht`) und die Fassung (`_fassung`). Bis 0.9.8 stand dort `Xiaomi Licht (<Ordner>)`; Sicherungen von 0.9.1 bis 0.9.8 werden daran weiter als eigene erkannt und angenommen.
  - Eine Datei eines anderen Plugins und eine Datei ohne Kennzeichen werden als Ganzes abgewiesen. Bisher wurde jede Datei gelesen, deren Schlüssel passten.
  - Eine Einstellung, die diese Fassung nicht kennt, wird nicht übernommen und mit ihrem Namen gemeldet, nie mit ihrem Wert – oben in der Datei, in den Blöcken der Sprachansagen und in den Zeilen der Lampenliste. Die übrigen Einstellungen werden zurückgespielt. Bisher wies ein unbekannter Name die ganze Datei ab.
  - Ein leerer Wert in der Sicherung überschreibt keinen bestehenden; der bisherige Wert bleibt, und die Meldung nennt den Namen. Bisher wurde die Datei dann abgewiesen.
  - Fehlt einer Sicherung eine Einstellung, die ihre Fassung noch nicht kannte (Sprachansagen, die Haken der Befehle und der HTTP-Lesezweig), behält diese ihren geltenden Wert, und die Meldung nennt sie.
  - Nach dem Zurückspielen wird die Befehlsbrücke sofort nach dem zurückgespielten Stand geschaltet, wie beim Speichern im Reiter MQTT. Die Meldung sagt, dass der laufende Dienst die Einstellungen spätestens nach 30 Sekunden neu liest und nicht neu gestartet wird.
  - Der Haken vor dem Knopf „Sicherung einspielen“ und die Prüfung der hochgeladenen Datei (über das Formular gekommen, höchstens 256 KB) kommen jetzt aus dem gemeinsamen Modul; der Text des Hakens lautet „Ja, die geltenden Einstellungen durch die Sicherung ersetzen“.
  - Unverändert: Eine Datei mit einem unzulässigen Wert ändert gar nichts, alle Beanstandungen werden genannt. Aktionstoken und Token der Lampen gehören in die Datei, die Sprechtoken nicht. Die Zweitschrift zieht mit.
- **Der Datenordner übersteht ein Update:** Bei jedem Update löscht LoxBerry den Datenordner des Plugins (`data/plugins/xiaomilicht/`). Jetzt sichert `preupgrade.sh` über den gemeinsamen Baustein `upgrade_daten.sh` (Fassung 1.0.0) den ganzen Datenordner mit Unterordnern, Rechten und Zeitstempeln neben den Ordner (`data/plugins/xiaomilicht.upgrade_daten/`, Ordner 0700) – ohne Sperr- und PID-Dateien und Sockets – und zählt die Kopie Datei für Datei gegen. `postinstall.sh` spielt als Erstes jede Datei zurück, die nach dem Update fehlt; bei einer Neuinstallation geschieht dort nichts. Was die neue Fassung schon angelegt hat, wird nicht überschrieben. Danach wird die Sicherung entfernt.
  - **Verhalten geändert – lieber kein Update als ein leerer Datenordner:** Lässt sich der Datenordner nicht vollständig sichern (zu wenig Platz, fehlende Rechte, eine unlesbare Datei), meldet `preupgrade.sh` das mit `<FAIL>` und bricht die Aktualisierung ab; die bisherige Fassung bleibt mit ihrem Datenordner installiert.
  - Eine Sicherung, die älter als eine Stunde ist, wird nicht eingespielt und bleibt liegen; das Protokoll sagt es. Die Deinstallation räumt eine liegengebliebene Sicherung mit ab.
  - Zurück kommt auch der Merker `soll_laufen`, wenn der Dienst vor dem Update laufen sollte. Der Wächter im Minutentakt startet den Dienst erst, wenn die Aktualisierung zu Ende ist (die Marke `xiaomilicht.upgrade_laeuft` sperrt den Start bis dahin); wie bisher startet `postupgrade.sh` den Dienst, wenn er vorher lief.

## Neu in 0.9.8

- **Konfiguration wird vervollständigt:** Fehlen in `xiaomilicht.json` Einstellungen, die eine neuere Fassung mitbringt, trägt der Dienst beim Start (`bin/xl_dienst.php`) sie jetzt einmal mit ihrer Vorgabe in die Datei ein; die Zweitschrift zieht mit. Das Protokoll nennt die ergänzten Namen in einer Zeile, nie die Werte. Bisher ergänzte das Plugin fehlende Einstellungen nur beim Lesen; in die Datei kamen sie erst beim nächsten Speichern im Formular. Die Vorgaben sind die Werte, die das Plugin bisher ohnehin angenommen hat; am Verhalten ändert sich nichts.
- Geschrieben werden die Werte, die in der Datei stehen, plus die fehlenden Vorgaben. Unbekannte Schlüssel bleiben stehen, geschrieben wird nur, wenn etwas fehlt. Eine fehlende, leere oder beschädigte Datei und eine Datei ohne gültiges Aktionstoken werden nicht angefasst, ebenso wenig während einer Aktualisierung. Läuft der Dienst nicht, wird auch nichts vervollständigt. Gemessen ist das im Prüfstand unter PHP 7.4 und 8.4 auf dem Arbeitsrechner, an einer Datei in der Gestalt der Fassung 0.9.1, nicht am Gerät.
- Leere Objekte (`{}`) in der Datei bleiben beim Vervollständigen erhalten, auch verschachtelte und solche unter unbekannten Schlüsseln; sie werden dabei nicht zu leeren Listen (`[]`).

## Neu in 0.9.7

- Gemeinsames Sprachmodul 1.4.3: Als LoxBerry-Wurzel aus `LBHOMEDIR` gilt für die Sprachausgabe nur noch ein Ordner mit `config/plugins`, `data/plugins` und `config/system/general.json`; bis 1.4.2 genügte `config/system`, und ein Prüfstand oder Rest mit diesem Ordner wurde wie ein LoxBerry gelesen. Auf einem LoxBerry ändert sich nichts.

## Neu in 0.9.6

- Gemeinsames Sprachmodul 1.4.2: Wird eine Ansage bewusst nicht gesprochen (gerade eben schon gesagt, gesperrt oder Ruhezeit), steht das im Protokoll jetzt so – und nicht mehr als Fehler.

## Neu in 0.9.5

- **Update:** Scheitert die Sicherung oder die Marke, bricht das Update ab und die bisherige Fassung bleibt.
- **Sprechtoken:** Ein von Hand eingetragenes Alexa- oder Google-Token wird nicht mehr vom Token des Nachbar-Plugins überschrieben.
- **Meldungen:** Texte mit Apostroph oder `&` stehen im Reiter wieder richtig da, nicht als `&#039;`.
- **Formular:** Ein beanstandeter Tafel- oder HomePod-Name bleibt zur Korrektur im Feld stehen.

## Neu in 0.9.4

- Vor einem Download wird jeder offene Ausgabepuffer geleert: PHP-Warnungen oder Reste landen nicht mehr in der heruntergeladenen Datei.

## Neu in 0.9.3

**Ab dieser Fassung sind die Sprachansagen ab Werk an, auch nach einem Update, wenn Sie die Ausgabeart nie gespeichert haben.** Eine gespeicherte Einstellung bleibt, wie sie ist, auch „aus“. **Ausschalten:** Reiter *Einstellungen*, Abschnitt *Sprachansagen*, Ausgabeart **„aus“** wählen und speichern; das bleibt auch nach jedem weiteren Update aus.

* **Was angesagt wird:** **ab Werk nichts,** weil Leuchten oft gewollt stromlos sind. Wer den Ausfall hören will, setzt den Haken „Leuchte antwortet seit 30 Minuten nicht“: Dann kommt er nach 30 Minuten Dauer, und wieder da (nur nach einer angesagten Störung); nachts schweigt das. Beim Einsetzen des Hakens wird nichts Altes nachgesagt.
* **Wohin:** ab Werk zugleich Loxone Music Server (braucht seine Adresse), Google-Lautsprecher über Chromecast4lox (braucht dort ein Standardgerät oder hier den Namen) und Amazon Echo über Alexa-NG. Ein Ziel, das nicht bereitsteht, wird ohne Fehler übergangen; der Abschnitt *Sprachansagen* sagt, was ihm fehlt. Das Sprechtoken der beiden Plugins müssen Sie nicht abschreiben: Es wird übernommen, in der Konfiguration gespeichert und nachgezogen; ein von Hand eingetragenes bleibt.
* **Wann nicht:** Nachtruhe 22 bis 7 Uhr (einstellbar, wird nicht nachgeholt; Dringendes spricht trotzdem), dieselbe Ansage höchstens einmal in 30 Minuten (einstellbar) und solange Loxone das retained MQTT-Thema `haus/ansage/ruhe` auf 1 setzt (das gilt auch für Dringendes). Die Testansage spricht immer. Ausgabeart, Ziele und Regeln reisen in der Sicherung mit, das Sprechtoken nie.
* **Der Dienst wartet nicht auf Lautsprecher:** Gesprochen wird in einem eigenen Vorgang.


## Neu in 0.9.2

Nur Texte: Schalten an yilai.light.ceiling1 ist am Gerät erprobt (direkt über miIO und aus Loxone über die
Befehlsbrücke 1.0.2) – Hinweise in Oberfläche, Hilfe und README angeglichen; am Code ändert sich nichts.

## Neu in 0.9.1

Erste Fassung (Stufe A – das Plugin beobachtet; geschaltet wird erst mit zwei Haken, beide ab Werk aus).

* **Zustand je Lampe über das MQTT-Gateway V2**: `xiaomilicht/lampe<n>/ein|hell|ct|modus` (zurückbehalten),
  `erreichbar` und `weg` (wie das Plugin den Zustand erfährt), Lebenszeichen `xiaomilicht/status/zaehler` (alle 30 s)
  und `xiaomilicht/status/ok` – beide unter Gateway V2 mit „Noncached“ anhaken. -1 = keine Aussage; ist eine Lampe
  nicht erreichbar, gehen alle ihre Werte auf -1 (ein stromloser Ausgang ist kein „an, 80 %“).
* **Rückmeldung in Echtzeit über Yeelight-LAN** (TCP 55443, „LAN-Steuerung“ in der Yeelight-App einschalten): eine
  gehaltene Verbindung je Lampe, jede Änderung kommt sofort. Ohne LAN fragt das Plugin über **miIO** (UDP 54321, Token)
  im Takt ab (Werk 10 s).
* **Befehle über die Befehlsbrücke 1.0.2**: `xiaomilicht/befehl/lampe<n>/schalten|umschalten|hell|ct|nacht|szene|abruf`
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
Modell. Am Gerät gemessen (28.10., drei ceiling1 mit Firmware 1.3.2_0024): ceiling1 lesen über miIO (get_prop; MIoT
get_properties lehnt die Lampe mit -5000 ab), Rückmeldung über Yeelight-LAN nach 0,1–0,4 s (auch bei Schaltungen aus
der Yeelight-App), schalten direkt über miIO (set_power/set_bright/set_ct_abx) und aus Loxone über die Befehlsbrücke
1.0.2 (`schalten 0|1` und `umschalten` je „angenommen“ nach 0–2 s, `hell 30|0|100` nach 1–2 s, `ct 3000|6500` nach
1–5 s, `hell 0` = aus; danach meldet die Lampe den neuen Zustand; eine stromlose Lampe ehrlich
`abgelehnt: KEIN_GRUSS`, Werte -1). Nicht eigens gemessen: Schalten allein über die Yeelight-LAN-Verbindung und das
Modell ceiling3 (nur gegen eine Attrappe geprüft).

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

Archiv eines Releases aus dem Repository `timanders22/LoxBerry-Plugin-Xiaomi-Licht` (besteht seit 28.10.2026) in
LoxBerry unter „Plugins installieren“ hochladen; spätere Fassungen findet das Auto-Update über die `release.cfg` dort.
