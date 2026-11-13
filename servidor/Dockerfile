# Servidor completo de CartoRed-Exacom en un contenedor (MikroTik RouterOS 7 con el paquete container, o Docker).
# Reemplaza a instalador/cabecera.sh + systemd: Apache + PHP, el worker de la cola, la actualizacion automatica
# de los mapas y la lista de fabricantes por MAC corren dentro de un solo contenedor (ver servidor/entrypoint).
#
# Se construye desde la raiz del repositorio:  docker build -f servidor/Dockerfile -t cartored-server .
#
# Misma base que la imagen del proxy: las librerias compiladas (cryptography, bcrypt, nacl, yaml) salen de los
# paquetes de Debian, que existen para amd64, arm64 y ARM de 32 bits. No hace falta compilador.
FROM debian:bookworm-slim

ARG VERSION_SC2=1.2.1

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php php-cli php-mbstring \
        python3 python3-venv python3-cryptography python3-cffi python3-bcrypt python3-nacl python3-yaml python3-requests \
        snmp fping curl ca-certificates openssl ieee-data tzdata tini \
 && rm -rf /var/lib/apt/lists/*

# SecureCartography2 sin la interfaz grafica (PyQt6, ~600 MB): solo el recorrido SNMP, el almacen y los iconos
RUN python3 -m venv --system-site-packages /opt/seccart2 \
 && /opt/seccart2/bin/pip install --no-cache-dir --no-deps "seccart2==${VERSION_SC2}" \
 && /opt/seccart2/bin/pip install --no-cache-dir --only-binary=:all: \
        pysnmp==7.1.30 pyasn1==0.6.5 paramiko==3.5.1 textfsm==2.1.0 aiofiles==25.1.0 \
 && find /opt/seccart2 -name '__pycache__' -prune -exec rm -rf {} + \
 && ln -sf /opt/seccart2/bin/seccart2-creds /usr/local/bin/seccart2-creds

# Programa: igual que el instalador (bin -> /usr/local/bin, sbin -> /usr/local/sbin, web -> /var/www/html/mapa)
COPY bin/seccart2-anexar bin/seccart2-publicar /usr/local/bin/
COPY bin/seccart2-crawl /usr/local/bin/
COPY sbin/seccart-web-run sbin/seccart-web-auto /usr/local/sbin/
COPY sbin/seccart-oui-update /usr/local/sbin/
COPY web/ /var/www/html/mapa/
COPY apache/seccart-mapa.conf /etc/apache2/conf-available/seccart-mapa.conf
COPY servidor/seccart-contenedor.conf /etc/apache2/conf-available/seccart-contenedor.conf
COPY servidor/entrypoint /usr/local/bin/cartored-entrypoint

RUN chmod 755 /usr/local/bin/seccart2-anexar /usr/local/bin/seccart2-publicar /usr/local/sbin/seccart-oui-update /usr/local/bin/cartored-entrypoint \
 && chmod 700 /usr/local/bin/seccart2-crawl /usr/local/sbin/seccart-web-run /usr/local/sbin/seccart-web-auto \
 # iconos de los equipos: los trae SecureCartography2
 && mkdir -p /var/www/html/mapa/icons /var/www/html/mapa/data \
 && iconos="$(/opt/seccart2/bin/python -c 'import sc2, pathlib; print(pathlib.Path(sc2.__file__).parent / "ui/assets/icons_lib")')" \
 && find "$iconos" -maxdepth 1 -type f -exec cp {} /var/www/html/mapa/icons/ \; \
 && chown -R root:root /var/www/html/mapa \
 && find /var/www/html/mapa -type d -exec chmod 755 {} + \
 && find /var/www/html/mapa -type f -exec chmod 644 {} + \
 # Apache: un solo sitio (el de este contenedor), prefork + PHP, configuracion del mapa
 && a2dissite -q 000-default \
 && { a2dismod -q mpm_event mpm_worker 2>/dev/null || true; } \
 && a2enmod -q mpm_prefork \
 && a2enconf -q seccart-mapa seccart-contenedor \
 # comprobaciones en la construccion: si algo falta, la imagen no se arma
 && a2query -m mpm_prefork >/dev/null \
 && ls /etc/apache2/mods-enabled/php*.load >/dev/null \
 && apache2ctl configtest 2>&1 | grep -v AH00558 \
 && /opt/seccart2/bin/python -c 'import sc2.scng.discovery.cli, sc2.scng.creds.vault' \
 && /opt/seccart2/bin/python -c "exec(open('/usr/local/bin/seccart2-publicar').read().split('DESTINO =')[0])" \
 && command -v snmpbulkwalk fping php >/dev/null

ENV PATH=/opt/seccart2/bin:$PATH \
    PYTHONUNBUFFERED=1 \
    HOME=/root \
    LC_ALL=C.UTF-8 \
    CARTORED_DATOS=/data
# Todo lo que no se puede perder va en /data (en RouterOS: un mount hacia el disco del CHR)
VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=60s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/mapa/login.php || exit 1

# tini: reenvia las senales (stop de RouterOS) y limpia los procesos hijos del worker
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/cartored-entrypoint"]
