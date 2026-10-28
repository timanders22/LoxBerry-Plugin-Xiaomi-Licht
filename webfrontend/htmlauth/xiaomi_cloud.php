<?php
/*
 * xiaomi_cloud.php - gemeinsames Stammmodul "Xiaomi-Cloud" 1.0.1 (Gruppe 33, 27.10.2026; Stammfassung
 * Werkzeuge/gemeinsam/xiaomi_cloud.php, byte-gleich verteilt wie Befehlsbruecke/Sprachmodul). Herausgeloest aus dem
 * Saugroboter 1.1.23 (robo_lib.php ro_cloud_*). Linieneigen bleiben: Oberflaeche, Uebernahme in die Geraetezeile,
 * Sprachtexte der Lagen. Abhaengigkeiten: keine (kein ro_*, kein bl_*); PHP 7.4/8.4/8.5, nur Stream-Wrapper https.
 * 1.0.1 (Gruppe 34, 27.10.2026): neu xc_auswahl_mehrere() - mehrere Geraete aus EINER Auswahl (ein Griff, danach
 * geloescht); dazu je Geraet das Feld 'online' (isOnline der Cloud: 1/0, -1 unbekannt) in Liste und Anzeige - nur
 * hinzugefuegt, kein Feld geaendert. xc_code_anfordern(): Antwort von Xiaomi bei SENDEN ('art' SPERRE/CAPTCHA/UNBEKANNT,
 * 'code', 'grund'), Versand-Captcha mit $icode (neu xc_sendefehler(), xc_bild_holen()); Lage SENDEN bleibt.
 * Alle uebrigen Funktionen, auch xc_auswahl_nehmen(), unveraendert.
 */
define('XC_FASSUNG', '1.0.1');

/** Ordner der Zwischenstaende (Captcha, Zweitfaktor, Auswahl; je 0600, <= 10 min). Die Linie setzt ihn einmal. */
function xc_ablage($ordner = null)
{
    static $o = '';
    if ($ordner !== null) { $o = rtrim((string) $ordner, '/\\'); }
    return $o;
}

/** Atomar schreiben, Rechte VOR dem Inhalt (Regeln/05). */
function xc_schreiben($datei, $inhalt, $rechte = 0600)
{
    $tmp = $datei . '.neu' . getmypid();
    if (@file_put_contents($tmp, '') === false) { return false; }
    @chmod($tmp, $rechte);
    if (@file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Welche Geraete eine Linie haben will. $filter: array('modelle' => Liste von Modell-Praefixen (leer = alle),
 * 'ble' => 0 | 1 (1 = nur Bluetooth-Geraete, Kennung did mit "blt" wie der Extractor; 0 = nur WLAN-Geraete mit Token)).
 * Saugroboter: ('modelle' => ['xiaomi.vacuum.'], 'ble' => 0); BLE-Scanner: ('modelle' => [], 'ble' => 1);
 * Xiaomi Licht: ('modelle' => ['yeelink.light.', 'yilai.light.'], 'ble' => 0).
 */
function xc_passt(array $g, array $filter)
{
    $ble = strpos((string) (isset($g['did']) ? $g['did'] : ''), 'blt') !== false;
    if ((int) (isset($filter['ble']) ? $filter['ble'] : 0) !== ($ble ? 1 : 0)) { return false; }
    $m = isset($filter['modelle']) && is_array($filter['modelle']) ? $filter['modelle'] : array();
    if (!$m) { return true; }
    foreach ($m as $p) { if (is_string($p) && $p !== '' && strpos((string) $g['model'], $p) === 0) { return true; } }
    return false;
}

/**
 * Bind-Key (BLE KEY) eines Bluetooth-Geraets: POST /v2/device/blt_get_beaconkey mit {"did":"<did>","pdid":1}
 * (Extractor get_beaconkey, gelesen 27.10.2026), Antwort result.beaconkey. 24 oder 32 Hex, sonst ''. Nie protokolliert.
 */
function xc_beaconkey(array $sitzung, $region, $did, array $ziele)
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}\z/', (string) $did)) { return ''; }
    $j = xc_rufen($sitzung, $region, '/v2/device/blt_get_beaconkey', '{"did":"' . $did . '","pdid":1}', $ziele);
    $k = (is_array($j) && isset($j['result']['beaconkey']) && is_string($j['result']['beaconkey'])) ? strtolower($j['result']['beaconkey']) : '';
    return preg_match('/^([0-9a-f]{24}|[0-9a-f]{32})\z/', $k) ? $k : '';
}

/** Einen Eintrag der Auswahl (mit Token/Bind-Key) nehmen und die Auswahl loeschen. null = abgelaufen. Linie uebernimmt. */
function xc_auswahl_nehmen($index)
{
    $d = xc_nehmen('auswahl');
    return ($d !== null && isset($d['liste'][(int) $index])) ? array('zeile' => (int) $d['zeile'], 'geraet' => $d['liste'][(int) $index]) : null;
}

/**
 * 1.0.1 (Gruppe 34, 27.10.2026): MEHRERE Eintraege der Auswahl in EINEM Griff nehmen - die Liste wird einmal gelesen
 * und dabei sofort geloescht wie bei xc_auswahl_nehmen() (eine Anmeldung, eine Uebernahme). $indizes: die angehakten
 * Indizes der Anzeige (ganze Zahlen >= 0; doppelte zaehlen einmal, unbekannte fallen weg, hoechstens 100).
 * Rueckgabe array(Index => Geraet mit Token/Bind-Key) in der Reihenfolge der Liste, oder null = keine Auswahl mehr
 * (abgelaufen oder schon genommen). Nichts angehakt: es wird NICHTS genommen - die Liste bleibt fuer einen zweiten
 * Versuch liegen (Rueckgabe array(), oder null, wenn sie schon abgelaufen ist). Die Linie uebernimmt.
 */
function xc_auswahl_mehrere(array $indizes)
{
    $wahl = array();
    foreach ($indizes as $i) {
        if ((is_int($i) || (is_string($i) && preg_match('/^[0-9]{1,4}\z/', $i))) && (int) $i >= 0 && count($wahl) < 100) {
            $wahl[(int) $i] = true;
        }
    }
    if (!$wahl) {
        return xc_lesen('auswahl') === null ? null : array();
    }
    $d = xc_nehmen('auswahl');
    if ($d === null || !isset($d['liste']) || !is_array($d['liste'])) { return null; }
    $aus = array();
    foreach ($d['liste'] as $i => $g) {
        if (isset($wahl[(int) $i]) && is_array($g)) { $aus[(int) $i] = $g; }
    }
    unset($d);
    return $aus;
}

/* ==================================================================
 * Token aus der Xiaomi-Cloud holen (Gruppe 28, 1.1.23; Entscheidung "Token-Beschaffung", Empfehlung A)
 * ==================================================================
 *
 * Der T12 antwortet nur mit seinem Token. Der Hausherr holt ihn mit SEINER Xiaomi-Anmeldung - im Reiter Einstellungen
 * ("Token aus der Xiaomi-Cloud holen") oder mit dem Xiaomi-cloud-tokens-extractor (Anleitung dort und in der Hilfe).
 *
 * HERKUNFT DES ABLAUFS: nachgebaut nach dem Quelltext von PiotrMachowski/Xiaomi-cloud-tokens-extractor
 * (github.com, token_extractor.py, Stand c4db715, gelesen 26.10.2026; Lizenz MIT, Copyright (c) 2020 Piotr Machowski).
 * Uebernommen sind nur die Schritte und Feldnamen der oeffentlichen Xiaomi-Schnittstelle, kein Quelltext:
 *   1. GET  account.xiaomi.com/pass/serviceLogin?sid=xiaomiio&_json=true   (Antwort mit Vorsatz &&&START&&&, _sign)
 *   2. POST account.xiaomi.com/pass/serviceLoginAuth2 (sid, hash = MD5(Kennwort) gross, callback, qs, user, _sign)
 *      -> ssecurity, userId, location | captchaUrl | notificationUrl (Zweitfaktor)
 *   3. GET  location -> Keks serviceToken
 *   Captcha (Nachbau 26.10. spaet): captchaUrl -> Bild mit der Keksdose holen (Keks ick), der Hausherr tippt die
 *      Zeichen im Reiter, Schritt 2 erneut mit captCode (Extractor login_step_2/handle_captcha); 87001 = falsch.
 *   Zweitfaktor: notificationUrl (authStart), identity/list (options 4 = SMS, 8 = Mail), der Hausherr waehlt den Weg,
 *      identity/auth/sendEmailTicket bzw. sendPhoneTicket (SMS-Versand ungeprueft), verifyEmail (_flag 8) bzw.
 *      verifyPhone (_flag 4; belegt in al-one/hass-xiaomi-miot, Apache-2.0, nur gelesen), Auth2/end (Kopfzeile
 *      extension-pragma -> ssecurity), sts -> serviceToken.
 *   4. Geraeteliste je Region <r>.api.io.mi.com/app (cn: api.io.mi.com/app): /v2/homeroom/gethome und
 *      /v2/home/home_device_list, signiert und RC4-verschluesselt (_nonce, signed_nonce = SHA256(ssecurity . nonce),
 *      rc4_hash__ und signature = SHA1 ueber "POST&<pfad>&k=v...&<signed_nonce>", RC4 ohne die ersten 1024 Bytes).
 * Ein Captcha wird NICHT umgangen und nicht maschinell geloest: das Bild von Xiaomi erscheint im Reiter, die Loesung
 * tippt der Hausherr.
 *
 * GEHEIMNISSE (Regeln/05, Bauvorschrift): Kennwort, Sitzung (serviceToken, ssecurity) und Kekse werden nie gespeichert,
 * nie protokolliert, nie ausgegeben, nie als Argument uebergeben - nur im Speicher des EINEN POST. Ausnahme laut
 * Entscheidung: der Zwischenzustand des Zweitfaktors (Kekse der Identitaetspruefung, KEIN Kennwort) liegt hoechstens
 * 10 Minuten in data/plugins/<ordner>/cloud_zweitfaktor.json (0600) und wird beim Bestaetigen sofort geloescht; der
 * des Captchas (Konto, _sign, Kekse der Anmeldung, Bild - KEIN Kennwort, das tippt der Hausherr mit der Loesung neu)
 * in cloud_captcha.json (0600, 10 Minuten, ein Versuch); ebenso
 * die Auswahlliste der gefundenen Roboter (cloud_auswahl.json, 0600, 10 Minuten). Geschickt wird nur an Hosts der
 * Liste xc_ziele() (https, *.xiaomi.com / *.mi.com) - eine Umleitung woandershin bricht ab.
 */

/** Die Regionen der Auswahl (Entscheidung: de, cn, i2, ru, sg, us). */
function xc_regionen()
{
    return array('de', 'cn', 'i2', 'ru', 'sg', 'us');
}

/**
 * Die Ziele der Anmeldung. Ab Werk die echten Xiaomi-Adressen; die Proben reichen eine eigene Liste mit der
 * Attrappe herein (es gibt keinen Schalter in Konfiguration oder Umgebung, der das umlenkt).
 */
function xc_ziele()
{
    return array(
        'konto'   => 'https://account.xiaomi.com',
        'sts'     => 'https://sts.api.io.mi.com/sts',
        'api'     => 'https://{r}.api.io.mi.com/app',
        'api_cn'  => 'https://api.io.mi.com/app',
        'erlaubt' => array('xiaomi.com', 'mi.com'),
        'schema'  => 'https',
    );
}

/** Darf an diese Adresse geschickt werden? Nur das Schema und die Hosts der Liste (Host gleich oder Unterdomain). */
function xc_url_ok($url, array $ziele)
{
    $t = @parse_url((string) $url);
    if (!is_array($t) || !isset($t['scheme'], $t['host']) || strtolower($t['scheme']) !== $ziele['schema']) { return false; }
    $h = strtolower($t['host']);
    foreach ($ziele['erlaubt'] as $e) {
        $e = strtolower($e);
        if ($h === $e || substr($h, -strlen($e) - 1) === '.' . $e) { return true; }
    }
    return false;
}

/** Die Adresse der Geraete-Schnittstelle einer Region. */
function xc_api($region, array $ziele)
{
    return $region === 'cn' ? $ziele['api_cn'] : str_replace('{r}', $region, $ziele['api']);
}

/** Zufallstext fuer Geraetekennung und User-Agent (wie die Mi-Home-App). */
function xc_zufall($n, $zeichen = 'abcdefghijklmnopqrstuvwxyz')
{
    $t = '';
    for ($i = 0; $i < $n; $i++) { $t .= $zeichen[random_int(0, strlen($zeichen) - 1)]; }
    return $t;
}

/**
 * Ein HTTP-Aufruf mit Keksdose. Folgt Umleitungen nur, wenn $folgen, hoechstens 6, und nur zu erlaubten Hosts.
 * Rueckgabe array(Code, Rumpf, Kopfzeilen (klein => letzter Wert), Kekse dieser Antwort (Name => Wert), letzte URL);
 * Code 0 = keine Antwort, -1 = Ziel nicht erlaubt.
 */
function xc_http($methode, $url, array $abfrage, $form, array &$dose, array $ziele, $folgen = false, $kopf_extra = '')
{
    if ($abfrage) { $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($abfrage, '', '&', PHP_QUERY_RFC3986); }
    $neu_kekse = array();
    for ($sprung = 0; $sprung < 7; $sprung++) {
        if (!xc_url_ok($url, $ziele)) { return array(-1, '', array(), $neu_kekse, $url); }
        $kekse = array();
        foreach ($dose as $k => $v) { $kekse[] = $k . '=' . $v; }
        $kopf = 'User-Agent: ' . xc_ua() . "\r\nAccept: */*\r\n"
              . ($kekse ? 'Cookie: ' . implode('; ', $kekse) . "\r\n" : '')
              . $kopf_extra;
        $opt = array('method' => $methode, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
                     'protocol_version' => 1.1, 'header' => $kopf . "Connection: close\r\n");
        if ($form !== null) {
            $rumpf = is_array($form) ? http_build_query($form, '', '&') : (string) $form;
            $opt['content'] = $rumpf;
            $opt['header'] .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($rumpf) . "\r\n";
        }
        $fp = @fopen($url, 'r', false, stream_context_create(array('http' => $opt)));
        if ($fp === false) { return array(0, '', array(), $neu_kekse, $url); }
        $meta = @stream_get_meta_data($fp);
        $rumpf = (string) @stream_get_contents($fp);
        @fclose($fp);
        $code = 0; $kz = array();
        foreach ((isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) ? $meta['wrapper_data'] : array() as $z) {
            if (!is_string($z)) { continue; }
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; $kz = array(); continue; }
            $p = strpos($z, ':');
            if ($p === false) { continue; }
            $n = strtolower(trim(substr($z, 0, $p)));
            $w = trim(substr($z, $p + 1));
            $kz[$n] = $w;
            if ($n === 'set-cookie' && preg_match('/^([^=;\s]+)=([^;]*)/', $w, $m)) {
                if ($m[2] !== '' && strtoupper($m[2]) !== 'EXPIRED') { $dose[$m[1]] = $m[2]; $neu_kekse[$m[1]] = $m[2]; }
            }
        }
        if (!$folgen || $code < 300 || $code > 399 || !isset($kz['location'])) {
            return array($code, $rumpf, $kz, $neu_kekse, $url);
        }
        $url = xc_absolut($url, $kz['location']);
        $methode = 'GET'; $form = null; $kopf_extra = '';
    }
    return array(0, '', array(), $neu_kekse, $url);
}

/** Eine (moeglicherweise relative) Umleitung absolut machen. */
function xc_absolut($basis, $ziel)
{
    if (preg_match('#^https?://#i', $ziel)) { return $ziel; }
    $t = parse_url($basis);
    $wurzel = $t['scheme'] . '://' . $t['host'] . (isset($t['port']) ? ':' . $t['port'] : '');
    return $wurzel . ($ziel !== '' && $ziel[0] === '/' ? $ziel : '/' . $ziel);
}

/** Der User-Agent der Mi-Home-App (Form wie im Extractor), einmal je Prozess gewuerfelt. */
function xc_ua()
{
    static $ua = null;
    if ($ua === null) {
        $ua = xc_zufall(18) . '-' . xc_zufall(13, 'ABCDE') . ' APP/com.xiaomi.mihome APPV/10.5.201';
    }
    return $ua;
}

/** Eine Xiaomi-JSON-Antwort lesen (Vorsatz &&&START&&& weg). */
function xc_json($t)
{
    $j = json_decode(str_replace('&&&START&&&', '', (string) $t), true);
    return is_array($j) ? $j : null;
}

/**
 * Schritt 2 der Anmeldung: POST serviceLoginAuth2 (mit Captcha-Loesung, wenn eine vorliegt). Rueckgabe array(lage,
 * JSON|null): lage ok | NETZ | ZIEL | ANTWORT. $kennwort wird nur zum MD5 gelesen.
 */
function xc_schritt2($konto, $kennwort, $sign, array &$dose, array $ziele, $captcha = null)
{
    $f = array('sid' => 'xiaomiio', 'hash' => strtoupper(md5((string) $kennwort)), 'callback' => $ziele['sts'],
               'qs' => '%3Fsid%3Dxiaomiio%26_json%3Dtrue', 'user' => (string) $konto, '_sign' => (string) $sign,
               '_json' => 'true');
    if ($captcha !== null) { $f['captCode'] = (string) $captcha; }
    list($c, $t) = xc_http('POST', $ziele['konto'] . '/pass/serviceLoginAuth2', $f, '', $dose, $ziele);
    unset($f);
    if ($c === -1) { return array('ZIEL', null); }
    if ($c === 0) { return array('NETZ', null); }
    $j = xc_json($t);
    return $j === null ? array('ANTWORT', null) : array('ok', $j);
}

/**
 * Die Antwort von Schritt 2 auswerten: Captcha -> Bild holen (Zwischenzustand ohne Kennwort), Zweitfaktor ->
 * beginnen, ssecurity -> location -> serviceToken. Rueckgabe wie xc_anmelden().
 */
function xc_schritt2_weiter(array $j, $konto, $sign, array $dose, array $ziele)
{
    if (!empty($j['captchaUrl']) && is_string($j['captchaUrl'])) {
        return xc_captcha_holen($j['captchaUrl'], $konto, $sign, $dose, $ziele);
    }
    if (isset($j['code']) && (int) $j['code'] === 87001) { return array('lage' => 'CAPTCHA_BILD'); }
    if (!empty($j['notificationUrl']) && is_string($j['notificationUrl'])) {
        return xc_zweitfaktor_beginnen($j['notificationUrl'], $dose, $ziele);
    }
    if (!isset($j['ssecurity']) || strlen((string) $j['ssecurity']) <= 4) { return array('lage' => 'KENNWORT'); }
    return xc_sitzung_holen($j, $dose, $ziele);
}

/** ssecurity + location -> Keks serviceToken. Rueckgabe array('lage' => ok, 'sitzung') oder ein Fehler. */
function xc_sitzung_holen(array $j, array $dose, array $ziele)
{
    if (empty($j['location']) || !is_string($j['location'])) { return array('lage' => 'ANTWORT'); }
    list($c, $t, $kz, $neu) = xc_http('GET', $j['location'], array(), null, $dose, $ziele, true);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    $tok = isset($neu['serviceToken']) ? $neu['serviceToken'] : (isset($dose['serviceToken']) ? $dose['serviceToken'] : '');
    if ($tok === '') { return array('lage' => 'ANTWORT'); }
    return array('lage' => 'ok', 'sitzung' => array('userId' => (string) (isset($j['userId']) ? $j['userId'] : ''),
        'ssecurity' => (string) $j['ssecurity'], 'serviceToken' => $tok));
}

/**
 * Anmelden. Rueckgabe array('lage' => ok | ZWEITFAKTOR | CAPTCHA | KENNWORT | NETZ | ANTWORT | ZIEL,
 *   'sitzung' => array(userId, ssecurity, serviceToken) nur im Speicher, 'zweitfaktor'/'captcha' => Zwischenzustand,
 *   'link'). $kennwort wird nur hier gelesen; der Aufrufer ueberschreibt seine Kopie danach.
 */
function xc_anmelden($konto, $kennwort, array $ziele)
{
    $dose = array('userId' => (string) $konto, 'sdkVersion' => 'accountsdk-18.8.15', 'deviceId' => xc_zufall(6));
    list($c, $t) = xc_http('GET', $ziele['konto'] . '/pass/serviceLogin', array('sid' => 'xiaomiio', '_json' => 'true'),
                                 null, $dose, $ziele);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    if ($c === 0) { return array('lage' => 'NETZ'); }
    $j = xc_json($t);
    if ($j === null) { return array('lage' => 'ANTWORT'); }
    if (isset($j['ssecurity'])) { return xc_sitzung_holen($j, $dose, $ziele); }
    if (!isset($j['_sign']) || !is_scalar($j['_sign'])) { return array('lage' => 'ANTWORT'); }
    $sign = (string) $j['_sign'];
    list($lage, $j) = xc_schritt2($konto, $kennwort, $sign, $dose, $ziele);
    if ($lage !== 'ok') { return array('lage' => $lage); }
    return xc_schritt2_weiter($j, $konto, $sign, $dose, $ziele);
}

/**
 * Captcha (Nachbau 26.10. spaet, Entscheidung: der Hausherr loest es selbst): das Bild von Xiaomi holen - mit der
 * Keksdose der Anmeldung; die Antwort setzt den Keks "ick", an den Xiaomi die Loesung bindet (Extractor
 * handle_captcha(); hass-xiaomi-miot _get_captcha() liest ick aus derselben Antwort). Kein Erkennen, kein Umgehen:
 * das Bild geht unveraendert in den Reiter, der Hausherr tippt die Zeichen. Zwischenzustand (0600, <= 10 min, EIN
 * Versuch): Konto, _sign, Kekse der Anmeldung, Bild - KEIN Kennwort, keine Sitzung. Nur Bilder PNG/JPEG/GIF bis 100 kB.
 */
function xc_captcha_holen($url, $konto, $sign, array $dose, array $ziele)
{
    $url = xc_absolut($ziele['konto'], (string) $url);
    list($c, $bild, $kz) = xc_http('GET', $url, array(), null, $dose, $ziele, true);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    if ($c !== 200) { return array('lage' => 'CAPTCHA_BILD'); }
    $typ = xc_bildtyp($bild);
    if ($typ === '' || strlen($bild) > 102400) { return array('lage' => 'CAPTCHA_BILD'); }
    return array('lage' => 'CAPTCHA', 'captcha' => array('konto' => (string) $konto, 'sign' => (string) $sign,
        'dose' => $dose, 'bild' => base64_encode($bild), 'bildtyp' => $typ, 'zeit' => time()));
}

/** Bildtyp an den ersten Bytes: image/png | image/jpeg | image/gif, sonst '' (kein Bild - nicht anzeigen). */
function xc_bildtyp($b)
{
    $b = (string) $b;
    if (substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") { return 'image/png'; }
    if (substr($b, 0, 3) === "\xff\xd8\xff") { return 'image/jpeg'; }
    if (substr($b, 0, 6) === 'GIF87a' || substr($b, 0, 6) === 'GIF89a') { return 'image/gif'; }
    return '';
}

/**
 * Zweitfaktor beginnen (Extractor do_2fa_email_flow Schritte 1-2): notificationUrl (authStart) oeffnen und die Wege
 * der Identitaetspruefung lesen (identity/list -> options: 4 = SMS an die Telefonnummer, 8 = Mail; Belegung nach
 * hass-xiaomi-miot check_identity_list/verify_ticket). Noch KEIN Code angefordert - der Hausherr waehlt den Weg im
 * Reiter (xc_code_anfordern()). Zwischenzustand ohne Kennwort.
 */
function xc_zweitfaktor_beginnen($link, array $dose, array $ziele)
{
    if (!xc_url_ok($link, $ziele)) { return array('lage' => 'ZIEL'); }
    $q = array();
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    $kontext = isset($q['context']) && is_string($q['context']) ? $q['context'] : '';
    list($c) = xc_http('GET', $link, array(), null, $dose, $ziele, true);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    list($c, $t) = xc_http('GET', $ziele['konto'] . '/identity/list', array('sid' => 'xiaomiio', 'context' => $kontext,
        '_locale' => 'en_US'), null, $dose, $ziele, true);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    if ($c === 0) { return array('lage' => 'NETZ'); }
    $j = xc_json($t);
    $opt = array();
    $roh = (is_array($j) && isset($j['options']) && is_array($j['options'])) ? $j['options']
         : ((is_array($j) && isset($j['flag'])) ? array($j['flag']) : array(4, 8));
    foreach ($roh as $o) { if (in_array((int) $o, array(4, 8), true) && !in_array((int) $o, $opt, true)) { $opt[] = (int) $o; } }
    if (!$opt) { $opt = array(4, 8); }
    sort($opt);
    return array('lage' => 'ZWEITFAKTOR', 'link' => $link,
                 'zweitfaktor' => array('kontext' => $kontext, 'dose' => $dose, 'optionen' => $opt, 'weg' => 0,
                                        'link' => $link, 'zeit' => time()));
}

/**
 * Den Code anfordern (Extractor Schritt 3 sendEmailTicket fuer Mail; fuer SMS derselbe Aufbau an sendPhoneTicket -
 * UNGEPRUEFT, in den gelesenen Quellen steht nur die Pruefung verifyPhone; klappt der Versand nicht, bleibt der Prueflink
 * von Xiaomi). $weg 'mail' | 'sms'. Der Zwischenzustand bleibt (mit dem gewaehlten Weg, Ablauf ab dem Beginn).
 * Rueckgabe array('lage' => CODE_GESENDET | WEG | ABGELAUFEN | SENDEN | NETZ | ZIEL | SCHREIBEN | LOESUNG, 'weg').
 * 1.0.1 (Geraetetest 27.10.): bei SENDEN zusaetzlich 'art' => SPERRE (zu viele Versuche: 70022 oder ein Text mit
 * limit/frequent) | CAPTCHA (Xiaomi verlangt fuer den Versand ein Captcha: captchaUrl oder 87001 - das Bild liegt dann
 * im Zwischenzustand unter 'sende_captcha', der Hausherr tippt die Zeichen, der Versand wird mit $icode wiederholt) |
 * UNBEKANNT, dazu 'code' (Zahl der Antwort oder 0) und 'grund' (description/desc, gekuerzt, ohne Kennungen). Die Lage
 * bleibt SENDEN - eine Linie, die 'art' nicht kennt, meldet wie bisher. LOESUNG = $icode hat nicht die Form.
 */
function xc_code_anfordern($weg, $ziele = null, $icode = null)
{
    if (!is_array($ziele)) { $ziele = xc_ziele(); }
    if ($icode !== null && !preg_match('/^[0-9A-Za-z]{3,10}\z/', (string) $icode)) { return array('lage' => 'LOESUNG', 'weg' => $weg); }
    $flag = $weg === 'mail' ? 8 : ($weg === 'sms' ? 4 : 0);
    $zf = xc_lesen('zweitfaktor');
    if ($zf === null) { return array('lage' => 'ABGELAUFEN'); }
    if ($flag === 0 || !in_array($flag, (array) $zf['optionen'], true)) { return array('lage' => 'WEG'); }
    $dose = is_array($zf['dose']) ? $zf['dose'] : array();
    list($c, $t) = xc_http('POST', $ziele['konto'] . '/identity/auth/' . ($flag === 8 ? 'sendEmailTicket' : 'sendPhoneTicket'),
        array('_dc' => (string) round(microtime(true) * 1000), 'sid' => 'xiaomiio', 'context' => (string) $zf['kontext'],
              'mask' => '0', '_locale' => 'en_US'),
        array('retry' => '0', 'icode' => $icode === null ? '' : (string) $icode, '_json' => 'true',
              'ick' => isset($dose['ick']) ? $dose['ick'] : ''), $dose, $ziele);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    if ($c === 0) { return array('lage' => 'NETZ'); }
    $j = xc_json($t);
    unset($zf['sende_captcha']);
    if ($c !== 200 || (is_array($j) && isset($j['code']) && (int) $j['code'] !== 0)) {
        $erg = xc_sendefehler($c, $j);
        $erg['weg'] = $weg;
        if ($erg['art'] === 'CAPTCHA') {
            $url = (is_array($j) && !empty($j['captchaUrl']) && is_string($j['captchaUrl'])) ? $j['captchaUrl'] : '/pass/getCode?icodeType=antiVerify';
            list($typ, $bild) = xc_bild_holen($url, $dose, $ziele);
            if ($typ !== '') {
                $zf['sende_captcha'] = array('bild' => base64_encode($bild), 'bildtyp' => $typ, 'weg' => $weg);
            } else {
                $erg['art'] = 'UNBEKANNT';
            }
        }
        $zf['dose'] = $dose;                        // ick des Captcha-Bilds gehoert zur Loesung
        if (!xc_ablegen('zweitfaktor', $zf)) { return array('lage' => 'SCHREIBEN'); }
        return $erg;
    }
    $zf['dose'] = $dose;
    $zf['weg'] = $flag;
    if (!xc_ablegen('zweitfaktor', $zf)) { return array('lage' => 'SCHREIBEN'); }
    return array('lage' => 'CODE_GESENDET', 'weg' => $weg);
}

/**
 * 1.0.1: eine abgelehnte Antwort des Code-Versands einordnen. Nur zwei Felder der Antwort: code (Zahl) und
 * description/desc (hoechstens 120 Zeichen; Mailadressen, Telefonnummern und lange Ziffern-/Hexfolgen herausgenommen).
 */
function xc_sendefehler($http, $j)
{
    $code = (is_array($j) && isset($j['code']) && is_numeric($j['code'])) ? (int) $j['code'] : 0;
    $t = '';
    foreach (array('description', 'desc') as $k) {
        if ($t === '' && is_array($j) && isset($j[$k]) && is_string($j[$k])) { $t = $j[$k]; }
    }
    $t = preg_replace('/\S+@\S+/', '<...>', $t);
    $t = preg_replace('/[0-9a-fA-F]{8,}|\+?[0-9][0-9 \-]{5,}[0-9]/', '<...>', $t);
    $t = trim(preg_replace('/[\x00-\x1F\x7F"\'<>&\\\\]+/', ' ', str_replace('<...>', '...', $t)));
    $t = preg_replace('/\s{2,}/', ' ', $t);
    $t = function_exists('mb_substr') ? mb_substr($t, 0, 120, 'UTF-8') : substr($t, 0, 120);
    $art = 'UNBEKANNT';
    if ($code === 70022 || preg_match('/limit|frequent/i', $t)) { $art = 'SPERRE'; }
    if ($code === 87001 || (is_array($j) && !empty($j['captchaUrl']))) { $art = 'CAPTCHA'; }
    return array('lage' => 'SENDEN', 'art' => $art, 'code' => $code, 'grund' => $t, 'http' => (int) $http);
}

/** 1.0.1: ein Captcha-Bild mit der Keksdose holen (setzt ick). Rueckgabe array(bildtyp|'', Bild). Nur PNG/JPEG/GIF <= 100 kB. */
function xc_bild_holen($url, array &$dose, array $ziele)
{
    list($c, $bild) = xc_http('GET', xc_absolut($ziele['konto'], (string) $url), array(), null, $dose, $ziele, true);
    $typ = $c === 200 ? xc_bildtyp($bild) : '';
    return ($typ === '' || strlen($bild) > 102400) ? array('', '') : array($typ, $bild);
}

/** Der Ort eines Zwischenstands (Zweitfaktor, Captcha, Auswahlliste) - alle 0600, hoechstens 10 Minuten. */
function xc_datei($art)
{
    $a = in_array($art, array('auswahl', 'captcha'), true) ? $art : 'zweitfaktor';
    return xc_ablage() . '/cloud_' . $a . '.json';
}

/** Einen Zwischenstand 0600 ablegen (die Zeit des Beginns reist im Inhalt mit - Ablauf ab dem Beginn). */
function xc_ablegen($art, array $d)
{
    if (xc_ablage() === '') { return false; }    // Ablage nicht gesetzt: nie nach /cloud_*.json
    $js = json_encode($d);
    return $js !== false && xc_schreiben(xc_datei($art), $js, 0600);
}

/** Abgelaufene Zwischenstaende wegraeumen (Seitenaufbau, Minutenlauf). */
function xc_aufraeumen()
{
    foreach (array('zweitfaktor', 'auswahl', 'captcha') as $a) {
        $f = xc_datei($a);
        if (is_file($f) && (time() - (int) @filemtime($f) > 600 || (int) @filemtime($f) > time() + 60)) { @unlink($f); }
    }
}

/** Gilt dieser Zwischenstand noch? Nach Inhalt UND nach Dateizeit (wie xc_aufraeumen()) - das Strengere gilt. */
function xc_gilt($d, $m)
{
    if (time() - $m > 600 || $m > time() + 60) { return false; }
    return is_array($d) && isset($d['zeit']) && time() - (int) $d['zeit'] <= 600 && (int) $d['zeit'] <= time() + 60;
}

/** Einen Zwischenstand lesen, OHNE ihn zu verbrauchen (Anzeige im Reiter, Code anfordern). null = keiner/abgelaufen. */
function xc_lesen($art)
{
    $f = xc_datei($art);
    if (!is_file($f)) { return null; }
    $m = (int) @filemtime($f);
    $d = json_decode((string) @file_get_contents($f), true);
    if (!xc_gilt($d, $m)) { @unlink($f); return null; }
    return $d;
}

/** Einen Zwischenstand lesen und SOFORT loeschen (ein Versuch). null = keiner oder abgelaufen. */
function xc_nehmen($art)
{
    $f = xc_datei($art);
    if (!is_file($f)) { return null; }
    $m = (int) @filemtime($f);
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    return xc_gilt($d, $m) ? $d : null;
}

/**
 * Zweitfaktor abschliessen: den Code pruefen lassen (Mail: verifyEmail _flag 8 wie der Extractor; SMS: verifyPhone
 * _flag 4 wie hass-xiaomi-miot) und die Sitzung holen. Rueckgabe wie xc_anmelden().
 */
function xc_zweitfaktor_beenden(array $zf, $code, array $ziele)
{
    $dose = isset($zf['dose']) && is_array($zf['dose']) ? $zf['dose'] : array();
    $kontext = isset($zf['kontext']) ? (string) $zf['kontext'] : '';
    $flag = (isset($zf['weg']) && (int) $zf['weg'] === 4) ? 4 : 8;
    list($c, $t, $kz) = xc_http('POST', $ziele['konto'] . '/identity/auth/' . ($flag === 8 ? 'verifyEmail' : 'verifyPhone'), array(
        '_flag' => (string) $flag, '_json' => 'true', 'sid' => 'xiaomiio', 'context' => $kontext, 'mask' => '0', '_locale' => 'en_US'),
        array('_flag' => (string) $flag, 'ticket' => (string) $code, 'trust' => 'false', '_json' => 'true',
              'ick' => isset($dose['ick']) ? $dose['ick'] : ''), $dose, $ziele);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    if ($c === 0) { return array('lage' => 'NETZ'); }
    $j = xc_json($t);
    $weiter = '';
    if (is_array($j) && !empty($j['location']) && is_string($j['location'])) { $weiter = $j['location']; }
    elseif (isset($kz['location'])) { $weiter = $kz['location']; }
    if ($weiter === '' && is_array($j) && isset($j['code']) && (int) $j['code'] !== 0) { return array('lage' => 'CODE'); }
    if ($weiter === '') {
        list($c, $t, $kz) = xc_http('GET', $ziele['konto'] . '/identity/result/check', array('sid' => 'xiaomiio',
            'context' => $kontext, '_locale' => 'en_US'), null, $dose, $ziele);
        $weiter = isset($kz['location']) ? $kz['location'] : '';
    }
    if ($weiter === '') { return array('lage' => 'CODE'); }
    $weiter = xc_absolut($ziele['konto'], $weiter);
    // check -> Auth2/end (ohne Umleitung), dort traegt die Kopfzeile extension-pragma das ssecurity.
    $ssec = ''; $sts = '';
    for ($i = 0; $i < 4 && $weiter !== ''; $i++) {
        list($c, $t, $kz) = xc_http('GET', $weiter, array(), null, $dose, $ziele);
        if ($c === -1) { return array('lage' => 'ZIEL'); }
        if (isset($kz['extension-pragma'])) {
            $p = json_decode($kz['extension-pragma'], true);
            if (is_array($p) && !empty($p['ssecurity'])) { $ssec = (string) $p['ssecurity']; }
        }
        if ($ssec !== '') {
            if (isset($kz['location']) && strpos($kz['location'], '/sts') !== false) { $sts = xc_absolut($weiter, $kz['location']); }
            elseif (preg_match('#https?://[^"\'\s<>]+/sts[^"\'\s<>]*#', $t, $m)) { $sts = html_entity_decode($m[0]); }
            break;
        }
        $weiter = isset($kz['location']) ? xc_absolut($weiter, $kz['location']) : ($c === 200 ? $weiter : '');
    }
    if ($ssec === '' || $sts === '') { return array('lage' => 'ANTWORT'); }
    list($c, $t, $kz, $neu) = xc_http('GET', $sts, array(), null, $dose, $ziele, true);
    if ($c === -1) { return array('lage' => 'ZIEL'); }
    $tok = isset($dose['serviceToken']) ? $dose['serviceToken'] : '';
    if ($tok === '') { return array('lage' => 'ANTWORT'); }
    return array('lage' => 'ok', 'sitzung' => array('userId' => (string) (isset($dose['userId']) ? $dose['userId'] : ''),
        'ssecurity' => $ssec, 'serviceToken' => $tok));
}

/** RC4 mit verworfenen ersten 1024 Byte Schluesselstrom (wie die Mi-Home-Schnittstelle). */
function xc_rc4($schluessel, $daten)
{
    $s = range(0, 255);
    $j = 0; $n = strlen($schluessel);
    for ($i = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($schluessel[$i % $n])) & 255;
        $x = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $x;
    }
    $i = 0; $j = 0; $aus = '';
    $alles = str_repeat("\0", 1024) . $daten;
    $len = strlen($alles);
    for ($k = 0; $k < $len; $k++) {
        $i = ($i + 1) & 255;
        $j = ($j + $s[$i]) & 255;
        $x = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $x;
        $b = ord($alles[$k]) ^ $s[($s[$i] + $s[$j]) & 255];
        if ($k >= 1024) { $aus .= chr($b); }
    }
    return $aus;
}

/** Die Signatur "METHODE&pfad&k=v...&signed_nonce" -> base64(SHA1). */
function xc_signatur($pfad, array $params, $signiert)
{
    $teile = array('POST', $pfad);
    foreach ($params as $k => $v) { $teile[] = $k . '=' . $v; }
    $teile[] = $signiert;
    return base64_encode(sha1(implode('&', $teile), true));
}

/** Ein signierter, verschluesselter Aufruf der Geraete-Schnittstelle. Rueckgabe JSON oder null. */
function xc_rufen(array $sitzung, $region, $pfad, $daten, array $ziele)
{
    $nonce = base64_encode(random_bytes(8) . pack('N', intdiv(time(), 60)));
    $signiert = base64_encode(hash('sha256', base64_decode($sitzung['ssecurity']) . base64_decode($nonce), true));
    $key = base64_decode($signiert);
    $p = array('data' => (string) $daten);
    $p['rc4_hash__'] = xc_signatur($pfad, $p, $signiert);
    foreach ($p as $k => $v) { $p[$k] = base64_encode(xc_rc4($key, $v)); }
    $p['signature'] = xc_signatur($pfad, $p, $signiert);
    $p['ssecurity'] = $sitzung['ssecurity'];
    $p['_nonce'] = $nonce;
    $dose = array('userId' => $sitzung['userId'], 'yetAnotherServiceToken' => $sitzung['serviceToken'],
                  'serviceToken' => $sitzung['serviceToken'], 'locale' => 'en_GB', 'timezone' => 'GMT+02:00',
                  'is_daylight' => '1', 'dst_offset' => '3600000', 'channel' => 'MI_APP_STORE');
    list($c, $t) = xc_http('POST', xc_api($region, $ziele) . $pfad, array(), $p, $dose, $ziele, false,
        "Accept-Encoding: identity\r\nx-xiaomi-protocal-flag-cli: PROTOCAL-HTTP2\r\nMIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4\r\n");
    unset($dose, $key);
    if ($c !== 200) { return null; }
    $roh = base64_decode(trim($t), true);
    if ($roh === false) { return null; }
    $j = json_decode(xc_rc4(base64_decode($signiert), $roh), true);
    return is_array($j) ? $j : null;
}

/**
 * Die Saugroboter (Modell xiaomi.vacuum.*) eines Kontos in den Regionen. Rueckgabe array(Liste, Regionen mit Antwort);
 * jeder Eintrag array(name, modell, ip, token, region) - der Token bleibt im Speicher bzw. in der Auswahlliste (0600).
 */
function xc_geraete(array $sitzung, array $regionen, array $ziele, array $filter)
{
    $aus = array(); $erreicht = array();
    foreach ($regionen as $reg) {
        $h = xc_rufen($sitzung, $reg, '/v2/homeroom/gethome',
            '{"fg": true, "fetch_share": true, "fetch_share_dev": true, "limit": 300, "app_ver": 7}', $ziele);
        if (!is_array($h) || !isset($h['result']) || !is_array($h['result'])) { continue; }
        $erreicht[] = $reg;
        $heime = array();
        foreach ((isset($h['result']['homelist']) && is_array($h['result']['homelist'])) ? $h['result']['homelist'] : array() as $e) {
            if (is_array($e) && isset($e['id'])) { $heime[] = array((string) $e['id'], (string) $sitzung['userId']); }
        }
        $geteilt = isset($h['result']['share']['share_family']) && is_array($h['result']['share']['share_family'])
            ? $h['result']['share']['share_family'] : array();
        foreach ($geteilt as $e) {
            if (is_array($e) && isset($e['home_id'], $e['home_owner'])) { $heime[] = array((string) $e['home_id'], (string) $e['home_owner']); }
        }
        foreach ($heime as $hm) {
            if (!preg_match('/^\d{1,20}\z/', $hm[0]) || !preg_match('/^\d{1,20}\z/', $hm[1])) { continue; }
            $l = xc_rufen($sitzung, $reg, '/v2/home/home_device_list',
                '{"home_owner": ' . $hm[1] . ',"home_id": ' . $hm[0] . ',  "limit": 200,  "get_split_device": true, "support_smart_home": true}', $ziele);
            $liste = (is_array($l) && isset($l['result']['device_info']) && is_array($l['result']['device_info'])) ? $l['result']['device_info'] : array();
            foreach ($liste as $g) {
                if (!is_array($g) || !isset($g['model'], $g['did']) || !is_string($g['model'])) { continue; }
                if (!xc_passt($g, $filter)) { continue; }
                $ble = strpos((string) $g['did'], 'blt') !== false;
                $tok = strtolower(isset($g['token']) ? (string) $g['token'] : '');
                if (!$ble && !preg_match('/^[0-9a-f]{32}\z/', $tok)) { continue; }
                $bind = $ble ? xc_beaconkey($sitzung, $reg, (string) $g['did'], $ziele) : '';
                if ($ble && $bind === '') { continue; }
                $mac = isset($g['mac']) && is_string($g['mac']) ? strtoupper($g['mac']) : '';
                if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}\z/', $mac)) { $mac = ''; }
                $ip = isset($g['localip']) && is_string($g['localip']) && preg_match('/^[\w\.\-]{1,253}\z/', $g['localip']) ? $g['localip'] : '';
                // 1.0.1: was die Cloud zur Erreichbarkeit meldet (isOnline): 1 online, 0 offline, -1 unbekannt (Feld fehlt)
                $on = isset($g['isOnline']) ? $g['isOnline'] : null;
                $on = ($on === true || $on === 1 || $on === '1' || $on === 'true') ? 1 : (($on === false || $on === 0 || $on === '0' || $on === 'false') ? 0 : -1);
                $aus[] = array('name' => substr(isset($g['name']) && is_string($g['name']) ? $g['name'] : '', 0, 60),
                               'modell' => substr($g['model'], 0, 60), 'ip' => $ip, 'token' => $ble ? '' : $tok,
                               'mac' => $mac, 'bindkey' => $bind, 'ble' => $ble ? 1 : 0, 'did' => substr((string) $g['did'], 0, 64),
                               'region' => $reg, 'online' => $on);
            }
        }
    }
    return array($aus, $erreicht);
}

/**
 * Der ganze Abruf fuer die Oberflaeche (EIN POST je Schritt). Rueckgabe array('lage', 'geraete' (ohne Token fuer die
 * Anzeige, mit Index), 'regionen', 'link', 'optionen'). Liegt mindestens ein Roboter vor, steht die Auswahlliste (mit
 * Token) 0600 in cloud_auswahl.json; bei CAPTCHA der Zwischenzustand in cloud_captcha.json, bei ZWEITFAKTOR in
 * cloud_zweitfaktor.json (beide ohne Kennwort, <= 10 min).
 * $code === null: Anmeldung mit Konto und Kennwort. Sonst: der Bestaetigungscode des Zweitfaktors (erst nach
 * xc_code_anfordern(); ohne gewaehlten Weg -> WEG, der Zustand bleibt).
 */
function xc_abruf($konto, $kennwort, $region, $zeile, array $filter, $code = null, $ziele = null)
{
    if (!is_array($ziele)) { $ziele = xc_ziele(); }
    // Das Konto geht als Keks und Feld hinaus: keine Steuerzeichen, kein Trenner (Kopfzeilen-Einschub).
    if ($code === null && !preg_match('/^[^\s;,\x00-\x1F\x7F]{1,100}\z/', (string) $konto)) { return array('lage' => 'KONTO'); }
    if (!in_array($region, array_merge(xc_regionen(), array('alle')), true)) { $region = 'de'; }
    if ($code !== null && !preg_match('/^[0-9A-Za-z]{4,12}\z/', (string) $code)) { return array('lage' => 'CODE'); }
    if ($code === null) {
        @unlink(xc_datei('captcha'));
        @unlink(xc_datei('zweitfaktor'));
        $a = xc_anmelden($konto, $kennwort, $ziele);
        $kennwort = '';
        return xc_ergebnis($a, $region, (int) $zeile, $ziele, $filter);
    }
    $zf = xc_lesen('zweitfaktor');
    if ($zf === null) { return array('lage' => 'ABGELAUFEN'); }
    if (!isset($zf['weg']) || !in_array((int) $zf['weg'], array(4, 8), true)) { return array('lage' => 'WEG'); }
    $zf = xc_nehmen('zweitfaktor');
    if ($zf === null) { return array('lage' => 'ABGELAUFEN'); }
    $reg = isset($zf['region']) ? (string) $zf['region'] : 'de';
    $zeile = isset($zf['zeile']) ? (int) $zf['zeile'] : (int) $zeile;
    $f = isset($zf['filter']) && is_array($zf['filter']) ? $zf['filter'] : $filter;
    $a = xc_zweitfaktor_beenden($zf, $code, $ziele);
    unset($zf);
    return xc_ergebnis($a, $reg, $zeile, $ziele, $f);
}

/**
 * Captcha loesen (Nachbau 26.10. spaet): die Zeichen, die der Hausherr im Bild liest, und sein Kennwort (erneut
 * eingetippt - es wurde nicht gespeichert) gehen mit den Keksen der Anmeldung (ick) als captCode an Xiaomi - genau wie
 * im Extractor login_step_2. EIN Versuch je Bild: der Zwischenzustand wird verbraucht. Falsche Loesung -> Xiaomi
 * schickt ein neues Captcha -> neues Bild, Lage CAPTCHA_FALSCH. Danach geht es weiter wie ohne Captcha (Zweitfaktor
 * oder Geraeteliste). Rueckgabe wie xc_abruf().
 */
function xc_captcha_loesen($loesung, $kennwort, $ziele = null)
{
    if (!is_array($ziele)) { $ziele = xc_ziele(); }
    if (!preg_match('/^[0-9A-Za-z]{3,10}\z/', (string) $loesung)) { return array('lage' => 'LOESUNG'); }
    if ((string) $kennwort === '') { return array('lage' => 'LEER'); }
    $cz = xc_nehmen('captcha');
    if ($cz === null || !isset($cz['konto'], $cz['sign'], $cz['dose']) || !is_array($cz['dose'])) { return array('lage' => 'ABGELAUFEN'); }
    $dose = $cz['dose'];
    $reg = isset($cz['region']) ? (string) $cz['region'] : 'de';
    $zeile = isset($cz['zeile']) ? (int) $cz['zeile'] : 1;
    list($lage, $j) = xc_schritt2($cz['konto'], $kennwort, $cz['sign'], $dose, $ziele, $loesung);
    $kennwort = '';
    if ($lage !== 'ok') { return array('lage' => $lage); }
    $a = xc_schritt2_weiter($j, $cz['konto'], $cz['sign'], $dose, $ziele);
    $erg = xc_ergebnis($a, $reg, $zeile, $ziele, isset($cz['filter']) && is_array($cz['filter']) ? $cz['filter'] : array());
    if ($erg['lage'] === 'CAPTCHA') { $erg['lage'] = 'CAPTCHA_FALSCH'; }
    return $erg;
}

/** Gemeinsamer Schluss: Zwischenzustand ablegen (Captcha/Zweitfaktor) oder die Geraeteliste holen. */
function xc_ergebnis(array $a, $region, $zeile, array $ziele, array $filter)
{
    if (!in_array($region, array_merge(xc_regionen(), array('alle')), true)) { $region = 'de'; }
    $regionen = $region === 'alle' ? xc_regionen() : array($region);
    if ($a['lage'] === 'CAPTCHA') {
        $z = $a['captcha'];
        $z['region'] = $region; $z['zeile'] = (int) $zeile; $z['filter'] = $filter;
        if (!xc_ablegen('captcha', $z)) { return array('lage' => 'SCHREIBEN'); }
        return array('lage' => 'CAPTCHA');
    }
    if ($a['lage'] === 'ZWEITFAKTOR') {
        $z = $a['zweitfaktor'];
        $z['region'] = $region; $z['zeile'] = (int) $zeile; $z['filter'] = $filter;
        if (!xc_ablegen('zweitfaktor', $z)) { return array('lage' => 'SCHREIBEN'); }
        return array('lage' => 'ZWEITFAKTOR', 'link' => $a['link'], 'optionen' => $z['optionen']);
    }
    if ($a['lage'] !== 'ok') { return array('lage' => $a['lage']); }
    list($liste, $erreicht) = xc_geraete($a['sitzung'], $regionen, $ziele, $filter);
    unset($a);
    if (!$erreicht) { return array('lage' => 'API'); }
    if (!$liste) { return array('lage' => 'KEINE', 'regionen' => $erreicht); }
    if (!xc_ablegen('auswahl', array('zeit' => time(), 'zeile' => (int) $zeile, 'liste' => $liste))) { return array('lage' => 'SCHREIBEN'); }
    $zeigen = array();
    foreach ($liste as $i => $g) { $zeigen[] = array('i' => $i, 'name' => $g['name'], 'modell' => $g['modell'], 'ip' => $g['ip'],
        'mac' => $g['mac'], 'ble' => $g['ble'], 'region' => $g['region'], 'online' => $g['online']); }
    return array('lage' => 'ok', 'geraete' => $zeigen, 'regionen' => $erreicht, 'zeile' => (int) $zeile);
}
