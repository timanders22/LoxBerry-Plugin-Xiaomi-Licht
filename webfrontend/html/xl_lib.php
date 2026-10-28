<?php
/**
 * Xiaomi Licht - gemeinsame Bibliothek (Kuerzel xl, Ordner xiaomilicht).
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt (webfrontend/html/index.php) sie ebenso braucht wie die
 * Oberflaeche, der Dienst (bin/xl_dienst.php), die Befehlsbruecke (bin/befehle_zuordnung.php) und bin/healthcheck
 * (Regeln/03: die Bibliothek liegt nur dann unter html/, wenn der Endpunkt sie braucht).
 *
 * ------------------------------------------------------------------
 * Was das Plugin tut (Entwurf Gruppe 34, Entscheidungen 1-5, Stufe A)
 * ------------------------------------------------------------------
 * Deckenleuchten von Xiaomi/Yeelight im Heimnetz: Zustand lesen (ein, Helligkeit, Farbtemperatur, Nachtlicht) und an
 * Loxone zurueckmelden - auch wenn jemand die Lampe ueber die App, Alexa oder den Wandschalter veraendert. Werte ueber
 * das MQTT-Gateway V2 (UDP-Eingang des Gateways), Befehle ueber die Befehlsbruecke 1.0.2 (zwei Haken, ab Werk aus).
 *
 * Zwei Wege zur Lampe:
 *   miIO/MIoT     UDP 54321, Token (32 Hex), AES-128-CBC. Transport: Abschrift ro_miio_* aus dem Saugroboter 1.1.23
 *                 (vb_g28_sr_bau, robo_lib.php md5 8d3e32d3, Zeilen 4737-4945) unter dem Praefix xl_miio_ - byte-gleich
 *                 bis auf den Praefix (Abschnitt "miIO-Transport" unten, beim Bau eingesetzt).
 *                 Belegung je Modell aus der amtlichen MIoT-Spezifikation (home.miot-spec.com, miot-spec.org instance,
 *                 gelesen 27.10.2026) - xl_modelle().
 *   Yeelight-LAN  TCP 55443, JSON-Zeilen mit \r\n, Push "props" bei jeder Zustandsaenderung an alle offenen Verbindungen,
 *                 hoechstens 4 Verbindungen je Lampe, 60 Befehle je Minute und Verbindung (Yeelight WiFi Light
 *                 Inter-Operation Specification, Abschnitte 3-4; vom Hersteller zur Nutzung durch Dritte
 *                 veroeffentlicht). Nur wenn "LAN-Steuerung" in der Yeelight-App eingeschaltet ist.
 * Gelesen, kein Code uebernommen: python-miio (GPL-3.0) - nur Methodennamen/Parameterformen als Beleg.
 *
 * Geheimnisse (Regeln/05): Lampen-Token und Aktionstoken nie im Protokoll, nie in einer Ausgabe, nie im HTML, nie auf
 * einer Befehlszeile. Die Token stehen in der Konfiguration (0600, Zweitschrift 0600) und in der Sicherung (Kopf-Hinweis).
 * Ein Token, das miIO.info mitliefert, wird verworfen (N8); ebenso MAC und Geraetekennung.
 *
 * Praefix 'xl_', weil LBWeb::lbheader() SDK-Globale setzt. PHP 7.4, 8.4, 8.5 (LoxBerry 3.x/4.x).
 */

if (!function_exists('xl_e')) {
    function xl_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* Feste Groessen an EINER Stelle (Regeln/04: eine Grenze steht genau einmal, als Konstante in der Bibliothek). */
define('XL_PRAEFIX', 'xiaomilicht');            // MQTT-Praefix = Ordnername (Hausform), nicht einstellbar
define('XL_LAN_PORT', 55443);                   // Yeelight-LAN (Spezifikation 4)
define('XL_SSDP_ADRESSE', '239.255.255.250');   // Yeelight-Suche (Spezifikation 3.1)
define('XL_SSDP_PORT', 1982);
define('XL_LAMPEN_MAX', 16);                    // Lampe n = 1..16 (Entwurf 2.5)
define('XL_TAKT_S', 30);                        // Herz des Dienstes: lauf.json + Lebenszeichen
define('XL_VOLL_S', 1800);                      // voller Satz alle 30 min
define('XL_ABGLEICH_S', 300);                   // get_prop-Abgleich auf der LAN-Verbindung alle 5 min
define('XL_LAN_QUOTE', 50);                     // Yeelight-LAN: 60 Befehle je Minute und Verbindung (Spezifikation) - 10 Reserve
define('XL_LAN_MIIO_S', 30);                    // miIO-Abfrage neben gehaltener LAN-Verbindung (mit Token): Takt und Rueckfall
define('XL_FEHL_GRENZE', 3);                    // erreichbar 0 erst nach 3 Fehlschlaegen (E-E4)
define('XL_BREMSE_S', 60);                      // Gleichwert-Bremse je Lampe und Befehl (E-E12)
define('XL_WARTEN_WEB', 10);                    // Obergrenze einer Wartezeit aus einer Web-Anfrage
define('XL_MODELL_ALTER', 86400);               // Modell aus miIO.info: einmal je 24 h und Adresse
define('XL_TEST_ZWISCHEN_S', 60);               // Netzpruefungen im Reiter Test: 60 s Zwischenspeicher
define('XL_LOG_MAX', 262144);                   // Protokoll: Kappung bei 256 kB auf 300 Zeilen (wie Saugroboter S16)
define('XL_LOG_REST', 300);
define('XL_TOKEN_ZEICHEN', 'abcdefghijkmnpqrstuvwxyz23456789');
define('XL_TOKEN_LAENGE', 24);

/* Die Fassung wird GELESEN, nicht eingetragen (Bauart gv_fassung() aus Govee 0.9.31): Plugin-Datenbank ueber den
 * ORDNERNAMEN, ersatzweise plugin.cfg (nur im ausgepackten Archiv), sonst leer. */
function xl_fassung()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = '';
    $p = xl_paths();
    if ($p['home'] !== '') {
        $db = $p['home'] . '/data/system/plugindatabase.json';
        $d = is_file($db) ? json_decode((string) @file_get_contents($db), true) : null;
        if (is_array($d) && isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder'], $e['version']) && $e['folder'] === $p['plugin']
                    && is_scalar($e['version'])) {
                    $f = trim((string) $e['version']);
                    break;
                }
            }
        }
    }
    if ($f === '') {
        $k = dirname(dirname(__DIR__)) . '/plugin.cfg';
        $roh = is_readable($k) ? (string) @file_get_contents($k) : '';
        if (preg_match('/^\s*VERSION\s*=\s*([0-9][0-9A-Za-z.\-]*)\s*$/m', $roh, $m)) {
            $f = $m[1];
        }
    }
    return $f;
}

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen: vom eigenen Ablageort aufwaerts, bis ein Verzeichnis
 * config/plugins, webfrontend UND config/system/general.json traegt (Regeln/06). Ohne Fund ein Leerstring. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel: $LBHOMEDIR, wenn dort config/plugins und data/plugins liegen, sonst die Suche (mit general.json). */
function xl_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/**
 * Pfade der Anlage (Hausform <p>_paths(), Schluessel 'datadir'). Bauart gv_paths() aus Govee 0.9.31: die Pfade DER
 * ANLAGE gelten nur, wenn diese Bibliothek dort installiert liegt (physisch verglichen) oder LBHOMEDIR UND LBPPLUGINDIR
 * ausdruecklich gesetzt sind (Pruefwerkzeuge, Attrappe). Sonst Archivmodus: alles im eigenen Ordner, der Dienst steigt
 * aus (xl_keine_wurzel_abbruch()).
 *
 * Ablage (Abweichung vom Entwurf 1.4, im Bericht): die Konfiguration steht wie bei Govee unter config/plugins/<ordner>/
 * (xiaomilicht.json 0600, Zweitschrift config/plugins/<ordner>.backup.json 0600), nicht unter data/ - data/plugins/
 * <ordner>/ raeumt der Installer beim Upgrade ab (Regeln/03, Merker-Eintrag). Fluechtiges (Werte, Lebenszeichen,
 * Modellzwischenspeicher, Warteschlange) liegt in data/plugins/<ordner>/.
 */
function xl_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = xl_lbhome();
    $dir = basename(dirname(__FILE__));
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '..', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true));
    if ($lbp_gilt) {
        $dir = $lbp;
    } elseif (in_array($dir, array('', '.', '/', 'html', 'bin', 'plugins'), true)) {
        $dir = 'xiaomilicht';
    }
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) {
            $home = '';
        }
    }
    if ($home !== '') {
        $p = array(
            'home'      => $home,
            'archiv'    => '',
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/xiaomilicht.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'htmlauth'  => $home . '/webfrontend/htmlauth/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/xl.log',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'archiv'    => $gefunden,
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/xiaomilicht.json',
            'sicherung' => $basis . '/config/xiaomilicht.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'htmlauth'  => $basis . '/webfrontend/htmlauth',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/xl.log',
        );
    }
    return $p;
}

/* Fuer bin/xl_dienst.php und bin/healthcheck: ohne Wurzel oder aus einem ausgepackten Archiv heraus nichts tun. */
function xl_keine_wurzel_abbruch($programm)
{
    $p = xl_paths();
    if ($p['home'] !== '') {
        return;
    }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter ' . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Es wurde nichts abgefragt, nichts gesendet und nichts geschrieben.'
            . "\n" . 'Abhilfe: LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden (config/plugins, webfrontend,'
        . ' config/system/general.json). Es wurde nichts abgefragt, nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/** Vorgaben - EINE Liste fuer Oberflaeche, Dienst, Sicherung und Selbstpruefung (Entwurf 2.8). */
function xl_vorgaben()
{
    return array(
        'lampen'           => array(),   // Zeilen {nr, name, ip, token, modell, lan[, did]}
        'abfrage_s'        => 10,        // miIO-Takt fuer Lampen ohne Push (5..60, E-E4)
        'mqtt_ein'         => 1,         // Werte ueber das MQTT-Gateway V2 senden
        'befehle_mqtt_ein' => 0,         // erster Haken der Befehlsbruecke (ab Werk aus)
        'steuerung_ein'    => 0,         // zweiter Haken "Lampen aus Loxone schalten" (ab Werk aus, E-E11)
        'endpunkt_ein'     => 0,         // HTTP-Lesezweig fuer Loxone (ab Werk aus, Entscheidung 3)
        'aktionstoken'     => '',        // Endpunkt-Token und Grundlage des Formularmerkmals
        'wartezeit'        => 6,         // so lange wartet ein Befehl auf den Dienst (Werk der Bruecke)
        'nr_hoechste'      => 0,         // hoechste je vergebene Lampennummer (Nummern wandern nie)
        'cloud_region'     => 'de',      // Vorauswahl der Region im Cloud-Abruf
    );
}

/** Die Schluessel, die erst spaet geltend sind: fehlen sie in einer Sicherung, gilt der geltende Wert (Bauart G26/G32). */
function xl_spaet_schluessel()
{
    return array('befehle_mqtt_ein', 'steuerung_ein', 'endpunkt_ein');
}

function xl_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Unteilbar schreiben: Nebendatei mit PID, Rechte VOR dem Inhalt, jedes Byte geschrieben und zurueckgelesen, dann
 * umbenennen (Bauart gv_json_schreiben(), Govee 0.9.31 C1). Rueckgabe true/false.
 */
function xl_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $ok = ftruncate($fh, 0) && @fwrite($fh, $json) === strlen($json);
    $ok = @fflush($fh) && $ok;
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = ((string) @file_get_contents($tmp) === $json);
    }
    if (!$ok || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Lage der Konfiguration beim letzten Lesen: ok | leer | zweitschrift | kaputt. */
function xl_config_lage($setzen = null)
{
    static $lage = 'ok';
    if ($setzen !== null) {
        $lage = $setzen;
    }
    return $lage;
}

/**
 * Die Konfiguration lesen. $heilen = false liest nur (unangemeldeter Endpunkt, Bruecke, healthcheck: wer sich nicht
 * ausweist, legt nichts an). Ungueltiges JSON ist ein Fehler, kein leerer Wert: die Datei bleibt als .kaputt liegen,
 * gearbeitet wird mit der Zweitschrift (Bauart gv_config()).
 */
function xl_config($heilen = true)
{
    $p = xl_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $daten = null;
    $lage = 'ok';
    if ($roh !== '') {
        $daten = json_decode($roh, true);
        if (!is_array($daten)) {
            $lage = 'kaputt';
            $daten = null;
            if ($heilen) {
                @rename($p['config'], $p['config'] . '.kaputt');
                xl_log_gebremst('cfg_kaputt', 'Die Konfiguration war kein gueltiges JSON. Sie liegt jetzt als '
                    . basename($p['config']) . '.kaputt daneben; weitergearbeitet wird mit der Zweitschrift.');
            }
        } elseif (!$daten) {
            $lage = 'leer';
            $daten = null;
        }
    } else {
        $lage = 'leer';
    }
    if ($daten === null) {
        $z = xl_json_lesen($p['sicherung']);
        if ($z) {
            $daten = $z;
            $lage = 'zweitschrift';
            if ($heilen) {
                @mkdir($p['configdir'], 0775, true);
                xl_json_schreiben($p['config'], $z, 0600);
                xl_log_gebremst('cfg_geheilt', 'Die Konfiguration wurde aus der Zweitschrift wiederhergestellt.');
            }
        }
    }
    xl_config_lage($lage);
    return array_merge(xl_vorgaben(), is_array($daten) ? $daten : array());
}

function xl_config_speichern($cfg)
{
    $p = xl_paths();
    if (!xl_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    if (xl_config_lage() === 'kaputt') {
        return true;     // die letzte heile Zweitschrift nicht mit einem Stand aus der Werkseinstellung ueberschreiben
    }
    if (!xl_json_schreiben($p['sicherung'], $cfg, 0600)) {
        xl_log_gebremst('zweitschrift', 'Die Zweitschrift ' . basename($p['sicherung'])
            . ' liess sich nicht schreiben; die Konfiguration selbst ist gespeichert.');
    }
    return true;
}

/**
 * Die Lampenzeilen, nach Nummer (1..16). Nur Zeilen mit Adresse; die Nummer ist eine ADRESSE (Themen lampe<n>), sie
 * wandert nie (fehlt sie, gilt die Zaehlung). Token klein, 32 Hex oder leer; lan auto|aus.
 */
function xl_lampen($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = xl_config(false);
    }
    $aus = array();
    $n = 0;
    foreach ((array) (isset($cfg['lampen']) ? $cfg['lampen'] : array()) as $z) {
        if (!is_array($z)) {
            continue;
        }
        $ip = trim((string) (isset($z['ip']) ? $z['ip'] : ''));
        if ($ip === '') {
            continue;
        }
        $n++;
        $nr = (isset($z['nr']) && (int) $z['nr'] >= 1 && (int) $z['nr'] <= XL_LAMPEN_MAX && !isset($aus[(int) $z['nr']]))
            ? (int) $z['nr'] : 0;
        if ($nr === 0) {
            $nr = $n;
            while (isset($aus[$nr]) && $nr < XL_LAMPEN_MAX) {
                $nr++;
            }
            if (isset($aus[$nr])) {
                continue;
            }
        }
        $tok = strtolower(trim((string) (isset($z['token']) ? $z['token'] : '')));
        $aus[$nr] = array(
            'nr'     => $nr,
            'name'   => trim((string) (isset($z['name']) ? $z['name'] : '')) !== '' ? trim((string) $z['name']) : ('Lampe ' . $nr),
            'ip'     => $ip,
            'token'  => preg_match('/^[0-9a-f]{32}\z/', $tok) ? $tok : '',
            'modell' => trim((string) (isset($z['modell']) ? $z['modell'] : '')),
            'lan'    => (isset($z['lan']) && $z['lan'] === 'aus') ? 'aus' : 'auto',
        );
        /* Nur fuer die Proben gegen die Attrappe (127.0.0.1): abweichende Ports. Die Oberflaeche setzt sie nie. */
        foreach (array('miio_port', 'lan_port') as $k) {
            if (isset($z[$k]) && is_int($z[$k]) && $z[$k] > 0 && $z[$k] < 65536) {
                $aus[$nr][$k] = $z[$k];
            }
        }
    }
    ksort($aus);
    return $aus;
}

function xl_lampe($nr, $cfg = null)
{
    $nr = (int) $nr;
    if ($nr < 1) {
        return null;
    }
    $l = xl_lampen($cfg);
    return isset($l[$nr]) ? $l[$nr] : null;
}

/** Die naechste freie Lampennummer; vergebene Nummern werden nie wieder ausgegeben. 0 = keine mehr frei. */
function xl_naechste_nummer($cfg)
{
    $belegt = array();
    $hoechste = isset($cfg['nr_hoechste']) ? (int) $cfg['nr_hoechste'] : 0;
    foreach ((array) (isset($cfg['lampen']) ? $cfg['lampen'] : array()) as $z) {
        if (is_array($z) && isset($z['nr'])) {
            $belegt[(int) $z['nr']] = true;
            $hoechste = max($hoechste, (int) $z['nr']);
        }
    }
    for ($i = $hoechste + 1; $i <= XL_LAMPEN_MAX; $i++) {
        if (!isset($belegt[$i])) {
            return $i;
        }
    }
    for ($i = 1; $i <= XL_LAMPEN_MAX; $i++) {
        if (!isset($belegt[$i])) {
            return $i;
        }
    }
    return 0;
}

function xl_token_erzeugen($laenge = XL_TOKEN_LAENGE)
{
    $z = XL_TOKEN_ZEICHEN;
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $z[random_int(0, strlen($z) - 1)];
    }
    return $t;
}

/** Traegt ein Aktionstoken die Form, die xl_token_erzeugen() erzeugt? \z statt $. */
function xl_token_gueltig($t)
{
    return is_string($t) && preg_match('/^[' . XL_TOKEN_ZEICHEN . ']{' . XL_TOKEN_LAENGE . '}\z/', $t) === 1;
}

/** Das Aktionstoken; fehlt es, wird es erzeugt und gespeichert (nur aus der Oberflaeche). */
function xl_token()
{
    $cfg = xl_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = xl_token_erzeugen();
        xl_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/** Das Merkmal jedes Formulars (Regeln/04); ohne Aktionstoken leer - dann weist die Pruefung ab (fail closed). */
function xl_formtoken($cfg = null)
{
    if ($cfg === null) {
        $cfg = xl_config();
    }
    $t = trim((string) (isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : ''));
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

/* ==================================================================
 * Sprache (Deutsch und Englisch; Englisch ist die Rueckfallebene)
 * ================================================================== */

function xl_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function xl_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = xl_paths()['home'];
        $pfad = $home !== '' ? $home . '/templates/plugins/' . xl_paths()['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . xl_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : (string) $schluessel;
}

/** Der Text ohne Auszeichnung und Entitaeten - fuer Protokoll, Vorlagen, Endpunkt, healthcheck. */
function xl_klartext($schluessel)
{
    return html_entity_decode(strip_tags(xl_t($schluessel)), ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Protokoll (log/plugins/<ordner>/xl.log; Ramdisk - Kappung ist Pflicht)
 * ================================================================== */

function xl_log($text)
{
    $p = xl_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > XL_LOG_MAX) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -XL_LOG_REST);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function xl_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $d = xl_paths()['datadir'];
    if (!is_dir($d)) {
        xl_log($text);
        return;
    }
    $f = $d . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        xl_log($text);
    }
}

/**
 * Eine Zeile nur beim Wechsel der Lage (xl_log_if_changed im Entwurf 3.2): je Schluessel die letzte Lage im Speicher
 * des Prozesses. Grund-Woerter: KEIN_GRUSS | TOKEN | KEINE_ANTWORT | TOKEN_FORM | OPENSSL | LAN_ZU | ok. Nie Token,
 * Antwort oder Kennung.
 */
function xl_log_wenn_neu($schluessel, $lage, $text)
{
    static $letzte = array();
    if (isset($letzte[$schluessel]) && $letzte[$schluessel] === $lage) {
        return false;
    }
    $erste = !isset($letzte[$schluessel]);
    $letzte[$schluessel] = $lage;
    if ($erste && $lage === 'ok') {
        return false;     // ein gesunder Start braucht keine Zeile
    }
    xl_log($text);
    return true;
}

/** Die letzten $anzahl Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek (Regeln/03). */
function xl_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}


/* ==================================================================
 * miIO-Transport - Abschrift aus dem Saugroboter 1.1.23 (vb_g28_sr_bau, webfrontend/html/robo_lib.php md5 8d3e32d3,
 * Zeilen 4737-4945, md5 der Zeilen 23485032), byte-gleich bis auf den Praefix ro_miio_ -> xl_miio_ und
 * RO_MIIO_ -> XL_MIIO_ (Entwurf Gruppe 34, 3.2). Eine spaetere Pflege zieht beide Linien mit (N6; Stammdatei
 * xiaomi_miio.php als eigener Auftrag, Entwurf 7.2). Kommentare darin sprechen vom "Roboter" - gemeint ist hier
 * die Lampe. Beim Bau eingesetzt von vb_g34_xl_bau_skripte/bau_lib34.py.
 * ================================================================== */

define('XL_MIIO_PORT', 54321);

/**
 * Nach so vielen Sekunden ohne frischen Geraetestempel gruesst die Sitzung vor dem naechsten Paket neu (Nachbau 26.10.
 * spaet). Beleg: python-miio (miio/miioprotocol.py, Zweig master, gelesen 26.10.2026) fuehrt dafuer handshake_timeout
 * ein ("Some devices require a recent handshake to accept commands") und rechnet den Stempel als letzter
 * Geraetestempel + 1 s. Am T12 gemessen: ein Paket mit einem 120 s zurueckliegenden Stempel bleibt ohne Antwort.
 */
define('XL_MIIO_GRUSS_ALTER', 10);

/** Token (32 Hex, Gross- oder Kleinbuchstaben) -> 16 Byte, sonst false. */
function xl_miio_token_bin($hex)
{
    $hex = strtolower(trim((string) $hex));
    if (!preg_match('/^[0-9a-f]{32}\z/', $hex)) { return false; }
    return hex2bin($hex);
}

/** array(Schluessel, IV) - je 16 Byte roh (python-miio: key = md5(token), iv = md5(key + token)). */
function xl_miio_schluessel($tok)
{
    $key = md5($tok, true);
    return array($key, md5($key . $tok, true));
}

function xl_miio_verschluesseln($klar, $tok)
{
    list($key, $iv) = xl_miio_schluessel($tok);
    // OPENSSL_RAW_DATA: Rohbytes; ohne OPENSSL_ZERO_PADDING rechnet openssl PKCS7 selbst.
    return openssl_encrypt($klar, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

function xl_miio_entschluesseln($geheim, $tok)
{
    list($key, $iv) = xl_miio_schluessel($tok);
    $k = openssl_decrypt($geheim, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $k === false ? false : rtrim($k, "\0");
}

/** Ein Paket bauen. $did = 4 Byte roh, $stempel = ganze Zahl. */
function xl_miio_paket($did, $stempel, $tok, $json)
{
    $nutz = xl_miio_verschluesseln($json . "\0", $tok);
    if ($nutz === false) { return false; }
    $kopf = pack('nnN', 0x2131, 32 + strlen($nutz), 0) . $did . pack('N', $stempel & 0xFFFFFFFF);
    return $kopf . md5($kopf . $tok . $nutz, true) . $nutz;
}

/**
 * Ein empfangenes Paket zerlegen: array('lage' => ok|gruss|kurz|magie|laenge|summe|entschl|json, 'did', 'stempel', 'j').
 * Was die Pruefsumme nicht besteht (falscher Token, fremdes Paket), wird nie entschluesselt.
 */
function xl_miio_zerlegen($roh, $tok)
{
    $aus = array('lage' => 'kurz', 'did' => '', 'stempel' => 0, 'j' => null);
    if (strlen($roh) < 32) { return $aus; }
    $k = unpack('nmagie/nlaenge/Nunbekannt', substr($roh, 0, 8));
    if ($k['magie'] !== 0x2131) { $aus['lage'] = 'magie'; return $aus; }
    if ($k['laenge'] !== strlen($roh)) { $aus['lage'] = 'laenge'; return $aus; }
    $aus['did'] = substr($roh, 8, 4);
    $s = unpack('Nst', substr($roh, 12, 4));
    $aus['stempel'] = $s['st'];
    if ($k['laenge'] === 32) { $aus['lage'] = 'gruss'; return $aus; }
    $nutz = substr($roh, 32);
    if (!hash_equals(md5(substr($roh, 0, 16) . $tok . $nutz, true), substr($roh, 16, 16))) {
        $aus['lage'] = 'summe';
        return $aus;
    }
    $klar = xl_miio_entschluesseln($nutz, $tok);
    if ($klar === false) { $aus['lage'] = 'entschl'; return $aus; }
    $j = json_decode($klar, true);
    if (!is_array($j)) {
        // python-miio kennt Geraete mit ",," im JSON - dieselbe Nachbesserung, nichts weiter.
        $j = json_decode(str_replace(',,', ',', $klar), true);
    }
    if (!is_array($j)) { $aus['lage'] = 'json'; return $aus; }
    $aus['lage'] = 'ok';
    $aus['j'] = $j;
    return $aus;
}

/** UDP-Strom zum Roboter - ohne die Erweiterung "sockets" (wie ro_udp_senden()). */
function xl_miio_strom($ip, $port)
{
    $s = @stream_socket_client('udp://' . $ip . ':' . (int) $port, $en, $es, 1);
    return $s === false ? null : $s;
}

/** Ein Datagramm lesen, hoechstens $tmo s. null = Zeitablauf oder niemand am Port (ICMP: fread liefert ''). */
function xl_miio_lesen($s, $tmo)
{
    $bis = microtime(true) + $tmo;
    while (true) {
        $rest = $bis - microtime(true);
        if ($rest <= 0) { return null; }
        $r = array($s); $w = null; $e = null;
        $n = @stream_select($r, $w, $e, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        if ($n === false) { return null; }
        if ($n === 0) { continue; }
        $d = @fread($s, 4096);
        if ($d !== false && $d !== '') { return $d; }
        return null;
    }
}

/** Gruss: array(ok, Kennung (4 Byte), Stempel, ms). Zwei Versuche je 1 s - ein verlorenes Datagramm ist kein Ausfall. */
function xl_miio_gruss($s, $tmo = 1.0, $versuche = 2)
{
    $gruss = pack('nn', 0x2131, 0x0020) . str_repeat("\xff", 28);
    for ($i = 0; $i < $versuche; $i++) {
        $t0 = microtime(true);
        @fwrite($s, $gruss);
        $d = xl_miio_lesen($s, $tmo);
        if ($d === null) { continue; }
        if (strlen($d) === 32 && substr($d, 0, 2) === "\x21\x31") {
            $st = unpack('Nst', substr($d, 12, 4));
            return array(true, substr($d, 8, 4), $st['st'], (int) round((microtime(true) - $t0) * 1000));
        }
    }
    return array(false, '', 0, 0);
}

/**
 * Eine Sitzung = ein Gruss je Lauf bzw. je Befehl (E-E4; kein Merker ueber Prozesse, keine Datei). Rueckgabe
 * array('fehler' => ''|TOKEN_FORM|OPENSSL|NETZ|KEIN_GRUSS, 's', 'tok', 'did', 'stempel', 'stempel_zeit', 'id', 'gruss_ms').
 * $r ist die Roboterzeile (ip, token; 'miio_port' nur fuer die Proben gegen die Attrappe).
 */
function xl_miio_sitzung($r)
{
    if (!extension_loaded('openssl')) { return array('fehler' => 'OPENSSL'); }
    $tok = xl_miio_token_bin(isset($r['token']) ? $r['token'] : '');
    if ($tok === false) { return array('fehler' => 'TOKEN_FORM'); }
    $s = xl_miio_strom($r['ip'], isset($r['miio_port']) ? (int) $r['miio_port'] : XL_MIIO_PORT);
    if ($s === null) { return array('fehler' => 'NETZ'); }
    list($ok, $did, $stempel, $ms) = xl_miio_gruss($s);
    if (!$ok) { @fclose($s); return array('fehler' => 'KEIN_GRUSS'); }
    return array('fehler' => '', 's' => $s, 'tok' => $tok, 'did' => $did,
                 'stempel' => $stempel, 'stempel_zeit' => microtime(true),
                 // Laufnummer: zufaelliger Beginn, damit zwei Prozesse kurz nacheinander nicht dieselbe senden.
                 'id' => mt_rand(1, 8999), 'gruss_ms' => $ms);
}

/** Sitzung schliessen und den Token aus dem Speicher nehmen. */
function xl_miio_schliessen(array &$z)
{
    if (isset($z['s']) && is_resource($z['s'])) { @fclose($z['s']); }
    $z['tok'] = '';
    $z['s'] = null;
}

/** Stempel fuer das naechste Paket: letzter Geraetestempel + verstrichene ganze Sekunden + 1 (python-miio: +1 s). */
function xl_miio_stempel(array $z)
{
    return (int) $z['stempel'] + (int) floor(microtime(true) - $z['stempel_zeit']) + 1;
}

/**
 * Ein Aufruf mit Wiederholung. Rueckgabe array(lage, result|null, fehlercode, text);
 * lage: ok | fehler (error vom Geraet) | keine_antwort | token (Antwort kam, Summe/Entschluesseln falsch).
 * Frist 2 s je Paket, hoechstens 2 Pakete: nach Zeitablauf neue Laufnummer (+100 wie python-miio) und ein Neugruss,
 * bei error.code -9999/-30001 gleich die naechste Nummer. Schlimmster Fall 2 x 1 s + 2 x 2 s + 1 s = 7 s.
 * Liegt der letzte Geraetestempel laenger als XL_MIIO_GRUSS_ALTER s zurueck (lange Pause in einer Sitzung), kommt
 * VOR dem Paket ein Neugruss (ein Versuch, 1 s) - der Stempel beruht dann auf dem frischen Geraetestempel.
 */
function xl_miio_rufen(array &$z, $methode, $params, $tmo = 2.0, $versuche = 2)
{
    $letzte = 'keine_antwort';
    for ($i = 0; $i < $versuche; $i++) {
        if (microtime(true) - (float) $z['stempel_zeit'] > XL_MIIO_GRUSS_ALTER) {
            list($ok, $did, $st) = xl_miio_gruss($z['s'], 1.0, 1);
            if ($ok) { $z['did'] = $did; $z['stempel'] = $st; $z['stempel_zeit'] = microtime(true); }
        }
        $id = $z['id'];
        $json = json_encode(array('id' => $id, 'method' => $methode, 'params' => $params),
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $p = ($json === false) ? false : xl_miio_paket($z['did'], xl_miio_stempel($z), $z['tok'], $json);
        if ($p === false) { return array('fehler', null, 0, 'OPENSSL'); }
        @fwrite($z['s'], $p);
        $bis = microtime(true) + $tmo;
        $antwort = null;
        while (($rest = $bis - microtime(true)) > 0) {
            $d = xl_miio_lesen($z['s'], $rest);
            if ($d === null) { break; }
            $t = xl_miio_zerlegen($d, $z['tok']);
            if ($t['lage'] === 'gruss') { continue; }                      // verspaeteter Gruss
            if ($t['lage'] !== 'ok') { $letzte = 'token'; continue; }      // Summe/Entschluesseln falsch
            $z['stempel'] = $t['stempel'];
            $z['stempel_zeit'] = microtime(true);
            if (!isset($t['j']['id']) || (int) $t['j']['id'] !== $id) { continue; }   // alte Antwort
            $antwort = $t['j'];
            break;
        }
        if ($antwort === null) {
            $z['id'] = ($z['id'] + 100) % 9999 + 1;
            list($ok, $did, $st) = xl_miio_gruss($z['s'], 1.0, 1);
            if ($ok) { $z['did'] = $did; $z['stempel'] = $st; $z['stempel_zeit'] = microtime(true); }
            continue;
        }
        $z['id'] = $z['id'] % 9999 + 1;
        if (isset($antwort['error'])) {
            $c = isset($antwort['error']['code']) ? (int) $antwort['error']['code'] : 0;
            if (in_array($c, array(-9999, -30001), true) && $i + 1 < $versuche) { $letzte = 'fehler'; continue; }
            return array('fehler', null, $c, '');
        }
        return array('ok', isset($antwort['result']) ? $antwort['result'] : null, 0, '');
    }
    return array($letzte, null, 0, '');
}


/* ==================================================================
 * Modelle (EINE Liste fuer Treiber, Reiter Test, Befehlstabelle, Wiki - Entwurf 2.2/2.3)
 * ==================================================================
 * Quelle: amtliche MIoT-Spezifikation von Xiaomi (home.miot-spec.com/spec/<modell>, Daten
 * miot-spec.org/miot-spec-v2/instance?type=<urn>, gelesen 27.10.2026). ACHTUNG (N2): die Farbtemperatur liegt bei
 * ceiling3 auf 2/4, bei ceiling1 auf 2/3 - bei ceiling3 ist 2/3 der Modus. Deshalb eine Tabelle JE MODELL und das
 * Modell aus miIO.info; was nicht passt, kommt als -1 bzw. "MIOT <code>", nie als falscher Wert.
 * 'lan' = Yeelight-LAN laut Spezifikation/python-miio belegt; 'alt' = miIO-Altbefehle (get_prop ...) belegt.
 * 'gemessen' = am Geraet erprobt (bis zum Geraetetest: nein).
 * 'lesen' = Leseweg der miIO-Abfrage: 'miot' (get_properties) oder 'alt' (get_prop power bright ct active_mode nl_br).
 * 'schalten' = Befehlsweg ueber miIO: 'miot' (set_properties/action) oder 'alt' (set_power/set_bright/set_ct_abx);
 * 'lan_schalten' = Befehle bevorzugt ueber die gehaltene Yeelight-LAN-Verbindung mit denselben Altbefehlen (Nachbesserung 2).
 * Nachbesserung 2 (28.10.2026, Hausherr "Beides mal JA"): an ceiling1 "Technikkeller" gemessen set_power on/off
 * (smooth 500 und sudden 0), set_bright 30, set_ct_abx 3000 und zurueck 6500 - jede Antwort ["ok"] unter 70 ms, get_prop
 * danach passend. set_properties ist dort NICHT gemessen und gilt wie get_properties als abgelehnt (-5000).
 * Nachbesserung Geraetetest 28.10.2026 (LoxBerry, drei yilai.light.ceiling1, Firmware 1.3.2_0024): JEDES
 * get_properties - auch 2/1 allein, 1/1, ohne did - beantwortet die Lampe mit error -5000; get_prop beantwortet sie
 * (on/100/6500/0/0), ueber miIO wie ueber Yeelight-LAN. Darum liest ceiling1 mit 'alt'; liefert eine andere Lampe auf
 * get_properties einen Fehler, versucht xl_lampe_lesen() get_prop und bleibt dabei, wenn es antwortet.
 */
function xl_modelle()
{
    return array(
        'yeelink.light.ceiling3' => array(
            'urn'      => 'urn:miot-spec-v2:device:light:0000A001:yeelink-ceiling3:1',
            'titel'    => 'Yeelight LED-Deckenleuchte (ceiling3)',
            'miot'     => array('ein' => array(2, 1), 'hell' => array(2, 2), 'modus' => array(2, 3), 'ct' => array(2, 4)),
            'umschalt' => array(2, 1),                     // Aktion A 2/1 toggle
            'ct'       => array(2700, 6500),
            'modus'    => array(1 => 'tag', 2 => 'nacht'),  // MIoT 2/3: 1 Day, 2 Night
            'szenen'   => array('lesen', 'gemuetlich', 'nacht'),
            'lan'      => true,
            'alt'      => true,
            'lesen'    => 'miot',
            'schalten' => 'miot',
            'lan_schalten' => false,  // ohne Token weiter xl_yee_befehl (E-E7); mit Token MIoT wie bisher
            'gemessen' => false,
        ),
        'yilai.light.ceiling1' => array(
            'urn'      => 'urn:miot-spec-v2:device:light:0000A001:yilai-ceiling1:1',
            'titel'    => 'Yilai LED-Deckenleuchte 480 (ceiling1)',
            'miot'     => array('ein' => array(2, 1), 'hell' => array(2, 2), 'ct' => array(2, 3)),
            'umschalt' => null,
            'ct'       => array(2700, 6500),
            'modus'    => array(),
            'szenen'   => array('lesen', 'gemuetlich'),
            'lan'      => true,       // Hausherr 28.10. "JA": Port 55443 antwortet (get_prop gemessen) - weg 2 mit Push
            'alt'      => true,       // gemessen 28.10.: get_prop ja (miIO), get_properties error -5000
            'lesen'    => 'alt',
            'schalten' => 'alt',      // gemessen 28.10.: set_power/set_bright/set_ct_abx ueber miIO -> ["ok"]
            'lan_schalten' => true,   // dieselben Altbefehle bevorzugt ueber die LAN-Verbindung (ueber LAN NICHT gemessen)
            'gemessen' => true,       // Lesen und Schalten (miIO) gemessen 28.10.; set_properties gilt als abgelehnt
        ),
    );
}

/** Tabelle eines Modells; unbekannt: Grundform des MIoT-Dienstes light (2/1 on, 2/2 brightness) mit 'bekannt' => false. */
function xl_modell($modell)
{
    $m = xl_modelle();
    if (isset($m[(string) $modell])) {
        return $m[(string) $modell] + array('bekannt' => true, 'modell' => (string) $modell);
    }
    return array('urn' => '', 'titel' => (string) $modell, 'miot' => array('ein' => array(2, 1), 'hell' => array(2, 2)),
                 'umschalt' => null, 'ct' => null, 'modus' => array(), 'szenen' => array(), 'lan' => false,
                 'alt' => false, 'lesen' => 'miot', 'schalten' => 'miot', 'lan_schalten' => false, 'gemessen' => false, 'bekannt' => false, 'modell' => (string) $modell);
}

/** Faehigkeiten einer Tabelle: array(name => bool) - Reiter Test "kann / kann nicht", Knoepfe nur fuer vorhandene. */
function xl_faehigkeiten(array $tab)
{
    return array(
        'schalten' => isset($tab['miot']['ein']),
        'hell'     => isset($tab['miot']['hell']),
        'ct'       => isset($tab['miot']['ct']),
        'nacht'    => isset($tab['miot']['modus']),
        'szene'    => !empty($tab['szenen']),
        'lan'      => !empty($tab['lan']),
    );
}

/* ==================================================================
 * MIoT ueber miIO (EIN get_properties je Lampe und Takt; EIN set_properties je Befehl)
 * ================================================================== */

/**
 * Werte lesen; fehlt ein Wert, ist sein Code != 0 oder liegt er ausserhalb der Spezifikation: -1, nie 0 (Probe P2b).
 * Rueckgabe array(lage ok|keine_antwort|token|fehler|form, Werte, error.code der Lampe bei 'fehler'). 'form': die Antwort
 * ist keine Liste (Nachbesserung 28.10.: am Geraet kam auf ein get_properties mit wiederholter id die zwischengespeicherte
 * miIO.info-Antwort zurueck - das galt bis dahin als "erreichbar" mit lauter -1).
 */
function xl_miot_lesen(array &$z, array $tab)
{
    $p = array();
    foreach ($tab['miot'] as $name => $sp) {
        $p[] = array('did' => $name, 'siid' => $sp[0], 'piid' => $sp[1]);
    }
    list($lage, $res, $code) = xl_miio_rufen($z, 'get_properties', $p);
    $w = array('ein' => -1, 'hell' => -1, 'ct' => -1, 'modus' => -1);
    if ($lage !== 'ok') {
        return array($lage, $w, (int) $code);
    }
    if (!is_array($res) || array_values($res) !== $res) {
        return array('form', $w, 0);
    }
    foreach ($res as $e) {
        if (!is_array($e) || !isset($e['did'], $e['code']) || (int) $e['code'] !== 0 || !array_key_exists('value', $e)) {
            continue;
        }
        $v = $e['value'];
        switch ((string) $e['did']) {
            case 'ein':
                if (is_bool($v)) { $w['ein'] = $v ? 1 : 0; }
                break;
            case 'hell':
                if (is_int($v) && $v >= 1 && $v <= 100) { $w['hell'] = $v; }
                break;
            case 'ct':
                if (is_int($v) && $tab['ct'] && $v >= $tab['ct'][0] && $v <= $tab['ct'][1]) { $w['ct'] = $v; }
                break;
            case 'modus':
                if (is_int($v) && isset($tab['modus'][$v])) { $w['modus'] = ($v === 2) ? 1 : 0; }
                break;
        }
    }
    if (!isset($tab['miot']['modus'])) {
        $w['modus'] = -1;
    }
    return array('ok', $w, 0);
}

/**
 * Werte einer Lampe lesen - EINE Stelle fuer Dienst und Reiter Test (Nachbesserung Geraetetest 28.10.). $lesart 'miot' |
 * 'alt' | '' (= 'lesen' aus der Tabelle). Kommt auf dem einen Weg ein Fehler der Lampe (lage 'fehler', z. B. -5000), wird
 * EINMAL der andere versucht; antwortet er, gilt fortan er (Rueckgabe Lesart). Hoechstens ein Paket je Weg, keine
 * Schleife. Rueckgabe array(lage, Werte (ein/hell/ct/modus, fehlend -1), Code, Lesart).
 */
function xl_lampe_lesen(array &$z, array $tab, $lesart = '')
{
    if ($lesart !== 'miot' && $lesart !== 'alt') {
        $lesart = (isset($tab['lesen']) && $tab['lesen'] === 'alt') ? 'alt' : 'miot';
    }
    $leer = array('ein' => -1, 'hell' => -1, 'ct' => -1, 'modus' => -1);
    $alt = function () use (&$z, $tab, $leer) {
        list($lage, $w, $code) = xl_alt_lesen($z);
        $w = is_array($w) ? array_merge($leer, $w) : $leer;
        if ($lage === 'ok' && $w['ein'] === -1) {
            $lage = 'form';        // get_prop ohne Schaltzustand: die Lampe kennt die Altbefehle nicht wirklich
        }
        if ($w['ct'] !== -1 && (!$tab['ct'] || $w['ct'] < $tab['ct'][0] || $w['ct'] > $tab['ct'][1])) {
            $w['ct'] = -1;
        }
        if (!isset($tab['miot']['modus'])) {
            $w['modus'] = -1;
        }
        return array($lage, $w, (int) $code);
    };
    if ($lesart === 'alt') {
        list($lage, $w, $code) = $alt();
        if ($lage === 'fehler') {
            list($l2, $w2) = xl_miot_lesen($z, $tab);
            if ($l2 === 'ok') {
                return array('ok', $w2, 0, 'miot');
            }
        }
        return array($lage, $w, $code, 'alt');
    }
    list($lage, $w, $code) = xl_miot_lesen($z, $tab);
    if ($lage === 'fehler') {
        list($l2, $w2) = $alt();
        if ($l2 === 'ok') {
            return array('ok', $w2, 0, 'alt');
        }
    }
    return array($lage, $w, $code, 'miot');
}

/** Grundwort fuer Protokoll, werte.json und Quittung aus einer Leselage - nie KEINE_ANTWORT, wenn die Lampe antwortete. */
function xl_lese_grund($lage, $code)
{
    if ($lage === 'token') {
        return 'TOKEN';
    }
    if ($lage === 'fehler') {
        return 'MIIO ' . (int) $code;
    }
    if ($lage === 'form') {
        return 'FORM';
    }
    return 'KEINE_ANTWORT';
}

/**
 * Einen Befehl pruefen und in MIoT-Eintraege uebersetzen - OHNE Paket, wenn ungueltig oder nicht unterstuetzt (E-E12).
 * $ist = aktueller Wert 'ein' (fuer umschalten ohne Aktion). Rueckgabe array(true, array('art' => set|aktion, 'p' => ...))
 * oder array(false, WERT|NICHT_UNTERSTUETZT|UNBEKANNT).
 */
function xl_befehl_bauen(array $tab, $cmd, $wert, $ist = -1)
{
    $m = $tab['miot'];
    $wert = is_string($wert) ? trim($wert) : (string) $wert;
    $ganz = function ($x) {
        return preg_match('/^-?[0-9]{1,5}\z/', $x) ? (int) $x : null;
    };
    $setz = function (array $liste) {
        $p = array();
        foreach ($liste as $e) {
            $p[] = array('siid' => $e[0][0], 'piid' => $e[0][1], 'value' => $e[1]);
        }
        return array(true, array('art' => 'set', 'p' => $p));
    };
    switch ((string) $cmd) {
        case 'schalten':
            if ($wert !== '0' && $wert !== '1') { return array(false, 'WERT'); }
            return $setz(array(array($m['ein'], $wert === '1')));
        case 'umschalten':
            if ($wert !== '1') { return array(false, 'WERT'); }
            if (!empty($tab['umschalt'])) {
                return array(true, array('art' => 'aktion', 'p' => array('did' => 'umschalten', 'siid' => $tab['umschalt'][0],
                                                                        'aiid' => $tab['umschalt'][1], 'in' => array())));
            }
            if ($ist !== 0 && $ist !== 1) { return array(false, 'ZUSTAND_UNBEKANNT'); }
            return $setz(array(array($m['ein'], $ist === 0)));
        case 'hell':
            $v = $ganz($wert);
            if ($v === null || $v < 0 || $v > 100) { return array(false, 'WERT'); }
            if ($v === 0) { return $setz(array(array($m['ein'], false))); }
            return $setz(array(array($m['ein'], true), array($m['hell'], $v)));
        case 'ct':
            if (!isset($m['ct'])) { return array(false, 'NICHT_UNTERSTUETZT'); }
            $v = $ganz($wert);
            if ($v === null || $v < $tab['ct'][0] || $v > $tab['ct'][1]) { return array(false, 'WERT'); }
            return $setz(array(array($m['ct'], $v)));
        case 'nacht':
            if (!isset($m['modus'])) { return array(false, 'NICHT_UNTERSTUETZT'); }
            if ($wert !== '0' && $wert !== '1') { return array(false, 'WERT'); }
            return $setz(array(array($m['ein'], true), array($m['modus'], $wert === '1' ? 2 : 1)));
        case 'szene':
            if (!in_array($wert, array('lesen', 'gemuetlich', 'nacht'), true)) { return array(false, 'WERT'); }
            if (!in_array($wert, $tab['szenen'], true)) { return array(false, 'NICHT_UNTERSTUETZT'); }
            if ($wert === 'nacht') {
                return $setz(array(array($m['ein'], true), array($m['modus'], 2)));
            }
            $liste = array(array($m['ein'], true));
            if (isset($m['modus'])) { $liste[] = array($m['modus'], 1); }
            $liste[] = array($m['hell'], $wert === 'lesen' ? 100 : 30);
            if (isset($m['ct'])) { $liste[] = array($m['ct'], $wert === 'lesen' ? 4000 : 2700); }
            return $setz($liste);
    }
    return array(false, 'UNBEKANNT');
}

/**
 * Einen gebauten Befehl senden. $tmo = Frist je Paket (der Dienst nimmt 1,2 s: hoechstens 2 Pakete und ein Neugruss -
 * die Bruecke wartet hoechstens 6 s, E-E12). Rueckgabe array(ok bool, INFO-Wort: 'MIOT 0' | 'MIOT <code>' | 'MIIO <code>' | ...).
 */
function xl_miot_befehl(array &$z, array $gebaut, $tmo = 2.0)
{
    if ($gebaut['art'] === 'aktion') {
        list($lage, $res, $code) = xl_miio_rufen($z, 'action', $gebaut['p'], $tmo, 2);
    } else {
        $p = array();
        foreach ($gebaut['p'] as $i => $e) {
            $p[] = array('did' => 'b' . $i) + $e;
        }
        list($lage, $res, $code) = xl_miio_rufen($z, 'set_properties', $p, $tmo, 2);
    }
    if ($lage === 'keine_antwort') { return array(false, 'KEINE_ANTWORT'); }
    if ($lage === 'token') { return array(false, 'TOKEN'); }
    if ($lage !== 'ok') { return array(false, 'MIIO ' . (int) $code); }
    $liste = ($gebaut['art'] === 'aktion') ? array(is_array($res) ? $res : array()) : (array) $res;
    foreach ($liste as $e) {
        if (!is_array($e) || !isset($e['code']) || !in_array((int) $e['code'], array(0, 1), true)) {
            return array(false, 'MIOT ' . ((is_array($e) && isset($e['code'])) ? (int) $e['code'] : '?'));
        }
    }
    return array(true, 'MIOT 0');
}

/**
 * Steckbrief aus miIO.info: array(lage, array('modell', 'fw')) - Token, MAC und Kennung der Antwort werden verworfen
 * (N8, Probe P1). Modelltext nur in der Form <hersteller>.<art>.<name>.
 */
function xl_miio_info(array &$z)
{
    list($lage, $res) = xl_miio_rufen($z, 'miIO.info', array());
    if ($lage !== 'ok' || !is_array($res)) {
        return array($lage, null);
    }
    $mod = (isset($res['model']) && is_string($res['model']) && preg_match('/^[a-z0-9_-]{1,32}(\.[a-z0-9_-]{1,32}){2,3}\z/', $res['model']))
        ? $res['model'] : '';
    $fw = (isset($res['fw_ver']) && is_string($res['fw_ver']) && preg_match('/^[A-Za-z0-9._-]{1,32}\z/', $res['fw_ver']))
        ? $res['fw_ver'] : '';
    unset($res);
    return array('ok', array('modell' => $mod, 'fw' => $fw));
}

/** Schluessel des Modellzwischenspeichers: aendert sich mit Adresse oder Token; kein Geheimnis darin (Einweg). */
function xl_modell_merkmal(array $l)
{
    return substr(hash('sha256', 'xl-modell|' . $l['ip'] . '|' . $l['token']), 0, 16);
}

/**
 * Das Modell einer Lampe: aus dem Zwischenspeicher data/.../modell_<n>.json (24 h, Merkmal aus Adresse und Token),
 * sonst aus miIO.info ($z = offene Sitzung oder null), sonst das eingetragene Modell (Cloud/Suche/Hand).
 * Rueckgabe array(modell, fw, quelle: speicher|lampe|zeile|keins).
 * $z als REFERENZ (Nachbesserung 28.10.): als Kopie zaehlte miIO.info die Laufnummer nur in der Kopie hoch - das naechste
 * Paket der Sitzung trug dieselbe id, und die Lampe antwortete darauf mit der zwischengespeicherten miIO.info-Antwort.
 */
function xl_modell_holen(array $l, &$z = null)
{
    $f = xl_paths()['datadir'] . '/modell_' . (int) $l['nr'] . '.json';
    $m = xl_json_lesen($f);
    $merk = xl_modell_merkmal($l);
    if ($m && isset($m['merkmal'], $m['zeit'], $m['modell']) && $m['merkmal'] === $merk
        && time() - (int) $m['zeit'] < XL_MODELL_ALTER && (int) $m['zeit'] <= time() + 60 && $m['modell'] !== '') {
        return array((string) $m['modell'], isset($m['fw']) ? (string) $m['fw'] : '', 'speicher');
    }
    if (is_array($z) && isset($z['s']) && $z['s']) {
        list($lage, $info) = xl_miio_info($z);
        if ($lage === 'ok' && $info['modell'] !== '') {
            if (is_dir(dirname($f))) {
                xl_json_schreiben($f, array('merkmal' => $merk, 'zeit' => time(), 'modell' => $info['modell'], 'fw' => $info['fw']), 0644);
            }
            return array($info['modell'], $info['fw'], 'lampe');
        }
    }
    if ($l['modell'] !== '') {
        return array($l['modell'], '', 'zeile');
    }
    return array('', '', 'keins');
}

/** miIO-Altbefehle get_prop power bright ct active_mode nl_br: Leseweg 'alt' (ceiling1, gemessen 28.10.) und Reiter Test. */
function xl_alt_lesen(array &$z)
{
    list($lage, $res, $code) = xl_miio_rufen($z, 'get_prop', array('power', 'bright', 'ct', 'active_mode', 'nl_br'));
    if ($lage !== 'ok' || !is_array($res) || count($res) !== 5) {
        return array($lage === 'ok' ? 'form' : $lage, null, $code);
    }
    foreach ($res as $r) {
        if (!is_string($r)) {
            return array('form', null, 0);
        }
    }
    return array('ok', xl_yee_abbilden(array('power' => $res[0], 'bright' => $res[1], 'ct' => $res[2],
                                             'active_mode' => $res[3], 'nl_br' => $res[4])), 0);
}

/**
 * Nachbesserung 2 (28.10.): einen Befehl in miIO-Altbefehle uebersetzen - NUR die am Geraet gemessenen set_power,
 * set_bright und set_ct_abx (ceiling1: smooth 500, Antwort ["ok"]). Kein toggle, kein set_scene (nicht gemessen).
 * set_bright/set_ct_abx wirken laut Yeelight-Spezifikation 4.1 nur bei eingeschalteter Lampe: ist 'ein' nicht als 1
 * bekannt, geht set_power on voraus. Alle Schritte sind absolute Sollwerte (eine Wiederholung schadet nicht).
 * $werte = aktueller Zustand (ein/hell/ct/modus, -1 unbekannt). Rueckgabe array(true, Liste array(methode, params))
 * oder array(false, WERT|NICHT_UNTERSTUETZT|ZUSTAND_UNBEKANNT|UNBEKANNT) - ohne Paket (E-E12).
 */
function xl_alt_folge(array $tab, $cmd, $wert, array $werte = array())
{
    $wert = is_string($wert) ? trim($wert) : (string) $wert;
    $ein = isset($werte['ein']) ? (int) $werte['ein'] : -1;
    $an = array('set_power', array('on', 'smooth', 500));
    $aus = array('set_power', array('off', 'smooth', 500));
    $vor = ($ein === 1) ? array() : array($an);
    $ganz = preg_match('/^[0-9]{1,5}\z/', $wert) ? (int) $wert : null;
    switch ((string) $cmd) {
        case 'schalten':
            if ($wert !== '0' && $wert !== '1') { return array(false, 'WERT'); }
            return array(true, array($wert === '1' ? $an : $aus));
        case 'umschalten':
            if ($wert !== '1') { return array(false, 'WERT'); }
            if ($ein !== 0 && $ein !== 1) { return array(false, 'ZUSTAND_UNBEKANNT'); }
            return array(true, array($ein === 1 ? $aus : $an));
        case 'hell':
            if ($ganz === null || $ganz > 100) { return array(false, 'WERT'); }
            if ($ganz === 0) { return array(true, array($aus)); }
            return array(true, array_merge($vor, array(array('set_bright', array($ganz, 'smooth', 500)))));
        case 'ct':
            if (empty($tab['ct'])) { return array(false, 'NICHT_UNTERSTUETZT'); }
            if ($ganz === null || $ganz < $tab['ct'][0] || $ganz > $tab['ct'][1]) { return array(false, 'WERT'); }
            return array(true, array_merge($vor, array(array('set_ct_abx', array($ganz, 'smooth', 500)))));
        case 'nacht':
            if (empty($tab['modus'])) { return array(false, 'NICHT_UNTERSTUETZT'); }
            if ($wert !== '0' && $wert !== '1') { return array(false, 'WERT'); }
            return array(true, array(array('set_power', array('on', 'smooth', 500, $wert === '1' ? 5 : 1))));
        case 'szene':
            if (!in_array($wert, array('lesen', 'gemuetlich', 'nacht'), true)) { return array(false, 'WERT'); }
            if (!in_array($wert, $tab['szenen'], true)) { return array(false, 'NICHT_UNTERSTUETZT'); }
            if ($wert === 'nacht') {
                return array(true, array(array('set_power', array('on', 'smooth', 500, 5))));
            }
            $f = $vor;
            $f[] = array('set_bright', array($wert === 'lesen' ? 100 : 30, 'smooth', 500));
            if (!empty($tab['ct'])) {
                $f[] = array('set_ct_abx', array($wert === 'lesen' ? 4000 : 2700, 'smooth', 500));
            }
            return array(true, $f);
    }
    return array(false, 'UNBEKANNT');
}

/**
 * Eine Folge von Altbefehlen ueber miIO senden (Frist je Paket $tmo, hoechstens 2 Pakete, gemeinsame Frist $bis).
 * Erfolg nur bei result ["ok"] (gemessen). Rueckgabe array(ok, INFO 'MIIO 0' | 'MIIO <code>' | 'FORM' | 'KEINE_ANTWORT'
 * | 'TOKEN' | 'FRIST', abgelehnt_vorab: true, wenn schon der ERSTE Schritt von der Lampe abgelehnt wurde - dann ist
 * nichts geschehen und der Dienst darf den anderen Weg versuchen).
 */
function xl_alt_befehl(array &$z, array $folge, $tmo = 1.2, $bis = 0.0)
{
    foreach ($folge as $i => $f) {
        if ($bis > 0.0 && microtime(true) + 0.2 > $bis) {
            return array(false, 'FRIST', false);
        }
        list($lage, $res, $code) = xl_miio_rufen($z, $f[0], $f[1], $tmo, 2);
        if ($lage === 'keine_antwort') { return array(false, 'KEINE_ANTWORT', false); }
        if ($lage === 'token') { return array(false, 'TOKEN', false); }
        if ($lage !== 'ok') { return array(false, 'MIIO ' . (int) $code, $i === 0); }
        if ($res !== array('ok')) { return array(false, 'FORM', false); }
    }
    return array(true, 'MIIO 0', false);
}

/**
 * Dieselbe Folge ueber die gehaltene Yeelight-LAN-Verbindung (Spezifikation 4: Antwort {"id":..,"result":["ok"]},
 * Fehler {"id":..,"error":{"code":..,"message":..}}). props, die waehrend der Antworten eintreffen, werden mitgeliefert -
 * sie sind die sofortige Rueckmeldung. Rueckgabe array(ok, INFO 'LAN 0' | 'LAN <code>' | 'LAN_ZU' | 'KEINE_ANTWORT' |
 * 'FORM' | 'FRIST', vorab: ERSTER Schritt ohne Wirkung gescheitert (abgelehnt, zu oder still) - dann darf miIO folgen,
 * Liste props, Anzahl gesendeter Zeilen fuer die Befehlsquote).
 */
function xl_yee_folge(array &$v, array $folge, $tmo = 1.0, $bis = 0.0)
{
    $props = array();
    $n = 0;
    foreach ($folge as $i => $f) {
        if ($bis > 0.0 && microtime(true) + 0.2 > $bis) {
            return array(false, 'FRIST', false, $props, $n);
        }
        if (!$v['offen']) {
            return array(false, 'LAN_ZU', $i === 0, $props, $n);
        }
        list($lage, $r, $pp) = xl_yee_rufen($v, $f[0], $f[1], $tmo);
        $n++;
        $props = array_merge($props, $pp);
        if ($lage === 'zu') { return array(false, 'LAN_ZU', $i === 0, $props, $n); }
        if ($lage === 'keine_antwort') { return array(false, 'KEINE_ANTWORT', $i === 0, $props, $n); }
        if ($lage === 'fehler') {
            $c = (is_array($r) && isset($r['code'])) ? (int) $r['code'] : 0;
            return array(false, 'LAN ' . $c, $i === 0, $props, $n);
        }
        if ($r !== array('ok')) { return array(false, 'FORM', false, $props, $n); }
    }
    /* Push, der direkt nach dem letzten "ok" kommt, noch mitnehmen (hoechstens 0,15 s). */
    foreach (xl_yee_lesen($v, 0.15) as $m) {
        if ($m['art'] === 'props') {
            $props[] = $m['p'];
        }
    }
    return array(true, 'LAN 0', false, $props, $n);
}

/* ==================================================================
 * Yeelight-LAN (TCP 55443, JSON-Zeilen) - Rueckmeldeweg (weg 2) und Befehlsweg OHNE Token (E-E7); mit Token nur fuer
 * Modelle mit 'lan_schalten' (ceiling1, Nachbesserung 2: xl_yee_folge mit den gemessenen Altbefehlen)
 * ================================================================== */

/** Verbindung; null = abgewiesen/zu. Das Plugin haelt EINE von vier moeglichen Verbindungen je Lampe. */
function xl_yee_verbinden($ip, $port, $tmo = 1.0)
{
    $en = 0;
    $es = '';
    $s = @stream_socket_client('tcp://' . $ip . ':' . (int) $port, $en, $es, $tmo);
    if ($s === false) {
        return null;
    }
    stream_set_blocking($s, false);
    return array('s' => $s, 'puf' => '', 'id' => mt_rand(1, 8999), 'offen' => true, 'seit' => time());
}

function xl_yee_schliessen(&$v)
{
    if (is_array($v) && isset($v['s']) && is_resource($v['s'])) {
        @fclose($v['s']);
    }
    $v = null;
}

function xl_yee_senden(array &$v, $methode, array $params)
{
    $id = $v['id'];
    $v['id'] = $v['id'] % 9999 + 1;
    $z = json_encode(array('id' => $id, 'method' => $methode, 'params' => $params), JSON_UNESCAPED_SLASHES) . "\r\n";
    $n = @fwrite($v['s'], $z);
    if ($n !== strlen($z)) {
        $v['offen'] = false;
    }
    return $id;
}

/**
 * Zeilen lesen, hoechstens $tmo s. Rueckgabe Liste von array('art' => ergebnis|props|kaputt, ...). Eine Zeile ohne
 * \r\n bleibt im Puffer (Grenze 16 kB). Hat die Gegenstelle geschlossen: $v['offen'] = false.
 */
function xl_yee_lesen(array &$v, $tmo)
{
    $aus = array();
    $bis = microtime(true) + $tmo;
    while (true) {
        while (($p = strpos($v['puf'], "\r\n")) !== false) {
            $zeile = substr($v['puf'], 0, $p);
            $v['puf'] = (string) substr($v['puf'], $p + 2);
            $j = json_decode($zeile, true);
            if (!is_array($j)) {
                $aus[] = array('art' => 'kaputt');
                continue;
            }
            if (isset($j['method']) && $j['method'] === 'props' && isset($j['params']) && is_array($j['params'])) {
                $aus[] = array('art' => 'props', 'p' => $j['params']);
            } elseif (isset($j['id'])) {
                $aus[] = array('art' => 'ergebnis', 'id' => (int) $j['id'], 'ok' => isset($j['result']),
                               'r' => isset($j['result']) ? $j['result'] : (isset($j['error']) ? $j['error'] : null));
            } else {
                $aus[] = array('art' => 'kaputt');
            }
        }
        if (strlen($v['puf']) > 16384) {
            $v['puf'] = '';
            $v['offen'] = false;
            return $aus;
        }
        if ($aus || !$v['offen']) {
            return $aus;
        }
        /* Mindestens EIN Blick auf den Socket, auch mit $tmo = 0 (der Dienst ruft nach stream_select mit 0 auf). */
        $rest = max(0.0, $bis - microtime(true));
        $r = array($v['s']);
        $w = null;
        $e = null;
        $n = @stream_select($r, $w, $e, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        if ($n === false) {
            $v['offen'] = false;
            return $aus;
        }
        if ($n === 0) {
            if ($bis - microtime(true) <= 0) {
                return $aus;
            }
            continue;
        }
        $d = @fread($v['s'], 4096);
        if ($d === false || $d === '') {
            if (!is_resource($v['s']) || feof($v['s'])) {
                $v['offen'] = false;
            }
            return $aus;
        }
        $v['puf'] .= $d;
    }
}

/** props/get_prop (alle Werte Text, Spezifikation 4.3) -> Plugin-Werte; nur passende Form, Unbekanntes bleibt weg. */
function xl_yee_abbilden(array $p)
{
    $w = array();
    $t = function ($k) use ($p) {
        return (isset($p[$k]) && is_scalar($p[$k])) ? (string) $p[$k] : null;
    };
    $power = $t('power');
    if ($power !== null) {
        $w['ein'] = $power === 'on' ? 1 : ($power === 'off' ? 0 : -1);
    }
    $am = $t('active_mode');
    if ($am !== null && $am !== '') {
        $w['modus'] = $am === '1' ? 1 : ($am === '0' ? 0 : -1);
    }
    $br = $t('bright');
    if ($br !== null && $am !== '1') {
        $w['hell'] = (preg_match('/^[0-9]{1,3}\z/', $br) && (int) $br >= 1 && (int) $br <= 100) ? (int) $br : -1;
    }
    $nl = $t('nl_br');
    if ($am === '1' && $nl !== null) {
        $w['hell'] = (preg_match('/^[0-9]{1,3}\z/', $nl) && (int) $nl >= 1 && (int) $nl <= 100) ? (int) $nl : -1;
    }
    $ct = $t('ct');
    if ($ct !== null) {
        $w['ct'] = (preg_match('/^[0-9]{4}\z/', $ct) && (int) $ct >= 1700 && (int) $ct <= 6500) ? (int) $ct : -1;
    }
    return $w;
}

/** Ein Aufruf ueber LAN, Antwort mit derselben id abwarten; props dazwischen werden mitgeliefert. */
function xl_yee_rufen(array &$v, $methode, array $params, $tmo = 2.0)
{
    $id = xl_yee_senden($v, $methode, $params);
    $props = array();
    $bis = microtime(true) + $tmo;
    while (($rest = $bis - microtime(true)) > 0 && $v['offen']) {
        foreach (xl_yee_lesen($v, $rest) as $m) {
            if ($m['art'] === 'props') {
                $props[] = $m['p'];
            }
            if ($m['art'] === 'ergebnis' && $m['id'] === $id) {
                return array($m['ok'] ? 'ok' : 'fehler', $m['r'], $props);
            }
        }
    }
    return array($v['offen'] ? 'keine_antwort' : 'zu', null, $props);
}

/**
 * Ein Befehl ueber LAN (nur ohne Token, ceiling3; E-E7). set_bright/set_ct_abx wirken nur bei eingeschalteter Lampe
 * (Spezifikation 4.1, N10) - "hell n" geht deshalb als set_scene ["ct", <ct>, n] (schaltet zugleich ein), ct als
 * set_power on + set_ct_abx. Rueckgabe array(ok, INFO-Wort).
 */
function xl_yee_befehl(array &$v, $cmd, $wert, array $werte)
{
    $ctjetzt = (isset($werte['ct']) && (int) $werte['ct'] >= 2700) ? (int) $werte['ct'] : 4000;
    $folge = array();
    switch ($cmd) {
        case 'schalten':
            $folge[] = array('set_power', array($wert === '1' ? 'on' : 'off', 'smooth', 300));
            break;
        case 'umschalten':
            $folge[] = array('toggle', array());
            break;
        case 'hell':
            $folge[] = ((int) $wert === 0) ? array('set_power', array('off', 'smooth', 300))
                : array('set_scene', array('ct', $ctjetzt, (int) $wert));
            break;
        case 'ct':
            $folge[] = array('set_power', array('on', 'smooth', 300));
            $folge[] = array('set_ct_abx', array((int) $wert, 'smooth', 300));
            break;
        case 'nacht':
            $folge[] = array('set_power', array('on', 'smooth', 300, $wert === '1' ? 5 : 1));
            break;
        case 'szene':
            if ($wert === 'nacht') {
                $folge[] = array('set_power', array('on', 'smooth', 300, 5));
            } else {
                $folge[] = array('set_scene', array('ct', $wert === 'lesen' ? 4000 : 2700, $wert === 'lesen' ? 100 : 30));
            }
            break;
        default:
            return array(false, 'UNBEKANNT');
    }
    foreach ($folge as $f) {
        list($lage, $r) = xl_yee_rufen($v, $f[0], $f[1], 2.0);
        if ($lage !== 'ok') {
            return array(false, $lage === 'zu' ? 'LAN_ZU' : ($lage === 'keine_antwort' ? 'KEINE_ANTWORT' : 'LAN_FEHLER'));
        }
    }
    return array(true, 'LAN 0');
}

/* ==================================================================
 * Geraetesuche (nur auf Knopfdruck, E-E13; nie im Dienst, nie beim Seitenaufruf)
 * ================================================================== */

/**
 * Ein Datagramm an $ziel:$port schicken und Antworten $tmo s sammeln - ueber einen UNVERBUNDENEN Socket (bind auf
 * 0.0.0.0:0, sendto/recvfrom): ein verbundener UDP-Socket nimmt nur Antworten der Zieladresse an, die Lampen antworten
 * aber von ihrer eigenen Adresse auf Multicast bzw. Broadcast. Rueckgabe Liste von array(Absenderadresse, Daten).
 */
function xl_udp_sammeln($ziel, $port, $nutz, $tmo, $broadcast = false)
{
    $ctx = stream_context_create(array('socket' => array('so_broadcast' => (bool) $broadcast)));
    $en = 0;
    $es = '';
    $s = @stream_socket_server('udp://0.0.0.0:0', $en, $es, STREAM_SERVER_BIND, $ctx);
    if ($s === false) {
        return array();
    }
    if (@stream_socket_sendto($s, $nutz, 0, $ziel . ':' . (int) $port) === false) {
        @fclose($s);
        return array();
    }
    $aus = array();
    $bis = microtime(true) + $tmo;
    while (($rest = $bis - microtime(true)) > 0 && count($aus) < 64) {
        $r = array($s);
        $w = null;
        $e = null;
        $n = @stream_select($r, $w, $e, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        if ($n === false || $n === 0) {
            break;
        }
        $von = '';
        $d = @stream_socket_recvfrom($s, 4096, 0, $von);
        if (!is_string($d) || $d === '') {
            continue;
        }
        $ip = preg_replace('/:[0-9]+$/', '', (string) $von);
        if (preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}\z/', $ip)) {
            $aus[] = array($ip, $d);
        }
    }
    @fclose($s);
    return $aus;
}

/** Yeelight-Suche: M-SEARCH an $ziel:$port (im Plugin 239.255.255.250:1982, Spezifikation 3.1). Ohne Kennung (N8). */
function xl_ssdp_suchen($ziel, $port, $tmo = 2.0)
{
    $gef = array();
    $frage = "M-SEARCH * HTTP/1.1\r\nHOST: " . XL_SSDP_ADRESSE . ':' . XL_SSDP_PORT
        . "\r\nMAN: \"ssdp:discover\"\r\nST: wifi_bulb\r\n";
    foreach (xl_udp_sammeln($ziel, $port, $frage, $tmo, false) as $a) {
        $h = array();
        foreach (preg_split('/\r\n/', $a[1]) as $z) {
            if (preg_match('/^([A-Za-z_-]+):\s*(.*)$/', $z, $m)) {
                $h[strtolower($m[1])] = trim($m[2]);
            }
        }
        if (isset($h['location']) && preg_match('#^yeelight://([0-9]{1,3}(?:\.[0-9]{1,3}){3}):([0-9]{1,5})$#', $h['location'], $m)) {
            $mod = (isset($h['model']) && preg_match('/^[A-Za-z0-9_-]{1,32}\z/', $h['model'])) ? $h['model'] : '';
            $gef[$m[1]] = array('ip' => $m[1], 'port' => (int) $m[2], 'weg' => 'lan', 'modell' => $mod);
        }
    }
    return array_values($gef);
}

/**
 * miIO-Suche: Gruss an $ziel (im Plugin die Broadcast-Adresse des Heimnetzes, socket.so_broadcast). Jede miIO-Lampe
 * antwortet mit Kennung + Stempel (ohne Modell). Zurueck nur die Absenderadressen - keine Kennung (N8).
 */
function xl_miio_suchen($ziel, $port, $tmo = 2.0)
{
    $gef = array();
    foreach (xl_udp_sammeln($ziel, $port, pack('nn', 0x2131, 0x0020) . str_repeat("\xff", 28), $tmo, true) as $a) {
        if (strlen($a[1]) === 32 && substr($a[1], 0, 2) === "\x21\x31") {
            $gef[$a[0]] = array('ip' => $a[0], 'weg' => 'miio', 'modell' => '');
        }
    }
    return array_values($gef);
}

/** Broadcast-Adresse des LoxBerry-Netzes: die eigene IPv4 mit /24 (die Maske ist ohne Hilfsprogramm nicht lesbar). */
function xl_broadcast_adresse()
{
    $ip = @gethostbyname((string) gethostname());
    if (!is_string($ip) || !preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\.[0-9]{1,3}\z/', $ip, $m) || strpos($ip, '127.') === 0) {
        return '255.255.255.255';
    }
    return $m[1] . '.255';
}

/**
 * Die Suche fuer die Oberflaeche: beide Wege, zusammengefuehrt nach Adresse, mit "schon eingetragen".
 * $ziele (nur Proben): array('ssdp' => array(ip, port), 'miio' => array(ip, port)).
 */
function xl_suche($ziele = null)
{
    if ($ziele === null) {
        $ziele = array('ssdp' => array(XL_SSDP_ADRESSE, XL_SSDP_PORT), 'miio' => array(xl_broadcast_adresse(), XL_MIIO_PORT));
    }
    $alle = array();
    foreach (xl_ssdp_suchen($ziele['ssdp'][0], $ziele['ssdp'][1]) as $g) {
        $alle[$g['ip']] = array('ip' => $g['ip'], 'lan' => 1, 'miio' => 0, 'modell' => $g['modell']);
    }
    foreach (xl_miio_suchen($ziele['miio'][0], $ziele['miio'][1]) as $g) {
        if (!isset($alle[$g['ip']])) {
            $alle[$g['ip']] = array('ip' => $g['ip'], 'lan' => 0, 'miio' => 1, 'modell' => '');
        } else {
            $alle[$g['ip']]['miio'] = 1;
        }
    }
    $eingetragen = array();
    foreach (xl_lampen() as $l) {
        $eingetragen[$l['ip']] = true;
    }
    foreach ($alle as $ip => $g) {
        $alle[$ip]['eingetragen'] = isset($eingetragen[$ip]) ? 1 : 0;
    }
    ksort($alle);
    return array_values($alle);
}


/* ==================================================================
 * Zustand des Dienstes (data/plugins/<ordner>/: werte.json, lauf.json - schreibt nur der Dienst)
 * ================================================================== */

/** Werte je Lampe aus dem letzten Dienstlauf: nr => {name, ein, hell, ct, modus, erreichbar, weg, fehl, ts, modell, grund}. */
function xl_werte()
{
    $d = xl_json_lesen(xl_paths()['datadir'] . '/werte.json');
    $l = (isset($d['lampen']) && is_array($d['lampen'])) ? $d['lampen'] : array();
    $aus = array();
    foreach ($l as $nr => $w) {
        if (is_array($w) && (int) $nr >= 1) {
            $aus[(int) $nr] = $w;
        }
    }
    ksort($aus);
    return $aus;
}

/** lauf.json: {ts, zaehler, start, pid} - das Takt-Herz des Dienstes (alle 30 s). */
function xl_lauf()
{
    return xl_json_lesen(xl_paths()['datadir'] . '/lauf.json');
}

/** Alter des letzten Takts in Sekunden, -1 wenn es keinen gab ("ts": 0 heisst "noch nie"). */
function xl_dienst_lebenszeichen()
{
    $z = xl_lauf();
    $ts = isset($z['ts']) ? (int) $z['ts'] : 0;
    return $ts > 0 ? max(0, time() - $ts) : -1;
}

/** Rundenzaehler 0..999 (status/zaehler), -1 solange keiner da ist. */
function xl_zaehler()
{
    $z = xl_lauf();
    return (isset($z['zaehler']) && is_numeric($z['zaehler']) && (int) $z['zaehler'] >= 0) ? ((int) $z['zaehler']) % 1000 : -1;
}

/**
 * PID des Dienstes - argumentweise und gegen den vollen Pfad geprueft (Regeln/03, Bauart gv_dienst_pid()): genau zwei
 * Argumente (php, <bindir>/xl_dienst.php). 0 = kein Dienst.
 */
function xl_dienst_pid()
{
    $f = xl_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $erwartet = xl_paths()['bindir'] . '/xl_dienst.php';
    $args = explode("\0", rtrim((string) @file_get_contents('/proc/' . $pid . '/cmdline'), "\0"));
    if (count($args) !== 2 || $args[1] === '' || !preg_match('#(^|/)php[0-9.]*$#', $args[0])) {
        return 0;
    }
    $ziel = $args[1];
    if (substr($ziel, 0, 1) !== '/') {
        $wd = @readlink('/proc/' . $pid . '/cwd');
        if (!is_string($wd) || $wd === '') {
            return 0;
        }
        $ziel = preg_replace('/ \(deleted\)$/', '', $wd) . '/' . $ziel;
    }
    if ($ziel !== $erwartet) {
        $r1 = @realpath($ziel);
        $r2 = @realpath($erwartet);
        if ($r1 === false || $r2 === false || $r1 !== $r2) {
            return 0;
        }
    }
    return $pid;
}

/**
 * Laeuft der Dienst? Gefragt wird seine SPERRE (dienst.sperre, flock solange er lebt), nicht PID-Datei oder Port
 * (Bauart gv_dienst_laeuft()). Nicht blockierend, sofort wieder frei; die Datei wird nie angelegt. 1 laeuft, 0 nicht.
 */
function xl_dienst_laeuft()
{
    $f = xl_paths()['datadir'] . '/dienst.sperre';
    if (!is_file($f)) {
        return 0;
    }
    $h = @fopen($f, 'r');
    if ($h === false) {
        return 0;
    }
    $blockiert = 0;
    $frei = @flock($h, LOCK_EX | LOCK_NB, $blockiert);
    if ($frei) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return (!$frei && $blockiert) ? 1 : 0;
}

function xl_dienst_soll()
{
    return is_file(xl_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/**
 * Marke "Aktualisierung laeuft" (data/plugins/<ordner>.upgrade_laeuft, neben dem Datenordner). Rueckgabe array(liegt,
 * gueltig, alter). Grenzen wie bin/dienst.sh marke_sperrt(): 3600 s Alter, 300 s Vorlauf aus der Zukunft.
 */
function xl_upgrade_marke()
{
    $p = xl_paths();
    if ($p['home'] === '') {
        return array(0, 0, -1);
    }
    $f = $p['home'] . '/data/plugins/' . $p['plugin'] . '.upgrade_laeuft';
    if (!is_file($f)) {
        return array(0, 0, -1);
    }
    $roh = trim((string) @file_get_contents($f));
    if ($roh === '' || !preg_match('/^[0-9]+$/', $roh)) {
        return array(1, 0, -1);
    }
    $alter = time() - (int) $roh;
    if ($alter < -300 || $alter > 3600) {
        return array(1, 0, $alter);
    }
    return array(1, 1, max(0, $alter));
}

/** $befehl start|stop|restart ueber bin/dienst.sh. Rueckgabe array(ok, Ausgabe). */
function xl_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = xl_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden.');
    }
    $ausgabe = array();
    $code = 0;
    @exec('/bin/bash ' . escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** Eine Sperre je Lampe um den Geraetezugriff (Regeln/03): Dienst und Reiter Test oeffnen nie gleichzeitig eine miIO-Sitzung. */
function xl_lampen_sperre($nr, $warten_s = 6)
{
    $d = xl_paths()['datadir'];
    if (!is_dir($d)) {
        return null;
    }
    $h = @fopen($d . '/lampe_' . (int) $nr . '.sperre', 'c');
    if ($h === false) {
        return null;
    }
    $bis = microtime(true) + $warten_s;
    do {
        if (@flock($h, LOCK_EX | LOCK_NB)) {
            return $h;
        }
        usleep(100000);
    } while (microtime(true) < $bis);
    fclose($h);
    return false;
}

function xl_lampen_sperre_frei($h)
{
    if (is_resource($h)) {
        @flock($h, LOCK_UN);
        @fclose($h);
    }
}

/* ==================================================================
 * MQTT-Gateway V2 (Namenstabelle, V2-Hakenliste, ok/zaehler Noncached, -1, Lebenszeichen status/*)
 * ================================================================== */

/**
 * Die Themenliste - EINE Quelle fuer Sendecode, Namenstabelle, V2-Hakenliste, Abraeumen und die Pruefzeile "Themenliste
 * gegen Sendecode" (Entwurf 2.5). lampeN = je Lampe. Felder: Thema, Sprachschluessel, Bereich, Fehlwert, retain,
 * V2-Haken, Noncached, im Musterprojekt.
 */
function xl_mqtt_themen()
{
    return array(
        array('t' => 'status/zaehler',    'k' => 'MQTT.T_ZAEHLER',    'b' => "0\u{2026}999",  'f' => '',   'r' => false, 'v2' => true,  'nc' => true,  'm' => true),
        array('t' => 'status/ok',         'k' => 'MQTT.T_OK',         'b' => '0/1',           'f' => '',   'r' => false, 'v2' => true,  'nc' => true,  'm' => true),
        array('t' => 'status/ts',         'k' => 'MQTT.T_TS',         'b' => 'Unix s',        'f' => '',   'r' => false, 'v2' => false, 'nc' => false, 'm' => false),
        array('t' => 'lampeN/erreichbar', 'k' => 'MQTT.T_ERREICHBAR', 'b' => '0/1',           'f' => '',   'r' => false, 'v2' => true,  'nc' => false, 'm' => true),
        array('t' => 'lampeN/weg',        'k' => 'MQTT.T_WEG',        'b' => '0/1/2',         'f' => '',   'r' => false, 'v2' => true,  'nc' => false, 'm' => true),
        array('t' => 'lampeN/ein',        'k' => 'MQTT.T_EIN',        'b' => '0/1',           'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false, 'm' => true),
        array('t' => 'lampeN/hell',       'k' => 'MQTT.T_HELL',       'b' => "1\u{2026}100 %", 'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false, 'm' => true),
        array('t' => 'lampeN/ct',         'k' => 'MQTT.T_CT',         'b' => "2700\u{2026}6500 K", 'f' => '-1', 'r' => true, 'v2' => true, 'nc' => false, 'm' => true),
        array('t' => 'lampeN/modus',      'k' => 'MQTT.T_MODUS',      'b' => '0/1',           'f' => '-1', 'r' => true,  'v2' => true,  'nc' => false, 'm' => true),
        array('t' => 'status/befehle',    'k' => 'MQTT.T_BEFEHLE',    'b' => "-1\u{2026}1",   'f' => '',   'r' => false, 'v2' => true,  'nc' => false, 'm' => true),
    );
}

/** Der Name, unter dem das MQTT-Gateway ein Thema an den Miniserver gibt: "/", "%" und Leerzeichen werden "_". */
function xl_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', XL_PRAEFIX . '/' . (string) $thema);
}

/** Geht ein Thema zurueckbehalten hinaus? Zustaende ein/hell/ct/modus ja; Dienstaussagen nie (Regeln/07). */
function xl_mqtt_retain($thema)
{
    $basis = preg_replace('#^lampe([0-9]+|N)/#', 'lampeN/', (string) $thema);
    foreach (xl_mqtt_themen() as $z) {
        if ($z['t'] === $basis) {
            return $z['r'];
        }
    }
    return false;
}

/**
 * Die Paare einer Runde - EINE Stelle fuer Dienst und Pruefzeile. $werte = nr => Werte des Dienstes, $kopf = zaehler, ok.
 * Nicht erreichbar: erreichbar 0, weg 0 und ein/hell/ct/modus -1 (E-E9). status/befehle sendet die Bruecke.
 */
function xl_mqtt_paare(array $werte, array $kopf, $jetzt)
{
    $paare = array(
        'status/zaehler' => (int) $kopf['zaehler'],
        'status/ok'      => (int) $kopf['ok'],
        'status/ts'      => (int) $jetzt,
    );
    foreach ($werte as $nr => $w) {
        $p = 'lampe' . (int) $nr . '/';
        $da = !empty($w['erreichbar']);
        $paare[$p . 'erreichbar'] = $da ? 1 : 0;
        $paare[$p . 'weg'] = $da ? (int) (isset($w['weg']) ? $w['weg'] : 0) : 0;
        foreach (array('ein', 'hell', 'ct', 'modus') as $k) {
            $paare[$p . $k] = ($da && isset($w[$k]) && is_int($w[$k])) ? $w[$k] : -1;
        }
    }
    return $paare;
}

/** Zustand des MQTT-Gateways aus config/system/general.json (Fassung, UDP-Eingang, Autostart). */
function xl_mqtt_zustand()
{
    $p = xl_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = xl_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        return isset($m[$gross]) ? $m[$gross] : (isset($m[$klein]) ? $m[$klein] : '');
    };
    return array(
        'gefunden'  => 1,
        'autostart' => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'fassung'   => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'   => (int) $hol('Udpinport', 'udpinport'),
    );
}

/**
 * Paare ueber den UDP-Eingang des Gateways (127.0.0.1) senden - "retain" fuer Zustaende, "publish" fuer Dienstaussagen;
 * ab dem zweiten Datagramm 5 ms Pause (Entwurf 2.5; der Eingang verliert Stoesse). $leeren: Themen mit leerer Nutzlast
 * (Abraeumen). Rueckgabe Zahl der Datagramme oder -1 (kein Port / Socket).
 */
function xl_mqtt_senden(array $paare, array $leeren = array(), $port = null)
{
    if ($port === null) {
        $z = xl_mqtt_zustand();
        $port = (int) $z['udpport'];
    }
    if ($port <= 0) {
        xl_log_gebremst('mqtt_kein_port', 'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return -1;
    }
    $en = 0;
    $es = '';
    $s = @stream_socket_client('udp://127.0.0.1:' . (int) $port, $en, $es, 2);
    if ($s === false) {
        xl_log_gebremst('mqtt_socket', 'MQTT: der UDP-Eingang des Gateways liess sich nicht oeffnen.');
        return -1;
    }
    $n = 0;
    $raus = function ($msg) use ($s, &$n) {
        if ($n > 0) {
            usleep(5000);
        }
        @fwrite($s, $msg);
        $n++;
    };
    foreach ($leeren as $t) {
        $raus('retain ' . XL_PRAEFIX . '/' . $t . ' ');
    }
    foreach ($paare as $t => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $raus((xl_mqtt_retain($t) ? 'retain ' : 'publish ') . XL_PRAEFIX . '/' . $t . ' ' . (string) $v);
    }
    fclose($s);
    return $n;
}

/**
 * Die Namenstabelle einer Lampe (Reiter Einbindung, Schritt 3) - aus xl_mqtt_themen(). $mit_dienst: die Themen des
 * Dienstes (status/...) nur einmal, bei der ersten eingetragenen Lampe.
 */
function xl_mqtt_namen($nr = 1, $mit_dienst = true)
{
    $aus = array();
    foreach (xl_mqtt_themen() as $z) {
        $je = strpos($z['t'], 'lampeN/') === 0;
        if (!$je && !$mit_dienst) {
            continue;
        }
        $t = $je ? 'lampe' . (int) $nr . '/' . substr($z['t'], 7) : $z['t'];
        $aus[] = array('thema' => $t, 'name' => xl_gateway_name($t), 'bedeutung' => xl_t($z['k']), 'bereich' => $z['b'],
                       'fehlwert' => $z['f'], 'retain' => $z['r'], 'v2' => $z['v2'], 'noncached' => $z['nc'],
                       'im_muster' => $z['m'] && ((int) $nr === 1 || $je));
    }
    return $aus;
}

/** Die V2-Hakenliste: alle Themen mit V2-Haken (Lampe 1: 8 + status/befehle, je weitere Lampe 6). */
function xl_mqtt_hakenliste($nr = 1, $mit_dienst = true)
{
    $aus = array();
    foreach (xl_mqtt_namen($nr, $mit_dienst) as $z) {
        if ($z['v2']) {
            $aus[] = array(XL_PRAEFIX . '/' . $z['thema'], $z['name'], $z['bedeutung'], $z['noncached']);
        }
    }
    return $aus;
}

/** Die Sollliste fuer alle eingetragenen Lampen (Dienstthemen einmal). */
function xl_mqtt_hakenliste_alle()
{
    $nummern = array_keys(xl_lampen());
    if (!$nummern) {
        $nummern = array(1);
    }
    $soll = array();
    foreach ($nummern as $i => $n) {
        $soll = array_merge($soll, xl_mqtt_hakenliste($n, $i === 0));
    }
    return $soll;
}

/**
 * Stehen unter Gateway V2 die Haken? Gelesen wird config/system/subscriptions.json (nur lesen). Rueckgabe
 * array(1|0|2, gesetzt, soll, fehlende Noncached-Haken (Liste)): 1 alle da, 0 es fehlt etwas, 2 nicht feststellbar/V1.
 */
function xl_mqtt_v2_haken_lage()
{
    $m = xl_mqtt_zustand();
    $p = xl_paths();
    $soll = xl_mqtt_hakenliste_alle();
    if ((int) $m['fassung'] === 1) {
        return array(2, 0, count($soll), array(), 'v1');
    }
    $d = ($p['home'] !== '') ? json_decode((string) @file_get_contents($p['home'] . '/config/system/subscriptions.json'), true) : null;
    if (!is_array($d)) {
        return array(2, 0, count($soll), array(), 'unklar');
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($d[$s]) && is_array($d[$s])) {
            $liste = $d[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) {
            continue;
        }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') {
            continue;
        }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    $gesetzt = 0;
    $nc_fehlt = array();
    foreach ($soll as $z) {
        if (array_key_exists($z[0], $abo)) {
            $gesetzt++;
            if ($z[3] && !$abo[$z[0]]) {
                $nc_fehlt[] = $z[0];
            }
        } elseif ($z[3]) {
            $nc_fehlt[] = $z[0];
        }
    }
    $ok = ($gesetzt === count($soll) && !$nc_fehlt) ? 1 : 0;
    return array($ok, $gesetzt, count($soll), $nc_fehlt, 'v2');
}

/**
 * Pruefzeile "Themenliste gegen Sendecode": jeder Name der Tabelle ist ein Name, unter dem das Gateway sendet, und jedes
 * gesendete Thema steht in der Tabelle. Gesendet = xl_mqtt_paare() fuer eine Lampe mit allen Werten + status/befehle.
 * Rueckgabe array(ok, Zahl, nur_tabelle, nur_gesendet).
 */
function xl_mqtt_namen_probe()
{
    $probe = array(1 => array('erreichbar' => 1, 'weg' => 2, 'ein' => 1, 'hell' => 50, 'ct' => 4000, 'modus' => 0));
    $gesendet = array_keys(xl_mqtt_paare($probe, array('zaehler' => 0, 'ok' => 1), time()));
    $gesendet[] = 'status/befehle';
    $soll = array_map('xl_gateway_name', $gesendet);
    $ist = array();
    foreach (xl_mqtt_namen(1, true) as $z) {
        $ist[] = $z['name'];
    }
    $a = array_values(array_diff($ist, $soll));
    $b = array_values(array_diff($soll, $ist));
    return array(!$a && !$b, count($ist), $a, $b);
}

/**
 * Abo-Datei fuer Gateway V1 (Rueckfall): config/plugins/<ordner>/mqtt_subscriptions.cfg mit "xiaomilicht/#". Das Archiv
 * bringt sie mit; hier wird nur gelesen. Rueckgabe array(pfad, steht das Abo darin?).
 */
function xl_abo_datei()
{
    $p = xl_paths();
    $pfad = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    return array($pfad, in_array(XL_PRAEFIX . '/#', array_map('trim', preg_split('/\r?\n/', $roh)), true));
}

/**
 * Aus der Deinstallation: die zurueckbehaltenen Themen DIESER Linie (xiaomilicht/lampe<n>/ein|hell|ct|modus) mit leerer
 * Nutzlast abraeumen - nie fremde Praefixe, nie ein Muster aus dem Ordnernamen (Gedaechtnis Uninstall-Praefix). Zwei
 * Runden mit 1 s Abstand. Liest nur. Rueckgabe: Zeilen fuer den Installer.
 */
function xl_mqtt_abraeumen()
{
    $cfg = xl_config(false);
    $z = xl_mqtt_zustand();
    if (!$z['udpport']) {
        return array('<INFO> MQTT: kein UDP-Eingangsport des Gateways in general.json - zurueckbehaltene Themen unter '
            . XL_PRAEFIX . '/ wurden nicht abgeraeumt.');
    }
    $nummern = array();
    for ($i = 1; $i <= min(XL_LAMPEN_MAX, (int) $cfg['nr_hoechste']); $i++) {
        $nummern[$i] = true;
    }
    foreach (array_keys(xl_lampen($cfg)) as $n) {
        $nummern[$n] = true;
    }
    foreach (array_keys(xl_werte()) as $n) {
        if ($n >= 1 && $n <= XL_LAMPEN_MAX) {
            $nummern[$n] = true;
        }
    }
    ksort($nummern);
    $themen = array();
    foreach (array_keys($nummern) as $n) {
        foreach (xl_mqtt_themen() as $t) {
            if ($t['r'] && strpos($t['t'], 'lampeN/') === 0) {
                $themen[] = 'lampe' . $n . '/' . substr($t['t'], 7);
            }
        }
    }
    $n1 = xl_mqtt_senden(array(), $themen, (int) $z['udpport']);
    usleep(1000000);
    $n2 = xl_mqtt_senden(array(), $themen, (int) $z['udpport']);
    if ($n1 < 0 || $n2 < 0) {
        return array('<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - zurueckbehaltene Themen unter '
            . XL_PRAEFIX . '/ wurden nicht abgeraeumt.');
    }
    return array('<INFO> MQTT: ' . count($themen) . ' zurueckbehaltene Themen unter ' . XL_PRAEFIX . '/ mit leerer Nutzlast'
        . ' an den UDP-Eingang des Gateways gesendet (zwei Runden). Gegenprobe: mosquitto_sub -t \'' . XL_PRAEFIX
        . '/#\' --retained-only');
}

/* ------------------------------------------------------------------
 * Abraeumen weggefallener Zeilen direkt am Broker mit Ruecklesen (Nachbesserung Geraetetest 28.10.2026)
 * ------------------------------------------------------------------
 * Befund am Geraet: die geleerte Zeile 2 stand am Broker weiter retained auf ein 1 / hell 100 / ct 6500 / modus 0 - der
 * Dienst hat eine Zeile, die verschwindet, nur aus seinem Speicher genommen. Jetzt: beim Dienststart (Bestand) und
 * immer, wenn eine Zeile aus der Konfiguration verschwindet (leeren, loeschen, Sicherung zurueckspielen - alles geht ueber
 * die Konfiguration), fragt der Dienst den Broker, was unter xiaomilicht/lampe<n>/ein|hell|ct|modus zurueckbehalten
 * steht. Jede Nummer, die NICHT eingetragen ist und nicht schon -1 traegt, bekommt -1 retained (Hausregel: ein
 * retained Zustand ohne Aussage ist -1, nie eine leere Nutzlast - die loescht nur) - ueber eine kurze Sitzung direkt am
 * Broker (QoS 1, PUBACK) und ZURUECKGELESEN. Bestaetigt ist ein Thema nur am empfangenen Paket mit Retain-Marke und dem
 * Wert -1; was offen bleibt, versucht der Dienst nach 10 min erneut. Nur dieser Praefix, nur diese vier Felder, nur
 * lampe<Zahl> - status/, befehl/ und fremde Praefixe bleiben unberuehrt. Der Praefix ist fest (XL_PRAEFIX, kein
 * Praefixwechsel moeglich). Bauart der Funktionen: Smartmeter 2.8.13 (sm_gemein.php smg_mq_*, smg_mqtt_behalten,
 * smg_mqtt_setzen_rueck), Praefix smg_ -> xl_. Das Kennwort aus general.json steht nur im CONNECT-Paket.
 */

/** Restlaenge eines MQTT-Pakets. */
function xl_mq_laenge($n)
{
    $o = '';
    do {
        $b = $n % 128;
        $n = intdiv($n, 128);
        if ($n > 0) { $b |= 128; }
        $o .= chr($b);
    } while ($n > 0);
    return $o;
}

/** $n Byte lesen bis zur Zeit $bis (microtime); null = Frist, Ende oder Fehler. */
function xl_mq_lies($s, $n, $bis)
{
    $d = '';
    while (strlen($d) < $n) {
        $rest = $bis - microtime(true);
        if ($rest <= 0) { return null; }
        stream_set_timeout($s, (int) floor($rest), (int) (($rest - floor($rest)) * 1000000));
        $t = @fread($s, $n - strlen($d));
        if ($t === false || $t === '') {
            $m = stream_get_meta_data($s);
            if (!empty($m['eof']) || feof($s)) { return null; }
            continue;
        }
        $d .= $t;
    }
    return $d;
}

/** Ein Paket array(Kopfbyte, Rumpf) oder null. */
function xl_mq_paket($s, $bis)
{
    $k = xl_mq_lies($s, 1, $bis);
    if ($k === null) { return null; }
    $n = 0;
    $f = 1;
    for ($i = 0; $i < 4; $i++) {
        $b = xl_mq_lies($s, 1, $bis);
        if ($b === null) { return null; }
        $n += (ord($b) & 127) * $f;
        $f *= 128;
        if (!(ord($b) & 128)) { break; }
    }
    $r = ($n > 0) ? xl_mq_lies($s, $n, $bis) : '';
    return ($r === null) ? null : array(ord($k), $r);
}

/** Eine Sitzung am Broker (Zugang feldweise aus general.json, sauberer Start). Rueckgabe der Strom oder null. */
function xl_mq_verbinden($kennung)
{
    $home = xl_paths()['home'];
    if ($home === '' || !function_exists('stream_socket_client')) { return null; }
    $d = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return null; }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) { return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : ''; };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $e1, $e2, 2);
    if (!$s) { return null; }
    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $flags = 0x02;
    $nutz = $zk($kennung . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $flags |= 0x40; $nutz .= $zk($kennwort); }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 30);
    $p = chr(0x10) . xl_mq_laenge(strlen($kopf . $nutz)) . $kopf . $nutz;
    if (@fwrite($s, $p) !== strlen($p)) { fclose($s); return null; }
    $ack = xl_mq_paket($s, microtime(true) + 2.0);
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2 || ord($ack[1][1]) !== 0) {
        fclose($s);
        return null;
    }
    return $s;
}

/**
 * Was haelt der Broker unter $filter zurueck? EINE Sitzung. Rueckgabe array('lage' => ok|unbekannt, 'werte' => thema =>
 * wert). 'ok' nur mit CONNACK 0 und SUBACK fuer JEDEN Filter unter 0x80; belegt ist ein Thema nur am empfangenen Paket
 * mit Retain-Marke und nicht leerer Nutzlast. 'unbekannt' heisst NIE "nichts belegt".
 */
function xl_mqtt_behalten(array $filter)
{
    $aus = array('lage' => 'unbekannt', 'werte' => array());
    $filter = array_values(array_filter(array_map('strval', $filter), 'strlen'));
    if (!$filter) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $s = xl_mq_verbinden('xlrueck');
    if (!$s) { return $aus; }
    $sub = pack('n', 1);
    foreach ($filter as $t) { $sub .= pack('n', strlen($t)) . $t . chr(0); }
    $p = chr(0x82) . xl_mq_laenge(strlen($sub)) . $sub;
    $bestaetigt = false;
    $abgelehnt = false;
    if (@fwrite($s, $p) === strlen($p)) {
        $ende = microtime(true) + 3.0;
        while (microtime(true) < $ende) {
            $pk = xl_mq_paket($s, $ende);
            if ($pk === null) { break; }
            $art = $pk[0] >> 4;
            if ($art === 9) {
                $rc = (string) substr($pk[1], 2);
                if (strlen($rc) !== count($filter)) { $abgelehnt = true; }
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                }
                if ($abgelehnt) { break; }
                $bestaetigt = true;
                $ende = min($ende, microtime(true) + 1.0);
            } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                $tl = unpack('n', substr($pk[1], 0, 2));
                $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                if (strlen($pk[1]) < $versatz) { continue; }
                $t = substr($pk[1], 2, $tl[1]);
                $wert = (string) substr($pk[1], $versatz);
                if (($pk[0] & 1) && $wert !== '') {
                    $aus['werte'][$t] = $wert;
                }
            }
        }
    }
    @fwrite($s, chr(0xE0) . chr(0));
    fclose($s);
    if ($bestaetigt && !$abgelehnt) {
        $aus['lage'] = 'ok';
    } else {
        $aus['werte'] = array();
    }
    return $aus;
}

/**
 * Retained setzen (QoS 1, PUBACK) und ZURUECKLESEN. $tw: thema => wert. Rueckgabe array('bestaetigt' => [...],
 * 'offen' => [...], 'lage' => keine_verbindung|kein_puback|nicht_nachgelesen|nachgelesen).
 */
function xl_mqtt_setzen_rueck(array $tw)
{
    $aus = array('bestaetigt' => array(), 'offen' => array_keys($tw), 'lage' => 'keine_verbindung');
    if (!$tw) {
        $aus['lage'] = 'nachgelesen';
        return $aus;
    }
    $s = xl_mq_verbinden('xlraeum');
    if (!$s) { return $aus; }
    $pid = 1;
    $puback = true;
    foreach ($tw as $t => $w) {
        $r = pack('n', strlen((string) $t)) . (string) $t . pack('n', $pid) . (string) $w;
        $p = chr(0x33) . xl_mq_laenge(strlen($r)) . $r;
        if (@fwrite($s, $p) !== strlen($p)) { $puback = false; break; }
        $ack = xl_mq_paket($s, microtime(true) + 2.0);
        if ($ack === null || $ack[0] !== 0x40) { $puback = false; }
        $pid++;
    }
    @fwrite($s, chr(0xE0) . chr(0));
    fclose($s);
    $f = xl_mqtt_behalten(array_keys($tw));
    if ($f['lage'] !== 'ok') {
        $aus['lage'] = $puback ? 'nicht_nachgelesen' : 'kein_puback';
        return $aus;
    }
    $aus['lage'] = 'nachgelesen';
    $aus['offen'] = array();
    foreach ($tw as $t => $w) {
        if (isset($f['werte'][(string) $t]) && $f['werte'][(string) $t] === (string) $w) {
            $aus['bestaetigt'][] = (string) $t;
        } else {
            $aus['offen'][] = (string) $t;
        }
    }
    return $aus;
}

/**
 * Themen weggefallener Zeilen abraeumen: $nummern = die eingetragenen Lampennummern. Rueckgabe array(erledigt bool,
 * Protokollzeilen). erledigt = der Broker war zu fragen und alles Gefundene ist als -1 zurueckgelesen (auch: nichts zu
 * tun); sonst versucht es der Dienst spaeter erneut.
 */
function xl_mqtt_geister_raeumen(array $nummern)
{
    $felder = array();
    foreach (xl_mqtt_themen() as $t) {
        if ($t['r'] && strpos($t['t'], 'lampeN/') === 0) {
            $felder[] = substr($t['t'], 7);
        }
    }
    $filter = array();
    foreach ($felder as $f) {
        $filter[] = XL_PRAEFIX . '/+/' . $f;
    }
    $b = xl_mqtt_behalten($filter);
    if ($b['lage'] !== 'ok') {
        return array(false, array('MQTT: Broker nicht zu fragen - Themen nicht mehr eingetragener Lampen werden in 10 min erneut geprueft.'));
    }
    $muster = '#^' . preg_quote(XL_PRAEFIX, '#') . '/lampe([1-9][0-9]?)/(' . implode('|', array_map('preg_quote', $felder)) . ')\z#';
    $tw = array();
    $je = array();
    foreach ($b['werte'] as $t => $w) {
        if (!preg_match($muster, (string) $t, $m)) {
            continue;
        }
        $n = (int) $m[1];
        if ($n > XL_LAMPEN_MAX || in_array($n, array_map('intval', $nummern), true) || (string) $w === '-1') {
            continue;
        }
        $tw[(string) $t] = '-1';
        $je[$n][] = $m[2];
    }
    if (!$tw) {
        return array(true, array());
    }
    $r = xl_mqtt_setzen_rueck($tw);
    $zeilen = array();
    ksort($je);
    foreach ($je as $n => $fl) {
        $offen = array();
        foreach ($fl as $f) {
            if (!in_array(XL_PRAEFIX . '/lampe' . $n . '/' . $f, $r['bestaetigt'], true)) {
                $offen[] = $f;
            }
        }
        $zeilen[] = $offen
            ? 'MQTT: Lampe ' . $n . ' ist nicht mehr eingetragen - -1 fuer ' . implode(', ', $offen) . ' ist nicht bestaetigt (Broker: '
              . $r['lage'] . '), naechster Versuch in 10 min.'
            : 'MQTT: Lampe ' . $n . ' ist nicht mehr eingetragen - ' . implode(', ', $fl) . ' auf -1 gesetzt (retained) und am Broker zurueckgelesen.';
    }
    return array(!$r['offen'], $zeilen);
}


/* ==================================================================
 * Befehle (EINE Liste fuer Bruecke, Endpunkt ?cmd=, Reiter Einbindung, Reiter Test - Entwurf 3.1, E-E12)
 * ==================================================================
 * Weg: Bruecke (MQTT) oder Endpunkt (?cmd=, Reiter Test) -> xl_befehl_annehmen() -> Warteschlange
 * data/plugins/<ordner>/warteschlange/ -> Dienst fuehrt aus (eine Sitzung je Lampe, Sperre um den Geraetezugriff) ->
 * Antwort antworten/<kennung>.json. Quittung "angenommen" erst nach MIoT-Code 0 (anders als Govee "uebergeben").
 */

/**
 * Die Befehle: name => art (Bruecke: schalter|puls|ganz|auswahl), Bereich/Werte, Sperre der Bruecke in s, eingreifend,
 * Sprachschluessel der Kachel. Werte werden zusaetzlich in xl_befehl_bauen() hart geprueft.
 */
function xl_befehle()
{
    return array(
        'schalten'   => array('art' => 'schalter', 'sperre_s' => 0,  'eingreifend' => true,  'kachel' => 'BEFEHL.K_SCHALTEN'),
        'umschalten' => array('art' => 'puls',     'sperre_s' => 1,  'eingreifend' => true,  'kachel' => 'BEFEHL.K_UMSCHALTEN'),
        'hell'       => array('art' => 'ganz', 'min' => 0, 'max' => 100, 'sperre_s' => 0, 'eingreifend' => true, 'kachel' => 'BEFEHL.K_HELL'),
        'ct'         => array('art' => 'ganz', 'min' => 2700, 'max' => 6500, 'sperre_s' => 0, 'eingreifend' => true, 'kachel' => 'BEFEHL.K_CT'),
        'nacht'      => array('art' => 'schalter', 'sperre_s' => 0,  'eingreifend' => true,  'kachel' => 'BEFEHL.K_NACHT'),
        'szene'      => array('art' => 'auswahl', 'werte' => array('lesen', 'gemuetlich', 'nacht'), 'sperre_s' => 2,
                              'eingreifend' => true, 'kachel' => 'BEFEHL.K_SZENE'),
        'abruf'      => array('art' => 'puls',     'sperre_s' => 10, 'eingreifend' => false, 'kachel' => 'BEFEHL.K_ABRUF'),
    );
}

/** Wert vorab pruefen (ohne Modell; das Modell prueft der Dienst mit xl_befehl_bauen()). Rueckgabe normierter Wert oder null. */
function xl_befehl_wert($cmd, $wert)
{
    $b = xl_befehle();
    if (!isset($b[$cmd]) || !is_string($wert)) {
        return null;
    }
    $w = trim($wert);
    $e = $b[$cmd];
    if ($e['art'] === 'puls') {
        return $w === '1' ? '1' : null;
    }
    if ($e['art'] === 'schalter') {
        return ($w === '0' || $w === '1') ? $w : null;
    }
    if ($e['art'] === 'ganz') {
        return (preg_match('/^[0-9]{1,5}\z/', $w) && (int) $w >= $e['min'] && (int) $w <= $e['max']) ? (string) (int) $w : null;
    }
    if ($e['art'] === 'auswahl') {
        return in_array($w, $e['werte'], true) ? $w : null;
    }
    return null;
}

/** Ist der Befehl wegen des zweiten Hakens "Lampen aus Loxone schalten" gesperrt? EINE Stelle fuer Endpunkt und Bruecke. */
function xl_befehl_gesperrt($cmd, $cfg)
{
    return $cmd !== 'abruf' && empty($cfg['steuerung_ein']);
}

/* ---------------- Gleichwert-Bremse (E-E12; Bauart gv_bremse_*, Govee 0.9.31) ----------------
 * Derselbe Sollwert an dieselbe Lampe binnen 60 s geht nicht erneut hinaus ("unveraendert", kein Paket) - faengt das
 * Echo des Loxone-Dimmers ab, wenn die Rueckmeldung an seinen Set-Eingang geht (E-E19). Zusaetzlich: meldet die Lampe
 * inzwischen einen ANDEREN Wert (App, Wandschalter), bremst nichts - sonst kaeme ein Loxone-Befehl nach einer
 * App-Aenderung nie an. Nur Sollwerte (schalten, hell, ct, nacht); umschalten, szene, abruf sind Ereignisse. */

function xl_bremse_wert($cmd, $wert)
{
    if ($cmd === 'schalten') { return array('ein', $wert); }
    if ($cmd === 'hell') { return array('hell', (string) (int) $wert); }
    if ($cmd === 'ct') { return array('ct', (string) (int) $wert); }
    if ($cmd === 'nacht') { return array('nacht', $wert); }
    return null;
}

/** Stimmt der gemeldete Zustand mit dem Sollwert ueberein? null = keine Aussage (dann gilt nur der Merker). */
function xl_bremse_zustand_passt(array $w, $groesse, $wert)
{
    if (empty($w['erreichbar'])) {
        return null;
    }
    $v = (int) $wert;
    switch ($groesse) {
        case 'ein':
            return isset($w['ein']) && (int) $w['ein'] >= 0 ? ((int) $w['ein'] === $v) : null;
        case 'hell':
            if ($v === 0) {
                return isset($w['ein']) && (int) $w['ein'] >= 0 ? ((int) $w['ein'] === 0) : null;
            }
            return (isset($w['hell'], $w['ein']) && (int) $w['hell'] >= 0) ? ((int) $w['hell'] === $v && (int) $w['ein'] === 1) : null;
        case 'ct':
            return isset($w['ct']) && (int) $w['ct'] >= 0 ? ((int) $w['ct'] === $v) : null;
        case 'nacht':
            return isset($w['modus']) && (int) $w['modus'] >= 0 ? ((int) $w['modus'] === $v) : null;
    }
    return null;
}

function xl_bremse_ziele($lampe, $cfg)
{
    $l = xl_lampen($cfg);
    if ($lampe === 'alle') {
        return array_keys($l);
    }
    return isset($l[(int) $lampe]) ? array((int) $lampe) : array();
}

/** Merker oeffnen und sperren; false = geschlossen ausfallen (503). */
function xl_bremse_oeffnen()
{
    $f = xl_paths()['datadir'] . '/befehlsbremse.json';
    clearstatcache(true, $f);
    if (is_link($f) || (file_exists($f) && !is_file($f))) {
        return false;
    }
    $fh = @fopen($f, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!@flock($fh, LOCK_EX)) {
        @fclose($fh);
        return false;
    }
    return $fh;
}

function xl_bremse_lesen($fh)
{
    @rewind($fh);
    $d = json_decode((string) @stream_get_contents($fh), true);
    return is_array($d) ? $d : array();
}

/** Rueckgabe array('stand' => frei|gleich|merker, 'seit' => s). */
function xl_bremse_pruefen($cmd, $wert, $lampe, $cfg)
{
    $frei = array('stand' => 'frei', 'seit' => 0);
    $g = xl_bremse_wert($cmd, $wert);
    $ziele = xl_bremse_ziele($lampe, $cfg);
    if ($g === null || !$ziele) {
        return $frei;
    }
    $fh = xl_bremse_oeffnen();
    if ($fh === false) {
        return array('stand' => 'merker', 'seit' => 0);
    }
    $m = xl_bremse_lesen($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
    $werte = xl_werte();
    $jetzt = time();
    $seit = 0;
    foreach ($ziele as $nr) {
        $k = $nr . '|' . $g[0];
        if (!isset($m[$k]['w'], $m[$k]['t']) || (string) $m[$k]['w'] !== $g[1]) {
            return $frei;
        }
        $s = $jetzt - (int) $m[$k]['t'];
        if ($s < 0 || $s >= XL_BREMSE_S) {
            return $frei;
        }
        if (isset($werte[$nr]) && xl_bremse_zustand_passt($werte[$nr], $g[0], $g[1]) === false) {
            return $frei;
        }
        $seit = max($seit, $s);
    }
    return array('stand' => 'gleich', 'seit' => $seit);
}

/** Den AUSGEFUEHRTEN Wert merken; ein Wert macht die Groessen ungueltig, die er mit veraendert. */
function xl_bremse_merken($cmd, $wert, $lampe, $cfg)
{
    $ziele = xl_bremse_ziele($lampe, $cfg);
    if (!$ziele) {
        return true;
    }
    $fh = xl_bremse_oeffnen();
    if ($fh === false) {
        return false;
    }
    $m = xl_bremse_lesen($fh);
    $jetzt = time();
    foreach ($m as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || $jetzt - (int) $e['t'] >= XL_BREMSE_S || $jetzt - (int) $e['t'] < 0) {
            unset($m[$k]);
        }
    }
    $g = xl_bremse_wert($cmd, $wert);
    $verdraengt = array('ein' => array('hell', 'nacht'), 'hell' => array('ein', 'nacht'), 'ct' => array('nacht'),
                        'nacht' => array('ein', 'hell', 'ct'));
    foreach ($ziele as $nr) {
        if ($g === null) {
            /* Ereignis (umschalten, szene): alle Merker dieser Lampe verwerfen. */
            foreach (array('ein', 'hell', 'ct', 'nacht') as $v) {
                unset($m[$nr . '|' . $v]);
            }
            continue;
        }
        foreach ($verdraengt[$g[0]] as $v) {
            unset($m[$nr . '|' . $v]);
        }
        $m[$nr . '|' . $g[0]] = array('w' => $g[1], 't' => $jetzt);
    }
    $js = (string) json_encode($m);
    $ok = @ftruncate($fh, 0) && @rewind($fh) && @fwrite($fh, $js) === strlen($js) && @fflush($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return $ok;
}

function xl_warteschlange_ordner()
{
    return xl_paths()['datadir'] . '/warteschlange';
}

/**
 * Einen Auftrag an den Dienst geben und auf die Antwort warten. Rueckgabe array(ok 0|1|2, info). 1 ausgefuehrt, 0
 * abgelehnt, 2 eingereiht, aber ohne Antwort in der Wartezeit (Ergebnis unbekannt). Ohne laufenden Dienst wird nichts
 * eingereiht.
 */
function xl_befehl_absetzen(array $auftrag, $wartezeit = null)
{
    $p = xl_paths();
    if ($wartezeit === null) {
        $wartezeit = (int) xl_config(false)['wartezeit'];
    }
    $wartezeit = max(1, min(XL_WARTEN_WEB, (int) $wartezeit));
    if (!xl_dienst_laeuft()) {
        return array(0, 'DIENST_LAEUFT_NICHT');
    }
    $ordner = xl_warteschlange_ordner();
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'WARTESCHLANGE');
    }
    $kennung = bin2hex(random_bytes(8));
    $auftrag['ts'] = time();
    $auftrag['kennung'] = $kennung;
    $js = json_encode($auftrag);
    $datei = $ordner . '/' . $kennung . '.json';
    if ($js === false || @file_put_contents($datei . '.tmp', $js) !== strlen($js) || !@rename($datei . '.tmp', $datei)) {
        @unlink($datei . '.tmp');
        return array(0, 'WARTESCHLANGE');
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    $bis = microtime(true) + $wartezeit;
    while (microtime(true) < $bis) {
        clearstatcache(true, $antwort);
        if (is_file($antwort)) {
            $a = xl_json_lesen($antwort);
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0), (string) (isset($a['info']) ? $a['info'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'OHNE_ANTWORT');
}

/**
 * Einen Befehl annehmen - EINE Funktion fuer Endpunkt (?cmd=, Reiter Test) und Bruecke (Quelle 'mqtt'). Reihenfolge:
 * Lampe/Befehl/Wert hart pruefen (ungueltig -> WERT, kein Paket) -> zweiter Haken (ausser abruf) -> Dienst laeuft ->
 * Gleichwert-Bremse -> Auftrag an den Dienst -> Bremse merken bei 1.
 * Rueckgabe array('ok' => 0|1|2, 'grund' => Wort, 'http' => Code, 'unveraendert' => 0|1, 'seit' => s, 'info' => Text).
 */
function xl_befehl_annehmen($lampe, $cmd, $wert, $cfg, $quelle = 'http')
{
    $r = array('ok' => 0, 'grund' => '', 'http' => 200, 'unveraendert' => 0, 'seit' => 0, 'info' => '');
    $cmd = (string) $cmd;
    $b = xl_befehle();
    if (!isset($b[$cmd])) {
        $r['grund'] = 'UNBEKANNT';
        $r['http'] = 400;
        return $r;
    }
    $w = xl_befehl_wert($cmd, is_string($wert) ? $wert : (string) $wert);
    if ($w === null) {
        $r['grund'] = 'WERT';
        $r['http'] = 400;
        return $r;
    }
    if ($lampe !== 'alle') {
        $lampe = (int) $lampe;
        if (xl_lampe($lampe, $cfg) === null) {
            $r['grund'] = 'LAMPE_UNBEKANNT';
            $r['http'] = 404;
            return $r;
        }
    } elseif (!xl_lampen($cfg)) {
        $r['grund'] = 'KEINE_LAMPE';
        $r['http'] = 404;
        return $r;
    }
    if (xl_befehl_gesperrt($cmd, $cfg)) {
        $r['grund'] = 'STEUERUNG_AUS';
        $r['http'] = 403;
        return $r;
    }
    if (!xl_dienst_laeuft()) {
        $r['grund'] = 'DIENST_LAEUFT_NICHT';
        $r['http'] = 503;
        return $r;
    }
    $br = xl_bremse_pruefen($cmd, $w, $lampe, $cfg);
    if ($br['stand'] === 'merker') {
        xl_log_gebremst('bremse_merker', 'Die Merkerdatei der Gleichwert-Bremse laesst sich nicht oeffnen - Sollwerte '
            . 'werden mit 503 abgewiesen, bis das behoben ist (Platz, Eigentuemer loxberry).');
        $r['grund'] = 'BREMSE_MERKER';
        $r['http'] = 503;
        return $r;
    }
    if ($br['stand'] === 'gleich') {
        $r['ok'] = 1;
        $r['unveraendert'] = 1;
        $r['seit'] = (int) $br['seit'];
        $r['info'] = 'UNVERAENDERT';
        return $r;
    }
    list($ok, $info) = xl_befehl_absetzen(array('lampe' => $lampe, 'cmd' => $cmd, 'wert' => $w,
                                                'quelle' => $quelle === 'mqtt' ? 'mqtt' : 'http'));
    $r['ok'] = (int) $ok;
    $r['info'] = (string) $info;
    if ($ok === 0) {
        $r['grund'] = preg_match('/^[A-Z_]+$/', $info) ? $info : 'ABGELEHNT';
        $r['http'] = ($info === 'DIENST_LAEUFT_NICHT') ? 503 : 500;
    }
    if ($ok === 1 && !xl_bremse_merken($cmd, $w, $lampe, $cfg)) {
        xl_log_gebremst('bremse_schreiben', 'Die Merkerdatei der Gleichwert-Bremse liess sich nicht schreiben.');
    }
    return $r;
}

/** Ergebnis fuer die Bruecke: array(ok, Grund der Quittung). UNVERAENDERT ist angenommen. */
function xl_befehl_ergebnis(array $r)
{
    if ((int) $r['ok'] === 1) {
        return array(true, '');
    }
    if ((int) $r['ok'] === 2) {
        return array(false, 'KEINE_ANTWORT');
    }
    if ($r['grund'] !== '' && $r['grund'] !== 'ABGELEHNT') {
        return array(false, (string) $r['grund']);
    }
    return array(false, $r['info'] !== '' ? (string) $r['info'] : 'ABGELEHNT');
}

/** Die Antwortzeile des Endpunkts/Reiters Test: CMD;OK=..;BEFEHL=..;LAMPE=..;INFO=.. */
function xl_befehl_zeile(array $r, $cmd, $lampe)
{
    $info = $r['unveraendert'] ? 'UNVERAENDERT;SEIT=' . (int) $r['seit']
        : ($r['grund'] !== '' ? $r['grund'] : ($r['info'] !== '' ? $r['info'] : '-'));
    return 'CMD;OK=' . ((int) $r['ok'] === 1 ? 1 : 0) . ';BEFEHL=' . preg_replace('/[^a-z]/', '', (string) $cmd)
        . ';LAMPE=' . ($lampe === 'alle' ? 'alle' : (int) $lampe) . ';INFO=' . str_replace(';', ',', $info);
}

/** Einstellungen fuer die Zuordnung der Bruecke: Praefix fest, annehmen = erster Haken UND MQTT an, eingreifend = zweiter Haken. */
function xl_befehle_einstellungen()
{
    $c = xl_config(false);
    return array('praefix' => XL_PRAEFIX,
                 'annehmen' => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                 'eingreifend' => !empty($c['steuerung_ein']),
                 'mqtt_ein' => !empty($c['mqtt_ein']));
}

/** Die Befehlsbruecke als Bibliothek laden (BB_NUR_LADEN). Installiert unter bin/plugins/<ordner>/, im Archiv unter bin/. */
function xl_bruecke_laden()
{
    if (function_exists('bb_waechter')) {
        return true;
    }
    $p = xl_paths();
    $kand = array();
    if ($p['home'] !== '') {
        $kand[] = $p['bindir'] . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) {
                define('BB_NUR_LADEN', true);
            }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Bruecke sofort schalten (nach dem Speichern des Reiters MQTT). Rueckgabe array(rc|null, start|stop|'', Ausgabe). */
function xl_bruecke_schalten($cfg = null)
{
    if ($cfg === null) {
        $cfg = xl_config(false);
    }
    if (!xl_bruecke_laden()) {
        return array(1, '', xl_t('BEFEHL.T_NICHT_DA'));
    }
    $p = bb_pfade();
    if (!$p) {
        return array(null, '', '');
    }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) {
        return array(null, '', '');
    }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Lage der Bruecke (Reiter Test, healthcheck): array(true|false|null, Text) - null = Haken aus. */
function xl_bruecke_lage()
{
    if (!xl_bruecke_laden()) {
        return array(false, xl_t('BEFEHL.T_NICHT_DA'));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) {
        return array(null, xl_t('BEFEHL.T_AUS'));
    }
    if ($soll !== 0) {
        return array(false, sprintf(xl_t('BEFEHL.T_DEFEKT'), xl_e($grund)));
    }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        return array(true, sprintf(xl_t('BEFEHL.T_LAEUFT'), implode(' ', $pids), XL_PRAEFIX . '/befehl/#',
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0));
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(xl_t('BEFEHL.T_STEHT'), $wie));
}

/* ==================================================================
 * Loxone-Vorlage der Steuerbefehle (VirtualOut an den UDP-Eingang des MQTT-Gateways; Bauart gv_vorlage_mqtt_befehle)
 * ================================================================== */

function xl_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Virtueller Ausgang, gemessene Bauform (Govee 0.9.31 gv_xml_virtual_out, aus einer Ausfuhr von Loxone Config). */
function xl_xml_virtual_out(array $kopf, array $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="' . xl_x($kopf['title']) . '" Comment="' . xl_x($kopf['comment']) . '" '
        . 'Address="' . xl_x($kopf['address']) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $analog = !empty($c['analog']);
        $o .= "\t" . '<VirtualOutCmd Title="' . xl_x($c['title']) . '" Comment="' . xl_x($c['comment']) . '" '
            . 'CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . xl_x($c['on']) . '" CmdOnHTTP="" CmdOnPost="" '
            . 'CmdOff="' . xl_x(isset($c['off']) ? $c['off'] : '') . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" '
            . 'Analog="' . ($analog ? 'true' : 'false') . '" Repeat="0" RepeatRate="0" '
            . ($analog ? 'SourceValLow="0" DestValLow="0" SourceValHigh="10" DestValHigh="10" ' : '')
            . 'HintText=""/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Vorlage "Xiaomi Licht MQTT-Befehle" fuer eine Lampe: je Befehl ein VirtualOutCmd "publish xiaomilicht/befehl/
 * lampe<n>/<befehl> <v.0>" (Szenen je Wert). Nur Befehle, die das Modell der Lampe kann (Regeln/04 :300). Titel fest
 * deutsch (Sprachregel), Kommentar aus der Sprachdatei. Rueckgabe array(Dateiname, Inhalt) oder array('', '').
 */
function xl_vorlage_befehle($nr)
{
    $l = xl_lampe($nr);
    if ($l === null) {
        return array('', '');
    }
    list($modell) = xl_modell_holen($l);
    $f = xl_faehigkeiten(xl_modell($modell));
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-]/', '', preg_replace('/:[0-9]+$/', '', (string) $_SERVER['HTTP_HOST']))
        : (gethostname() ?: 'loxberry');
    $port = (int) xl_mqtt_zustand()['udpport'];
    if ($port <= 0) {
        $port = 11884;
    }
    $titel = array('schalten' => 'Ein/Aus', 'umschalten' => 'Umschalten', 'hell' => 'Helligkeit', 'ct' => 'Farbtemperatur',
                   'nacht' => 'Nachtlicht', 'szene' => 'Szene', 'abruf' => 'Zustand abrufen');
    $cmds = array();
    $tab = xl_modell($modell);
    foreach (xl_befehle() as $name => $b) {
        if (($name === 'ct' && !$f['ct']) || ($name === 'nacht' && !$f['nacht']) || ($name === 'szene' && !$f['szene'])) {
            continue;
        }
        $thema = XL_PRAEFIX . '/befehl/lampe' . (int) $nr . '/' . $name;
        $t = 'Xiaomi Licht: Lampe ' . (int) $nr . ' ' . $titel[$name];
        $kom = xl_klartext($b['kachel']);
        if ($b['art'] === 'schalter') {
            $cmds[] = array('title' => $t, 'comment' => $kom, 'on' => 'publish ' . $thema . ' 1', 'off' => 'publish ' . $thema . ' 0', 'analog' => false);
        } elseif ($b['art'] === 'puls') {
            $cmds[] = array('title' => $t, 'comment' => $kom, 'on' => 'publish ' . $thema . ' 1', 'analog' => false);
        } elseif ($b['art'] === 'ganz') {
            $cmds[] = array('title' => $t, 'comment' => $kom, 'on' => 'publish ' . $thema . ' <v.0>', 'analog' => true);
        } else {
            foreach ($b['werte'] as $v) {
                if (!in_array($v, $tab['szenen'], true)) {
                    continue;
                }
                $cmds[] = array('title' => $t . ' ' . $v, 'comment' => $kom, 'on' => 'publish ' . $thema . ' ' . $v, 'analog' => false);
            }
        }
    }
    return array(
        'VQ_XIAOMILICHT_LAMPE' . (int) $nr . '_MQTT.xml',
        xl_xml_virtual_out(array('title' => 'Xiaomi Licht MQTT-Befehle', 'comment' => xl_klartext('LOX.VORLAGE_KOMMENTAR'),
                                 'address' => '/dev/udp/' . $host . '/' . $port), $cmds),
    );
}


/* ==================================================================
 * Regeln des Formulars und der Sicherung (EINE Stelle; Bauart gv_regeln())
 * ================================================================== */

function xl_regeln()
{
    return array(
        'ip4'     => '/^\d{1,3}(\.\d{1,3}){3}\z/',
        'host'    => '/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}\z/',
        'token'   => '/^[0-9a-f]{32}\z/',
        'modell'  => '/^([a-z0-9_-]{1,32}(\.[a-z0-9_-]{1,32}){2,3})?\z/',
        'did'     => '/^[A-Za-z0-9._-]{0,64}\z/',      // Geraete-ID aus der Cloud (Mehrfachauswahl; leer = von Hand)
        'zahlen'  => array('abfrage_s' => array(5, 60), 'wartezeit' => array(1, 10), 'nr_hoechste' => array(0, XL_LAMPEN_MAX)),
        'schalter' => array('mqtt_ein', 'befehle_mqtt_ein', 'steuerung_ein', 'endpunkt_ein'),
        'regionen' => array('de', 'cn', 'i2', 'ru', 'sg', 'us', 'alle'),
    );
}

/** '' gueltig, 'bereich' (IPv4-Form, Gruppe ueber 255), 'muster' (weder IPv4 noch Rechnername). */
function xl_adresse_befund($a)
{
    $a = (string) $a;
    $r = xl_regeln();
    if (preg_match($r['ip4'], $a)) {
        foreach (explode('.', $a) as $g) {
            if ((int) $g > 255) {
                return 'bereich';
            }
        }
        return '';
    }
    return preg_match($r['host'], $a) ? '' : 'muster';
}

/** Text, wie ihn das Formular speichert (keine Steuerzeichen, keine geraden Anfuehrungszeichen, kein Rand-Leerraum). */
function xl_sich_text($w)
{
    return is_string($w) && !preg_match('/[\x00-\x1F\x7F"\']/', $w) && trim($w) === $w && strlen($w) <= 64;
}

function xl_ist_liste($w)
{
    return is_array($w) && ($w === array() || array_keys($w) === range(0, count($w) - 1));
}

/**
 * Eine Lampenliste (Formular oder Sicherung) pruefen: hoechstens 16 Zeilen, Pflicht ip, Nummern 1..16 einmalig, IP nicht
 * doppelt (S3), Token leer oder 32 Hex, lan auto|aus. Rueckgabe Liste der Beanstandungen (Text), leer = gut.
 */
function xl_lampen_pruefen($w)
{
    $r = xl_regeln();
    if (!xl_ist_liste($w) || count($w) > XL_LAMPEN_MAX) {
        return array(sprintf(xl_t('EINST.SICH_W_LISTE'), 'lampen', XL_LAMPEN_MAX));
    }
    $m = array();
    $nummern = array();
    $ips = array();
    $felder = array('nr', 'name', 'ip', 'token', 'modell', 'lan', 'did');
    foreach ($w as $i => $z) {
        $zeile = $i + 1;
        if (!is_array($z) || ($z && xl_ist_liste($z))) {
            $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, xl_t('EINST.SICH_W_OBJEKT'));
            continue;
        }
        foreach (array_keys($z) as $f) {
            if (!in_array((string) $f, $felder, true)) {
                $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, sprintf(xl_t('EINST.SICH_W_FELD_FREMD'), xl_e($f)));
            }
        }
        foreach (array('name', 'ip', 'modell') as $f) {
            if (array_key_exists($f, $z) && !xl_sich_text($z[$f])) {
                $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, sprintf(xl_t('EINST.SICH_W_TEXT'), $f));
            }
        }
        $ip = (isset($z['ip']) && is_string($z['ip'])) ? $z['ip'] : '';
        if ($ip === '') {
            $m[] = sprintf(xl_t('EINST.FEHLER_IP_FEHLT'), $zeile);
        } elseif (xl_adresse_befund($ip) === 'bereich') {
            $m[] = sprintf(xl_t('EINST.FEHLER_IP_BEREICH'), $zeile, xl_e($ip));
        } elseif (xl_adresse_befund($ip) !== '') {
            $m[] = sprintf(xl_t('EINST.FEHLER_IP'), $zeile);
        } elseif (isset($ips[strtolower($ip)])) {
            $m[] = sprintf(xl_t('EINST.FEHLER_IP_DOPPELT'), $zeile, xl_e($ip));
        } else {
            $ips[strtolower($ip)] = true;
        }
        if (array_key_exists('token', $z) && !(is_string($z['token']) && ($z['token'] === '' || preg_match($r['token'], $z['token'])))) {
            $m[] = sprintf(xl_t('EINST.FEHLER_TOKEN_FORM'), $zeile);
        }
        if (array_key_exists('modell', $z) && is_string($z['modell']) && !preg_match($r['modell'], $z['modell'])) {
            $m[] = sprintf(xl_t('EINST.FEHLER_MODELL'), $zeile);
        }
        if (array_key_exists('did', $z) && !(is_string($z['did']) && preg_match($r['did'], $z['did']))) {
            $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, sprintf(xl_t('EINST.SICH_W_TEXT'), 'did'));
        }
        if (array_key_exists('lan', $z) && $z['lan'] !== 'auto' && $z['lan'] !== 'aus') {
            $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, xl_t('EINST.SICH_W_LAN'));
        }
        if (!isset($z['nr']) || !is_int($z['nr']) || $z['nr'] < 1 || $z['nr'] > XL_LAMPEN_MAX || isset($nummern[$z['nr']])) {
            $m[] = sprintf(xl_t('EINST.SICH_W_EINTRAG'), $zeile, xl_t('EINST.SICH_W_NR'));
        } else {
            $nummern[$z['nr']] = true;
        }
    }
    return $m;
}

/** Einen Wert aus einer Sicherung pruefen; ein Schluessel ohne Regel wird abgewiesen (fail closed). */
function xl_sicherung_wert_pruefen($k, $w)
{
    $r = xl_regeln();
    if (isset($r['zahlen'][$k])) {
        list($u, $o) = $r['zahlen'][$k];
        if (!is_int($w)) {
            return array(sprintf(xl_t('EINST.SICH_W_ZAHL'), $k));
        }
        return ($w < $u || $w > $o) ? array(sprintf(xl_t('EINST.FEHLER_BEREICH'), $k, $u, $o)) : array();
    }
    if (in_array($k, $r['schalter'], true)) {
        return ($w === 0 || $w === 1) ? array() : array(sprintf(xl_t('EINST.SICH_W_SCHALTER'), $k));
    }
    switch ($k) {
        case 'aktionstoken':
            return xl_token_gueltig($w) ? array() : array(xl_t('EINST.SICH_W_TOKEN'));
        case 'cloud_region':
            return in_array($w, $r['regionen'], true) ? array() : array(sprintf(xl_t('EINST.SICH_W_SCHALTER'), $k));
        case 'lampen':
            return xl_lampen_pruefen($w);
    }
    return array(sprintf(xl_t('EINST.SICH_FREMD'), xl_e($k)));
}

/**
 * Eine Sicherungsdatei einlesen - eine halb gueltige Datei ueberschreibt NICHTS. Feste Feldliste (xl_vorgaben()),
 * unbekannte Schluessel beanstandet, fehlende beanstandet - ausser der Spaetliste: fehlt dort ein Schluessel, gilt der
 * GELTENDE Wert mit Hinweis (Bauart G26/G27/G32). Rueckgabe array(Konfiguration|null, Beanstandungen, Hinweise, Anzahl).
 */
function xl_sicherung_lesen($roh, $geltend = null, &$namen = null)
{
    $namen = array();
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten && xl_ist_liste($daten))) {
        return array(null, array(xl_t('EINST.SICH_KEIN_JSON')), array(), 0);
    }
    if (!is_array($geltend)) {
        $geltend = xl_config(false);
    }
    $neu = xl_vorgaben();
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if (substr($k, 0, 1) === '_') {
            continue;     // lesbarer Kopf (_plugin, _stand, _hinweis)
        }
        if (!array_key_exists($k, $neu)) {
            $mangel[] = sprintf(xl_t('EINST.SICH_FREMD'), xl_e($k));
            $namen[] = $k;
            continue;
        }
        $wm = xl_sicherung_wert_pruefen($k, $w);
        if ($wm) {
            $mangel = array_merge($mangel, $wm);
            $namen[] = $k;
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = xl_t('EINST.SICH_LEER');
    }
    $fehlend = array();
    foreach (array_keys(xl_vorgaben()) as $fk) {
        if (array_key_exists($fk, $daten)) {
            continue;
        }
        if (in_array($fk, xl_spaet_schluessel(), true)) {
            $neu[$fk] = isset($geltend[$fk]) ? (int) !empty($geltend[$fk]) : 0;
            $hinweise[] = sprintf(xl_t('EINST.SICH_SPAET'), $fk);
            continue;
        }
        $fehlend[] = $fk;
    }
    if ($fehlend) {
        $mangel[] = sprintf(xl_t('EINST.SICH_FEHLEND'), count($fehlend), implode(', ', $fehlend));
        $namen = array_merge($namen, $fehlend);
    }
    return array($mangel ? null : $neu, $mangel, $hinweise, $anzahl);
}

/** Die Sicherungsdatei: lesbarer Kopf (_plugin, _stand, _hinweis - Regeln/05) und die ganze Konfiguration samt Token. */
function xl_sicherung_datei()
{
    $p = xl_paths();
    $kopf = array(
        '_plugin'  => 'Xiaomi Licht (' . $p['plugin'] . ')',
        '_stand'   => (xl_fassung() !== '' ? xl_fassung() : 'unbekannt') . ', ' . date('Y-m-d H:i:s'),
        '_hinweis' => xl_klartext('EINST.SICH_KOPF_HINWEIS'),
    );
    $cfg = xl_config();
    $daten = array();
    foreach (array_keys(xl_vorgaben()) as $k) {
        $daten[$k] = $cfg[$k];
    }
    return json_encode($kopf + $daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Einmalmeldung nach dem POST (Regeln/04, U5): Datei 0600 im Datenordner, beim folgenden GET gelesen UND geloescht;
 * aelter als 120 s verworfen. Aktionstoken und Lampen-Token werden vorher unkenntlich gemacht.
 */
function xl_einmal_schreiben($meldungen, $fehler, $test, $hinweise = array(), $zusatz = null)
{
    $cfg = xl_config(false);
    $geheim = array();
    $kand = array((string) $cfg['aktionstoken']);
    foreach (xl_lampen($cfg) as $l) {
        $kand[] = $l['token'];
    }
    foreach ($kand as $w) {
        if (is_string($w) && strlen($w) >= 4) {
            $geheim[] = $w;
            $geheim[] = strtoupper($w);
        }
    }
    $weg = function ($t) use ($geheim) {
        return $geheim ? str_replace($geheim, '***', (string) $t) : (string) $t;
    };
    return xl_json_schreiben(xl_paths()['datadir'] . '/einmalmeldung.json', array(
        'zeit'      => time(),
        'meldungen' => array_map($weg, array_values((array) $meldungen)),
        'fehler'    => array_map($weg, array_values((array) $fehler)),
        'test'      => $weg($test),
        'hinweise'  => array_map($weg, array_values((array) $hinweise)),
        'zusatz'    => is_array($zusatz) ? $zusatz : null,
    ), 0600);
}

function xl_einmal_lesen()
{
    $f = xl_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $liste = function ($x) {
        return is_array($x) ? array_values(array_map('strval', array_filter($x, 'is_scalar'))) : array();
    };
    return array(
        'meldungen' => $liste(isset($d['meldungen']) ? $d['meldungen'] : null),
        'fehler'    => $liste(isset($d['fehler']) ? $d['fehler'] : null),
        'test'      => isset($d['test']) && is_scalar($d['test']) ? (string) $d['test'] : '',
        'hinweise'  => $liste(isset($d['hinweise']) ? $d['hinweise'] : null),
        'zusatz'    => isset($d['zusatz']) && is_array($d['zusatz']) ? $d['zusatz'] : null,
    );
}

/* ==================================================================
 * Token aus der Xiaomi-Cloud (Stammmodul xiaomi_cloud.php 1.0.1, Gruppe 33/34; linieneigen nur Filter und Uebernahme)
 * ================================================================== */

/** Das Stammmodul laden (liegt neben der Oberflaeche in htmlauth/) und die Ablage setzen. Rueckgabe true/false. */
function xl_cloud_bereit()
{
    if (!function_exists('xc_abruf')) {
        $p = xl_paths();
        foreach (array($p['htmlauth'] . '/xiaomi_cloud.php', dirname(__DIR__) . '/htmlauth/xiaomi_cloud.php') as $k) {
            if (is_file($k)) {
                require_once $k;
                break;
            }
        }
    }
    if (!function_exists('xc_abruf')) {
        return false;
    }
    $d = xl_paths()['datadir'];
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    xc_ablage($d);
    return true;
}

/** Filter der Linie (Bauvorschrift, Stammmodul-Kopf): WLAN-Geraete der Modellreihen yeelink.light.* und yilai.light.*. */
function xl_cloud_filter()
{
    return array('modelle' => array('yeelink.light.', 'yilai.light.'), 'ble' => 0);
}

/**
 * Die Anzeige der Cloud-Liste OHNE Token/Bind-Key/Kennung (Index, Name, Modell, Adresse, Region, online 1/0/-1 laut
 * Cloud) - aus dem Ergebnis von xc_abruf() oder aus der liegenden Auswahl (xc_lesen(), nach einem Absenden ohne Haken).
 */
function xl_cloud_zeigen($liste)
{
    $aus = array();
    foreach ((array) $liste as $i => $g) {
        if (!is_array($g)) {
            continue;
        }
        $aus[] = array('i' => isset($g['i']) ? (int) $g['i'] : (int) $i,
                       'name' => isset($g['name']) ? (string) $g['name'] : '',
                       'modell' => isset($g['modell']) ? (string) $g['modell'] : '',
                       'ip' => isset($g['ip']) ? (string) $g['ip'] : '',
                       'region' => isset($g['region']) ? (string) $g['region'] : '',
                       'online' => (isset($g['online']) && in_array($g['online'], array(0, 1), true)) ? $g['online'] : -1);
    }
    return $aus;
}

/**
 * Mehrfachauswahl (Nachtrag 27.10.): die angehakten Eintraege der Auswahl mit EINEM Griff (xc_auswahl_mehrere(),
 * Stammmodul 1.0.1) in die Lampenzeilen uebernehmen. Je Geraet: Zeile mit gleicher Geraete-ID (did) -> nur Token,
 * Adresse und Modell auffrischen; Zeile OHNE did mit derselben Adresse (von Hand oder vor der Mehrfachauswahl angelegt)
 * gilt als dasselbe Geraet (auffrischen, did nachtragen); sonst die naechste freie Lampennummer (Name aus Mi Home).
 * Gespeichert wird EINMAL am Schluss. Die Token reisen nur im Speicher; Meldungen nennen nur Namen.
 * Rueckgabe array(lage, neu (Namen), aufgefrischt (Namen), abgewiesen (Grund => Namen), uebernommen (Nummer => Name,
 * fuer xl_cloud_pruefen())) mit lage ok | WAHL (nichts angehakt, Liste bleibt) | ABGELAUFEN | MODUL | SPEICHERN.
 */
function xl_cloud_uebernehmen(array $indizes)
{
    $stand = array('neu' => array(), 'frisch' => array(), 'ab' => array(), 'nrn' => array());
    if (!xl_cloud_bereit()) {
        return array('MODUL', array(), array(), array(), array());
    }
    $wahl = xc_auswahl_mehrere($indizes);
    if ($wahl === null) {
        return array('ABGELAUFEN', array(), array(), array(), array());
    }
    if (!$wahl) {
        return array('WAHL', array(), array(), array(), array());
    }
    $r = xl_regeln();
    $cfg = xl_config();
    $lampen = is_array($cfg['lampen']) ? array_values($cfg['lampen']) : array();
    foreach ($wahl as $g) {
        $tok = strtolower(trim((string) (isset($g['token']) ? $g['token'] : '')));
        $name = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) (isset($g['name']) ? $g['name'] : '')));
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 40, 'UTF-8') : substr($name, 0, 40);
        $did = (isset($g['did']) && is_string($g['did']) && $g['did'] !== '' && preg_match($r['did'], $g['did'])) ? $g['did'] : '';
        $modell = (isset($g['modell']) && is_string($g['modell']) && preg_match($r['modell'], $g['modell'])) ? $g['modell'] : '';
        $ip = (isset($g['ip']) && is_string($g['ip']) && $g['ip'] !== '' && xl_adresse_befund($g['ip']) === '') ? $g['ip'] : '';
        $zeigen = $name !== '' ? $name : ($modell !== '' ? $modell : '?');
        if (!preg_match($r['token'], $tok)) {
            $stand['ab']['TOKEN_FORM'][] = $zeigen;
            continue;
        }
        $treffer = -1;
        foreach ($lampen as $i => $z) {
            if ($did !== '' && is_array($z) && isset($z['did']) && (string) $z['did'] === $did) {
                $treffer = $i;
                break;
            }
        }
        if ($treffer < 0 && $ip !== '') {
            foreach ($lampen as $i => $z) {
                if (is_array($z) && (!isset($z['did']) || (string) $z['did'] === '') && isset($z['ip'])
                    && strtolower((string) $z['ip']) === strtolower($ip)) {
                    $treffer = $i;
                    break;
                }
            }
        }
        if ($treffer >= 0) {
            $lampen[$treffer]['token'] = $tok;
            if ($modell !== '') {
                $lampen[$treffer]['modell'] = $modell;
            }
            if ($did !== '') {
                $lampen[$treffer]['did'] = $did;
            }
            if ($ip !== '' && strtolower((string) $lampen[$treffer]['ip']) !== strtolower($ip)) {
                $belegt = false;
                foreach ($lampen as $i => $z) {
                    if ($i !== $treffer && is_array($z) && isset($z['ip']) && strtolower((string) $z['ip']) === strtolower($ip)) {
                        $belegt = true;
                    }
                }
                if (!$belegt) {
                    $lampen[$treffer]['ip'] = $ip;
                }
            }
            $stand['frisch'][] = $zeigen;
            $stand['nrn'][(int) $lampen[$treffer]['nr']] = $zeigen;
            continue;
        }
        if ($ip === '') {
            $stand['ab']['KEINE_IP'][] = $zeigen;
            continue;
        }
        foreach ($lampen as $z) {
            if (is_array($z) && isset($z['ip']) && strtolower((string) $z['ip']) === strtolower($ip)) {
                $stand['ab']['IP_DOPPELT'][] = $zeigen;
                continue 2;
            }
        }
        $probe = $cfg;
        $probe['lampen'] = $lampen;
        $nr = xl_naechste_nummer($probe);
        if ($nr === 0 || count($lampen) >= XL_LAMPEN_MAX) {
            $stand['ab']['VOLL'][] = $zeigen;
            continue;
        }
        $lampen[] = array('nr' => $nr, 'name' => $name !== '' ? $name : ('Lampe ' . $nr), 'ip' => $ip, 'token' => $tok,
                          'modell' => $modell, 'lan' => 'auto', 'did' => $did);
        $cfg['nr_hoechste'] = max((int) $cfg['nr_hoechste'], $nr);
        $stand['neu'][] = $zeigen;
        $stand['nrn'][$nr] = $zeigen;
    }
    $tok = '';
    unset($wahl, $g);
    if ($stand['neu'] || $stand['frisch']) {
        $cfg['lampen'] = $lampen;
        if (!xl_config_speichern($cfg)) {
            return array('SPEICHERN', array(), array(), $stand['ab'], array());
        }
    }
    return array('ok', $stand['neu'], $stand['frisch'], $stand['ab'], $stand['nrn']);
}

/**
 * Nach der Uebernahme (Nachtrag Geraetetest 27.10.): je uebernommener Zeile ein kurzer Gruss im Heimnetz (miIO hello,
 * 2 x 1 s) und bei Antwort miIO.info mit dem Token (ein Versuch, 2 s). Die Cloud kennt auch Lampen, die es nicht mehr
 * gibt - ihre Adresse ist dann veraltet, ihr Token passt zu keiner Lampe. Rueckgabe array(Nummer => array(Name,
 * ANTWORTET | STUMM | TOKEN_FALSCH | OFFEN)); OFFEN = nicht pruefbar (openssl fehlt, Token unlesbar). Token und Adresse
 * bleiben im Speicher; eine falsch verschluesselte oder ausbleibende Antwort auf miIO.info heisst "Token passt nicht"
 * (eine Lampe beantwortet ein Paket mit fremdem Token nicht).
 */
function xl_cloud_pruefen(array $nrn)
{
    $aus = array();
    $cfg = xl_config(false);
    foreach ($nrn as $nr => $name) {
        $l = xl_lampe((int) $nr, $cfg);
        if ($l === null) {
            continue;
        }
        $z = xl_miio_sitzung($l);
        if ($z['fehler'] === 'KEIN_GRUSS' || $z['fehler'] === 'NETZ') {
            $aus[(int) $nr] = array((string) $name, 'STUMM');
            continue;
        }
        if ($z['fehler'] !== '') {
            $aus[(int) $nr] = array((string) $name, 'OFFEN');
            continue;
        }
        list($lage) = xl_miio_rufen($z, 'miIO.info', array(), 2.0, 1);
        xl_miio_schliessen($z);
        $aus[(int) $nr] = array((string) $name, $lage === 'ok' ? 'ANTWORTET' : 'TOKEN_FALSCH');
    }
    return $aus;
}

/**
 * Code-Versand des Zweitfaktors (Geraetetest 27.10.): eine abgelehnte Antwort (Lage SENDEN mit art/code/grund aus dem
 * Stammmodul 1.0.1) protokollieren und in Saetze fassen. Rueckgabe array(gelungen, misslungen). Im Protokoll und in der
 * Meldung stehen nur Weg, Art, Antwortnummer und der bereinigte Text von Xiaomi - kein Konto, keine Kennung.
 */
function xl_cloud_versand(array $erg)
{
    if (!isset($erg['lage']) || $erg['lage'] !== 'SENDEN') {
        return array(array(), array());
    }
    $art = isset($erg['art']) ? (string) $erg['art'] : 'UNBEKANNT';
    $code = isset($erg['code']) ? (int) $erg['code'] : 0;
    $grund = isset($erg['grund']) ? (string) $erg['grund'] : '';
    $weg = (isset($erg['weg']) && $erg['weg'] === 'sms') ? 'SMS' : 'E-Mail';
    xl_log('Xiaomi-Cloud: Code-Versand per ' . $weg . ' abgelehnt (' . $art . ', Antwort ' . $code
        . ($grund !== '' ? ': ' . $grund : '') . ').');
    $antwort = sprintf(xl_t('CLOUD.ANTWORT_XIAOMI'), $code, $grund !== '' ? $grund : '-');
    if ($art === 'CAPTCHA') {
        return array(array(xl_t('CLOUD.M_SENDE_CAPTCHA')), array());
    }
    if ($art === 'SPERRE') {
        return array(array(), array(sprintf(xl_t('CLOUD.F_SPERRE'), $antwort)));
    }
    return array(array(), array(sprintf(xl_t('CLOUD.F_SENDEN_GRUND'), $antwort)));
}

/** Meldungen zu xl_cloud_pruefen(): array(gelungen, misslungen); nur Namen, nie Adresse oder Token. */
function xl_cloud_pruef_meldungen(array $erg)
{
    $gut = array();
    $schlecht = array();
    foreach ($erg as $e) {
        if ($e[1] === 'ANTWORTET') {
            $gut[] = sprintf(xl_t('CLOUD.G_ANTWORTET'), $e[0]);
        } elseif ($e[1] === 'STUMM') {
            $schlecht[] = sprintf(xl_t('CLOUD.G_STUMM'), $e[0]);
        } elseif ($e[1] === 'TOKEN_FALSCH') {
            $schlecht[] = sprintf(xl_t('CLOUD.G_TOKEN_FALSCH'), $e[0]);
        } else {
            $schlecht[] = sprintf(xl_t('CLOUD.G_OFFEN'), $e[0]);
        }
    }
    return array($gut, $schlecht);
}

/**
 * Die Meldungen zur Uebernahme (feste Schluessel, damit die Platzhalter pruefbar bleiben). Rueckgabe array(gelungen,
 * misslungen) - je eine Liste von Saetzen; die Namen stehen roh darin (die Oberflaeche maskiert beim Ausgeben).
 */
function xl_cloud_meldungen(array $erg)
{
    list($lage, $neu, $frisch, $ab) = $erg;    // [4] = Nummern fuer xl_cloud_pruefen()
    $gut = array();
    $schlecht = array();
    switch ($lage) {
        case 'MODUL':      return array(array(), array(xl_t('CLOUD.F_MODUL')));
        case 'ABGELAUFEN': return array(array(), array(xl_t('CLOUD.U_ABGELAUFEN')));
        case 'WAHL':       return array(array(), array(xl_t('CLOUD.U_WAHL')));
        case 'SPEICHERN':  $schlecht[] = xl_t('CLOUD.U_SPEICHERN'); break;
        default:           $gut[] = sprintf(xl_t('CLOUD.M_ERGEBNIS'), count($neu), count($frisch));
    }
    $namen = function ($grund) use ($ab) {
        return empty($ab[$grund]) ? '' : implode(', ', $ab[$grund]);
    };
    if ($namen('VOLL') !== '') {
        $schlecht[] = sprintf(xl_t('CLOUD.U_VOLL'), XL_LAMPEN_MAX, $namen('VOLL'));
    }
    if ($namen('KEINE_IP') !== '') {
        $schlecht[] = sprintf(xl_t('CLOUD.U_KEINE_IP'), $namen('KEINE_IP'));
    }
    if ($namen('IP_DOPPELT') !== '') {
        $schlecht[] = sprintf(xl_t('CLOUD.U_IP_DOPPELT'), $namen('IP_DOPPELT'));
    }
    if ($namen('TOKEN_FORM') !== '') {
        $schlecht[] = sprintf(xl_t('CLOUD.U_TOKEN_FORM'), $namen('TOKEN_FORM'));
    }
    return array($gut, $schlecht);
}

/* ==================================================================
 * Gesundheit (bin/healthcheck, Kopf der Oberflaeche) - nur Dateien, kein Abruf bei Lampe/Broker/Cloud (Regeln/04 :818)
 * ================================================================== */

/**
 * Rueckgabe array(status, Text) mit status 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis (Regeln/03 healthcheck; Entwurf
 * 3.6). Mehrere Befunde: der schwerste zaehlt, alle Texte werden genannt.
 */
function xl_gesundheit()
{
    $cfg = xl_config(false);
    $befunde = array();
    if (xl_config_lage() === 'kaputt') {
        $befunde[] = array(3, xl_klartext('HEALTH.CFG_KAPUTT'));
    }
    $lampen = xl_lampen($cfg);
    $soll = xl_dienst_soll();
    $leb = xl_dienst_lebenszeichen();
    if ($soll && ($leb < 0 || $leb > 180)) {
        $befunde[] = array(3, sprintf(xl_klartext('HEALTH.DIENST_STEHT'), $leb < 0 ? '-' : (string) $leb));
    }
    if (!extension_loaded('openssl')) {
        $befunde[] = array(4, xl_klartext('HEALTH.OPENSSL'));
    }
    $werte = xl_werte();
    $echtzeit = 0;
    $erreichbar = 0;
    foreach ($lampen as $nr => $l) {
        $w = isset($werte[$nr]) ? $werte[$nr] : array();
        list($modell) = xl_modell_holen($l);
        $tab = xl_modell($modell);
        if ($l['token'] === '' && ($l['lan'] === 'aus' || !$tab['lan'])) {
            $befunde[] = array(4, sprintf(xl_klartext('HEALTH.TOKEN_FEHLT'), $l['name']));
        }
        if ($modell !== '' && !$tab['bekannt']) {
            $befunde[] = array(6, sprintf(xl_klartext('HEALTH.MODELL_UNBEKANNT'), $l['name'], $modell));
        }
        if (!empty($w['erreichbar'])) {
            $erreichbar++;
            if ((int) (isset($w['weg']) ? $w['weg'] : 0) === 2) {
                $echtzeit++;
            }
        } elseif ($soll) {
            $zuletzt = isset($w['zuletzt']) ? (int) $w['zuletzt'] : 0;
            $seit = isset($w['seit']) ? (int) $w['seit'] : 0;
            $bezug = $zuletzt > 0 ? $zuletzt : $seit;
            if ($bezug > 0 && time() - $bezug > 900) {
                $befunde[] = array(4, sprintf(xl_klartext('HEALTH.LAMPE_WEG'), $l['name'], (int) floor((time() - $bezug) / 60)));
            }
        }
    }
    if (!$lampen) {
        $befunde[] = array(6, xl_klartext('HEALTH.KEINE_LAMPE'));
    }
    if (!$soll) {
        $befunde[] = array(6, xl_klartext('HEALTH.DIENST_AUS'));
    }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        list($bl) = xl_bruecke_lage();
        if ($bl === false) {
            $befunde[] = array(4, xl_klartext('HEALTH.BRUECKE'));
        }
    }
    if (!empty($cfg['mqtt_ein'])) {
        $v2 = xl_mqtt_v2_haken_lage();
        if ($v2[4] === 'v2' && $v2[3]) {
            $befunde[] = array(4, sprintf(xl_klartext('HEALTH.NONCACHED'), implode(', ', $v2[3])));
        }
    }
    if (!$befunde) {
        return array(5, sprintf(xl_klartext('HEALTH.OK'), $erreichbar, count($lampen), $echtzeit, $leb < 0 ? '-' : (string) $leb));
    }
    $stufe = 6;
    foreach ($befunde as $b) {
        if ($b[0] === 3 || ($b[0] === 4 && $stufe !== 3)) {
            $stufe = ($b[0] === 3) ? 3 : 4;
        }
    }
    $texte = array();
    foreach ($befunde as $b) {
        $texte[] = $b[1];
    }
    return array($stufe, implode(' ', $texte));
}

/* ==================================================================
 * Baustein-Liste (Reiter Einbindung, Schritt 6) - Zeilen aus der Sprachdatei, Abschnitt [BAUSTEIN]; die deutsche
 * Fassung ist wortgleich mit Entwurf Gruppe 34 Abschnitt 9 samt Nachtrag B1 (Status #15 mit V4) bzw.
 * liste_vorlaeufig_g34.txt (beim Bau erzeugt).
 * ================================================================== */

/** Rueckgabe array(Seitentitel => Liste array(Nr, Typ, Name, Parameter, Eingaenge)). */
function xl_baustein_seiten()
{
    $aus = array();
    for ($s = 1; $s <= 9; $s++) {
        $titel = xl_t('BAUSTEIN.SEITE_' . $s);
        if ($titel === 'BAUSTEIN.SEITE_' . $s) {
            break;
        }
        $zeilen = array();
        for ($i = 1; $i <= 99; $i++) {
            $k = sprintf('BAUSTEIN.S%d_%02d', $s, $i);
            $v = xl_t($k);
            if ($v === $k) {
                break;
            }
            $zeilen[] = array_pad(explode(' | ', $v, 5), 5, '');
        }
        $aus[$titel] = $zeilen;
    }
    return $aus;
}

/* ==================================================================
 * Lesezweig des Endpunkts (nur mit endpunkt_ein; Lese-Regel: fehlt -> 0)
 * ================================================================== */

/** XL;OK=..;N=..;ZAEHLER=..;L1_EIN=..;L1_HELL=..;L1_CT=..;L1_MODUS=..;L1_ERREICHBAR=..;L1_WEG=.. */
function xl_lesezeile()
{
    $werte = xl_werte();
    $lampen = xl_lampen();
    $ok = 0;
    $teile = array();
    foreach ($lampen as $nr => $l) {
        $w = isset($werte[$nr]) ? $werte[$nr] : array();
        $da = !empty($w['erreichbar']);
        if ($da) {
            $ok = 1;
        }
        foreach (array('ein' => 'EIN', 'hell' => 'HELL', 'ct' => 'CT', 'modus' => 'MODUS') as $k => $g) {
            $teile[] = 'L' . (int) $nr . '_' . $g . '=' . (($da && isset($w[$k]) && is_int($w[$k])) ? $w[$k] : -1);
        }
        $teile[] = 'L' . (int) $nr . '_ERREICHBAR=' . ($da ? 1 : 0);
        $teile[] = 'L' . (int) $nr . '_WEG=' . ($da ? (int) (isset($w['weg']) ? $w['weg'] : 0) : 0);
    }
    $ok = ($ok && xl_dienst_laeuft()) ? 1 : 0;
    return 'XL;OK=' . $ok . ';N=' . count($lampen) . ';ZAEHLER=' . xl_zaehler() . ($teile ? ';' . implode(';', $teile) : '');
}
