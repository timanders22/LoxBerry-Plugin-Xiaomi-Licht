#!/bin/sh
# Xiaomi Licht - postupgrade.sh (Gruppe 34, Entwurf 3.8; Bauart Govee 0.9.31). Laeuft als loxberry NACH postinstall.
# - Konfiguration aus der Zweitschrift zurueck, wenn die mitgelieferte Datei keine Einstellungen traegt;
# - Upgrade-Marke entfernen (auch bei Abbruch: trap);
# - Dienst starten, wenn er vorher lief (Merker aus preupgrade, hoechstens 1 h alt); Befehlsbruecke starten (sie
#   startet nur, wenn ihr Haken an ist). Die Wurzel wird GELESEN ($5/LBHOMEDIR/Suche), nicht geraten.
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - kein Dienst gestartet, nichts zurueckgespielt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
xl_marke_weg() { rm -f "$MARKE"; }
trap xl_marke_weg EXIT
CF="$BASE/config/plugins/$PFOLDER/xiaomilicht.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
xl_traegt() {   # $1 Datei - 0 traegt Einstellungen, 1 nicht, 2 nicht pruefbar
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -d allow_url_fopen=0 -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit((is_array($d) && ((isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && $d["aktionstoken"] !== "")
            || (isset($d["lampen"]) && is_array($d["lampen"]) && count($d["lampen"]) > 0))) ? 0 : 1);' "$1" >/dev/null 2>&1
}
if [ -f "$BK" ]; then
    xl_traegt "$CF"; XL_C=$?
    xl_traegt "$BK"; XL_B=$?
    if [ "$XL_C" = 1 ] && [ "$XL_B" = 0 ]; then
        mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
        if cp "$BK" "$CF.neu" 2>/dev/null && chmod 600 "$CF.neu" 2>/dev/null && mv -f "$CF.neu" "$CF" 2>/dev/null; then
            echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
        else
            rm -f "$CF.neu"
            echo "<WARNING> Die Konfiguration liess sich nicht zurueckspielen - die Zweitschrift liegt unter $BK."
        fi
    elif [ "$XL_C" = 2 ] || [ "$XL_B" = 2 ]; then
        echo "<WARNING> php fehlt - Konfiguration nicht geprueft; die Zweitschrift liegt unter $BK."
    fi
fi
[ -f "$CF" ] && chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null
MERKER="$BASE/data/plugins/$PFOLDER.soll_laufen"
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
SOLL=0
if [ -f "$MERKER" ]; then
    T=$(cat "$MERKER" 2>/dev/null)
    JETZT=$(date +%s 2>/dev/null)
    case "$T" in
        ''|*[!0-9]*) ;;
        *) case "$JETZT" in
               ''|*[!0-9]*) SOLL=1 ;;
               *) ALTER=$((JETZT - T))
                  [ "$ALTER" -ge -300 ] && [ "$ALTER" -le 3600 ] && SOLL=1 ;;
           esac ;;
    esac
    rm -f "$MERKER"
fi
xl_marke_weg
if [ "$SOLL" -eq 1 ] && [ -f "$DIENST" ]; then
    if /bin/bash "$DIENST" start; then
        echo "<OK> Dienst nach dem Upgrade wieder gestartet."
    else
        echo "<WARNING> Der Dienst lief vor dem Upgrade, liess sich aber nicht wieder starten - Reiter Einstellungen, 'Dienst starten'."
    fi
else
    echo "<INFO> Der Dienst lief vor dem Upgrade nicht und bleibt angehalten."
fi
XL_BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$XL_BBSH" ]; then
    XL_BBAUS=$(/bin/bash "$XL_BBSH" start 2>&1)
    echo "<INFO> Befehlsbruecke: $XL_BBAUS"
fi
echo "<OK> postupgrade abgeschlossen."
exit 0
