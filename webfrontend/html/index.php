<?php
/**
 * Xiaomi Licht - Endpunkt fuer den Miniserver und den Reiter Test (webfrontend/html/, ohne Anmeldung erreichbar).
 * Entwurf Gruppe 34, 3.5 (Entscheidung 3: endpunkt_ein ab Werk aus; Referenz Heimkino, Regeln/03 :97).
 *
 *   ?selftest=1&token=<t>          prueft nur das Token, ohne Wirkung, vor jedem Geraetekontakt:
 *                                  SELFTEST;OK=1;TOKEN=OK | SELFTEST;OK=0;ERR=TOKEN | SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET
 *   ?cmd=<befehl>&wert=<w>&lampe=<n|alle>&token=<t>
 *                                  derselbe Weg wie die Befehlsbruecke (xl_befehl_annehmen()); immer offen, aber nur mit
 *                                  dem zweiten Haken "Lampen aus Loxone schalten" (ausser abruf). Fuer neue Projekte gehen
 *                                  Befehle ueber das MQTT-Gateway V2 (Regeln/07 :303) - dieser Weg dient dem Reiter Test.
 *   ?token=<t>  [&json=1]          Lesezweig XL;OK=..;L1_EIN=.. - NUR mit endpunkt_ein 1, sonst 403
 *                                  CMD;OK=0;GRUND=ENDPUNKT_AUS (gebremst protokolliert). Lese-Regel: fehlt -> 0.
 * Der Endpunkt legt nichts an und schreibt keine Konfiguration (xl_config(false), Regeln/05).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
require_once __DIR__ . '/xl_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$xl_cfg = xl_config(false);
$xl_soll = is_string($xl_cfg['aktionstoken']) ? $xl_cfg['aktionstoken'] : '';
$xl_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
$xl_selbsttest = isset($_GET['selftest']) && is_string($_GET['selftest']) && $_GET['selftest'] === '1';

if ($xl_soll === '') {
    http_response_code(403);
    echo $xl_selbsttest ? "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n" : "CMD;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    exit;
}
if (!xl_token_gueltig($xl_soll) || !hash_equals($xl_soll, $xl_ist)) {
    http_response_code(403);
    echo $xl_selbsttest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "CMD;OK=0;GRUND=TOKEN\n";
    exit;
}
if ($xl_selbsttest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

if (isset($_GET['cmd'])) {
    $xl_cmd = is_string($_GET['cmd']) ? $_GET['cmd'] : '';
    $xl_wert = (isset($_GET['wert']) && is_string($_GET['wert'])) ? $_GET['wert'] : '';
    $xl_lroh = (isset($_GET['lampe']) && is_string($_GET['lampe'])) ? $_GET['lampe'] : '1';
    if (!preg_match('/^([1-9][0-9]?|alle)\z/', $xl_lroh) || !preg_match('/^[a-z]{1,16}\z/', $xl_cmd)) {
        http_response_code(400);
        echo "CMD;OK=0;GRUND=PARAMETER\n";
        exit;
    }
    $xl_lampe = $xl_lroh === 'alle' ? 'alle' : (int) $xl_lroh;
    $xl_r = xl_befehl_annehmen($xl_lampe, $xl_cmd, $xl_wert, $xl_cfg, 'http');
    if ((int) $xl_r['http'] !== 200) {
        http_response_code((int) $xl_r['http']);
    }
    echo xl_befehl_zeile($xl_r, $xl_cmd, $xl_lampe) . "\n";
    exit;
}

if (empty($xl_cfg['endpunkt_ein'])) {
    http_response_code(403);
    xl_log_gebremst('endpunkt_aus', 'Endpunkt: Leseanfrage abgewiesen - der HTTP-Lesezweig ist aus (Reiter Einbindung in '
        . 'Loxone, Haken "HTTP-Lesezweig zulassen"). Werte kommen ueber das MQTT-Gateway V2.', 3600);
    echo "CMD;OK=0;GRUND=ENDPUNKT_AUS\n";
    exit;
}
if (isset($_GET['json']) && $_GET['json'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $xl_aus = array();
    $xl_w = xl_werte();
    foreach (xl_lampen($xl_cfg) as $xl_nr => $xl_l) {
        $xl_e = isset($xl_w[$xl_nr]) ? $xl_w[$xl_nr] : array();
        $xl_da = !empty($xl_e['erreichbar']);
        $xl_aus[] = array('nr' => $xl_nr, 'name' => $xl_l['name'], 'erreichbar' => $xl_da ? 1 : 0,
                          'weg' => $xl_da ? (int) (isset($xl_e['weg']) ? $xl_e['weg'] : 0) : 0,
                          'ein' => $xl_da && isset($xl_e['ein']) ? (int) $xl_e['ein'] : -1,
                          'hell' => $xl_da && isset($xl_e['hell']) ? (int) $xl_e['hell'] : -1,
                          'ct' => $xl_da && isset($xl_e['ct']) ? (int) $xl_e['ct'] : -1,
                          'modus' => $xl_da && isset($xl_e['modus']) ? (int) $xl_e['modus'] : -1);
    }
    echo json_encode(array('zaehler' => xl_zaehler(), 'lampen' => $xl_aus), JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}
echo xl_lesezeile() . "\n";
