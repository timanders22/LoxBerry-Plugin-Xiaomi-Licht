#!/bin/sh
# Xiaomi Licht - postinstall.sh (Gruppe 34, Entwurf 3.8; Bauart Govee 0.9.31). Laeuft als loxberry, auch beim
# Upgrade (Reihenfolge preupgrade -> Kopie -> postinstall -> postupgrade, Regeln/06), idempotent.
# Rechte: data/plugins/<ordner> 0700, Konfiguration und Zweitschrift 0600 (falls vorhanden), bin/* ausfuehrbar.
# Legt NICHTS an (die Oberflaeche legt beim ersten Speichern an), startet keinen Dienst (das tut postupgrade.sh, wenn
# er vorher lief). Selbsttest des Dienstes als Hinweis.
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
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurden keine Rechte gesetzt."
    exit 1
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts getan."; exit 1 ;;
esac
PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
[ -d "$PDATA" ] && chmod 700 "$PDATA" 2>/dev/null
[ -d "$PCONFIG" ] && chmod 700 "$PCONFIG" 2>/dev/null
for f in "$PCONFIG/xiaomilicht.json" "$BASE/config/plugins/$PFOLDER.backup.json"; do
    [ -f "$f" ] && chmod 600 "$f" 2>/dev/null
done
if [ -d "$PBIN" ]; then
    for f in "$PBIN/dienst.sh" "$PBIN/befehlsbruecke.sh" "$PBIN/healthcheck"; do
        [ -f "$f" ] && chmod 755 "$f" 2>/dev/null
    done
fi
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden - ohne PHP laufen weder Oberflaeche noch Dienst."
    exit 1
fi
if [ -f "$PBIN/xl_dienst.php" ]; then
    if AUS=$(php "$PBIN/xl_dienst.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest des Dienstes: $(echo "$AUS" | tail -1)"
    else
        echo "<INFO> Der Selbsttest des Dienstes ist nicht sauber durchgelaufen:"
        echo "$AUS" | head -10 | sed 's/^/<INFO> /'
    fi
fi
echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche: Reiter Einstellungen - Lampen eintragen (Adresse, Token von"
echo "<INFO> Hand oder ueber 'Token aus der Xiaomi-Cloud holen'), speichern, Dienst starten; Reiter Test pruefen."
exit 0
