<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke 1.0.1, Linie Xiaomi Licht (Gruppe 34, Entwurf 3.1,
 * E-E11/E-E12; Muster bin/befehle_zuordnung.php aus Govee 0.9.31). Die Bruecke selbst (bin/befehlsbruecke.php und .sh)
 * ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln: die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. 'tun' ruft xl_befehl_annehmen()
 * - dieselbe Funktion wie der Endpunkt webfrontend/html/index.php (?cmd=) - mit Quelle 'mqtt' und gibt array(bool ok,
 * string grund) zurueck (xl_befehl_ergebnis()).
 * Themen xiaomilicht/befehl/lampe<n>/<befehl> und xiaomilicht/befehl/alle/<befehl> (Teil lampe[1-9][0-9]?|alle). Alles
 * ausser abruf steht hinter dem zweiten Haken "Lampen aus Loxone schalten" (steuerung_ein, ab Werk aus). Sperren der
 * Bruecke: Sollwerte 0 s (die Gleichwert-Bremse des Plugins antwortet "unveraendert"), umschalten 1 s, szene 2 s, abruf
 * 10 s. Quittung "angenommen" erst nach dem MIoT-Code der Lampe ('ausgefuehrt'); die Bruecke wartet hoechstens die
 * Wartezeit des Plugins (6 s), der Dienst schliesst einen Befehl in hoechstens etwa 5,4 s ab.
 */
if (!function_exists('xl_config')) {
    $xl_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $xl_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/xl_lib.php';
    } else {
        $xl_kand[] = dirname(__DIR__) . '/webfrontend/html/xl_lib.php';
    }
    foreach ($xl_kand as $xl_k) {
        if (is_file($xl_k)) { require_once $xl_k; break; }
    }
}
$xl_lampe = function (array $t) {
    return $t['lampe'] === 'alle' ? 'alle' : (int) substr($t['lampe'], 5);
};
$xl_soll = 'Sollwert: die Gleichwert-Bremse des Plugins (60 s, gleicher Wert an dieselbe Lampe, solange die Lampe ihn meldet) '
         . "antwortet 'unveraendert'; eine zweite Sperre in der Bruecke wiese Dimmerwerte zusaetzlich als 'wiederholt' ab";
$xl_eintrag = function ($name, $titel, $grund) use ($xl_lampe) {
    $b = xl_befehle();
    $e = $b[$name];
    $z = array('art' => $e['art'], 'eingreifend' => $e['eingreifend'], 'sperre_s' => $e['sperre_s'], 'begruendung' => $grund,
               'titel' => $titel, 'kachel' => $e['kachel'], 'quittung' => 'ausgefuehrt',
               'tun' => function ($w, $t) use ($name, $xl_lampe) {
                   return xl_befehl_ergebnis(xl_befehl_annehmen($xl_lampe($t), $name, (string) $w, xl_config(false), 'mqtt'));
               });
    if ($e['art'] === 'ganz') {
        $z['min'] = $e['min'];
        $z['max'] = $e['max'];
    }
    if ($e['art'] === 'auswahl') {
        $z['werte'] = $e['werte'];
    }
    return $z;
};
return array(
    'fassung' => 1,
    'linie'   => 'xiaomilicht',
    'plugin'  => 'Xiaomi Licht',
    'geladen' => function_exists('xl_config') && function_exists('xl_befehl_annehmen') && function_exists('xl_befehl_ergebnis'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        return xl_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        xl_log('Befehlsbruecke: ' . ($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('lampe' => 'lampe[1-9][0-9]?|alle'),
    'befehle' => function_exists('xl_befehle') ? array(
        '{lampe}/schalten'   => $xl_eintrag('schalten', 'Xiaomi Licht Ein/Aus (MQTT)', $xl_soll),
        '{lampe}/umschalten' => $xl_eintrag('umschalten', 'Xiaomi Licht Umschalten (MQTT)',
            'Ereignis (Taste); schuetzt nur vor einem doppelt ausgeloesten Tastendruck'),
        '{lampe}/hell'       => $xl_eintrag('hell', 'Xiaomi Licht Helligkeit (MQTT)', $xl_soll),
        '{lampe}/ct'         => $xl_eintrag('ct', 'Xiaomi Licht Farbtemperatur (MQTT)', $xl_soll),
        '{lampe}/nacht'      => $xl_eintrag('nacht', 'Xiaomi Licht Nachtlicht (MQTT)', $xl_soll),
        '{lampe}/szene'      => $xl_eintrag('szene', 'Xiaomi Licht Szene (MQTT)',
            'Ereignis; schuetzt vor Doppelausloesung, dieselbe Szene darf kurz danach erneut gewaehlt werden'),
        '{lampe}/abruf'      => $xl_eintrag('abruf', 'Xiaomi Licht Zustand jetzt abrufen (MQTT)',
            'Eine miIO-Abfrage dauert unter 1 s; oefter abzufragen bringt nichts'),
    ) : array(),
);
