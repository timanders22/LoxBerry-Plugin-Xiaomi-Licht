<?php
/**
 * Xiaomi Licht - Bedienoberflaeche (Gruppe 34, Entwurf 3.7, E-E16; Regeln/04; Kopf wie Dashboard, Entscheidung Nr. 43).
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien. Diese Datei ist NUR Oberflaeche: die Lampen
 * liest der Dienst (bin/xl_dienst.php), der Miniserver spricht mit webfrontend/html/index.php bzw. ueber das
 * MQTT-Gateway V2. Netz nur auf Knopfdruck (Suche, Cloud-Abruf) und im Reiter Test, wenn er serverseitig offen ist.
 * Jedes Formular traegt das Merkmal (fmt) und formular=<name>; EINE Pruefung vor allen Handlern; jeder POST endet mit
 * 303 (Einmalmeldung). Token: Passwortfeld, nie vorbefuellt, leeres Feld behaelt, Loeschhaken (Regeln/04 :246, /05).
 * Praefix 'xl_', weil LBWeb::lbheader() SDK-Globale setzt. PHP 7.4, 8.4, 8.5.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$xl_gefunden_lib = false;
if (basename(dirname(__DIR__)) === 'plugins') {
    $xl_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/xl_lib.php');
} else {
    $xl_kandidaten = array(dirname(__DIR__) . '/html/xl_lib.php');
}
foreach ($xl_kandidaten as $xl_kandidat) {
    if (is_file($xl_kandidat)) {
        require_once $xl_kandidat;
        $xl_gefunden_lib = true;
        break;
    }
}
if (!$xl_gefunden_lib) {
    echo '<p><b>Fehler:</b> xl_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/xl_test.php';

$xl_p = xl_paths();
if ($xl_p['home'] !== '' && is_file($xl_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $xl_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $xl_p['home'] . '/libs/phplib/loxberry_web.php';
}
if ($xl_p['home'] !== '' && !is_dir($xl_p['datadir'])) {
    @mkdir($xl_p['datadir'], 0700, true);
}

/* Die Reiter an EINER Stelle; Leiste und Bereiche sind ausgeschrieben, die Pruefzeile im Reiter Test misst sie gegen
 * dieses Feld und den Ausdruck darunter (Regeln/04). */
$xl_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$xl_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$xl_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && preg_match($xl_muster, $_POST['activetab'])) {
    $xl_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && preg_match($xl_muster, 'tab-' . $_GET['form'])) {
    $xl_tab = 'tab-' . $_GET['form'];
}

$xl_meldungen = array();
$xl_fehler = array();      // Beanstandungen beim Speichern - es wurde nichts gespeichert
$xl_misslungen = array();  // ein Vorgang ohne Speichern ist nicht gelungen
$xl_hinweise = array();    // Hinweise, die das Speichern nicht verhinderten
$xl_testausgabe = '';
$xl_zusatz = array();
$xl_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$xl_post_roh = $xl_post;

if ($xl_p['home'] !== '') {
    xl_token();     // legt das Aktionstoken beim ersten Oeffnen an (Grundlage des Formularmerkmals)
}
$xl_fmt = xl_formtoken();
if ($xl_post) {
    $xl_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($xl_fmt === '' || !hash_equals($xl_fmt, $xl_mit)) {
        $xl_post = false;
        $xl_misslungen[] = xl_t('ALLG.FORMULAR_FREMD');
    }
}
$xl_formular = ($xl_post && isset($_POST['formular']) && is_string($_POST['formular'])) ? $_POST['formular'] : '';
$xl_wert = function ($k, $vorgabe = '') {
    return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : $vorgabe;
};

/* ---------------- Handler (nur POST mit gueltigem Merkmal; jeder fasst nur seine eigenen Schluessel an) ---------------- */
if ($xl_post) {
    $xl_cfg = xl_config();
    switch ($xl_formular) {
        case 'dienst':
            $xl_tab = 'tab-settings';
            $xl_aktion = $xl_wert('aktion');
            if (!in_array($xl_aktion, array('start', 'stop', 'restart'), true)) {
                $xl_misslungen[] = xl_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            list($xl_ok, $xl_aus) = xl_dienst($xl_aktion);
            if ($xl_ok) {
                $xl_meldungen[] = xl_t('EINST.M_DIENST_' . strtoupper($xl_aktion));
            } else {
                $xl_misslungen[] = xl_t('EINST.F_DIENST');
            }
            $xl_testausgabe = $xl_aus;
            break;

        case 'einstellungen':
            $xl_tab = 'tab-settings';
            $xl_neu = array();
            $xl_alt = array();
            foreach ((array) $xl_cfg['lampen'] as $xl_z) {
                if (is_array($xl_z) && isset($xl_z['nr'])) {
                    $xl_alt[(int) $xl_z['nr']] = $xl_z;
                }
            }
            $xl_nrn = (isset($_POST['l_nr']) && is_array($_POST['l_nr'])) ? $_POST['l_nr'] : array();
            $xl_hoechste = (int) $xl_cfg['nr_hoechste'];
            $xl_vergeben = array();
            for ($xl_i = 0; $xl_i < XL_LAMPEN_MAX; $xl_i++) {
                if (!array_key_exists($xl_i, $xl_nrn)) {
                    continue;
                }
                $xl_f = function ($k) use ($xl_i) {
                    return (isset($_POST[$k][$xl_i]) && is_string($_POST[$k][$xl_i])) ? trim($_POST[$k][$xl_i]) : '';
                };
                $xl_ip = $xl_f('l_ip');
                $xl_name = $xl_f('l_name');
                $xl_tokein = strtolower(str_replace(' ', '', $xl_f('l_token')));
                $xl_nr = preg_match('/^[0-9]{1,2}\z/', $xl_f('l_nr')) ? (int) $xl_f('l_nr') : 0;
                if ($xl_f('l_entfernen') === '1') {
                    continue;
                }
                if ($xl_ip === '' && $xl_name === '' && $xl_tokein === '') {
                    continue;     // leere Zeile
                }
                if ($xl_nr < 1 || !isset($xl_alt[$xl_nr])) {
                    $xl_cfg_tmp = $xl_cfg;
                    $xl_cfg_tmp['lampen'] = array_merge(array_values($xl_alt), $xl_neu);
                    $xl_cfg_tmp['nr_hoechste'] = $xl_hoechste;
                    $xl_nr = xl_naechste_nummer($xl_cfg_tmp);
                }
                if ($xl_nr < 1 || isset($xl_vergeben[$xl_nr])) {
                    $xl_fehler[] = sprintf(xl_t('EINST.FEHLER_VOLL'), XL_LAMPEN_MAX);
                    continue;
                }
                $xl_vergeben[$xl_nr] = true;
                $xl_hoechste = max($xl_hoechste, $xl_nr);
                $xl_tok = isset($xl_alt[$xl_nr]['token']) ? (string) $xl_alt[$xl_nr]['token'] : '';
                if ($xl_f('l_token_loeschen') === '1') {
                    $xl_tok = '';
                } elseif ($xl_tokein !== '') {
                    $xl_tok = $xl_tokein;   // Form prueft xl_lampen_pruefen()
                }
                if ($xl_name !== '' && preg_match('/[\x00-\x1F\x7F"\']/', $xl_name)) {
                    $xl_fehler[] = sprintf(xl_t('EINST.FEHLER_ZEICHEN'), $xl_i + 1);
                }
                $xl_neu[] = array('nr' => $xl_nr, 'name' => $xl_name, 'ip' => $xl_ip, 'token' => $xl_tok,
                                  'modell' => isset($xl_alt[$xl_nr]['modell']) ? (string) $xl_alt[$xl_nr]['modell'] : '',
                                  'lan' => $xl_f('l_lan') === 'aus' ? 'aus' : 'auto');
                if (isset($xl_alt[$xl_nr]['did']) && is_string($xl_alt[$xl_nr]['did']) && $xl_alt[$xl_nr]['did'] !== '') {
                    $xl_neu[count($xl_neu) - 1]['did'] = $xl_alt[$xl_nr]['did'];   // Geraete-ID aus der Cloud (unsichtbar) bleibt
                }
                $xl_tokein = '';
            }
            unset($_POST['l_token']);
            $xl_fehler = array_merge($xl_fehler, xl_lampen_pruefen($xl_neu));
            $xl_abfrage = $xl_wert('abfrage_s');
            if (!preg_match('/^[0-9]{1,2}\z/', $xl_abfrage) || (int) $xl_abfrage < 5 || (int) $xl_abfrage > 60) {
                $xl_fehler[] = sprintf(xl_t('EINST.FEHLER_BEREICH'), xl_t('EINST.L_ABFRAGE'), 5, 60);
            }
            if ($xl_fehler) {
                $xl_zusatz['eingaben'] = array('abfrage_s' => substr($xl_abfrage, 0, 4));
                break;     // eine Beanstandung: NICHTS wird gespeichert
            }
            $xl_cfg['lampen'] = $xl_neu;
            $xl_cfg['nr_hoechste'] = $xl_hoechste;
            $xl_cfg['abfrage_s'] = (int) $xl_abfrage;
            $xl_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
            if (xl_config_speichern($xl_cfg)) {
                $xl_meldungen[] = xl_t('EINST.M_GESPEICHERT');
                xl_test_zwischen_leeren();
                if (xl_dienst_laeuft()) {
                    $xl_hinweise[] = xl_t('EINST.H_DIENST_LIEST');
                }
            } else {
                $xl_misslungen[] = xl_t('EINST.F_SPEICHERN');
            }
            break;

        case 'mqtt':
            $xl_tab = 'tab-mqtt';
            $xl_wz = $xl_wert('wartezeit');
            if (!preg_match('/^[0-9]{1,2}\z/', $xl_wz) || (int) $xl_wz < 1 || (int) $xl_wz > 10) {
                $xl_fehler[] = sprintf(xl_t('EINST.FEHLER_BEREICH'), xl_t('MQTT.L_WARTEZEIT'), 1, 10);
                break;
            }
            $xl_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
            $xl_cfg['befehle_mqtt_ein'] = isset($_POST['befehle_mqtt_ein']) ? 1 : 0;
            $xl_cfg['wartezeit'] = (int) $xl_wz;
            if (!xl_config_speichern($xl_cfg)) {
                $xl_misslungen[] = xl_t('EINST.F_SPEICHERN');
                break;
            }
            $xl_meldungen[] = xl_t('EINST.M_GESPEICHERT');
            list($xl_rc, $xl_was) = xl_bruecke_schalten($xl_cfg);
            if ($xl_rc !== null) {
                $xl_hinweise[] = $xl_rc === 0 ? sprintf(xl_t('MQTT.M_BRUECKE_OK'), $xl_was) : sprintf(xl_t('MQTT.M_BRUECKE_FEHL'), $xl_was);
            }
            break;

        case 'endpunkt':
            $xl_tab = 'tab-loxone';
            if ($xl_wert('aktion') === 'token_neu') {
                if (!isset($_POST['token_neu_ok'])) {
                    $xl_misslungen[] = xl_t('LOX.F_TOKEN_HAKEN');
                    break;
                }
                $xl_cfg['aktionstoken'] = xl_token_erzeugen();
            } else {
                $xl_cfg['endpunkt_ein'] = isset($_POST['endpunkt_ein']) ? 1 : 0;
            }
            if (xl_config_speichern($xl_cfg)) {
                $xl_meldungen[] = xl_t($xl_wert('aktion') === 'token_neu' ? 'LOX.M_TOKEN_NEU' : 'EINST.M_GESPEICHERT');
            } else {
                $xl_misslungen[] = xl_t('EINST.F_SPEICHERN');
            }
            break;

        case 'cloud':
            $xl_tab = 'tab-settings';
            if (!xl_cloud_bereit()) {
                $xl_misslungen[] = xl_t('CLOUD.F_MODUL');
                break;
            }
            $xl_schritt = $xl_wert('schritt');
            $xl_erg = null;
            if ($xl_schritt === 'uebernehmen') {
                // Mehrfachauswahl: die angehakten Indizes (index[]); ein Griff ins Stammmodul, gespeichert wird einmal
                $xl_wahl = array();
                foreach ((isset($_POST['index']) && is_array($_POST['index'])) ? $_POST['index'] : array() as $xl_ci) {
                    if (is_string($xl_ci) && preg_match('/^[0-9]{1,3}\z/', $xl_ci) && count($xl_wahl) < 100) {
                        $xl_wahl[] = (int) $xl_ci;
                    }
                }
                $xl_cerg = xl_cloud_uebernehmen($xl_wahl);
                list($xl_cgut, $xl_cschlecht) = xl_cloud_meldungen($xl_cerg);
                $xl_meldungen = array_merge($xl_meldungen, $xl_cgut);
                $xl_misslungen = array_merge($xl_misslungen, $xl_cschlecht);
                if ($xl_cerg[0] === 'ok' && ($xl_cerg[1] || $xl_cerg[2])) {
                    xl_test_zwischen_leeren();
                    // je uebernommener Lampe ein kurzer Gruss + Tokenprobe im Heimnetz (hoechstens 4 s je Lampe)
                    @set_time_limit(30 + 5 * count($xl_cerg[4]));
                    list($xl_cgut, $xl_cschlecht) = xl_cloud_pruef_meldungen(xl_cloud_pruefen($xl_cerg[4]));
                    $xl_meldungen = array_merge($xl_meldungen, $xl_cgut);
                    $xl_misslungen = array_merge($xl_misslungen, $xl_cschlecht);
                }
                if ($xl_cerg[0] === 'WAHL' && ($xl_liegt = xc_lesen('auswahl')) !== null && isset($xl_liegt['liste'])) {
                    $xl_zusatz['cloud'] = array('lage' => 'ok', 'geraete' => xl_cloud_zeigen($xl_liegt['liste']));   // Liste noch einmal zeigen
                }
                unset($xl_cerg, $xl_liegt);
                break;
            } elseif ($xl_schritt === 'captcha') {
                $xl_pw = isset($_POST['cloud_pass']) && is_string($_POST['cloud_pass']) ? $_POST['cloud_pass'] : '';
                $_POST['cloud_pass'] = '';
                $xl_erg = xc_captcha_loesen($xl_wert('cloud_captcha'), $xl_pw);
            } elseif ($xl_schritt === 'weg') {
                $xl_weg = in_array($xl_wert('cloud_weg'), array('mail', 'sms'), true) ? $xl_wert('cloud_weg') : '';
                $xl_erg = xc_code_anfordern($xl_weg);
            } elseif ($xl_schritt === 'weg_captcha') {
                // Xiaomi verlangte fuer den Versand ein Captcha: dieselbe Anforderung mit den Zeichen aus dem Bild (icode)
                $xl_weg = in_array($xl_wert('cloud_weg'), array('mail', 'sms'), true) ? $xl_wert('cloud_weg') : '';
                $xl_erg = xc_code_anfordern($xl_weg, null, $xl_wert('cloud_captcha'));
                if (isset($xl_erg['lage']) && $xl_erg['lage'] === 'LOESUNG') {
                    $xl_erg = array('lage' => 'SENDEN', 'art' => 'CAPTCHA_LOESUNG', 'weg' => $xl_weg);
                }
            } elseif ($xl_schritt === 'code') {
                $xl_erg = xc_abruf('', '', 'de', 0, xl_cloud_filter(), $xl_wert('cloud_code'));
            } elseif ($xl_schritt === 'abruf') {
                $xl_konto = $xl_wert('cloud_konto');
                $xl_pw = isset($_POST['cloud_pass']) && is_string($_POST['cloud_pass']) ? $_POST['cloud_pass'] : '';
                $_POST['cloud_pass'] = '';
                $xl_reg = in_array($xl_wert('cloud_region'), xl_regeln()['regionen'], true) ? $xl_wert('cloud_region') : 'de';
                $xl_erg = ($xl_konto === '' || $xl_pw === '') ? array('lage' => 'LEER')
                    : xc_abruf($xl_konto, $xl_pw, $xl_reg, 0, xl_cloud_filter());
                if ($xl_reg !== $xl_cfg['cloud_region']) {
                    $xl_cfg['cloud_region'] = $xl_reg;
                    xl_config_speichern($xl_cfg);
                }
            } else {
                $xl_misslungen[] = xl_t('ALLG.FORMULAR_UNBEKANNT');
                break;
            }
            $xl_pw = '';
            $xl_lage = isset($xl_erg['lage']) ? (string) $xl_erg['lage'] : 'ANTWORT';
            $xl_c = array('lage' => $xl_lage);
            if ($xl_lage === 'ok') {
                $xl_c['geraete'] = xl_cloud_zeigen($xl_erg['geraete']);
                $xl_meldungen[] = sprintf(xl_t('CLOUD.M_GEFUNDEN'), count($xl_c['geraete']), implode(', ', $xl_erg['regionen']));
            } elseif ($xl_lage === 'ZWEITFAKTOR') {
                $xl_c['link'] = (string) $xl_erg['link'];
                $xl_meldungen[] = xl_t('CLOUD.M_ZWEITFAKTOR');
            } elseif ($xl_lage === 'CODE_GESENDET') {
                $xl_meldungen[] = xl_t($xl_erg['weg'] === 'sms' ? 'CLOUD.M_CODE_SMS' : 'CLOUD.M_CODE_MAIL');
            } elseif ($xl_lage === 'CAPTCHA') {
                $xl_meldungen[] = xl_t('CLOUD.M_CAPTCHA');
            } elseif ($xl_lage === 'SENDEN') {
                $xl_c['art'] = isset($xl_erg['art']) ? (string) $xl_erg['art'] : 'UNBEKANNT';
                $xl_c['weg'] = (isset($xl_erg['weg']) && $xl_erg['weg'] === 'sms') ? 'sms' : 'mail';
                if ($xl_c['art'] === 'CAPTCHA_LOESUNG') {
                    $xl_c['art'] = 'CAPTCHA';
                    $xl_misslungen[] = xl_t('CLOUD.F_LOESUNG');
                } else {
                    list($xl_cgut, $xl_cschlecht) = xl_cloud_versand($xl_erg);
                    $xl_meldungen = array_merge($xl_meldungen, $xl_cgut);
                    $xl_misslungen = array_merge($xl_misslungen, $xl_cschlecht);
                }
            } elseif ($xl_lage === 'KEINE') {
                $xl_misslungen[] = sprintf(xl_t('CLOUD.F_KEINE'), implode(', ', isset($xl_erg['regionen']) ? $xl_erg['regionen'] : array()));
            } else {
                $xl_k = 'CLOUD.F_' . preg_replace('/[^A-Z_]/', '', $xl_lage);
                $xl_misslungen[] = xl_t($xl_k) !== $xl_k ? xl_t($xl_k) : xl_t('CLOUD.F_ANTWORT');
            }
            $xl_zusatz['cloud'] = $xl_c;
            unset($xl_erg);
            break;

        case 'suche':
            $xl_tab = 'tab-settings';
            if ($xl_wert('aktion') === 'uebernehmen') {
                $xl_sip = $xl_wert('ip');
                if (xl_adresse_befund($xl_sip) !== '' || $xl_sip === '') {
                    $xl_misslungen[] = sprintf(xl_t('EINST.FEHLER_IP'), 1);
                    break;
                }
                $xl_nr = xl_naechste_nummer($xl_cfg);
                $xl_l = is_array($xl_cfg['lampen']) ? array_values($xl_cfg['lampen']) : array();
                foreach ($xl_l as $xl_z) {
                    if (is_array($xl_z) && isset($xl_z['ip']) && $xl_z['ip'] === $xl_sip) {
                        $xl_nr = -1;
                    }
                }
                if ($xl_nr === -1) {
                    $xl_misslungen[] = sprintf(xl_t('EINST.FEHLER_IP_DOPPELT'), 1, $xl_sip);
                    break;
                }
                if ($xl_nr === 0 || count($xl_l) >= XL_LAMPEN_MAX) {
                    $xl_misslungen[] = sprintf(xl_t('EINST.FEHLER_VOLL'), XL_LAMPEN_MAX);
                    break;
                }
                $xl_l[] = array('nr' => $xl_nr, 'name' => 'Lampe ' . $xl_nr, 'ip' => $xl_sip, 'token' => '', 'modell' => '', 'lan' => 'auto');
                $xl_cfg['lampen'] = $xl_l;
                $xl_cfg['nr_hoechste'] = max((int) $xl_cfg['nr_hoechste'], $xl_nr);
                if (xl_config_speichern($xl_cfg)) {
                    $xl_meldungen[] = sprintf(xl_t('SUCHE.M_UEBERNOMMEN'), $xl_sip, $xl_nr);
                } else {
                    $xl_misslungen[] = xl_t('EINST.F_SPEICHERN');
                }
                break;
            }
            $xl_gef = xl_suche();
            $xl_zusatz['suche'] = $xl_gef;
            $xl_meldungen[] = sprintf(xl_t('SUCHE.M_ERGEBNIS'), count($xl_gef));
            break;

        case 'sicherung':
            $xl_tab = 'tab-settings';
            if ($xl_wert('aktion') === 'holen') {
                $xl_js = xl_sicherung_datei();
                if ($xl_js === false) {
                    $xl_misslungen[] = xl_t('EINST.F_SICHERUNG');
                    break;
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="xiaomilicht-' . date('Ymd-Hi') . '.json"');
                echo $xl_js;
                exit;
            }
            if (!isset($_POST['zurueck_ok'])) {
                $xl_misslungen[] = xl_t('EINST.F_ZURUECK_HAKEN');
                break;
            }
            if (!isset($_FILES['sicherung']['tmp_name']) || !is_string($_FILES['sicherung']['tmp_name'])
                || !@is_uploaded_file($_FILES['sicherung']['tmp_name'])) {
                $xl_misslungen[] = xl_t('EINST.SICH_KEINE_DATEI');
                break;
            }
            if ((int) $_FILES['sicherung']['size'] > 262144) {
                $xl_misslungen[] = xl_t('EINST.SICH_ZU_GROSS');
                break;
            }
            list($xl_neu, $xl_mangel, $xl_sh, $xl_n) = xl_sicherung_lesen((string) @file_get_contents($_FILES['sicherung']['tmp_name']), $xl_cfg);
            if ($xl_neu === null) {
                $xl_fehler[] = xl_t('EINST.SICH_ABGELEHNT');
                $xl_fehler = array_merge($xl_fehler, $xl_mangel);
                break;
            }
            if (xl_config_speichern($xl_neu)) {
                $xl_meldungen[] = sprintf(xl_t('EINST.SICH_UEBERNOMMEN'), $xl_n);
                $xl_hinweise = array_merge($xl_hinweise, $xl_sh);
                xl_test_zwischen_leeren();
            } else {
                $xl_misslungen[] = xl_t('EINST.F_SPEICHERN');
            }
            break;

        case 'vorlage':
            $xl_vn = $xl_wert('lampe');
            list($xl_vname, $xl_vinhalt) = preg_match('/^[0-9]{1,2}\z/', $xl_vn) ? xl_vorlage_befehle((int) $xl_vn) : array('', '');
            if ($xl_vinhalt === '') {
                $xl_tab = 'tab-loxone';
                $xl_misslungen[] = xl_t('LOX.F_VORLAGE');
                break;
            }
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $xl_vname . '"');
            echo $xl_vinhalt;
            exit;

        case 'test':
            $xl_tab = 'tab-test';
            $xl_ta = $xl_wert('aktion');
            if ($xl_ta === 'neu') {
                xl_test_zwischen_leeren();
                $xl_meldungen[] = xl_t('TEST.M_NEU');
            } elseif ($xl_ta === 'rohwerte') {
                $xl_roh = array('lauf' => xl_lauf(), 'werte' => xl_werte());
                $xl_testausgabe = json_encode($xl_roh, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($xl_ta === 'schalten') {
                $xl_tl = $xl_wert('lampe');
                $xl_tc = $xl_wert('cmd');
                $xl_tw = $xl_wert('wert');
                if (!preg_match('/^[0-9]{1,2}\z/', $xl_tl) || !in_array($xl_tc, array('schalten', 'hell', 'ct', 'nacht'), true)) {
                    $xl_misslungen[] = xl_t('ALLG.FORMULAR_UNBEKANNT');
                    break;
                }
                $xl_r = xl_befehl_annehmen((int) $xl_tl, $xl_tc, $xl_tw, $xl_cfg, 'http');
                $xl_testausgabe = xl_befehl_zeile($xl_r, $xl_tc, (int) $xl_tl);
                if ((int) $xl_r['ok'] !== 1) {
                    $xl_misslungen[] = sprintf(xl_t('TEST.F_SCHALTEN'), $xl_r['grund'] !== '' ? $xl_r['grund'] : $xl_r['info']);
                } else {
                    $xl_meldungen[] = xl_t('TEST.M_SCHALTEN');
                }
            } else {
                $xl_misslungen[] = xl_t('ALLG.FORMULAR_UNBEKANNT');
            }
            break;

        case 'log':
            $xl_tab = 'tab-log';
            if (!isset($_POST['leeren_ok'])) {
                $xl_misslungen[] = xl_t('LOG.F_HAKEN');
                break;
            }
            if (is_file($xl_p['log']) && @file_put_contents($xl_p['log'], '') === false) {
                $xl_misslungen[] = xl_t('LOG.F_LEEREN');
            } else {
                xl_log('Protokoll geleert (Oberflaeche).');
                $xl_meldungen[] = xl_t('LOG.M_GELEERT');
            }
            break;

        default:
            $xl_misslungen[] = xl_t('ALLG.FORMULAR_UNBEKANNT');
    }
}
if ($xl_post_roh) {
    /* Jeder POST endet mit 303 und einer Einmalmeldung - ein Neuladen schickt nichts doppelt. */
    xl_einmal_schreiben($xl_meldungen, array_merge($xl_fehler ? array('#F#') : array(), $xl_fehler), $xl_testausgabe,
        array_merge($xl_hinweise, $xl_misslungen ? array('#M#') : array(), $xl_misslungen), $xl_zusatz);
    header('Location: index.php?form=' . substr($xl_tab, 4), true, 303);
    exit;
}
$xl_einmal = xl_einmal_lesen();
if ($xl_einmal) {
    $xl_meldungen = $xl_einmal['meldungen'];
    $xl_fehler = array_values(array_filter($xl_einmal['fehler'], function ($x) { return $x !== '#F#'; }));
    $xl_teil = array_search('#M#', $xl_einmal['hinweise'], true);
    $xl_hinweise = $xl_teil === false ? $xl_einmal['hinweise'] : array_slice($xl_einmal['hinweise'], 0, $xl_teil);
    $xl_misslungen = $xl_teil === false ? array() : array_slice($xl_einmal['hinweise'], $xl_teil + 1);
    $xl_testausgabe = $xl_einmal['test'];
    $xl_zusatz = is_array($xl_einmal['zusatz']) ? $xl_einmal['zusatz'] : array();
}

/* ---------------- Seitenaufbau ---------------- */
$xl_cfg = xl_config();
$xl_lampen = xl_lampen($xl_cfg);
$xl_werte = xl_werte();
$xl_laeuft = xl_dienst_laeuft();
$xl_pid = xl_dienst_pid();
$xl_mqtt = xl_mqtt_zustand();
$xl_leb = xl_dienst_lebenszeichen();
list($xl_bl, $xl_bt) = xl_bruecke_lage();
$xl_erreichbar = 0;
$xl_echtzeit = 0;
foreach ($xl_lampen as $xl_nr => $xl_l) {
    if (!empty($xl_werte[$xl_nr]['erreichbar'])) {
        $xl_erreichbar++;
        if ((int) $xl_werte[$xl_nr]['weg'] === 2) {
            $xl_echtzeit++;
        }
    }
}
$xl_test_offen = ($xl_tab === 'tab-test');
$xl_logzeilen = xl_log_ende($xl_p['log'], 300);
$xl_eingaben = isset($xl_zusatz['eingaben']) && is_array($xl_zusatz['eingaben']) ? $xl_zusatz['eingaben'] : array();
$xl_ja_nein = function ($b) {
    return xl_e(xl_t($b ? 'ALLG.JA' : 'ALLG.NEIN'));
};
$xl_zeichen = function ($ok) {
    return $ok === true ? '<span class="sm-an">&#10004;</span>' : ($ok === false ? '<span class="sm-aus">&#10008;</span>'
        : '<span class="sm-hinweiszeichen">&#9432;</span>');
};

$xl_rahmen = class_exists('LBWeb', false);
if ($xl_rahmen) {
    LBWeb::lbheader('Xiaomi Licht', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard (VORLAGE_hausstandard.css.html, wortgleich wie Govee 0.9.31 / Dashboard 0.9.38). */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-log { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.82em;
    overflow: auto; margin: 8px 0; max-height: 420px; white-space: pre-wrap; font-family: Consolas, "Courier New", monospace; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-tbl button.sm-btn { min-width: 0; padding: 5px 10px !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px; padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-hinweiszeichen { color: #546e7a; font-weight: 700; }
.sm-status { margin: 10px 0 0; }
.sm-status td:first-child { width: 28%; font-weight: 600; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-tbl input[type=text], .sm-tbl input[type=password] { width: 100%; box-sizing: border-box; }
.sm-wrap details { margin: 12px 0; }
.sm-wrap summary { cursor: pointer; font-weight: 600; color: #4f7d17; }
</style>

<div class="sm-wrap">

<?php if ($xl_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('xl_e', $xl_meldungen)) ?></div>
<?php } ?>
<?php if ($xl_fehler) { ?>
<div class="sm-warnung"><b><?= xl_e(xl_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('xl_e', $xl_fehler)) ?></div>
<?php } ?>
<?php if ($xl_misslungen) { ?>
<div class="sm-warnung"><b><?= xl_e(xl_t('ALLG.MISSLUNGEN')) ?></b><br><?= implode('<br>', array_map('xl_e', $xl_misslungen)) ?></div>
<?php } ?>
<?php if ($xl_hinweise) { ?>
<div class="sm-warnung"><?= implode('<br>', array_map('xl_e', $xl_hinweise)) ?></div>
<?php } ?>

<!-- Statusuebersicht ueber der Reiterleiste (Kopf wie Dashboard, Entscheidung Nr. 43). -->
<table class="sm-tbl sm-status">
<tr><th><?= xl_e(xl_t('KOPF.EIGENSCHAFT')) ?></th><th><?= xl_e(xl_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= xl_e(xl_t('KOPF.DIENST')) ?></td>
<td><b class="<?= $xl_laeuft ? 'sm-an' : 'sm-aus' ?>"><?= xl_e(xl_t($xl_laeuft ? 'ALLG.LAEUFT' : 'ALLG.GESTOPPT')) ?></b>
<?= $xl_pid ? ' &middot; PID ' . (int) $xl_pid : '' ?><?= $xl_laeuft && $xl_leb >= 0 ? ' &middot; ' . xl_e(sprintf(xl_t('KOPF.TAKT_VOR'), $xl_leb)) : '' ?></td></tr>
<tr><td>MQTT</td>
<td><b class="<?= !empty($xl_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= xl_e(xl_t(!empty($xl_cfg['mqtt_ein']) ? 'ALLG.EIN' : 'ALLG.AUS')) ?></b>
<span class="sm-hilfe"><?= xl_e(sprintf(xl_t('KOPF.AUTOSTART'), !$xl_mqtt['gefunden'] ? xl_t('ALLG.NICHT_FESTSTELLBAR')
    : xl_t($xl_mqtt['autostart'] ? 'ALLG.EIN' : 'ALLG.AUS'))) ?></span></td></tr>
<tr><td><?= xl_e(xl_t('KOPF.LAMPEN')) ?></td>
<td><?= xl_e(sprintf(xl_t('KOPF.LAMPEN_WERT'), $xl_erreichbar, count($xl_lampen), $xl_echtzeit)) ?></td></tr>
<tr><td><?= xl_e(xl_t('KOPF.BRUECKE')) ?></td>
<td><?= $xl_zeichen($xl_bl) ?> <?= $xl_bt ?></td></tr>
</table>

<div class="sm-tabs">
	<a class="sm-tab<?= $xl_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= xl_e(xl_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $xl_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $xl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= xl_e(xl_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $xl_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= xl_e(xl_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $xl_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= xl_e(xl_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $xl_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= xl_e(xl_test_zusammenfassung($xl_lampen, $xl_werte, $xl_cfg)) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= xl_e(xl_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= xl_e(xl_t('LEGENDE.AKTION')) ?></span>
</div>

<h2><?= xl_e(xl_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.DIENST_TEXT')) ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="start"><?= xl_e(xl_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="restart"><?= xl_e(xl_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="dienst">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="stop"><?= xl_e(xl_t('EINST.K_STOP')) ?></button>
  </form>
</div>

<h2><?= xl_e(xl_t('EINST.H_LAMPEN')) ?></h2>
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.LAMPEN_TEXT')) ?></p>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="einstellungen">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('EINST.T_NR')) ?></th><th><?= xl_e(xl_t('EINST.T_NAME')) ?></th><th><?= xl_e(xl_t('EINST.T_IP')) ?></th>
<th><?= xl_e(xl_t('EINST.T_MODELL')) ?></th><th><?= xl_e(xl_t('EINST.T_TOKEN')) ?></th><th><?= xl_e(xl_t('EINST.T_LAN')) ?></th>
<th><?= xl_e(xl_t('EINST.T_ENTFERNEN')) ?></th></tr>
<?php
$xl_zeilen = array_values($xl_lampen);
$xl_frei = min(2, XL_LAMPEN_MAX - count($xl_zeilen));
for ($xl_i = 0; $xl_i < count($xl_zeilen) + $xl_frei; $xl_i++) {
    $xl_z = isset($xl_zeilen[$xl_i]) ? $xl_zeilen[$xl_i] : array('nr' => 0, 'name' => '', 'ip' => '', 'token' => '', 'modell' => '', 'lan' => 'auto');
    list($xl_zm) = $xl_z['nr'] ? xl_modell_holen($xl_z) : array('');
    $xl_ztab = xl_modell($xl_zm);
?>
<tr>
<td><input data-role="none" type="hidden" name="l_nr[<?= (int) $xl_i ?>]" value="<?= (int) $xl_z['nr'] ?>"><?= $xl_z['nr'] ? (int) $xl_z['nr'] : '&ndash;' ?></td>
<td><input data-role="none" type="text" name="l_name[<?= (int) $xl_i ?>]" value="<?= xl_e($xl_z['nr'] ? $xl_z['name'] : '') ?>" maxlength="40"></td>
<td><input data-role="none" type="text" name="l_ip[<?= (int) $xl_i ?>]" value="<?= xl_e($xl_z['ip']) ?>" maxlength="80" placeholder="192.0.2.10"></td>
<td><?= $xl_zm !== '' ? '<span class="sm-mono">' . xl_e($xl_zm) . '</span>' . ($xl_ztab['bekannt'] ? '' : '<br><span class="sm-hilfe">' . xl_e(xl_t('EINST.MODELL_FREMD')) . '</span>') : '&ndash;' ?></td>
<td><input data-role="none" type="password" name="l_token[<?= (int) $xl_i ?>]" value="" autocomplete="new-password" maxlength="40"
     placeholder="<?= xl_e(xl_t($xl_z['token'] !== '' ? 'EINST.TOKEN_HINTERLEGT' : 'EINST.TOKEN_FEHLT')) ?>">
<?php if ($xl_z['token'] !== '') { ?>
<label><input data-role="none" type="checkbox" name="l_token_loeschen[<?= (int) $xl_i ?>]" value="1"> <?= xl_e(xl_t('EINST.TOKEN_LOESCHEN')) ?></label>
<?php } ?></td>
<td><select data-role="none" name="l_lan[<?= (int) $xl_i ?>]">
<option value="auto"<?= $xl_z['lan'] === 'auto' ? ' selected' : '' ?>><?= xl_e(xl_t('EINST.LAN_AUTO')) ?></option>
<option value="aus"<?= $xl_z['lan'] === 'aus' ? ' selected' : '' ?>><?= xl_e(xl_t('EINST.LAN_AUS')) ?></option>
</select></td>
<td><?php if ($xl_z['nr']) { ?><label><input data-role="none" type="checkbox" name="l_entfernen[<?= (int) $xl_i ?>]" value="1"> <?= xl_e(xl_t('EINST.ENTFERNEN')) ?></label><?php } ?></td>
</tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.TOKEN_HILFE')) ?></p>
<div class="sm-feld"><label for="abfrage_s"><?= xl_e(xl_t('EINST.L_ABFRAGE')) ?></label>
<input data-role="none" type="text" id="abfrage_s" name="abfrage_s" value="<?= xl_e(isset($xl_eingaben['abfrage_s']) ? $xl_eingaben['abfrage_s'] : (string) (int) $xl_cfg['abfrage_s']) ?>" maxlength="2" inputmode="numeric">
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.ABFRAGE_HILFE')) ?></p></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="steuerung_ein" value="1"<?= !empty($xl_cfg['steuerung_ein']) ? ' checked' : '' ?>>
<?= xl_e(xl_t('EINST.L_STEUERUNG')) ?></label>
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.STEUERUNG_HILFE')) ?></p></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= xl_e(xl_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>

<?php
xl_cloud_bereit();
if (function_exists('xc_aufraeumen')) {
    xc_aufraeumen();
}
$xl_cloud = isset($xl_zusatz['cloud']) && is_array($xl_zusatz['cloud']) ? $xl_zusatz['cloud'] : null;
?>
<h2 id="xiaomi-cloud"><?= xl_e(xl_t('CLOUD.H_TITEL')) ?></h2>
<details<?= $xl_cloud ? ' open' : '' ?>>
<summary><?= xl_e(xl_t('CLOUD.ABRUF_TITEL')) ?></summary>
<p class="sm-hilfe"><?= xl_e(xl_t('CLOUD.ABRUF_TEXT')) ?></p>
<?php if ($xl_cloud && $xl_cloud['lage'] === 'ok' && !empty($xl_cloud['geraete'])) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="uebernehmen">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('CLOUD.SP_WAHL')) ?></th><th><?= xl_e(xl_t('CLOUD.SP_NAME')) ?></th><th><?= xl_e(xl_t('EINST.T_MODELL')) ?></th>
<th><?= xl_e(xl_t('EINST.T_IP')) ?></th><th><?= xl_e(xl_t('CLOUD.SP_REGION')) ?></th><th><?= xl_e(xl_t('CLOUD.SP_ONLINE')) ?></th></tr>
<?php foreach ($xl_cloud['geraete'] as $xl_cg) {
    if (!is_array($xl_cg) || !isset($xl_cg['i'])) { continue; }
    $xl_cgt = xl_modell(isset($xl_cg['modell']) ? (string) $xl_cg['modell'] : ''); ?>
<tr><td><input data-role="none" type="checkbox" id="cloud_wahl_<?= (int) $xl_cg['i'] ?>" name="index[]" value="<?= (int) $xl_cg['i'] ?>"<?= (isset($xl_cg['online']) && $xl_cg['online'] === 1) ? ' checked' : '' ?>></td>
<td><label for="cloud_wahl_<?= (int) $xl_cg['i'] ?>"><?= xl_e(isset($xl_cg['name']) ? (string) $xl_cg['name'] : '') ?></label></td>
<td><span class="sm-mono"><?= xl_e(isset($xl_cg['modell']) ? (string) $xl_cg['modell'] : '') ?></span><br>
<span class="sm-hilfe"><?= xl_e(xl_t($xl_cgt['bekannt'] ? 'CLOUD.MODELL_BEKANNT' : 'CLOUD.MODELL_GRUNDFORM')) ?></span></td>
<td><span class="sm-mono"><?= xl_e(isset($xl_cg['ip']) && $xl_cg['ip'] !== '' ? (string) $xl_cg['ip'] : '-') ?></span></td>
<td><?= xl_e(isset($xl_cg['region']) ? (string) $xl_cg['region'] : '') ?></td>
<td><?= xl_e(xl_t(!isset($xl_cg['online']) || $xl_cg['online'] === -1 ? 'CLOUD.ONLINE_UNBEKANNT' : ($xl_cg['online'] === 1 ? 'CLOUD.ONLINE_JA' : 'CLOUD.ONLINE_NEIN'))) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= xl_e(xl_t('CLOUD.UEBERNEHMEN_TEXT')) ?></p>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="senden" value="1"><?= xl_e(xl_t('CLOUD.K_AUSGEWAEHLT')) ?></button>
</div>
</form>
<?php } elseif ($xl_cloud && in_array($xl_cloud['lage'], array('CAPTCHA', 'CAPTCHA_FALSCH', 'LOESUNG'), true)
                && function_exists('xc_lesen') && ($xl_cap = xc_lesen('captcha')) !== null && isset($xl_cap['bild'], $xl_cap['bildtyp'])
                && in_array($xl_cap['bildtyp'], array('image/png', 'image/jpeg', 'image/gif'), true)) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('CLOUD.CAPTCHA_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="captcha">
<div class="sm-feld"><img src="data:<?= xl_e($xl_cap['bildtyp']) ?>;base64,<?= xl_e(preg_replace('#[^A-Za-z0-9+/=]#', '', (string) $xl_cap['bild'])) ?>" alt="<?= xl_e(xl_t('CLOUD.CAPTCHA_BILD')) ?>" style="max-width:100%;border:1px solid #ccc;background:#fff;"></div>
<div class="sm-feld"><label for="cloud_captcha"><?= xl_e(xl_t('CLOUD.CAPTCHA_LOESUNG')) ?></label>
<input data-role="none" type="text" id="cloud_captcha" name="cloud_captcha" value="" autocomplete="off" maxlength="10"></div>
<div class="sm-feld"><label for="cloud_pass_c"><?= xl_e(xl_t('CLOUD.KENNWORT')) ?></label>
<input data-role="none" type="password" id="cloud_pass_c" name="cloud_pass" value="" autocomplete="new-password" maxlength="200"></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="senden" value="1"><?= xl_e(xl_t('CLOUD.K_CAPTCHA')) ?></button>
</div>
</form>
<?php } elseif ($xl_cloud && $xl_cloud['lage'] === 'SENDEN' && isset($xl_cloud['art']) && $xl_cloud['art'] === 'CAPTCHA'
                && function_exists('xc_lesen') && ($xl_zf = xc_lesen('zweitfaktor')) !== null && isset($xl_zf['sende_captcha']['bild'], $xl_zf['sende_captcha']['bildtyp'])
                && in_array($xl_zf['sende_captcha']['bildtyp'], array('image/png', 'image/jpeg', 'image/gif'), true)) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('CLOUD.SENDE_CAPTCHA_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="weg_captcha">
<input data-role="none" type="hidden" name="cloud_weg" value="<?= $xl_cloud['weg'] === 'sms' ? 'sms' : 'mail' ?>">
<div class="sm-feld"><img src="data:<?= xl_e($xl_zf['sende_captcha']['bildtyp']) ?>;base64,<?= xl_e(preg_replace('#[^A-Za-z0-9+/=]#', '', (string) $xl_zf['sende_captcha']['bild'])) ?>" alt="<?= xl_e(xl_t('CLOUD.CAPTCHA_BILD')) ?>" style="max-width:100%;border:1px solid #ccc;background:#fff;"></div>
<div class="sm-feld"><label for="cloud_captcha_v"><?= xl_e(xl_t('CLOUD.CAPTCHA_LOESUNG')) ?></label>
<input data-role="none" type="text" id="cloud_captcha_v" name="cloud_captcha" value="" autocomplete="off" maxlength="10"></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="senden" value="1"><?= xl_e(xl_t('CLOUD.K_SENDE_CAPTCHA')) ?></button>
</div>
</form>
<?php } elseif ($xl_cloud && in_array($xl_cloud['lage'], array('ZWEITFAKTOR', 'SENDEN', 'WEG'), true)
                && function_exists('xc_lesen') && ($xl_zf = xc_lesen('zweitfaktor')) !== null) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('CLOUD.ZWEITFAKTOR_WAHL')) ?></div>
<?php if ($xl_cloud['lage'] === 'SENDEN' && isset($xl_cloud['weg']) && count(array_intersect(array(4, 8), (array) $xl_zf['optionen'])) === 2) { ?>
<p class="sm-hilfe"><?= xl_e(sprintf(xl_t('CLOUD.H_ANDERER_WEG'), xl_t($xl_cloud['weg'] === 'sms' ? 'CLOUD.K_WEG_MAIL' : 'CLOUD.K_WEG_SMS'))) ?></p>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="weg">
<div class="sm-knopfreihe">
<?php if (in_array(8, (array) $xl_zf['optionen'], true)) { ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="cloud_weg" value="mail"><?= xl_e(xl_t('CLOUD.K_WEG_MAIL')) ?></button>
<?php } ?>
<?php if (in_array(4, (array) $xl_zf['optionen'], true)) { ?>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="cloud_weg" value="sms"><?= xl_e(xl_t('CLOUD.K_WEG_SMS')) ?></button>
<?php } ?>
</div>
</form>
<?php } elseif ($xl_cloud && in_array($xl_cloud['lage'], array('CODE_GESENDET', 'CODE'), true)
                && function_exists('xc_lesen') && ($xl_zf = xc_lesen('zweitfaktor')) !== null && !empty($xl_zf['weg'])) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('CLOUD.ZWEITFAKTOR_TEXT')) ?></div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="code">
<div class="sm-feld"><label for="cloud_code"><?= xl_e(xl_t('CLOUD.CODE')) ?></label>
<input data-role="none" type="text" id="cloud_code" name="cloud_code" value="" inputmode="numeric" autocomplete="one-time-code" maxlength="12"></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="senden" value="1"><?= xl_e(xl_t('CLOUD.K_CODE')) ?></button>
</div>
</form>
<?php } ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="cloud">
<input data-role="none" type="hidden" name="schritt" value="abruf">
<div class="sm-feld"><label for="cloud_konto"><?= xl_e(xl_t('CLOUD.KONTO')) ?></label>
<input data-role="none" type="text" id="cloud_konto" name="cloud_konto" value="" autocomplete="off" maxlength="100"></div>
<div class="sm-feld"><label for="cloud_pass"><?= xl_e(xl_t('CLOUD.KENNWORT')) ?></label>
<input data-role="none" type="password" id="cloud_pass" name="cloud_pass" value="" autocomplete="new-password" maxlength="200"></div>
<div class="sm-feld"><label for="cloud_region"><?= xl_e(xl_t('CLOUD.REGION')) ?></label>
<select data-role="none" id="cloud_region" name="cloud_region">
<?php foreach (xl_regeln()['regionen'] as $xl_cr) { ?>
<option value="<?= xl_e($xl_cr) ?>"<?= $xl_cr === $xl_cfg['cloud_region'] ? ' selected' : '' ?>><?= xl_e(xl_t('CLOUD.REGION_' . strtoupper($xl_cr))) ?></option>
<?php } ?>
</select></div>
<p class="sm-hilfe"><?= xl_e(xl_t('CLOUD.KENNWORT_HINWEIS')) ?></p>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="senden" value="1"><?= xl_e(xl_t('CLOUD.K_HOLEN')) ?></button>
</div>
</form>
</details>

<?php $xl_suche = isset($xl_zusatz['suche']) && is_array($xl_zusatz['suche']) ? $xl_zusatz['suche'] : null; ?>
<h2><?= xl_e(xl_t('SUCHE.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= xl_e(xl_t('SUCHE.TEXT')) ?></p>
<?php if ($xl_suche !== null) { ?>
<?php if (!$xl_suche) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('SUCHE.KEINE')) ?></div>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('EINST.T_IP')) ?></th><th><?= xl_e(xl_t('SUCHE.SP_WEG')) ?></th><th><?= xl_e(xl_t('EINST.T_MODELL')) ?></th>
<th><?= xl_e(xl_t('SUCHE.SP_EINGETRAGEN')) ?></th><th></th></tr>
<?php foreach ($xl_suche as $xl_sg) {
    if (!is_array($xl_sg) || !isset($xl_sg['ip'])) { continue; } ?>
<tr><td><span class="sm-mono"><?= xl_e($xl_sg['ip']) ?></span></td>
<td><?= xl_e(trim((!empty($xl_sg['lan']) ? 'Yeelight-LAN ' : '') . (!empty($xl_sg['miio']) ? 'miIO' : ''))) ?></td>
<td><?= xl_e(isset($xl_sg['modell']) && $xl_sg['modell'] !== '' ? $xl_sg['modell'] : '-') ?></td>
<td><?= $xl_ja_nein(!empty($xl_sg['eingetragen'])) ?></td>
<td><?php if (empty($xl_sg['eingetragen'])) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="suche">
<input data-role="none" type="hidden" name="aktion" value="uebernehmen">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ip" value="<?= xl_e($xl_sg['ip']) ?>"><?= xl_e(xl_t('SUCHE.K_UEBERNEHMEN')) ?></button>
</form><?php } ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="suche">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="suchen"><?= xl_e(xl_t('SUCHE.K_SUCHEN')) ?></button>
  </form>
</div>

<h2><?= xl_e(xl_t('EINST.H_SICHERUNG')) ?></h2>
<p class="sm-hilfe"><?= xl_e(xl_t('EINST.SICHERUNG_TEXT')) ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="sicherung">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="holen"><?= xl_e(xl_t('EINST.K_SICHERUNG')) ?></button>
  </form>
</div>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="sicherung">
<div class="sm-feld"><label for="sicherung"><?= xl_e(xl_t('EINST.L_ZURUECK')) ?></label>
<input data-role="none" type="file" id="sicherung" name="sicherung" accept=".json,application/json"></div>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="zurueck_ok" value="1"> <?= xl_e(xl_t('EINST.ZURUECK_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="zurueck"><?= xl_e(xl_t('EINST.K_ZURUECK')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $xl_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-hinweis"><?= xl_e(xl_t('MQTT.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= xl_e(xl_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="mqtt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($xl_cfg['mqtt_ein']) ? ' checked' : '' ?>>
<?= xl_e(xl_t('MQTT.L_EIN')) ?></label></div>
<div class="sm-feld"><label><?= xl_e(xl_t('MQTT.L_PRAEFIX')) ?></label><span class="sm-mono"><?= xl_e(XL_PRAEFIX) ?></span>
<p class="sm-hilfe"><?= xl_e(xl_t('MQTT.PRAEFIX_HILFE')) ?></p></div>
<h3><?= xl_e(xl_t('MQTT.H_BRUECKE')) ?></h3>
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= !empty($xl_cfg['befehle_mqtt_ein']) ? ' checked' : '' ?>>
<?= xl_e(xl_t('MQTT.L_BEFEHLE')) ?></label>
<p class="sm-hilfe"><?= xl_e(xl_t('MQTT.BEFEHLE_HILFE')) ?></p></div>
<div class="sm-feld"><label for="wartezeit"><?= xl_e(xl_t('MQTT.L_WARTEZEIT')) ?></label>
<input data-role="none" type="text" id="wartezeit" name="wartezeit" value="<?= (int) $xl_cfg['wartezeit'] ?>" maxlength="2" inputmode="numeric"></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= xl_e(xl_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
<h2><?= xl_e(xl_t('MQTT.H_GATEWAY')) ?></h2>
<?php $xl_v2 = xl_mqtt_v2_haken_lage(); ?>
<p><?= xl_e($xl_mqtt['gefunden'] ? sprintf(xl_t('MQTT.GATEWAY'), $xl_mqtt['fassung'] > 0 ? (string) $xl_mqtt['fassung'] : '?',
    (int) $xl_mqtt['udpport'], xl_t($xl_mqtt['autostart'] ? 'ALLG.EIN' : 'ALLG.AUS')) : xl_t('MQTT.GATEWAY_UNKLAR')) ?></p>
<?php if ((int) $xl_mqtt['fassung'] === 1) {
    list($xl_abo_pfad, $xl_abo_da) = xl_abo_datei(); ?>
<div class="<?= $xl_abo_da ? 'sm-hinweis' : 'sm-warnung' ?>"><?= xl_e($xl_abo_da ? sprintf(xl_t('MQTT.ABO_V1_DATEI'), XL_PRAEFIX . '/#') : sprintf(xl_t('MQTT.ABO_V1_FEHLT'), XL_PRAEFIX . '/#')) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= xl_e(xl_t('MQTT.ABO_V2')) ?> <?= xl_e($xl_v2[4] === 'v2' ? sprintf(xl_t('TEST.A_V2'), $xl_v2[1], $xl_v2[2]) : '') ?></div>
<h3><?= xl_e(xl_t('MQTT.H_HAKEN')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('MQTT.SP_DATENPUNKT')) ?></th><th><?= xl_e(xl_t('MQTT.SP_NONCACHED')) ?></th><th><?= xl_e(xl_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (xl_mqtt_hakenliste_alle() as $xl_h) { ?>
<tr><td><span class="sm-mono"><?= xl_e($xl_h[0]) ?></span></td><td><?= $xl_ja_nein($xl_h[3]) ?></td><td><?= xl_e($xl_h[2]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<h2><?= xl_e(xl_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('MQTT.SP_THEMA')) ?></th><th><?= xl_e(xl_t('MQTT.SP_BEREICH')) ?></th><th><?= xl_e(xl_t('MQTT.SP_RETAIN')) ?></th>
<th><?= xl_e(xl_t('MQTT.SP_V2')) ?></th><th><?= xl_e(xl_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (xl_mqtt_themen() as $xl_th) { ?>
<tr><td><span class="sm-mono"><?= xl_e(XL_PRAEFIX . '/' . str_replace('lampeN/', 'lampe<n>/', $xl_th['t'])) ?></span></td>
<td><?= xl_e($xl_th['b']) ?><?= $xl_th['f'] !== '' ? ' (' . xl_e(xl_t('MQTT.FEHLWERT')) . ' ' . xl_e($xl_th['f']) . ')' : '' ?></td>
<td><?= $xl_ja_nein($xl_th['r']) ?></td><td><?= $xl_th['v2'] ? ($xl_th['nc'] ? xl_e(xl_t('MQTT.V2_NC')) : xl_e(xl_t('ALLG.JA'))) : xl_e(xl_t('ALLG.NEIN')) ?></td>
<td><?= xl_e(xl_t($xl_th['k'])) ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono"><?= xl_e(XL_PRAEFIX) ?>/befehl/&hellip;/quittung</span></td><td>Text</td><td><?= $xl_ja_nein(false) ?></td>
<td><?= $xl_ja_nein(false) ?></td><td><?= xl_e(xl_t('MQTT.T_QUITTUNG')) ?></td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $xl_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-hinweis"><?= xl_e(xl_t('LOX.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= xl_e(xl_t('LEGENDE.KOPIEREN')) ?></span>
<?php if ($xl_lampen) { /* grau nur, wenn es Vorlage-Knoepfe gibt (je eingetragene Lampe) */ ?>
<span><i class="sm-punkt sm-b-technik"></i> <?= xl_e(xl_t('LEGENDE.TECHNIK')) ?></span>
<?php } ?>
<span><i class="sm-punkt sm-b-aktion"></i> <?= xl_e(xl_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S1_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S1_TEXT')) ?></div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S2_TITEL')) ?></b><br><?= xl_e(xl_t((int) $xl_mqtt['fassung'] === 1 ? 'LOX.S2_V1' : ((int) $xl_mqtt['fassung'] >= 2 ? 'LOX.S2_V2' : 'LOX.S2_BEIDE'))) ?></div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S3_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S3_TEXT')) ?>
<?php
$xl_nummern = $xl_lampen ? array_keys($xl_lampen) : array(1);
foreach ($xl_nummern as $xl_ix => $xl_nr) { ?>
<h3><?= xl_e(isset($xl_lampen[$xl_nr]) ? sprintf(xl_t('LOX.LAMPE_TITEL'), $xl_nr, $xl_lampen[$xl_nr]['name']) : sprintf(xl_t('LOX.LAMPE_MUSTER'), $xl_nr)) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('LOX.SP_NAME')) ?></th><th><?= xl_e(xl_t('LOX.SP_BAUFORM')) ?></th><th><?= xl_e(xl_t('MQTT.SP_BEDEUTUNG')) ?></th>
<th><?= xl_e(xl_t('MQTT.FEHLWERT')) ?></th><th><?= xl_e(xl_t('MQTT.SP_V2')) ?></th><th><?= xl_e(xl_t('LOX.SP_MUSTER')) ?></th></tr>
<?php foreach (xl_mqtt_namen($xl_nr, $xl_ix === 0) as $xl_n) {
    if (!$xl_n['v2']) { continue; } ?>
<tr><td><span class="sm-mono"><?= xl_e($xl_n['name']) ?></span><br>
<button data-role="none" class="sm-btn sm-b-lesen sm-kopie" type="button" data-kopie="<?= xl_e($xl_n['name']) ?>"><?= xl_e(xl_t('LOX.K_KOPIEREN')) ?></button></td>
<td><?= xl_e(xl_t('LOX.BAUFORM_VI')) ?></td><td><?= xl_e($xl_n['bedeutung']) ?></td>
<td><?= xl_e($xl_n['fehlwert'] !== '' ? $xl_n['fehlwert'] : '-') ?></td><td><?= xl_e($xl_n['noncached'] ? 'N' : xl_t('ALLG.JA')) ?></td>
<td><?= xl_e($xl_n['im_muster'] && (int) $xl_nr === 1 ? xl_t('LOX.MUSTER_JA') : '-') ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<p class="sm-hilfe"><?= xl_e(xl_t('LOX.S3_SAETZE')) ?></p>
</div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S4_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S4_TEXT')) ?>
<div class="<?= !empty($xl_cfg['befehle_mqtt_ein']) && !empty($xl_cfg['steuerung_ein']) ? 'sm-hinweis' : 'sm-warnung' ?>"><?= xl_e(xl_t('LOX.S4_HAKEN')) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= xl_e(xl_t('LOX.SP_BEFEHL')) ?></th><th><?= xl_e(xl_t('LOX.SP_WERT')) ?></th><th>ceiling3</th><th>ceiling1</th><th><?= xl_e(xl_t('LOX.SP_LOXONE')) ?></th></tr>
<?php
$xl_f3 = xl_faehigkeiten(xl_modell('yeelink.light.ceiling3'));
$xl_f1 = xl_faehigkeiten(xl_modell('yilai.light.ceiling1'));
foreach (xl_befehle() as $xl_bn => $xl_b) {
    $xl_k3 = $xl_bn === 'ct' ? $xl_f3['ct'] : ($xl_bn === 'nacht' ? $xl_f3['nacht'] : true);
    $xl_k1 = $xl_bn === 'ct' ? $xl_f1['ct'] : ($xl_bn === 'nacht' ? $xl_f1['nacht'] : true);
    $xl_bw = $xl_b['art'] === 'ganz' ? $xl_b['min'] . "\u{2026}" . $xl_b['max'] : ($xl_b['art'] === 'schalter' ? '0 | 1'
        : ($xl_b['art'] === 'puls' ? '1' : implode(' | ', $xl_b['werte']))); ?>
<tr><td><span class="sm-mono"><?= xl_e($xl_bn) ?></span><br><span class="sm-hilfe"><?= xl_e(xl_t($xl_b['kachel'])) ?></span></td>
<td><?= xl_e($xl_bw) ?></td><td><?= $xl_ja_nein($xl_k3) ?></td><td><?= $xl_ja_nein($xl_bn === 'szene' ? true : $xl_k1) ?><?= $xl_bn === 'szene' ? ' (' . xl_e(xl_t('LOX.OHNE_NACHT')) . ')' : '' ?></td>
<td><span class="sm-mono">publish <?= xl_e(XL_PRAEFIX) ?>/befehl/lampe1/<?= xl_e($xl_bn) ?> <?= $xl_b['art'] === 'ganz' ? '&lt;v.0&gt;' : ($xl_b['art'] === 'auswahl' ? xl_e($xl_b['werte'][0]) : '1') ?></span></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-knopfreihe">
<?php foreach ($xl_lampen as $xl_nr => $xl_l) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="vorlage">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="lampe" value="<?= (int) $xl_nr ?>"><?= xl_e(sprintf(xl_t('LOX.K_VORLAGE'), $xl_nr)) ?></button>
  </form>
<?php } ?>
</div>
<p class="sm-hilfe"><?= xl_e($xl_lampen ? xl_t('LOX.VORLAGE_HILFE') : xl_t('LOX.VORLAGE_KEINE')) ?></p>
</div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S5_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S5_TEXT')) ?></div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S6_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S6_TEXT')) ?>
<?php
// Bildnachzug 28.10.2026: je Musterprojekt-Seite ein Bild (Druck FUSS=0.95), Datei im Plugin wie Govee/BatterieBMS/BLE-Scanner.
$xl_bilder = array(1 => 'einbindung_loxone.png', 2 => 'einbindung_loxone_steuerung.png');
$xl_snr = 0;
foreach (xl_baustein_seiten() as $xl_seite => $xl_zeilen_b) {
    $xl_snr++; ?>
<h3><?= xl_e($xl_seite) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= xl_e(xl_t('LOX.SP_TYP')) ?></th><th><?= xl_e(xl_t('LOX.SP_NAME_VORSCHLAG')) ?></th><th><?= xl_e(xl_t('LOX.SP_PARAMETER')) ?></th><th><?= xl_e(xl_t('LOX.SP_EINGAENGE')) ?></th></tr>
<?php foreach ($xl_zeilen_b as $xl_bz) { ?>
<tr><td><?= xl_e($xl_bz[0]) ?></td><td><?= xl_e($xl_bz[1]) ?></td><td><?= xl_e($xl_bz[2]) ?></td><td><?= xl_e($xl_bz[3]) ?></td><td><?= xl_e($xl_bz[4]) ?></td></tr>
<?php } ?>
</table>
</div>
<?php if (isset($xl_bilder[$xl_snr])) { ?>
<figure class="sm-bild">
<img src="<?= xl_e($xl_bilder[$xl_snr]) ?>" alt="<?= xl_e(sprintf(xl_t('LOX.BILD_ALT'), $xl_seite)) ?>" loading="lazy">
<figcaption><?= xl_e(sprintf(xl_t('LOX.BILD_UNTERSCHRIFT'), $xl_seite)) ?></figcaption>
</figure>
<div class="sm-hilfe"><?= xl_t('LOX.MUSTERPROJEKT') ?></div>
<?php } ?>
<?php } ?>
<p class="sm-hilfe"><?= xl_e(xl_t('LOX.S6_HINWEIS')) ?></p>
</div>
<div class="sm-step"><b><?= xl_e(xl_t('LOX.S7_TITEL')) ?></b><br><?= xl_e(xl_t('LOX.S7_TEXT')) ?></div>
<details>
<summary><?= xl_e(xl_t('LOX.H_HTTP')) ?></summary>
<p class="sm-hilfe"><?= xl_e(xl_t('LOX.HTTP_TEXT')) ?></p>
<p><span class="sm-mono">http://&lt;LoxBerry&gt;/plugins/<?= xl_e($xl_p['plugin']) ?>/index.php?token=&lt;<?= xl_e(xl_t('LOX.TOKEN_PLATZ')) ?>&gt;</span></p>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="endpunkt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="endpunkt_ein" value="1"<?= !empty($xl_cfg['endpunkt_ein']) ? ' checked' : '' ?>>
<?= xl_e(xl_t('LOX.L_ENDPUNKT')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="speichern"><?= xl_e(xl_t('ALLG.K_SPEICHERN')) ?></button>
</div>
</form>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="endpunkt">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="token_neu_ok" value="1"> <?= xl_e(xl_t('LOX.TOKEN_NEU_HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="aktion" value="token_neu"><?= xl_e(xl_t('LOX.K_TOKEN_NEU')) ?></button>
</div>
</form>
</details>
</div>

<!-- ================= Reiter: Test (echter Seitenwechsel; Netzpruefungen nur, wenn er serverseitig offen ist) ================= -->
<div class="sm-seite<?= $xl_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-hinweis"><?= xl_e(xl_t('TEST.WAS_IST_DAS')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= xl_e(xl_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= xl_e(xl_t('LEGENDE.TECHNIK')) ?></span>
<?php if ($xl_test_offen && $xl_lampen) { /* orange nur, wenn die Schalt-Knoepfe stehen */ ?>
<span><i class="sm-punkt sm-b-aktion"></i> <?= xl_e(xl_t('LEGENDE.AKTION')) ?></span>
<?php } ?>
</div>
<?php if ($xl_test_offen) { ?>
<h2><?= xl_e(xl_t('TEST.H_SELBST')) ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<?php foreach (xl_test_zeilen(true) as $xl_tz) { ?>
<tr><td><?= $xl_zeichen($xl_tz[0]) ?></td><td><?= xl_e($xl_tz[1]) ?></td><td><?= $xl_tz[2] ?></td></tr>
<?php } ?>
</table>
</div>
<?php foreach ($xl_lampen as $xl_nr => $xl_l) {
    $xl_tl = xl_test_lampe($xl_l); ?>
<h3><?= xl_e(sprintf(xl_t('LOX.LAMPE_TITEL'), $xl_nr, $xl_l['name'])) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<?php foreach ($xl_tl['zeilen'] as $xl_tz) { ?>
<tr><td><?= $xl_zeichen($xl_tz[0]) ?></td><td><?= xl_e($xl_tz[1]) ?></td><td><?= $xl_tz[2] ?></td></tr>
<?php }
    $xl_kann = array();
    $xl_kannnicht = array();
    foreach ($xl_tl['faehig'] as $xl_fk => $xl_fv) {
        if ($xl_fv) { $xl_kann[] = xl_t('TEST.FAEHIG_' . strtoupper($xl_fk)); } else { $xl_kannnicht[] = xl_t('TEST.FAEHIG_' . strtoupper($xl_fk)); }
    } ?>
<tr><td><?= $xl_zeichen(null) ?></td><td><?= xl_e(xl_t('TEST.L_FAEHIG')) ?></td>
<td><?= xl_e(sprintf(xl_t('TEST.L_FAEHIG_TEXT'), $xl_kann ? implode(', ', $xl_kann) : '-', $xl_kannnicht ? implode(', ', $xl_kannnicht) : '-')) ?></td></tr>
</table>
</div>
<p class="sm-hilfe"><?= xl_e(sprintf(xl_t('TEST.ZWISCHEN'), max(0, time() - (int) $xl_tl['zeit']))) ?></p>
<?php } ?>
<?php if (!$xl_lampen) { ?>
<div class="sm-warnung"><?= xl_e(xl_t('TEST.KEINE_LAMPE')) ?></div>
<?php } ?>
<?php } else { ?>
<div class="sm-hinweis"><?= xl_e(xl_t('TEST.NUR_OFFEN')) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="aktion" value="neu"><?= xl_e(xl_t('TEST.K_NEU')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="aktion" value="rohwerte"><?= xl_e(xl_t('TEST.K_ROHWERTE')) ?></button>
  </form>
</div>
<?php if ($xl_testausgabe !== '') { ?>
<div class="sm-pre"><?= xl_e($xl_testausgabe) ?></div>
<?php } ?>
<h2><?= xl_e(xl_t('TEST.H_SCHALTEN')) ?></h2>
<div class="sm-warnung"><?= xl_e(xl_t('TEST.SCHALTEN_TEXT')) ?></div>
<?php if ($xl_test_offen) {
    foreach ($xl_lampen as $xl_nr => $xl_l) {
        $xl_tl = xl_test_lampe($xl_l);
        $xl_kn = array(array('schalten', '1', 'TEST.K_AN'), array('schalten', '0', 'TEST.K_AUS'), array('hell', '50', 'TEST.K_HELL50'));
        if (!empty($xl_tl['faehig']['ct'])) {
            $xl_kn[] = array('ct', '2700', 'TEST.K_CT2700');
            $xl_kn[] = array('ct', '6500', 'TEST.K_CT6500');
        }
        if (!empty($xl_tl['faehig']['nacht'])) {
            $xl_kn[] = array('nacht', '1', 'TEST.K_NACHT');
            $xl_kn[] = array('nacht', '0', 'TEST.K_TAG');
        } ?>
<h3><?= xl_e(sprintf(xl_t('LOX.LAMPE_TITEL'), $xl_nr, $xl_l['name'])) ?></h3>
<div class="sm-knopfreihe">
<?php foreach ($xl_kn as $xl_k) { ?>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
    <input data-role="none" type="hidden" name="formular" value="test">
    <input data-role="none" type="hidden" name="aktion" value="schalten">
    <input data-role="none" type="hidden" name="lampe" value="<?= (int) $xl_nr ?>">
    <input data-role="none" type="hidden" name="cmd" value="<?= xl_e($xl_k[0]) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="wert" value="<?= xl_e($xl_k[1]) ?>"><?= xl_e(xl_t($xl_k[2])) ?></button>
  </form>
<?php } ?>
</div>
<?php }
} ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $xl_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-hinweis"><?= xl_e(xl_t('LOG.ERKLAERUNG')) ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= xl_e(xl_t('LEGENDE.AKTION')) ?></span>
</div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><span class="sm-mono"><?= xl_e($xl_p['log']) ?></span></p>
<?php if ($xl_logzeilen) { ?>
<div class="sm-log"><?= xl_e(implode("\n", $xl_logzeilen)) ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= xl_e(xl_t('LOG.LEER')) ?></p>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<input data-role="none" type="hidden" name="fmt" value="<?= xl_e($xl_fmt) ?>">
<input data-role="none" type="hidden" name="formular" value="log">
<div class="sm-feld"><label><input data-role="none" type="checkbox" name="leeren_ok" value="1"> <?= xl_e(xl_t('LOG.HAKEN')) ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="leeren" value="1"><?= xl_e(xl_t('LOG.K_LEEREN')) ?></button>
</div>
</form>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		if (r.dataset.ziel === 'tab-test') { return; }   // der Reiter Test laedt die Seite neu (Netzpruefungen nur dort)
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($xl_tab) ?>);
	document.querySelectorAll('.sm-kopie').forEach(function (k) {
		k.addEventListener('click', function () {
			var n = k.getAttribute('data-kopie');
			var fertig = function () { k.textContent = <?= json_encode(xl_t('LOX.KOPIERT')) ?>; };
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(n).then(fertig);
			} else {
				var f = document.createElement('textarea');
				f.value = n; document.body.appendChild(f); f.select();
				try { document.execCommand('copy'); fertig(); } catch (e) {}
				document.body.removeChild(f);
			}
		});
	});
})();
</script>
<?php
if ($xl_rahmen) {
    LBWeb::lbfooter();
}
