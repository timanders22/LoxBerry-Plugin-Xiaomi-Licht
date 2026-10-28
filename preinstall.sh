#!/bin/sh
# Xiaomi Licht - preinstall.sh (Gruppe 34, Entwurf 3.8; Bauart Govee 0.9.31). Laeuft als loxberry, VOR dem Kopieren.
# Aufruf durch LoxBerry: $1 Temp-Ordner, $2 Plugin-Name, $3 Plugin-Ordner, $4 Fassung, $5 LoxBerry-Wurzel.
# Nur Pruefungen (PHP >= 7.4, openssl als Hinweis) - und bei einer NEUinstallation (keine Upgrade-Marke) werden
# Zweitschriften einer frueheren Installation beiseitegelegt (.alt), damit kein fremder Stand mit Lampen-Token
# eingespielt wird. Nichts geloescht; die Deinstallation raeumt die .alt-Dateien ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-xiaomilicht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if command -v php >/dev/null 2>&1; then
    if php -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' >/dev/null 2>&1; then
        echo "<OK> PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null) - ausreichend (mindestens 7.4)."
    else
        echo "<WARNING> PHP ist aelter als 7.4 - das Plugin ist dafuer nicht gebaut."
    fi
    if php -r 'exit(extension_loaded("openssl") ? 0 : 1);' >/dev/null 2>&1; then
        echo "<OK> Die PHP-Erweiterung openssl ist geladen (miIO braucht AES-128-CBC)."
    else
        echo "<WARNING> Die PHP-Erweiterung openssl fehlt - ohne sie spricht das Plugin nur Yeelight-LAN, kein miIO."
    fi
else
    echo "<WARNING> Kein PHP gefunden - ohne PHP laufen weder Oberflaeche noch Dienst."
fi
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0
ZIEL="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -e "$ZIEL" ]; then
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        chmod 600 "$ZIEL.alt" 2>/dev/null
        echo "<WARNING> Neuinstallation: die Zweitschrift einer frueheren Installation wird NICHT eingespielt;"
        echo "<WARNING> sie liegt als $(basename "$ZIEL").alt daneben (die Deinstallation raeumt sie ab)."
    else
        echo "<WARNING> $ZIEL liess sich nicht beiseitelegen - bitte von Hand entfernen."
    fi
fi
exit 0
