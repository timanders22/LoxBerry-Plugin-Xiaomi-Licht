<?php
/**
 * Xiaomi Licht - Reiter Test (Selbstpruefung ohne Loxone; Regeln/04 :356-429, Entwurf 3.7, E-E16).
 *
 * Pflichtzeilen (kosten kein Netz, laufen bei jedem Oeffnen des Reiters): Dienst, Lebenszeichen, Konfiguration heil und
 * vollstaendig, Vorgaben Oberflaeche = Dienst, Reiterleiste/Bereiche/Positivliste, Formulare mit Merkmal, Themenliste
 * gegen Sendecode, Vorlage wohlgeformt, openssl, Gateway V2 n von m Haken, Befehlsbruecke, Upgrade-Marke.
 * Netzpruefungen (eigener Endpunkt ?selftest, je Lampe Gruss/Token/Steckbrief/Yeelight-LAN/Altbefehle) laufen NUR, wenn
 * der Reiter Test serverseitig der offene ist (echter Seitenwechsel), mit 60 s Zwischenspeicher je Lampe
 * (data/plugins/<ordner>/test_<n>.json - ohne Token, ohne Kennung, ohne Antwort der Lampe).
 * Rueckgabe je Zeile array(ok true|false|null, Frage, Antwort) - null = Hinweis (kein Kreuz).
 */

/** Passen Reiterleiste, Bereiche und Positivliste zusammen? Gelesen wird die eigene Datei (eine Quelle). */
function xl_test_reiter_probe($datei = null)
{
    $datei = $datei !== null ? $datei : __DIR__ . '/index.php';
    $t = (string) @file_get_contents($datei);
    if ($t === '') {
        return array(false, xl_t('TEST.A_REITER_LESEN'));
    }
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $t, $m1);
    preg_match_all('/class="sm-seite<\?= \$xl_tab === \'tab-([a-z]+)\' \? \' sm-active\' : \'\' \?>" id="tab-([a-z]+)"/', $t, $m2);
    $muster = preg_match('#\$xl_muster = \'/\^tab-\(([a-z|]+)\)\$/\';#', $t, $m3) ? explode('|', $m3[1]) : array();
    preg_match_all("/^\s+'([a-z]+)'\s+=> (?:'REITER\.[A-Z]+'|null),/m", $t, $m4);
    $leiste = $m1[1];
    $bereiche = array();
    foreach ($m2[1] as $i => $a) {
        if ($a === $m2[2][$i]) {
            $bereiche[] = $a;
        }
    }
    $feld = $m4[1];
    $alle = array($leiste, $bereiche, $muster, $feld);
    $gleich = count($leiste) > 0 && $leiste === $bereiche && $leiste === $muster && $leiste === $feld;
    if ($gleich) {
        return array(true, sprintf(xl_t('TEST.A_REITER_OK'), count($leiste), count($bereiche), count($muster), count($feld)));
    }
    return array(false, sprintf(xl_t('TEST.A_REITER_FEHL'), count($leiste), count($bereiche), count($muster), count($feld))
        . ' (' . xl_e(json_encode($alle)) . ')');
}

/** Tragen alle Formulare das Merkmal? Zaehlung in der eigenen Datei. */
function xl_test_formulare_probe($datei = null)
{
    $datei = $datei !== null ? $datei : __DIR__ . '/index.php';
    $t = (string) @file_get_contents($datei);
    $formen = preg_match_all('/<form\b/', $t);
    $merk = preg_match_all('/name="fmt" value="<\?= xl_e\(\$xl_fmt\) \?>"/', $t);
    $art = preg_match_all('/name="formular" value="[a-z_]+"/', $t);
    $ok = $formen > 0 && $formen === $merk && $formen === $art;
    return array($ok, $ok ? sprintf(xl_t('TEST.A_FORM_OK'), $formen, $merk, $art) : sprintf(xl_t('TEST.A_FORM_FEHL'), $formen, $merk, $art));
}

/** Ist die Vorlage wohlgeformt? Parser ueber jede erzeugbare Datei (je eingetragene Lampe). */
function xl_test_vorlagen_probe()
{
    $n = 0;
    $schlecht = array();
    foreach (xl_lampen() as $nr => $l) {
        list(, $inhalt) = xl_vorlage_befehle($nr);
        $n++;
        $alt = libxml_use_internal_errors(true);
        $x = function_exists('simplexml_load_string') ? simplexml_load_string($inhalt) : null;
        libxml_use_internal_errors($alt);
        $crlf = substr_count($inhalt, "\r\n") === substr_count($inhalt, "\n");
        if ((function_exists('simplexml_load_string') && $x === false) || !$crlf) {
            $schlecht[] = $nr;
        }
    }
    if ($n === 0) {
        return array(null, xl_t('TEST.A_VORLAGE_KEINE'));
    }
    if (!function_exists('simplexml_load_string')) {
        return array(null, sprintf(xl_t('TEST.A_VORLAGE_OHNE_PARSER'), $n));
    }
    return array(!$schlecht, $schlecht ? sprintf(xl_t('TEST.A_VORLAGE_FEHL'), implode(', ', $schlecht))
                                       : sprintf(xl_t('TEST.A_VORLAGE_OK'), $n));
}

/** Den eigenen Endpunkt wirklich aufrufen (?selftest=1, 127.0.0.1, 3 s). Nur bei offenem Reiter. */
function xl_test_endpunkt()
{
    $p = xl_paths();
    $cfg = xl_config(false);
    if ($p['home'] === '' || !xl_token_gueltig($cfg['aktionstoken'])) {
        return array(null, xl_t('TEST.A_ENDPUNKT_UNKLAR'));
    }
    if (!ini_get('allow_url_fopen')) {
        return array(null, xl_t('TEST.A_ENDPUNKT_UNKLAR'));
    }
    $url = 'http://127.0.0.1/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1&token=' . rawurlencode($cfg['aktionstoken']);
    $ctx = stream_context_create(array('http' => array('timeout' => 3, 'ignore_errors' => true)));
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(null, xl_t('TEST.A_ENDPUNKT_KEINE_ANTWORT'));
    }
    $meta = @stream_get_meta_data($fp);
    $t = (string) @stream_get_contents($fp, 512);
    @fclose($fp);
    $code = 0;
    foreach ((isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) ? $meta['wrapper_data'] : array() as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];
        }
    }
    if ($code === 200 && strpos($t, 'SELFTEST;OK=1') === 0) {
        return array(true, xl_t('TEST.A_ENDPUNKT_OK'));
    }
    return array(false, sprintf(xl_t('TEST.A_ENDPUNKT_FEHL'), $code, xl_e(substr(trim($t), 0, 60))));
}

/** Die Pflichtzeilen. $offen = Reiter Test ist serverseitig offen (dann auch der Endpunktaufruf). */
function xl_test_zeilen($offen)
{
    $z = array();
    $cfg = xl_config(false);
    $pid = xl_dienst_pid();
    $laeuft = xl_dienst_laeuft();
    $z[] = array($laeuft ? true : (xl_dienst_soll() ? false : null), xl_t('TEST.F_DIENST'),
        $laeuft ? sprintf(xl_t('TEST.A_DIENST_LAEUFT'), $pid ? (string) $pid : '-')
                : xl_t(xl_dienst_soll() ? 'TEST.A_DIENST_STEHT' : 'TEST.A_DIENST_AUS'));
    $leb = xl_dienst_lebenszeichen();
    if ($laeuft) {
        $z[] = array($leb >= 0 && $leb <= 90, xl_t('TEST.F_LEBENSZEICHEN'),
            $leb < 0 ? xl_t('TEST.A_LEBEN_NIE') : sprintf(xl_t('TEST.A_LEBEN'), $leb, xl_zaehler()));
    } else {
        $z[] = array(null, xl_t('TEST.F_LEBENSZEICHEN'), xl_t('TEST.A_LEBEN_OHNE_DIENST'));
    }
    $lage = xl_config_lage();
    $z[] = array($lage === 'ok' ? true : ($lage === 'kaputt' ? false : null), xl_t('TEST.F_KONFIG'), xl_t('TEST.A_KONFIG_' . strtoupper($lage)));
    $roh = xl_json_lesen(xl_paths()['config']);
    $fehlt = array();
    foreach (array_keys(xl_vorgaben()) as $k) {
        if (!array_key_exists($k, $roh)) {
            $fehlt[] = $k;
        }
    }
    $nv = count(xl_vorgaben());
    $z[] = array($roh ? !$fehlt : null, xl_t('TEST.F_VOLLSTAENDIG'), $roh ? ($fehlt
        ? sprintf(xl_t('TEST.A_VOLL_FEHLT'), $nv - count($fehlt), $nv, xl_e(implode(', ', $fehlt)))
        : sprintf(xl_t('TEST.A_VOLL_OK'), $nv, $nv)) : xl_t('TEST.A_VOLL_LEER'));
    $z[] = xl_test_vorgaben_dienst();
    list($ok, $t) = xl_test_reiter_probe();
    $z[] = array($ok, xl_t('TEST.F_REITER'), $t);
    list($ok, $t) = xl_test_formulare_probe();
    $z[] = array($ok, xl_t('TEST.F_FORMULARE'), $t);
    list($ok, $n, $a, $b) = xl_mqtt_namen_probe();
    $z[] = array($ok, xl_t('TEST.F_THEMEN'), $ok ? sprintf(xl_t('TEST.A_THEMEN_OK'), $n)
        : sprintf(xl_t('TEST.A_THEMEN_FEHL'), xl_e(implode(', ', $a)), xl_e(implode(', ', $b))));
    list($ok, $t) = xl_test_vorlagen_probe();
    $z[] = array($ok, xl_t('TEST.F_VORLAGE'), $t);
    $z[] = array(extension_loaded('openssl'), xl_t('TEST.F_OPENSSL'), xl_t(extension_loaded('openssl') ? 'TEST.A_OPENSSL_OK' : 'TEST.A_OPENSSL_FEHLT'));
    $m = xl_mqtt_zustand();
    $z[] = array($m['gefunden'] ? (bool) $m['udpport'] : null, xl_t('TEST.F_GATEWAY'), $m['gefunden']
        ? sprintf(xl_t('TEST.A_GATEWAY'), $m['fassung'] > 0 ? (string) $m['fassung'] : '?', (int) $m['udpport'],
                  xl_t($m['autostart'] ? 'ALLG.EIN' : 'ALLG.AUS'))
        : xl_t('TEST.A_GATEWAY_UNKLAR'));
    $v2 = xl_mqtt_v2_haken_lage();
    if ($v2[4] === 'v2') {
        $z[] = array($v2[0] === 1, xl_t('TEST.F_V2'), sprintf(xl_t('TEST.A_V2'), $v2[1], $v2[2])
            . ($v2[3] ? ' ' . sprintf(xl_t('TEST.A_V2_NC'), xl_e(implode(', ', $v2[3]))) : ''));
    } else {
        $z[] = array(null, xl_t('TEST.F_V2'), xl_t($v2[4] === 'v1' ? 'TEST.A_V2_V1' : 'TEST.A_V2_UNKLAR'));
    }
    list($bl, $bt) = xl_bruecke_lage();
    $z[] = array($bl, xl_t('TEST.F_BRUECKE'), $bt);
    list($liegt, $gilt, $alter) = xl_upgrade_marke();
    $z[] = array($liegt ? ($gilt ? null : false) : true, xl_t('TEST.F_MARKE'),
        !$liegt ? xl_t('TEST.A_MARKE_KEINE') : ($gilt ? sprintf(xl_t('TEST.A_MARKE_GILT'), $alter) : xl_t('TEST.A_MARKE_ALT')));
    if ($offen) {
        list($ok, $t) = xl_test_endpunkt();
        $z[] = array($ok, xl_t('TEST.F_ENDPUNKT'), $t);
    }
    return $z;
}

/** Kennen Oberflaeche und Dienst dieselben Vorgaben? (bin/xl_dienst.php --vorgaben; ohne exec: Hinweis) */
function xl_test_vorgaben_dienst()
{
    $d = xl_paths()['bindir'] . '/xl_dienst.php';
    if (!is_file($d)) {
        $d = dirname(dirname(__DIR__)) . '/bin/xl_dienst.php';
    }
    if (!is_file($d) || !function_exists('exec')) {
        return array(null, xl_t('TEST.F_VORGABEN'), xl_t('TEST.A_VORGABEN_UNKLAR'));
    }
    $aus = array();
    $rc = 1;
    @exec('php ' . escapeshellarg($d) . ' --vorgaben 2>/dev/null', $aus, $rc);
    $j = ($rc === 0 && $aus) ? json_decode(implode('', $aus), true) : null;
    if (!is_array($j)) {
        return array(null, xl_t('TEST.F_VORGABEN'), xl_t('TEST.A_VORGABEN_UNKLAR'));
    }
    $gleich = ($j == xl_vorgaben());
    return array($gleich, xl_t('TEST.F_VORGABEN'), ($gleich ? sprintf(xl_t('TEST.A_VORGABEN_OK'), count($j)) : sprintf(xl_t('TEST.A_VORGABEN_FEHL'), count($j))));
}

/** Zwischenspeicher der Lampenpruefungen verwerfen (Knopf "Pruefungen neu"). */
function xl_test_zwischen_leeren()
{
    foreach (glob(xl_paths()['datadir'] . '/test_*.json') ?: array() as $f) {
        @unlink($f);
    }
}

/**
 * Pruefblock einer Lampe (nur bei offenem Reiter Test): (1) miIO-Gruss (ms, ohne Kennung), (2) Token passt (Werte
 * gelesen), (3) Steckbrief miIO.info (Modell, Firmware), (4) Yeelight-LAN (verbunden/abgewiesen), (5) Altbefehle
 * get_prop ja/nein, (6) Faehigkeiten aus xl_modelle(). Ergebnis 60 s zwischengespeichert. Sperre je Lampe gegen den
 * Dienst. Rueckgabe array('zeit', 'zeilen' => Liste array(ok, Frage, Antwort), 'faehig' => xl_faehigkeiten()).
 */
function xl_test_lampe(array $l)
{
    $f = xl_paths()['datadir'] . '/test_' . (int) $l['nr'] . '.json';
    $merk = xl_modell_merkmal($l);
    $c = xl_json_lesen($f);
    if ($c && isset($c['zeit'], $c['merkmal'], $c['zeilen']) && $c['merkmal'] === $merk
        && time() - (int) $c['zeit'] < XL_TEST_ZWISCHEN_S && (int) $c['zeit'] <= time() + 60) {
        return $c;
    }
    $zeilen = array();
    $sp = xl_lampen_sperre($l['nr'], 6);
    $modell = '';
    $fw = '';
    if ($sp === false) {
        $zeilen[] = array(null, xl_t('TEST.L_GRUSS'), xl_t('TEST.L_BELEGT'));
    } else {
        $s = xl_miio_strom($l['ip'], isset($l['miio_port']) ? $l['miio_port'] : XL_MIIO_PORT);
        $gruss = false;
        if ($s !== null) {
            list($gruss, , , $ms) = xl_miio_gruss($s, 1.0, 2);
            @fclose($s);
        }
        $zeilen[] = array($gruss, xl_t('TEST.L_GRUSS'), $gruss ? sprintf(xl_t('TEST.L_GRUSS_OK'), $ms) : xl_t('TEST.L_GRUSS_KEIN'));
        if ($gruss && $l['token'] !== '' && extension_loaded('openssl')) {
            $z = xl_miio_sitzung($l);
            if ($z['fehler'] === '') {
                list($lage, $info) = xl_miio_info($z);
                if ($lage === 'ok') {
                    $modell = $info['modell'];
                    $fw = $info['fw'];
                    $tab = xl_modell($modell);
                    list($wl, $w, $wc, $wa) = xl_lampe_lesen($z, $tab);
                    $zeilen[] = array($wl === 'ok', xl_t('TEST.L_TOKEN'), $wl === 'ok' ? sprintf(xl_t('TEST.L_TOKEN_OK'),
                        xl_test_werte_text($w) . ($wa === 'alt' ? ' (get_prop)' : ''))
                        : sprintf(xl_t('TEST.L_TOKEN_WERTE'), xl_e(xl_lese_grund($wl, $wc))));
                    $zeilen[] = array($tab['bekannt'] ? true : null, xl_t('TEST.L_STECKBRIEF'), ($tab['bekannt'] ? sprintf(xl_t('TEST.L_STECKBRIEF_OK'), xl_e($modell), xl_e($fw !== '' ? $fw : '-'))
                        : sprintf(xl_t('TEST.L_STECKBRIEF_FREMD'), xl_e($modell), xl_e($fw !== '' ? $fw : '-'))));
                    list($al) = xl_alt_lesen($z);
                    $zeilen[] = array(null, xl_t('TEST.L_ALT'), xl_t($al === 'ok' ? 'TEST.L_ALT_JA' : 'TEST.L_ALT_NEIN'));
                    if (is_dir(dirname($f)) && $modell !== '') {
                        xl_modell_holen($l, $z);
                    }
                } else {
                    $zeilen[] = array(false, xl_t('TEST.L_TOKEN'), xl_t($lage === 'token' ? 'TEST.L_TOKEN_FALSCH' : 'TEST.L_TOKEN_KEINE'));
                }
                xl_miio_schliessen($z);
            } else {
                $zeilen[] = array(false, xl_t('TEST.L_TOKEN'), sprintf(xl_t('TEST.L_SITZUNG'), $z['fehler']));
            }
        } elseif ($l['token'] === '') {
            $zeilen[] = array(null, xl_t('TEST.L_TOKEN'), xl_t('TEST.L_TOKEN_FEHLT'));
        } elseif (!$gruss) {
            $zeilen[] = array(null, xl_t('TEST.L_TOKEN'), xl_t('TEST.L_TOKEN_OHNE_GRUSS'));
        } elseif (!extension_loaded('openssl')) {
            $zeilen[] = array(false, xl_t('TEST.L_TOKEN'), xl_t('TEST.A_OPENSSL_FEHLT'));
        }
        $v = xl_yee_verbinden($l['ip'], isset($l['lan_port']) ? $l['lan_port'] : XL_LAN_PORT, 1.0);
        if ($v === null) {
            $zeilen[] = array(null, xl_t('TEST.L_LAN'), xl_t('TEST.L_LAN_ZU'));
        } else {
            list($ll) = xl_yee_rufen($v, 'get_prop', array('power', 'bright', 'ct', 'active_mode', 'nl_br'), 2.0);
            xl_yee_schliessen($v);
            $zeilen[] = array($ll === 'ok' ? true : null, xl_t('TEST.L_LAN'), xl_t($ll === 'ok' ? 'TEST.L_LAN_OK' : 'TEST.L_LAN_ABGEWIESEN'));
        }
        xl_lampen_sperre_frei($sp);
    }
    if ($modell === '') {
        list($modell) = xl_modell_holen($l);
    }
    $faehig = xl_faehigkeiten(xl_modell($modell));
    $c = array('zeit' => time(), 'merkmal' => $merk, 'zeilen' => $zeilen, 'faehig' => $faehig, 'modell' => $modell);
    if (is_dir(dirname($f))) {
        xl_json_schreiben($f, $c, 0644);
    }
    return $c;
}

/**
 * Zusammenfassung fuer den hellgruenen Kasten oben im ersten Reiter (Kopf wie Dashboard): wie viele Lampen, wer meldet
 * sich selbst (Yeelight-LAN), wer wird abgefragt, wer ist nicht erreichbar.
 */
function xl_test_zusammenfassung(array $lampen, array $werte, array $cfg)
{
    if (!$lampen) {
        return xl_t('EINST.ZUS_KEINE');
    }
    $push = array();
    $abfrage = array();
    $weg = array();
    foreach ($lampen as $nr => $l) {
        $w = isset($werte[$nr]) ? $werte[$nr] : array();
        if (empty($w['erreichbar'])) {
            $weg[] = $l['name'];
        } elseif ((int) $w['weg'] === 2) {
            $push[] = $l['name'];
        } else {
            $abfrage[] = $l['name'];
        }
    }
    $t = sprintf(xl_t('EINST.ZUS_ANZAHL'), count($lampen), count($push) + count($abfrage));
    if ($push) {
        $t .= ' ' . sprintf(xl_t('EINST.ZUS_PUSH'), implode(', ', $push));
    }
    if ($abfrage) {
        $t .= ' ' . sprintf(xl_t('EINST.ZUS_ABFRAGE'), implode(', ', $abfrage), (int) $cfg['abfrage_s']);
    }
    if ($weg) {
        $t .= ' ' . sprintf(xl_t('EINST.ZUS_WEG'), implode(', ', $weg));
    }
    if (!xl_dienst_laeuft()) {
        $t .= ' ' . xl_t('EINST.ZUS_DIENST_AUS');
    }
    return $t;
}

/** Werte als kurzer Text (ohne Token): an, 50 %, 4000 K, Tageslicht. */
function xl_test_werte_text(array $w)
{
    $t = array();
    $t[] = $w['ein'] === 1 ? xl_t('ALLG.AN') : ($w['ein'] === 0 ? xl_t('ALLG.AUS') : '-');
    $t[] = $w['hell'] >= 0 ? $w['hell'] . ' %' : '-';
    $t[] = $w['ct'] >= 0 ? $w['ct'] . ' K' : '-';
    if ($w['modus'] >= 0) {
        $t[] = xl_t($w['modus'] === 1 ? 'ALLG.NACHTLICHT' : 'ALLG.TAGESLICHT');
    }
    return implode(', ', $t);
}
