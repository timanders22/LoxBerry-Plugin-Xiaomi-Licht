<?php
/**
 * bin/xl_dienst.php - Dauerdienst des Plugins Xiaomi Licht (Entwurf Gruppe 34, 2.4 Vorschlag 1, E-E6/E-E7).
 *
 * Je Lampe EIN Rueckmeldeweg:
 *   weg 2  Yeelight-LAN: EINE gehaltene TCP-Verbindung (drei von vier bleiben fuer App/Home Assistant frei); "props"
 *          sofort auswerten und senden; Abgleich get_prop nach jedem (Neu-)Verbinden und alle 5 min. Bricht die
 *          Verbindung ab: sofort weg 1 (mit Token) und Neuverbindung nach 5, 10, 30, 60 s (Hoechstwert). Mit Token
 *          laeuft daneben die miIO-Abfrage alle XL_LAN_MIIO_S (30 s) als Takt und Rueckfall (Nachbesserung 2).
 *   weg 1  miIO-Abfrage: EIN Lesepaket je Lampe und Takt abfrage_s (Werk 10 s) - get_properties oder, wo die Lampe
 *          das ablehnt, get_prop (Lesart je Modell/Lampe, xl_lampe_lesen; ceiling1 gemessen 28.10.); erreichbar 0 erst
 *          nach 3 Fehlschlaegen (ein verlorener Gruss ist kein Ausfall).
 *   weg 0  nicht erreichbar (stromlos, WLAN, falsche Adresse/Token) -> ein/hell/ct/modus -1 (E-E9).
 * Yeelight-LAN nur, wenn die Zeile lan=auto hat UND das Modell laut Tabelle LAN kann - oder kein Token eingetragen ist
 * (LAN braucht keinen Token). Takt-Herz alle 30 s: lauf.json + Lebenszeichen status/zaehler|ok|ts (MQTT-Gateway V2).
 * Befehle an ceiling1 (Nachbesserung 2, gemessen 28.10.): Altbefehle set_power/set_bright/set_ct_abx, bevorzugt ueber
 * die LAN-Verbindung (Quote 50/min, sonst miIO); lehnt eine Lampe den Schaltweg ab, einmal der andere, je Lampe gemerkt.
 * Werte: Aenderung sofort, voller Satz alle 30 min und nach dem Start. Befehle: Warteschlange data/.../warteschlange/
 * (Endpunkt, Reiter Test, Befehlsbruecke), eine Sperre je Lampe um den Geraetezugriff (Regeln/03), Antwort nach dem
 * MIoT-Code. Bei frischer Upgrade-Marke ruht der Dienst (kein Geraetezugriff, Saugroboter S19).
 * Weggefallene Zeilen (leeren, loeschen, Sicherung zurueckspielen): beim Start und bei jedem Wegfall stellt der Dienst
 * deren zurueckbehaltene Themen am Broker auf -1 und liest zurueck (xl_mqtt_geister_raeumen, Nachbesserung 28.10.).
 *
 * Aufrufformen:
 *   php xl_dienst.php               Dienst (gestartet NUR ueber bin/dienst.sh: flock auf 8, Start mit 8<&-)
 *   php xl_dienst.php --selbsttest  Rechenkern ohne Netz: "N Faelle geprueft, M Fehlschlaege."
 *   php xl_dienst.php --vorgaben    Vorgabeliste als JSON (Reiter Test: kennen Oberflaeche und Dienst dieselben?)
 *   php xl_dienst.php --mqtt-leeren zurueckbehaltene Themen xiaomilicht/lampe<n>/ein|hell|ct|modus abraeumen (Deinstallation)
 * Kein Token, keine Antwort einer Lampe, keine Kennung im Protokoll (Regeln/05).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$xl_kand = array();
if (basename(dirname(__DIR__)) === 'plugins') {
    $xl_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/xl_lib.php';
} else {
    $xl_kand[] = dirname(__DIR__) . '/webfrontend/html/xl_lib.php';
}
$xl_geladen = false;
foreach ($xl_kand as $xl_k) {
    if (is_file($xl_k)) {
        require_once $xl_k;
        $xl_geladen = true;
        break;
    }
}
if (!$xl_geladen) {
    fwrite(STDERR, "xl_dienst.php: xl_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}

$xl_args = isset($argv) ? array_slice($argv, 1) : array();
if (in_array('--vorgaben', $xl_args, true)) {
    echo json_encode(xl_vorgaben()) . "\n";
    exit(0);
}
if (in_array('--selbsttest', $xl_args, true)) {
    exit(xl_dienst_selbsttest());
}
if (in_array('--mqtt-leeren', $xl_args, true)) {
    /* Aus uninstall/uninstall: zurueckbehaltene Themen dieser Linie abraeumen (liest nur, legt nichts an). */
    if (xl_paths()['home'] === '') {
        echo "<INFO> MQTT: keine Anlage gefunden - zurueckbehaltene Themen wurden nicht abgeraeumt.\n";
        exit(0);
    }
    echo implode("\n", xl_mqtt_abraeumen()) . "\n";
    exit(0);
}
if ($xl_args) {
    fwrite(STDERR, "Aufruf: php xl_dienst.php [--selbsttest|--vorgaben|--mqtt-leeren]\n");
    exit(2);
}

xl_keine_wurzel_abbruch('xl_dienst.php');
$xl_p = xl_paths();
foreach (array($xl_p['datadir'], $xl_p['logdir']) as $xl_o) {
    if (!is_dir($xl_o)) {
        @mkdir($xl_o, 0775, true);
    }
}
@chmod($xl_p['datadir'], 0700);
$xl_sperre = @fopen($xl_p['datadir'] . '/dienst.sperre', 'c');
if ($xl_sperre === false || !@flock($xl_sperre, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Es laeuft bereits ein Xiaomi-Licht-Dienst (Sperre dienst.sperre belegt) - dieser endet.\n");
    exit(3);
}

$GLOBALS['xl_ende'] = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $xl_halt = function () {
        $GLOBALS['xl_ende'] = true;
    };
    pcntl_signal(SIGTERM, $xl_halt);
    pcntl_signal(SIGINT, $xl_halt);
}

xl_log('Dienst gestartet (PID ' . getmypid() . ', PHP ' . PHP_VERSION . (extension_loaded('openssl') ? '' : ', OHNE openssl') . ').');
xl_dienst_lauf();
xl_log('Dienst beendet.');
exit(0);

/* ==================================================================
 * Dienst
 * ================================================================== */

/** Zustand einer Lampe neu aufsetzen (Start, Aenderung von Adresse/Token/Modell/LAN). */
function xl_dz_neu(array $l)
{
    list($modell) = xl_modell_holen($l);
    return array('l' => $l, 'merk' => xl_dz_merkmal($l), 'modell' => $modell, 'tab' => xl_modell($modell),
                 'z' => null, 'v' => null, 'weg' => 0, 'erreichbar' => 0, 'fehl' => 0,
                 'werte' => array('ein' => -1, 'hell' => -1, 'ct' => -1, 'modus' => -1),
                 'abfrage' => 0.0, 'lan_naechst' => 0.0, 'lan_stufe' => 0, 'abgleich' => 0.0,
                 'zuletzt' => 0, 'seit' => time(), 'grund' => '', 'modell_geprueft' => false, 'lesart' => '',
                 'schaltart' => '', 'lan_zeiten' => array());
}

function xl_dz_merkmal(array $l)
{
    return md5(json_encode(array($l['ip'], $l['token'], $l['modell'], $l['lan'],
        isset($l['miio_port']) ? $l['miio_port'] : 0, isset($l['lan_port']) ? $l['lan_port'] : 0)));
}

function xl_dz_lan_gewollt(array $d)
{
    return $d['l']['lan'] === 'auto' && (!empty($d['tab']['lan']) || $d['l']['token'] === '');
}

function xl_dz_schliessen(array &$d)
{
    if (is_array($d['z'])) {
        xl_miio_schliessen($d['z']);
    }
    $d['z'] = null;
    xl_yee_schliessen($d['v']);
}

/** Lage einer Lampe protokollieren - einmal je Wechsel, nie Token/Antwort/Kennung. */
function xl_dz_lage($nr, array &$d, $grund)
{
    $d['grund'] = $grund;
    $text = 'Lampe ' . (int) $nr . ' (' . $d['l']['name'] . '): ';
    if ($grund === 'ok') {
        $text .= 'erreichbar, Rueckmeldung ' . ($d['weg'] === 2 ? 'ueber Yeelight-LAN (Push)' : 'ueber miIO-Abfrage') . '.';
    } elseif ($grund === 'KEIN_GRUSS' || $grund === 'NETZ') {
        $text .= 'antwortet nicht im Netz (WLAN pruefen) - stromlos, nicht im WLAN oder falsche Adresse (' . $grund . ').';
    } elseif (strpos($grund, 'MIIO ') === 0) {
        $text .= 'antwortet, lehnt aber das Lesen ab (' . $grund . ').';
    } else {
        $text .= 'nicht erreichbar (' . $grund . ').';
    }
    xl_log_wenn_neu('lampe' . (int) $nr, $grund === 'ok' ? 'ok' . $d['weg'] : $grund, $text);
}

/** Werte uebernehmen (nur bekannte Schluessel, nur ganze Zahlen). */
function xl_dz_werte(array &$d, array $neu)
{
    foreach (array('ein', 'hell', 'ct', 'modus') as $k) {
        if (array_key_exists($k, $neu) && is_int($neu[$k])) {
            $d['werte'][$k] = $neu[$k];
        }
    }
    if (empty($d['tab']['modus'])) {        // ceiling1 hat keinen Modus - auch ueber LAN -1 (Entwurf 4), nie active_mode 0
        $d['werte']['modus'] = -1;
    }
}

/** miIO-Sitzung sicherstellen. Rueckgabe Fehlerwort oder ''. */
function xl_dz_sitzung(array &$d)
{
    if (is_array($d['z']) && isset($d['z']['s']) && is_resource($d['z']['s'])) {
        return '';
    }
    $z = xl_miio_sitzung($d['l']);
    if ($z['fehler'] !== '') {
        $d['z'] = null;
        return $z['fehler'];
    }
    $d['z'] = $z;
    if (!$d['modell_geprueft']) {
        $d['modell_geprueft'] = true;
        list($mod, , $quelle) = xl_modell_holen($d['l'], $d['z']);
        if ($mod !== '' && $mod !== $d['modell']) {
            $d['modell'] = $mod;
            $d['tab'] = xl_modell($mod);
            $d['lesart'] = '';
            $d['schaltart'] = '';
        }
        if ($quelle === 'lampe' && !$d['tab']['bekannt']) {
            xl_log('Lampe ' . (int) $d['l']['nr'] . ': unbekanntes Modell ' . $mod . ' - Grundform (schalten, Helligkeit).');
        }
    }
    return '';
}

/** Eine miIO-Abfrage. Rueckgabe true bei Erfolg. */
function xl_dz_abfragen($nr, array &$d)
{
    $sp = xl_lampen_sperre($nr, 2);
    if ($sp === false) {
        return false;      // Reiter Test haelt die Lampe gerade; naechster Takt
    }
    $f = xl_dz_sitzung($d);
    $ok = false;
    if ($f === '') {
        list($lage, $w, $code, $lesart) = xl_lampe_lesen($d['z'], $d['tab'], $d['lesart']);
        if ($lesart !== ($d['lesart'] !== '' ? $d['lesart'] : (isset($d['tab']['lesen']) ? $d['tab']['lesen'] : 'miot'))) {
            xl_log('Lampe ' . (int) $nr . ': liest jetzt mit ' . ($lesart === 'alt' ? 'get_prop (miIO-Altbefehle) - get_properties lehnt sie ab'
                : 'get_properties (MIoT) - get_prop lehnt sie ab') . '.');
        }
        $d['lesart'] = $lesart;
        if ($lage === 'ok') {
            $ok = true;
            xl_dz_werte($d, $w);
        } else {
            $f = xl_lese_grund($lage, $code);
            if ($lage === 'keine_antwort' || $lage === 'token') {
                xl_dz_schliessen_miio($d);
            }
        }
    }
    xl_lampen_sperre_frei($sp);
    if ($ok) {
        $d['fehl'] = 0;
        $d['zuletzt'] = time();
        if ($d['weg'] !== 2) {
            $d['weg'] = 1;
        }
        $d['erreichbar'] = 1;
        xl_dz_lage($nr, $d, 'ok');
        return true;
    }
    $d['fehl']++;
    if ($d['fehl'] >= XL_FEHL_GRENZE && $d['weg'] !== 2) {
        xl_dz_ausfall($d);
        xl_dz_lage($nr, $d, $f);
    }
    return false;
}

function xl_dz_schliessen_miio(array &$d)
{
    if (is_array($d['z'])) {
        xl_miio_schliessen($d['z']);
    }
    $d['z'] = null;
}

function xl_dz_ausfall(array &$d)
{
    $d['erreichbar'] = 0;
    $d['weg'] = 0;
    $d['werte'] = array('ein' => -1, 'hell' => -1, 'ct' => -1, 'modus' => -1);
}

/** Yeelight-LAN verbinden und abgleichen. */
function xl_dz_lan_verbinden($nr, array &$d)
{
    $port = isset($d['l']['lan_port']) ? (int) $d['l']['lan_port'] : XL_LAN_PORT;
    $v = xl_yee_verbinden($d['l']['ip'], $port, 1.0);
    $stufen = array(5, 10, 30, 60);
    if ($v === null) {
        $d['lan_naechst'] = microtime(true) + $stufen[min($d['lan_stufe'], 3)];
        $d['lan_stufe']++;
        return false;
    }
    $d['v'] = $v;
    if (!xl_dz_lan_abgleich($d)) {
        xl_yee_schliessen($d['v']);
        $d['lan_naechst'] = microtime(true) + $stufen[min($d['lan_stufe'], 3)];
        $d['lan_stufe']++;
        return false;
    }
    $d['lan_stufe'] = 0;
    $d['weg'] = 2;
    $d['erreichbar'] = 1;
    $d['fehl'] = 0;
    $d['zuletzt'] = time();
    xl_dz_schliessen_miio($d);
    xl_dz_lage($nr, $d, 'ok');
    return true;
}

/** get_prop auf der LAN-Verbindung (nach dem Verbinden und alle 5 min). */
function xl_dz_lan_abgleich(array &$d)
{
    xl_dz_quote_buchen($d, 1);
    list($lage, $r, $props) = xl_yee_rufen($d['v'], 'get_prop', array('power', 'bright', 'ct', 'active_mode', 'nl_br'), 2.0);
    foreach ($props as $pp) {
        xl_dz_werte($d, xl_yee_abbilden($pp));
    }
    $d['abgleich'] = microtime(true);
    if ($lage !== 'ok' || !is_array($r) || count($r) !== 5) {
        return false;
    }
    xl_dz_werte($d, xl_yee_abbilden(array('power' => (string) $r[0], 'bright' => (string) $r[1], 'ct' => (string) $r[2],
                                          'active_mode' => (string) $r[3], 'nl_br' => (string) $r[4])));
    $d['zuletzt'] = time();
    return true;
}

/** Die LAN-Verbindung ist zu: weg 1 (mit Token, sofort abfragen) oder Ausfall; Neuverbindung nach 5 s. */
function xl_dz_lan_zu($nr, array &$d)
{
    xl_yee_schliessen($d['v']);
    $d['lan_naechst'] = microtime(true) + 5;
    $d['lan_stufe'] = 1;
    if ($d['l']['token'] !== '') {
        $d['weg'] = 1;
        $d['abfrage'] = 0.0;
        xl_log_wenn_neu('lan' . (int) $nr, 'zu', 'Lampe ' . (int) $nr . ': Yeelight-LAN-Verbindung geschlossen - weiter mit miIO-Abfrage.');
    } else {
        xl_dz_ausfall($d);
        xl_dz_lage($nr, $d, 'LAN_ZU');
    }
}

/** Einen Auftrag der Warteschlange ausfuehren. Rueckgabe array(ok 0|1, info). */
function xl_dz_befehl(array &$lampen, array $a)
{
    $cmd = isset($a['cmd']) ? (string) $a['cmd'] : '';
    $wert = isset($a['wert']) ? (string) $a['wert'] : '';
    if (xl_befehl_wert($cmd, $wert) === null) {
        return array(0, 'WERT');
    }
    $ziele = (isset($a['lampe']) && $a['lampe'] === 'alle') ? array_keys($lampen) : array((int) (isset($a['lampe']) ? $a['lampe'] : 0));
    $erg = array(1, '');
    foreach ($ziele as $nr) {
        if (!isset($lampen[$nr])) {
            return array(0, 'LAMPE_UNBEKANNT');
        }
        list($ok, $info) = xl_dz_befehl_lampe($nr, $lampen[$nr], $cmd, $wert);
        if ($erg[1] === '' || (!$ok && $erg[0] === 1)) {
            $erg = array($ok ? 1 : 0, $info);
        }
    }
    return $erg;
}

/**
 * Befehlsquote der Yeelight-LAN-Verbindung (Spezifikation: 60 Befehle je Minute und Verbindung, 144 fuer alle
 * Verbindungen der Lampe). Das Plugin bucht jede gesendete Zeile (Befehl wie Abgleich) und haelt XL_LAN_QUOTE (50) je
 * gleitende Minute ein; was darueber hinausgeht, geht ueber miIO (keine Quote) - so wird ein Dimmer nicht abgeschnitten.
 */
function xl_dz_quote_frei(array &$d, $n)
{
    $grenze = microtime(true) - 60.0;
    $d['lan_zeiten'] = array_values(array_filter($d['lan_zeiten'], function ($t) use ($grenze) {
        return $t > $grenze;
    }));
    return count($d['lan_zeiten']) + (int) $n <= XL_LAN_QUOTE;
}

function xl_dz_quote_buchen(array &$d, $n)
{
    for ($i = 0; $i < (int) $n; $i++) {
        $d['lan_zeiten'][] = microtime(true);
    }
}

/** Schaltart ueber miIO: gemerkte der Lampe, sonst aus der Tabelle ('alt' | 'miot'). */
function xl_dz_schaltart(array $d)
{
    if ($d['schaltart'] === 'alt' || $d['schaltart'] === 'miot') {
        return $d['schaltart'];
    }
    return (isset($d['tab']['schalten']) && $d['tab']['schalten'] === 'alt') ? 'alt' : 'miot';
}

function xl_dz_befehl_lampe($nr, array &$d, $cmd, $wert)
{
    if ($cmd === 'abruf') {
        if ($d['weg'] === 2 && is_array($d['v'])) {
            return xl_dz_lan_abgleich($d) ? array(true, 'LAN 0') : array(false, 'KEINE_ANTWORT');
        }
        if ($d['l']['token'] === '') {
            return array(false, 'TOKEN_FEHLT');
        }
        return xl_dz_abfragen($nr, $d) ? array(true, $d['lesart'] === 'alt' ? 'MIIO 0' : 'MIOT 0')
            : array(false, $d['grund'] !== '' && $d['grund'] !== 'ok' ? $d['grund'] : 'KEINE_ANTWORT');
    }
    /* Gemeinsame Frist: die Bruecke wartet hoechstens 6 s (E-E12). */
    $bis = microtime(true) + 5.0;
    $lan_da = is_array($d['v']) && !empty($d['v']['offen']);
    if ($d['l']['token'] === '' && empty($d['tab']['lan_schalten'])) {
        /* Ohne Token nur ueber LAN (ceiling3 mit eingeschalteter LAN-Steuerung, E-E7). */
        if (!$lan_da) {
            return array(false, 'TOKEN_FEHLT');
        }
        $ft = xl_faehigkeiten($d['tab']['bekannt'] ? $d['tab'] : xl_modell('yeelink.light.ceiling3'));
        if (($cmd === 'ct' && !$ft['ct']) || ($cmd === 'nacht' && !$ft['nacht'])) {
            return array(false, 'NICHT_UNTERSTUETZT');
        }
        $r = xl_yee_befehl($d['v'], $cmd, $wert, $d['werte']);
        if (!$d['v']['offen']) {
            xl_dz_lan_zu($nr, $d);
        }
        return $r;
    }
    /* Nachbesserung 2: Modelle mit 'lan_schalten' (ceiling1) schalten bevorzugt ueber die gehaltene LAN-Verbindung mit
       den gemessenen Altbefehlen; ohne Verbindung, ueber der Quote oder wenn schon der erste Schritt dort scheitert:
       miIO (alle Schritte sind absolute Sollwerte, eine Wiederholung schadet nicht). */
    if (!empty($d['tab']['lan_schalten'])) {
        list($gut, $folge) = xl_alt_folge($d['tab'], $cmd, $wert, $d['werte']);
        if (!$gut) {
            return array(false, $folge);
        }
        if ($lan_da && xl_dz_quote_frei($d, count($folge))) {
            list($ok, $info, $vorab, $props, $n) = xl_yee_folge($d['v'], $folge, 1.0, $bis);
            xl_dz_quote_buchen($d, $n);
            foreach ($props as $pp) {
                xl_dz_werte($d, xl_yee_abbilden($pp));
                $d['zuletzt'] = time();
            }
            if (!$d['v']['offen']) {
                xl_dz_lan_zu($nr, $d);
            }
            if ($ok || !$vorab || $d['l']['token'] === '') {
                return array($ok, $info);
            }
            xl_log_wenn_neu('lanbefehl' . (int) $nr, $info, 'Lampe ' . (int) $nr . ': Befehl ueber Yeelight-LAN nicht angenommen ('
                . $info . ') - weiter ueber miIO.');
        } elseif ($lan_da) {
            xl_log_gebremst('quote' . (int) $nr, 'Lampe ' . (int) $nr . ': Befehlsquote der Yeelight-LAN-Verbindung erreicht ('
                . XL_LAN_QUOTE . ' je Minute) - Befehle gehen solange ueber miIO.', 300);
            if ($d['l']['token'] === '') {
                return array(false, 'GEDROSSELT');
            }
        } elseif ($d['l']['token'] === '') {
            return array(false, 'TOKEN_FEHLT');
        }
    }
    $art = xl_dz_schaltart($d);
    list($gut_m, $gebaut) = xl_befehl_bauen($d['tab'], $cmd, $wert, (int) $d['werte']['ein']);
    list($gut_a, $folge) = xl_alt_folge($d['tab'], $cmd, $wert, $d['werte']);
    if ($art === 'alt' ? !$gut_a : !$gut_m) {
        return array(false, $art === 'alt' ? $folge : $gebaut);
    }
    $sp = xl_lampen_sperre($nr, 2);
    if ($sp === false) {
        return array(false, 'BELEGT');
    }
    $f = xl_dz_sitzung($d);
    if ($f !== '') {
        xl_lampen_sperre_frei($sp);
        return array(false, $f);
    }
    /* xl_dz_sitzung kann das Modell eben erst aus miIO.info gelernt haben: Tabelle und Art neu */
    $art = xl_dz_schaltart($d);
    list($gut_m, $gebaut) = xl_befehl_bauen($d['tab'], $cmd, $wert, (int) $d['werte']['ein']);
    list($gut_a, $folge) = xl_alt_folge($d['tab'], $cmd, $wert, $d['werte']);
    if ($art === 'alt' ? !$gut_a : !$gut_m) {
        xl_lampen_sperre_frei($sp);
        return array(false, $art === 'alt' ? $folge : $gebaut);
    }
    /* Frist fuer Befehle: 1,2 s je Paket, hoechstens 2 Pakete - die Bruecke wartet hoechstens 6 s (E-E12). */
    if ($art === 'alt') {
        list($ok, $info, $vorab) = xl_alt_befehl($d['z'], $folge, 1.2, $bis);
    } else {
        list($ok, $info) = xl_miot_befehl($d['z'], $gebaut, 1.2);
        $vorab = !$ok && strpos($info, 'MIIO ') === 0;      // die Lampe lehnte set_properties/action als Ganzes ab
    }
    /* Rueckfall wie beim Lesen: lehnt die Lampe den einen Weg ganz ab, EINMAL den anderen; gelingt er, gilt er fortan. */
    if (!$ok && $vorab && microtime(true) + 0.5 < $bis) {
        $anders = ($art === 'alt') ? 'miot' : 'alt';
        $ok2 = false;
        $info2 = '';
        if ($anders === 'alt' && $gut_a && (!empty($d['tab']['alt']) || empty($d['tab']['bekannt']))) {
            list($ok2, $info2) = xl_alt_befehl($d['z'], $folge, 1.2, $bis);
        } elseif ($anders === 'miot' && $gut_m) {
            list($ok2, $info2) = xl_miot_befehl($d['z'], $gebaut, 1.2);
        }
        if ($ok2) {
            $d['schaltart'] = $anders;
            xl_log('Lampe ' . (int) $nr . ': schaltet jetzt mit ' . ($anders === 'alt'
                ? 'set_power/set_bright/set_ct_abx (miIO-Altbefehle) - set_properties lehnt sie ab (' . $info . ')'
                : 'set_properties (MIoT) - die Altbefehle lehnt sie ab (' . $info . ')') . '.');
            $ok = true;
            $info = $info2;
        }
    }
    if (!$ok && in_array($info, array('KEINE_ANTWORT', 'TOKEN'), true)) {
        xl_dz_schliessen_miio($d);
    }
    xl_lampen_sperre_frei($sp);
    if ($ok && $d['weg'] !== 2) {
        $d['abfrage'] = 0.0;        // gleich nachlesen (ohne Push)
    }
    return array($ok, $info);
}

/** Warteschlange abarbeiten (aelteste zuerst); Auftraege aelter als 30 s werden verworfen (kein spaetes Schalten). */
function xl_dz_warteschlange(array &$lampen)
{
    $o = xl_warteschlange_ordner();
    $liste = is_dir($o) ? glob($o . '/*.json') : array();
    if (!$liste) {
        return false;
    }
    sort($liste);
    $antw = xl_paths()['datadir'] . '/antworten';
    if (!is_dir($antw)) {
        @mkdir($antw, 0775, true);
    }
    $etwas = false;
    $auftraege = array();
    foreach ($liste as $f) {
        $a = json_decode((string) @file_get_contents($f), true);
        @unlink($f);
        if (is_array($a) && isset($a['kennung']) && preg_match('/^[0-9a-f]{16}\z/', (string) $a['kennung'])) {
            $auftraege[] = $a;
        }
    }
    usort($auftraege, function ($x, $y) {
        return (int) $x['ts'] - (int) $y['ts'];
    });
    foreach ($auftraege as $a) {
        $alter = time() - (int) (isset($a['ts']) ? $a['ts'] : 0);
        if ($alter > 30 || $alter < -60) {
            xl_log('Befehl verworfen (' . $alter . ' s alt).');
            continue;
        }
        list($ok, $info) = xl_dz_befehl($lampen, $a);
        xl_json_schreiben($antw . '/' . $a['kennung'] . '.json', array('ok' => $ok, 'info' => $info), 0644);
        xl_log('Befehl ' . (isset($a['cmd']) ? preg_replace('/[^a-z]/', '', (string) $a['cmd']) : '?') . ' '
            . (isset($a['wert']) ? preg_replace('/[^a-z0-9]/', '', (string) $a['wert']) : '') . ' an Lampe '
            . (isset($a['lampe']) ? ($a['lampe'] === 'alle' ? 'alle' : (int) $a['lampe']) : '?')
            . ((isset($a['quelle']) && $a['quelle'] === 'mqtt') ? ' (ueber MQTT)' : '') . ': ' . ($ok ? 'ausgefuehrt' : 'abgelehnt') . ' - ' . $info);
        $etwas = true;
    }
    foreach (glob($antw . '/*.json') ?: array() as $f) {
        if (time() - (int) @filemtime($f) > 120) {
            @unlink($f);     // niemand hat sie abgeholt
        }
    }
    return $etwas;
}

/** werte.json schreiben (fuer Oberflaeche, Endpunkt, healthcheck). */
function xl_dz_werte_schreiben(array $lampen)
{
    $aus = array();
    foreach ($lampen as $nr => $d) {
        $aus[$nr] = array('name' => $d['l']['name'], 'erreichbar' => $d['erreichbar'], 'weg' => $d['weg'],
                          'ein' => $d['werte']['ein'], 'hell' => $d['werte']['hell'], 'ct' => $d['werte']['ct'],
                          'modus' => $d['werte']['modus'], 'fehl' => $d['fehl'], 'zuletzt' => $d['zuletzt'],
                          'seit' => $d['seit'], 'modell' => $d['modell'], 'grund' => $d['grund'], 'ts' => time());
    }
    xl_json_schreiben(xl_paths()['datadir'] . '/werte.json', array('ts' => time(), 'lampen' => $aus), 0644);
}

function xl_dienst_lauf()
{
    $lampen = array();
    $cfg = xl_config(false);
    $gesendet = array();
    $zaehler = 0;
    $herz = 0.0;
    $voll = 0.0;
    $cfg_zeit = 0.0;
    $erste_runde = true;
    $geaendert = true;
    $raeumen = 0.0;            // naechste Pruefung weggefallener Zeilen am Broker (0 = beim ersten Takt, Bestand)
    while (!$GLOBALS['xl_ende']) {
        $jetzt = microtime(true);
        /* Konfiguration je Takt neu lesen; geaenderte Lampen neu aufsetzen, entfernte schliessen. */
        if ($jetzt - $cfg_zeit >= XL_TAKT_S || $cfg_zeit === 0.0) {
            $cfg_zeit = $jetzt;
            $cfg = xl_config(false);
            $soll = xl_lampen($cfg);
            foreach ($lampen as $nr => $d) {
                if (!isset($soll[$nr]) || xl_dz_merkmal($soll[$nr]) !== $d['merk']) {
                    xl_dz_schliessen($lampen[$nr]);
                    unset($lampen[$nr]);
                    $geaendert = true;
                    if (!isset($soll[$nr])) {
                        $raeumen = 0.0;
                        unset($gesendet['lampe' . (int) $nr . '/erreichbar'], $gesendet['lampe' . (int) $nr . '/weg']);
                        foreach (array('ein', 'hell', 'ct', 'modus') as $k) {
                            unset($gesendet['lampe' . (int) $nr . '/' . $k]);
                        }
                    }
                }
            }
            foreach ($soll as $nr => $l) {
                if (!isset($lampen[$nr])) {
                    $lampen[$nr] = xl_dz_neu($l);
                    $geaendert = true;
                } else {
                    $lampen[$nr]['l']['name'] = $l['name'];
                }
            }
        }
        list(, $marke) = xl_upgrade_marke();
        if ($marke) {
            foreach ($lampen as $nr => $d) {
                xl_dz_schliessen($lampen[$nr]);
            }
            sleep(2);
            continue;
        }
        if (xl_dz_warteschlange($lampen)) {
            $geaendert = true;
        }
        $jetzt = microtime(true);
        $takt = max(5, min(60, (int) $cfg['abfrage_s']));
        foreach ($lampen as $nr => $d) {
            $vorher = array($d['erreichbar'], $d['weg'], $d['werte']);
            if (xl_dz_lan_gewollt($lampen[$nr]) && !is_array($lampen[$nr]['v']) && $jetzt >= $lampen[$nr]['lan_naechst']) {
                xl_dz_lan_verbinden($nr, $lampen[$nr]);
            }
            if (is_array($lampen[$nr]['v']) && $jetzt - $lampen[$nr]['abgleich'] >= XL_ABGLEICH_S) {
                if (!xl_dz_lan_abgleich($lampen[$nr]) || !$lampen[$nr]['v']['offen']) {
                    xl_dz_lan_zu($nr, $lampen[$nr]);
                }
            }
            if ($lampen[$nr]['weg'] === 2 && $lampen[$nr]['l']['token'] !== '' && $jetzt >= $lampen[$nr]['abfrage']) {
                /* Nachbesserung 2: neben der LAN-Verbindung bleibt die miIO-Abfrage als Takt (XL_LAN_MIIO_S) - eine stromlose
                   Lampe schliesst die TCP-Verbindung nicht. Scheitert sie, sofort ein Abgleich ueber LAN; scheitert auch
                   der, ist die Verbindung tot (weg 1, die Abfrage entscheidet dann ueber den Ausfall). */
                $lampen[$nr]['abfrage'] = $jetzt + max($takt, XL_LAN_MIIO_S);
                if (!xl_dz_abfragen($nr, $lampen[$nr])) {
                    if (!xl_dz_lan_abgleich($lampen[$nr]) || !$lampen[$nr]['v']['offen']) {
                        xl_dz_lan_zu($nr, $lampen[$nr]);
                    }
                }
            } elseif ($lampen[$nr]['weg'] !== 2 && $jetzt >= $lampen[$nr]['abfrage']) {
                $lampen[$nr]['abfrage'] = $jetzt + $takt;
                if ($lampen[$nr]['l']['token'] !== '') {
                    xl_dz_abfragen($nr, $lampen[$nr]);
                } elseif (!is_array($lampen[$nr]['v'])) {
                    if ($lampen[$nr]['erreichbar'] || $lampen[$nr]['grund'] === '') {
                        xl_dz_ausfall($lampen[$nr]);
                        xl_dz_lage($nr, $lampen[$nr], xl_dz_lan_gewollt($lampen[$nr]) ? 'LAN_ZU' : 'TOKEN_FEHLT');
                    }
                }
            }
            if ($vorher !== array($lampen[$nr]['erreichbar'], $lampen[$nr]['weg'], $lampen[$nr]['werte'])) {
                $geaendert = true;
            }
        }
        /* Auf LAN-Pushes warten - hoechstens 0,25 s, damit Warteschlange und Abfragen puenktlich bleiben. */
        $lesen = array();
        $wem = array();
        foreach ($lampen as $nr => $d) {
            if (is_array($d['v']) && is_resource($d['v']['s'])) {
                $lesen[] = $d['v']['s'];
                $wem[(int) $d['v']['s']] = $nr;
            }
        }
        if ($lesen) {
            $w = null;
            $e = null;
            $n = @stream_select($lesen, $w, $e, 0, 250000);
            if ($n > 0) {
                foreach ($lesen as $s) {
                    $nr = isset($wem[(int) $s]) ? $wem[(int) $s] : 0;
                    if (!$nr) {
                        continue;
                    }
                    $vorher = $lampen[$nr]['werte'];
                    foreach (xl_yee_lesen($lampen[$nr]['v'], 0.0) as $m) {
                        if ($m['art'] === 'props') {
                            xl_dz_werte($lampen[$nr], xl_yee_abbilden($m['p']));
                            $lampen[$nr]['zuletzt'] = time();
                        }
                    }
                    if (!$lampen[$nr]['v']['offen']) {
                        xl_dz_lan_zu($nr, $lampen[$nr]);
                        $geaendert = true;
                    } elseif ($vorher !== $lampen[$nr]['werte']) {
                        $geaendert = true;
                    }
                }
            }
        } else {
            usleep(250000);
        }
        /* Senden: Aenderungen sofort, voller Satz alle 30 min, Lebenszeichen je Takt (30 s). */
        $jetzt = microtime(true);
        $herz_faellig = ($jetzt - $herz >= XL_TAKT_S);
        if ($herz_faellig) {
            $herz = $jetzt;
            $zaehler = $erste_runde ? 0 : ($zaehler + 1) % 1000;
            xl_json_schreiben(xl_paths()['datadir'] . '/lauf.json',
                array('ts' => time(), 'zaehler' => $zaehler, 'pid' => getmypid(), 'lampen' => count($lampen)), 0644);
        }
        if ($geaendert || $herz_faellig) {
            xl_dz_werte_schreiben($lampen);
        }
        if (($geaendert || $herz_faellig) && !empty($cfg['mqtt_ein'])) {
            $werte = array();
            $ok = 0;
            foreach ($lampen as $nr => $d) {
                $werte[$nr] = array('erreichbar' => $d['erreichbar'], 'weg' => $d['weg']) + $d['werte'];
                if ($d['erreichbar']) {
                    $ok = 1;
                }
            }
            $paare = xl_mqtt_paare($werte, array('zaehler' => $zaehler, 'ok' => $ok), time());
            $voll_faellig = ($jetzt - $voll >= XL_VOLL_S);
            $raus = array();
            foreach ($paare as $t => $v) {
                $status = strpos($t, 'status/') === 0;
                if ($voll_faellig || ($status && $herz_faellig) || (!$status && (!array_key_exists($t, $gesendet) || $gesendet[$t] !== $v))) {
                    $raus[$t] = $v;
                }
            }
            if ($raus && xl_mqtt_senden($raus) >= 0) {
                foreach ($raus as $t => $v) {
                    $gesendet[$t] = $v;
                }
                if ($voll_faellig) {
                    $voll = $jetzt;
                }
            }
        }
        /* Themen nicht (mehr) eingetragener Lampen am Broker auf -1 (beim Start: Bestand; danach bei jedem Wegfall). */
        if (!empty($cfg['mqtt_ein']) && $raeumen >= 0.0 && microtime(true) >= $raeumen) {
            list($fertig, $zeilen) = xl_mqtt_geister_raeumen(array_keys($lampen));
            foreach ($zeilen as $zl) {
                xl_log_wenn_neu('raeumen', md5($zl), $zl);
            }
            $raeumen = $fertig ? -1.0 : microtime(true) + 600;
        }
        $geaendert = false;
        $erste_runde = false;
    }
    foreach ($lampen as $nr => $d) {
        xl_dz_schliessen($lampen[$nr]);
    }
}

/* ==================================================================
 * Selbsttest (Rechenkern, ohne Netz)
 * ================================================================== */

function xl_dienst_selbsttest()
{
    $n = 0;
    $f = 0;
    $p = function ($name, $ist, $soll) use (&$n, &$f) {
        $n++;
        if ($ist !== $soll) {
            $f++;
            echo 'FEHL ' . $name . ': ist ' . json_encode($ist) . ', soll ' . json_encode($soll) . "\n";
        }
    };
    $c3 = xl_modell('yeelink.light.ceiling3');
    $c1 = xl_modell('yilai.light.ceiling1');
    $gr = xl_modell('philips.light.bulb');
    $p('S1 ct ceiling3 auf 2/4', xl_befehl_bauen($c3, 'ct', '3000'), array(true, array('art' => 'set', 'p' => array(array('siid' => 2, 'piid' => 4, 'value' => 3000)))));
    $p('S2 ct ceiling1 auf 2/3 (N2)', xl_befehl_bauen($c1, 'ct', '3000'), array(true, array('art' => 'set', 'p' => array(array('siid' => 2, 'piid' => 3, 'value' => 3000)))));
    $p('S3 nacht ceiling1 nicht unterstuetzt', xl_befehl_bauen($c1, 'nacht', '1'), array(false, 'NICHT_UNTERSTUETZT'));
    $p('S4 hell 101 WERT', xl_befehl_bauen($c3, 'hell', '101'), array(false, 'WERT'));
    $p('S5 ct 2600 WERT', xl_befehl_bauen($c3, 'ct', '2600'), array(false, 'WERT'));
    $p('S6 hell 0 = aus', xl_befehl_bauen($c1, 'hell', '0'), array(true, array('art' => 'set', 'p' => array(array('siid' => 2, 'piid' => 1, 'value' => false)))));
    $p('S7 hell 40 schaltet ein', xl_befehl_bauen($c1, 'hell', '40'), array(true, array('art' => 'set', 'p' => array(
        array('siid' => 2, 'piid' => 1, 'value' => true), array('siid' => 2, 'piid' => 2, 'value' => 40)))));
    $p('S8 nacht 1 ceiling3: an + Modus 2', xl_befehl_bauen($c3, 'nacht', '1'), array(true, array('art' => 'set', 'p' => array(
        array('siid' => 2, 'piid' => 1, 'value' => true), array('siid' => 2, 'piid' => 3, 'value' => 2)))));
    $p('S9 umschalten ceiling3 = Aktion 2/1', xl_befehl_bauen($c3, 'umschalten', '1')[1]['art'], 'aktion');
    $p('S10 umschalten ceiling1 aus -> ein', xl_befehl_bauen($c1, 'umschalten', '1', 0), array(true, array('art' => 'set', 'p' => array(array('siid' => 2, 'piid' => 1, 'value' => true)))));
    $p('S11 umschalten ohne Zustand', xl_befehl_bauen($c1, 'umschalten', '1', -1), array(false, 'ZUSTAND_UNBEKANNT'));
    $p('S12 szene nacht ceiling1', xl_befehl_bauen($c1, 'szene', 'nacht'), array(false, 'NICHT_UNTERSTUETZT'));
    $p('S13 szene gemuetlich ceiling1', count(xl_befehl_bauen($c1, 'szene', 'gemuetlich')[1]['p']), 3);
    $p('S14 Grundform ct nicht unterstuetzt', xl_befehl_bauen($gr, 'ct', '3000'), array(false, 'NICHT_UNTERSTUETZT'));
    $p('S15 Grundform unbekannt', $gr['bekannt'], false);
    $p('S16 Wert "4;rm" abgewiesen', xl_befehl_wert('hell', '4;rm'), null);
    $p('S17 Wert szene lesen', xl_befehl_wert('szene', 'lesen'), 'lesen');
    $p('S18 Wert ct 6500', xl_befehl_wert('ct', '6500'), '6500');
    $p('S19 props Teilmeldung', xl_yee_abbilden(array('power' => 'on', 'bright' => '10')), array('ein' => 1, 'hell' => 10));
    $p('S20 props Nachtlicht hell = nl_br', xl_yee_abbilden(array('active_mode' => '1', 'bright' => '80', 'nl_br' => '5')), array('modus' => 1, 'hell' => 5));
    $p('S21 props kaputte Werte -> -1', xl_yee_abbilden(array('bright' => '300', 'ct' => 'x')), array('hell' => -1, 'ct' => -1));
    $paare = xl_mqtt_paare(array(1 => array('erreichbar' => 0, 'weg' => 2, 'ein' => 1, 'hell' => 50, 'ct' => 4000, 'modus' => 0)),
                           array('zaehler' => 7, 'ok' => 0), 1000);
    $p('S22 Ausfall -> -1 und weg 0', array($paare['lampe1/ein'], $paare['lampe1/hell'], $paare['lampe1/weg'], $paare['lampe1/erreichbar']), array(-1, -1, 0, 0));
    $p('S23 Gateway-Name', xl_gateway_name('lampe1/erreichbar'), 'xiaomilicht_lampe1_erreichbar');
    $p('S24 retain ein ja, erreichbar nein', array(xl_mqtt_retain('lampe3/ein'), xl_mqtt_retain('lampe3/erreichbar'), xl_mqtt_retain('status/ok')), array(true, false, false));
    list($pr_ok, $pr_n) = xl_mqtt_namen_probe();
    $p('S25 Themenliste gegen Sendecode', array($pr_ok, $pr_n), array(true, 10));
    $p('S26 V2-Haken Lampe 1 (8 + Bruecke)', count(xl_mqtt_hakenliste(1, true)), 9);
    $p('S27 V2-Haken weitere Lampe', count(xl_mqtt_hakenliste(2, false)), 6);
    $p('S28 Bremse: App-Aenderung bremst nicht', xl_bremse_zustand_passt(array('erreichbar' => 1, 'ein' => 1, 'hell' => 80), 'hell', '50'), false);
    $p('S29 Bremse: Echo bremst', xl_bremse_zustand_passt(array('erreichbar' => 1, 'ein' => 1, 'hell' => 50), 'hell', '50'), true);
    $p('S30 miIO-Token-Form', array(xl_miio_token_bin('00112233445566778899AABBCCDDEEFF') !== false, xl_miio_token_bin('0011')), array(true, false));
    $p('S31 ceiling1 liest mit get_prop (gemessen 28.10.)', array($c1['lesen'], $c3['lesen'], $gr['lesen']), array('alt', 'miot', 'miot'));
    $p('S32 Grundwort: Lampe lehnt ab -> MIIO-Code, nie KEINE_ANTWORT', array(xl_lese_grund('fehler', -5000), xl_lese_grund('keine_antwort', 0),
        xl_lese_grund('token', 0), xl_lese_grund('form', 0)), array('MIIO -5000', 'KEINE_ANTWORT', 'TOKEN', 'FORM'));
    /* Nachbesserung 2 (28.10.): Schalten an ceiling1 mit den gemessenen Altbefehlen */
    $an = array('set_power', array('on', 'smooth', 500));
    $aus = array('set_power', array('off', 'smooth', 500));
    $p('S33 Schaltweg je Modell (ceiling1 alt + LAN, ceiling3 miot, Grundform miot)', array($c1['schalten'], $c1['lan_schalten'], $c1['lan'],
        $c3['schalten'], $c3['lan_schalten'], $gr['schalten']), array('alt', true, true, 'miot', false, 'miot'));
    $p('S34 hell 30 bei eingeschalteter Lampe = nur set_bright', xl_alt_folge($c1, 'hell', '30', array('ein' => 1)),
        array(true, array(array('set_bright', array(30, 'smooth', 500)))));
    $p('S35 hell 30 bei unbekanntem Zustand = set_power on + set_bright', xl_alt_folge($c1, 'hell', '30', array('ein' => -1)),
        array(true, array($an, array('set_bright', array(30, 'smooth', 500)))));
    $p('S36 ct 3000 an / ct 2600 WERT / ct 6500 aus = an + ct', array(xl_alt_folge($c1, 'ct', '3000', array('ein' => 1)),
        xl_alt_folge($c1, 'ct', '2600', array('ein' => 1)), xl_alt_folge($c1, 'ct', '6500', array('ein' => 0))),
        array(array(true, array(array('set_ct_abx', array(3000, 'smooth', 500)))), array(false, 'WERT'),
        array(true, array($an, array('set_ct_abx', array(6500, 'smooth', 500))))));
    $p('S37 nacht ceiling1 NICHT_UNTERSTUETZT, szene gemuetlich aus = 3 Schritte', array(xl_alt_folge($c1, 'nacht', '1'),
        xl_alt_folge($c1, 'szene', 'gemuetlich', array('ein' => 0))), array(array(false, 'NICHT_UNTERSTUETZT'),
        array(true, array($an, array('set_bright', array(30, 'smooth', 500)), array('set_ct_abx', array(2700, 'smooth', 500))))));
    $p('S38 umschalten ein -> set_power off, ohne Zustand abgewiesen', array(xl_alt_folge($c1, 'umschalten', '1', array('ein' => 1)),
        xl_alt_folge($c1, 'umschalten', '1')), array(array(true, array($aus)), array(false, 'ZUSTAND_UNBEKANNT')));
    $p('S39 schalten 1 / hell 0 / hell 101', array(xl_alt_folge($c1, 'schalten', '1'), xl_alt_folge($c1, 'hell', '0', array('ein' => 1)),
        xl_alt_folge($c1, 'hell', '101')), array(array(true, array($an)), array(true, array($aus)), array(false, 'WERT')));
    echo $n . ' Faelle geprueft, ' . $f . " Fehlschlaege.\n";
    return $f === 0 ? 0 : 1;
}
