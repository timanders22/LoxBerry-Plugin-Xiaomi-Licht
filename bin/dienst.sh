#!/bin/bash
# Xiaomi Licht - Dienststeuerung: start | stop | restart | status | selbsttest | waechter
# Bauart bin/dienst.sh aus Govee 0.9.31 (Gruppe 34, Entwurf 1.2/1.4):
# - laeuft als loxberry (als root aufgerufen: su loxberry), Wurzel aus LBHOMEDIR oder gesucht (config/plugins,
#   data/plugins, config/system/general.json), nie ein fester Systempfad;
# - nur aus dem eigenen bin-Ordner der Anlage oder mit LBHOMEDIR UND LBPPLUGINDIR (Pruefstand), sonst nichts anlegen;
# - flock auf Kanal 8 gegen Parallelaufrufe; der Dienst startet mit 8<&- (die Sperre darf er nicht erben - Gedaechtnis
#   "Sperre vererbt sich an Kinder");
# - Dienst argumentweise erkannt (php + voller Pfad von xl_dienst.php, genau zwei Argumente);
# - Upgrade-Marke data/plugins/<ordner>.upgrade_laeuft (3600 s, 300 s Vorlauf) sperrt den Start.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
xl_wurzel_suchen() {
    xl_v="$SELF"
    xl_i=0
    while [ -n "$xl_v" ] && [ "$xl_v" != "/" ] && [ "$xl_i" -lt 8 ]; do
        if [ -d "$xl_v/config/plugins" ] && [ -d "$xl_v/data/plugins" ] \
           && [ -f "$xl_v/config/system/general.json" ]; then
            echo "$xl_v"
            return 0
        fi
        xl_v=$(dirname "$xl_v")
        xl_i=$((xl_i + 1))
    done
    return 1
}
XL_UMGEBUNG_HOME="${LBHOMEDIR:-}"
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] && [ -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
else
    LBHOMEDIR=$(xl_wurzel_suchen) || LBHOMEDIR=""
fi
if [ -z "$LBHOMEDIR" ] && [ "$1" != "selbsttest" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden (config/plugins, data/plugins,"
    echo "FEHLER: config/system/general.json). Es wurde nichts angelegt, nichts gestartet und nichts angehalten."
    [ "$1" = "status" ] && exit 4
    exit 1
fi
PNAME="${LBPPLUGINDIR:-}"
PNAME="${PNAME%/}"
PNAME="${PNAME##*/}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")
PBIN="$LBHOMEDIR/bin/plugins/$PNAME"
XL_AUSDRUECKLICH=0
if [ -n "${LBPPLUGINDIR:-}" ] && [ -n "$XL_UMGEBUNG_HOME" ] \
   && [ "$LBHOMEDIR" = "$(cd "$XL_UMGEBUNG_HOME" 2>/dev/null && pwd -P)" ] \
   && [ -d "$LBHOMEDIR/config/plugins/$PNAME" ]; then
    XL_AUSDRUECKLICH=1
fi
if [ "$1" != "selbsttest" ] && [ "$SELF" != "$(readlink -f "$PBIN" 2>/dev/null)" ] && [ "$XL_AUSDRUECKLICH" != "1" ]; then
    echo "FEHLER: $SELF ist nicht der bin-Ordner von '$PNAME' unter $LBHOMEDIR, und LBHOMEDIR und"
    echo "        LBPPLUGINDIR nennen die Anlage nicht beide (ausgepacktes Archiv oder Pruefordner)."
    echo "        Es wurde nichts angelegt."
    exit 1
fi
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/xl.log"
STARTLOG="$PLOG/dienst_start.log"
SKRIPT="$PBIN/xl_dienst.php"
DIENSTUID=$(id -u loxberry 2>/dev/null || id -u)

ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
    chmod 700 "$PDATA" 2>/dev/null
}
laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    case "$P" in ''|*[!0-9]*) return 1 ;; esac
    kill -0 "$P" 2>/dev/null || return 1
    ARGS=$(cat "/proc/$P/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -z "$(echo "$ARGS" | sed -n '3p')" ] || return 1
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
}
ist_dienst() {   # $1 PID
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$DIENSTUID" ] || return 1
    ROH=$(cat "/proc/$1/cmdline" 2>/dev/null | tr '\0' '\n')
    [ -n "$ROH" ] || return 1
    A0=$(printf '%s\n' "$ROH" | sed -n '1p')
    A1=$(printf '%s\n' "$ROH" | sed -n '2p')
    [ -z "$(printf '%s\n' "$ROH" | sed -n '3p')" ] || return 1
    [ -n "$A0" ] && [ -n "$A1" ] || return 1
    case "${A0##*/}" in php|php[0-9.]*) ;; *) return 1 ;; esac
    case "$A1" in
        /*) ZIEL=$A1 ;;
        *)  WD=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ZIEL="${WD% (deleted)}/$A1" ;;
    esac
    [ "$ZIEL" = "$SKRIPT" ]
}
dienste_suchen() {
    for D in /proc/[0-9]*; do
        ist_dienst "${D#/proc/}" && echo "${D#/proc/}"
    done
    return 0
}
waisen_beenden() {
    LISTE=$(dienste_suchen)
    [ -n "$LISTE" ] || return 0
    kill $LISTE 2>/dev/null
    N=0
    while [ $N -lt 10 ] && [ -n "$(dienste_suchen)" ]; do
        sleep 1
        N=$((N + 1))
    done
    REST=$(dienste_suchen)
    [ -n "$REST" ] && kill -9 $REST 2>/dev/null
    echo $LISTE
}
marke_sperrt() {
    [ -f "$MARKE" ] || return 1
    [ "${XL_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -lt -300 ] && return 1
    [ "$ALTER" -le 3600 ]
}
starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if marke_sperrt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet."
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/xiaomilicht.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/xiaomilicht.json). Erst die Oberflaeche oeffnen und speichern."
        return 1
    fi
    ordner_anlegen
    touch "$SOLL"
    : > "$STARTLOG"
    nohup php "$SKRIPT" >> "$STARTLOG" 2>&1 8<&- &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe dienst_start.log und xl.log (Reiter Logdateien)."
    rm -f "$PID"
    return 1
}
anhalten() {
    rm -f "$SOLL"
    ETWAS=0
    if laeuft; then
        P=$(cat "$PID")
        kill "$P" 2>/dev/null
        for i in 1 2 3 4 5 6 7 8 9 10; do
            laeuft || break
            sleep 1
        done
        if laeuft; then
            kill -9 "$P" 2>/dev/null
            sleep 1
        fi
        ETWAS=1
    fi
    rm -f "$PID"
    WAISEN=$(waisen_beenden)
    if [ -n "$WAISEN" ]; then
        echo "angehalten; zusaetzlich ein Dienst ohne PID-Datei beendet (PID $WAISEN)"
        return 0
    fi
    if [ "$ETWAS" = "1" ]; then
        echo "angehalten"
        return 0
    fi
    echo "laeuft nicht"
    return 0
}
sperren() {
    command -v flock >/dev/null 2>&1 || return 0
    exec 8<"$(readlink -f "$0")" || return 0
    if ! flock -w 30 8; then
        echo "FEHLER: Ein anderer Aufruf von dienst.sh laeuft seit 30 s noch - abgebrochen."
        exit 1
    fi
}
case "$1" in
    start)   sperren; starten ;;
    stop)    sperren; anhalten ;;
    restart) sperren; anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    selbsttest)
        php "$SELF/xl_dienst.php" --selbsttest
        exit $?
        ;;
    waechter)
        if [ -f "$SOLL" ] && ! laeuft; then
            sperren
            if [ -f "$SOLL" ] && ! laeuft; then
                ordner_anlegen
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
                starten >> "$STARTLOG" 2>&1
            fi
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|waechter}"
        exit 2
        ;;
esac
