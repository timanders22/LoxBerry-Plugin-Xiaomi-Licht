#!/bin/sh
# Xiaomi Licht - preupgrade.sh (Gruppe 34, Entwurf 3.8; Bauart Govee 0.9.31). Laeuft als loxberry VOR dem Kopieren.
# - Upgrade-Marke data/plugins/<ordner>.upgrade_laeuft (sperrt Dienststart; der Dienst ruht bei frischer Marke);
# - Merker "lief vorher" AUSSERHALB data/plugins/<ordner>/ (den raeumt der Installer ab, Regeln/03);
# - Dienst (argumentweise erkannt) und Befehlsbruecke anhalten;
# - Konfiguration vor dem Kopierschritt in die Zweitschrift config/plugins/<ordner>.backup.json sichern (0600), nur
#   wenn sie Einstellungen traegt - eine leere Datei ueberschreibt nie eine heile Zweitschrift.
# Ordner und Wurzel kommen aus $3/$5 (nicht $1, nicht /tmp).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-xiaomilicht}"
BASE="${ARGV5:-$LBHOMEDIR}"
xl_wurzel_suchen() {
    xl_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    xl_i=0
    while [ -n "$xl_v" ] && [ "$xl_v" != "/" ] && [ "$xl_i" -lt 8 ]; do
        if [ -d "$xl_v/config/plugins" ] && [ -d "$xl_v/data/plugins" ] && [ -f "$xl_v/config/system/general.json" ]; then
            echo "$xl_v"
            return 0
        fi
        xl_v=$(dirname "$xl_v")
        xl_i=$((xl_i + 1))
    done
    return 1
}
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(xl_wurzel_suchen "$SELF") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - nichts gesichert, kein Dienst angehalten."
    exit 2
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 2 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Dienststart bis zum Ende der Aktualisierung gesperrt."
else
    # 05.11.2026: ohne Marke haelt die Installation das Update fuer eine Neuinstallation und legt die Einstellungen beiseite; bis hierher nur eine Warnung.
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
# ---------- Update-Schutz (05.11.2026, Bauart Govee 0.9.34) ----------
# plugininstall.pl bricht nur bei Rueckgabewert >1 vor purge_installation ab. Die Kopie entsteht als <Ziel>.neu.<pid>, traegt 0600,
# BEVOR Inhalt hineinkommt, wird mit cmp verglichen und erst dann per mv an ihren Platz gestellt: eine heile Sicherung bleibt heil.
ZS_FEHL=""
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if ( : > "$zs_neu" ) 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}
zs_schluss() {   # Fehlschlag gesammelt: Marke weg, Abbruch vor purge_installation
    [ -n "$ZS_FEHL" ] || return 0
    echo "<FAIL> Nicht gesichert:$ZS_FEHL - die Aktualisierung wird abgebrochen, damit der Purge die Originale nicht loescht."
    echo "<FAIL> Die bisherige Fassung bleibt installiert. Bitte Platz und Rechte unter config/plugins und data/plugins pruefen."
    rm -f "$MARKE" 2>/dev/null
    rm -f "$MERKER" 2>/dev/null
    exit 2
}
MERKER="$BASE/data/plugins/$PFOLDER.soll_laufen"
rm -f "$MERKER"
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    date +%s > "$MERKER" 2>/dev/null && echo "<INFO> Der Dienst soll laufen - Merker fuer postupgrade gesetzt."
fi
XL_DIENST="$BASE/bin/plugins/$PFOLDER/xl_dienst.php"
XL_UID=$(id -u loxberry 2>/dev/null || id -u)
xl_ist_dienst() {   # $1 PID
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$XL_UID" ] || return 1
    xl_roh=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$xl_roh" ] || return 1
    xl_a0=$(printf '%s\n' "$xl_roh" | sed -n '1p')
    xl_a1=$(printf '%s\n' "$xl_roh" | sed -n '2p')
    [ -z "$(printf '%s\n' "$xl_roh" | sed -n '3p')" ] || return 1
    case "${xl_a0##*/}" in php|php[0-9.]*) ;; *) return 1 ;; esac
    case "$xl_a1" in
        /*) xl_ziel=$xl_a1 ;;
        *)  xl_wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            xl_ziel="${xl_wd% (deleted)}/$xl_a1" ;;
    esac
    [ "$xl_ziel" = "$XL_DIENST" ]
}
XL_LISTE=""
for xl_d in /proc/[0-9]*; do
    xl_ist_dienst "${xl_d#/proc/}" && XL_LISTE="$XL_LISTE ${xl_d#/proc/}"
done
if [ -n "$XL_LISTE" ]; then
    kill $XL_LISTE 2>/dev/null
    xl_i=0
    while [ $xl_i -lt 10 ]; do
        xl_rest=""
        for xl_p in $XL_LISTE; do kill -0 "$xl_p" 2>/dev/null && xl_rest="$xl_rest $xl_p"; done
        [ -z "$xl_rest" ] && break
        sleep 1
        xl_i=$((xl_i + 1))
    done
    [ -n "$xl_rest" ] && kill -9 $xl_rest 2>/dev/null
    echo "<INFO> Laufender Dienst angehalten (PID$XL_LISTE)."
else
    echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
fi
rm -f "$BASE/data/plugins/$PFOLDER/dienst.pid"
CF="$BASE/config/plugins/$PFOLDER/xiaomilicht.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -s "$CF" ] && command -v php >/dev/null 2>&1; then
    if php -d allow_url_fopen=0 -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit((is_array($d) && ((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && $d["aktionstoken"] !== "")
            || (isset($d["lampen"]) && is_array($d["lampen"]) && count($d["lampen"]) > 0))) ? 0 : 1);' "$CF" >/dev/null 2>&1; then
        if zs_hinlegen "$CF" "$BK"; then
            echo "<OK> Konfiguration gesichert."
        else
            echo "<WARNING> Die Konfiguration liess sich nicht sichern ($BK)."
            ZS_FEHL="$ZS_FEHL xiaomilicht.json"
        fi
    elif [ -f "$BK" ]; then
        echo "<INFO> xiaomilicht.json traegt keine Einstellungen - die vorhandene Zweitschrift bleibt unberuehrt."
    fi
fi
XL_BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$XL_BBSH" ]; then
    XL_BBAUS=$(timeout 40 /bin/bash "$XL_BBSH" stop 2>&1)
    echo "<INFO> Befehlsbruecke: $XL_BBAUS"
fi
zs_schluss
echo "<OK> preupgrade abgeschlossen."
exit 0
