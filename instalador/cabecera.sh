#!/bin/bash
# Instalador de CartoRed-Exacom (servidor completo) para Ubuntu 22.04 / 24.04 y Debian 12 / 13.
#
#   sudo bash cartored-instalar.sh
#
# Solo pide el usuario y la contraseña para entrar a la web. Todo lo demas es automatico: paquetes,
# SecureCartography2, almacen cifrado de credenciales, web, servicios y Apache.
# Sirve tambien para actualizar: vuelve a copiar el programa y no toca mapas, hosts, comunidades ni historial.
# Sin preguntas:  sudo CARTORED_USUARIO=admin CARTORED_CLAVE='...' bash cartored-instalar.sh
set -euo pipefail

VERSION_SC2=1.2.1
WEB=/var/www/html/mapa
BASE=/var/lib/seccart-web
export HOME=/root DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8

paso() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
aviso() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
falla() { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }
hay_systemd() { [ -d /run/systemd/system ]; }

pedir_credenciales() {
    USUARIO=${CARTORED_USUARIO:-}
    CLAVE=${CARTORED_CLAVE:-}
    if [ -z "$USUARIO" ]; then
        while :; do
            read -rp "Usuario para entrar a la web: " USUARIO </dev/tty
            [[ $USUARIO =~ ^[A-Za-z0-9._-]{2,32}$ ]] && break
            echo "  De 2 a 32 caracteres: letras, números, punto, - o _."
        done
    fi
    [[ $USUARIO =~ ^[A-Za-z0-9._-]{2,32}$ ]] || falla "Usuario inválido: de 2 a 32 letras, números, punto, - o _."
    if [ -z "$CLAVE" ]; then
        while :; do
            read -rsp "Contraseña (mínimo 8 caracteres): " CLAVE </dev/tty; echo
            if [ ${#CLAVE} -lt 8 ]; then echo "  Muy corta."; continue; fi
            read -rsp "Repite la contraseña: " C2 </dev/tty; echo
            [ "$CLAVE" = "$C2" ] && break
            echo "  No coinciden, de nuevo."
        done
    fi
    [ ${#CLAVE} -ge 8 ] || falla "La contraseña debe tener al menos 8 caracteres."
}

main() {
    [ "$(id -u)" = 0 ] || falla "Ejecútalo como root:  sudo bash $0"
    . /etc/os-release
    case "${ID:-}" in
        ubuntu|debian) ;;
        *) aviso "Probado en Ubuntu y Debian; este sistema es ${PRETTY_NAME:-desconocido}. Sigo igual." ;;
    esac
    echo "CartoRed-Exacom: instalación del servidor en ${PRETTY_NAME:-este equipo}"
    pedir_credenciales

    paso "Paquetes del sistema"
    apt-get update -q
    apt-get install -y -q --no-install-recommends \
        apache2 libapache2-mod-php php-cli php-mbstring snmp fping curl ca-certificates openssl ieee-data \
        python3 python3-venv python3-pip
    python3 -c 'import sys; sys.exit(sys.version_info < (3, 10))' || falla "Hace falta Python 3.10 o más nuevo (Ubuntu 22.04+ / Debian 12+)."

    paso "SecureCartography2 $VERSION_SC2 en /opt/seccart2"
    [ -x /opt/seccart2/bin/python ] || python3 -m venv /opt/seccart2
    /opt/seccart2/bin/pip install -q --upgrade pip
    # sin la interfaz grafica (PyQt6, ~600 MB): el servidor solo usa el recorrido SNMP, el almacen y los iconos
    /opt/seccart2/bin/pip install -q --no-deps "seccart2==$VERSION_SC2"
    # pip avisa que falta PyQt6: es a proposito, se oculta ese aviso
    if ! /opt/seccart2/bin/pip install -q pysnmp==7.1.30 pyasn1==0.6.5 cryptography paramiko==3.5.1 textfsm==2.1.0 \
            PyYAML==6.0.3 aiofiles==25.1.0 requests 2>/tmp/cartored-pip.err; then
        cat /tmp/cartored-pip.err; falla "No se pudieron instalar las librerías de Python."
    fi
    grep -v "PyQt6\|dependency resolver\|which is not installed" /tmp/cartored-pip.err || true
    rm -f /tmp/cartored-pip.err
    if [ -e /opt/seccart2/bin/seccart2-creds ]; then ln -sf /opt/seccart2/bin/seccart2-creds /usr/local/bin/seccart2-creds; fi
    /opt/seccart2/bin/python -c 'import sc2.scng.discovery.cli, sc2.scng.creds.vault' \
        || falla "SecureCartography2 quedó instalado pero no carga (ver el error de arriba)."

    paso "Programa CartoRed"
    TMP_INST=$(mktemp -d)
    trap 'rm -rf "${TMP_INST:-}"' EXIT
    local tmp=$TMP_INST
    extraer "$tmp"
    install -m 755 "$tmp"/bin/seccart2-anexar "$tmp"/bin/seccart2-publicar /usr/local/bin/
    install -m 700 "$tmp"/bin/seccart2-crawl /usr/local/bin/
    install -m 700 "$tmp"/sbin/seccart-web-run "$tmp"/sbin/seccart-web-auto /usr/local/sbin/
    install -m 755 "$tmp"/sbin/seccart-oui-update /usr/local/sbin/
    mkdir -p "$WEB"/data "$WEB"/icons
    cp -r "$tmp"/web/. "$WEB"/
    # iconos de los equipos: los trae SecureCartography2
    local iconos
    iconos=$(/opt/seccart2/bin/python -c 'import sc2, pathlib; print(pathlib.Path(sc2.__file__).parent / "ui/assets/icons_lib")')
    if [ -d "$iconos" ]; then find "$iconos" -maxdepth 1 -type f -exec cp {} "$WEB"/icons/ \; ; fi
    chown -R root:root "$WEB"
    find "$WEB" -type d -exec chmod 755 {} + ; find "$WEB" -type f -exec chmod 644 {} +
    # el publicador carga los iconos de SecureCartography2 (solo sus importaciones, sin publicar nada)
    /opt/seccart2/bin/python -c "exec(open('/usr/local/bin/seccart2-publicar').read().split('DESTINO =')[0])" \
        || falla "seccart2-publicar no carga (ver el error de arriba)."
    install -m 644 "$tmp"/systemd/* /etc/systemd/system/
    install -m 644 "$tmp"/apache/seccart-mapa.conf /etc/apache2/conf-available/

    paso "Carpetas de datos"
    install -d -o root -g www-data -m 750 "$BASE" "$BASE"/historial "$BASE"/puertos "$BASE"/proxy
    for d in cola cuentas inventario mapas posiciones sesiones; do install -d -o root -g www-data -m 2770 "$BASE/$d"; done
    install -d -m 755 "$BASE"/estado "$BASE"/vlanmem "$BASE"/olt /var/lib/seccart-oui
    install -d -m 700 /root/mapas /root/seccart-proxy /root/.seccart2

    paso "Almacén cifrado de credenciales"
    if [ ! -s /root/.seccart2/vault.pass ]; then
        (umask 077; openssl rand -base64 30 | tr -d '\n' > /root/.seccart2/vault.pass)
    fi
    chmod 600 /root/.seccart2/vault.pass
    /opt/seccart2/bin/python - <<'PY'
from pathlib import Path
from sc2.scng.creds.vault import CredentialVault
v = CredentialVault()
pw = Path('/root/.seccart2/vault.pass').read_text().strip()
if not v.is_initialized:
    v.initialize(pw)
    print('almacén creado')
elif not v.unlock(pw):
    raise SystemExit('El almacén ya existe y /root/.seccart2/vault.pass no lo abre')
else:
    print('almacén existente: se conserva')
PY

    paso "Usuario de la web: $USUARIO"
    CARTORED_USUARIO="$USUARIO" CARTORED_CLAVE="$CLAVE" php -r '
        $f = "/var/lib/seccart-web/cuentas/usuarios.json";
        $d = is_file($f) ? json_decode(file_get_contents($f), true) : [];
        $u = is_array($d["usuarios"] ?? null) ? $d["usuarios"] : [];
        $n = getenv("CARTORED_USUARIO");
        $nuevo = !isset($u[$n]);
        $u[$n] = ["hash" => password_hash(getenv("CARTORED_CLAVE"), PASSWORD_DEFAULT),
                  "creado" => $u[$n]["creado"] ?? date("Y-m-d H:i"), "cambio" => date("Y-m-d H:i")];
        ksort($u);
        file_put_contents($f, json_encode(["usuarios" => $u], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo $nuevo ? "usuario creado\n" : "contraseña actualizada\n";'
    chown www-data:www-data "$BASE"/cuentas/usuarios.json
    chmod 660 "$BASE"/cuentas/usuarios.json
    unset CLAVE C2

    paso "Apache"
    a2enconf -q seccart-mapa
    a2query -m mpm_prefork >/dev/null 2>&1 || { a2dismod -q mpm_event mpm_worker 2>/dev/null || true; a2enmod -q mpm_prefork; }
    if ! ls /etc/apache2/mods-enabled/php*.load >/dev/null 2>&1; then
        a2enmod -q "$(basename "$(ls /etc/apache2/mods-available/php*.load | head -1)" .load)"
    fi
    apache2ctl configtest 2>&1 | grep -v "Syntax OK" | grep -v AH00558 || true

    paso "Servicios"
    if hay_systemd; then
        systemctl daemon-reload
        systemctl enable -q --now apache2
        systemctl reload apache2
        systemctl enable -q --now seccart-web.path seccart-web-auto.timer seccart-oui.timer
        systemctl start seccart-oui.service || aviso "No se pudo bajar la lista de fabricantes por MAC (se reintenta cada mes; se usa la del sistema)."
        systemctl start seccart-web.service || aviso "El primer arranque del servicio falló: journalctl -u seccart-web"
    else
        aviso "Sin systemd: servicios no activados (instalación de prueba)."
    fi

    local ip
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    paso "Listo"
    cat <<FIN
  Entra a:  http://${ip:-<ip-del-servidor>}/mapa   con el usuario "$USUARIO".

  Primeros pasos:
    1. Comunidades SNMP: carga al menos una comunidad.
    2. Mapas > Nuevo mapa, y en Hosts agrégale sus routers y switches.
    3. Para redes que este servidor no alcanza: Proxys.

  Para actualizar el programa más adelante, vuelve a ejecutar este instalador:
  conserva mapas, hosts, comunidades, usuarios e historial.
FIN
}

# --- programa (bin, sbin, web, systemd, apache) en tar.gz + base64 ---
extraer() {
base64 -d <<'__CARGA__' | tar xz -C "$1"
