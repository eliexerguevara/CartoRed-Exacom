# CartoRed-Exacom

Mapa web de la red: descubre routers y switches por SNMP, ubica los equipos de acceso, las OLT y los
equipos sin SNMP, y muestra todo en un visor con VLANs, RSTP, fondo geográfico e historial de 7 días.

Está construido sobre [Secure Cartography 2](https://github.com/scottpeterman/securecartography2)
(SecureCartography2, de Scott Peterman, GPL-3.0). El visor parte de su `topology_viewer.html`.

## Qué hace

- **Mapas por sitio**: cada mapa tiene sus routers/switches (se recorren por SNMP), sus equipos de acceso y
  sus OLT. Se crean y administran desde la web (página **Hosts**), con importación y exportación en `.csv`.
- **Actualización automática** cada 5 a 60 minutos (por mapa) y botón **Actualizar**. Cada actualización
  queda en el **historial** (7 días), con una barra para ver cómo estaba la red en cada momento.
- **Ubicación de equipos**: ARP de los routers + tabla de MAC (Q-BRIDGE) de los switches + LLDP/CDP +
  IP neighbor de MikroTik. Vecinos nuevos se agregan solos. Puertos bloqueados, equipos ocultos y bloqueo
  de enlaces PPP/VPN desde la página Hosts.
- **Enlaces manuales** entre dos equipos (principal / respaldo), con sugerencia de puertos.
- **Tipos de equipo**: router, switch, switch de acceso, otro y **OLT**. Antenas (Ubiquiti / Mimosa) y
  servidores (SUPERMICRO / HP / VMware) se reconocen por la marca.
  - **Solo dibujar (no escanear)**: el equipo se dibuja sin enviarle SNMP ni ping (p. ej. VMware).
  - **OLT**: no entra al recorrido completo; si tiene SNMP solo se leen sus **uplinks** (la lista de
    interfaces una vez al día y el estado de los uplinks en cada actualización). Sus conexiones son
    siempre manuales.
- **VLANs**: por enlace, juntando lo que ve el puerto de los dos extremos (tabla Bridge > Hosts y PVID),
  las interfaces VLAN (`vlan100-ether9` = VLAN 100 en ether9) y una memoria de 2 horas para VLANs sin
  tráfico. Opción **API RouterOS** (apagada por defecto, por equipo) para leer las VLANs tagged/untagged
  configuradas en el bridge, con o sin tráfico. En el visor: selector de VLAN, recorrido puerto por puerto,
  camino entre dos equipos (principal y alternativo) y opción de ocultar lo que no tiene la VLAN.
- **RSTP**: root marcado en el mapa, prioridad (`RSTP 3000 hex`), bridge ID, puerto y costo hacia el root.
- **Visor**: menú **Tipos** (ocultar routers, switches, antenas, OLT…), menú **Datos** (IP, RSTP, marca),
  fondo blanco, mapa de calles/satelital de fondo, orden guardado, etiquetas de VLAN ordenadas en bloque,
  exportar PNG.
- **Proxys** (como los proxys activos de Zabbix): para redes que el servidor no alcanza. Un contenedor dentro
  de esa red (por ejemplo en un MikroTik) le pregunta al servidor cada 30 s si hay mapas para recorrer, los
  recorre con el mismo motor y le entrega el resultado. Pedidos firmados (HMAC) y datos cifrados (AES-GCM)
  con un token por proxy, aunque el servidor use HTTP. Se crean en la página **Proxys** y cada mapa elige
  quién lo recorre en **Hosts → Opciones**.
- **Acceso**: usuarios propios con contraseña (bcrypt), bloqueo tras 5 intentos, sesión de 8 horas.
  Las comunidades SNMP se guardan cifradas en el almacén de SecureCartography2 y el usuario API en un archivo
  que solo lee root (fuera del almacén, para que el recorrido no lo pruebe como login SSH): la web nunca ve
  sus valores.

## Cómo funciona

```
navegador ──> Apache + PHP (web/)           escribe pedidos en /var/lib/seccart-web/cola/*.json
                                               │
            seccart-web.path ──> seccart-web.service ──> sbin/seccart-web-run   (root)
            seccart-web-auto.timer (cada minuto) ──> sbin/seccart-web-auto (encola los mapas que tocan)
                                               │
              1. seccart2-crawl     recorrido SNMP de routers/switches (SecureCartography2)
              2. seccart2-anexar    ARP, tabla de MAC, LLDP, equipos de acceso, OLT, VLANs, RSTP,
                                    puertos bloqueados, ocultos, PPP, enlaces manuales
              3. seccart2-publicar  íconos, marca por MAC (lista OUI) y publicación
                                               │
                                     /var/www/html/mapa/data/<mapa>.json  (solo con sesión, datos.php)
```

La web (usuario `www-data`) solo escribe configuración y pedidos; todo lo que consulta la red lo hace
`seccart-web-run` como root, que valida lo que escribe la web.

## Contenido del repositorio

| Carpeta | Va en el servidor | Qué es |
|---|---|---|
| `web/` | `/var/www/html/mapa/` | Páginas PHP, visor (`viewer.html`), estilos y librerías (`vendor/`, MIT) |
| `bin/` | `/usr/local/bin/` | `seccart2-anexar`, `seccart2-publicar`, `seccart2-crawl` |
| `sbin/` | `/usr/local/sbin/` | `seccart-web-run`, `seccart-web-auto`, `seccart-oui-update` |
| `systemd/` | `/etc/systemd/system/` | Cola (`.path` + `.service`), actualización automática y lista OUI mensual |
| `apache/` | `/etc/apache2/conf-available/` | `seccart-mapa.conf` |
| `proxy/` | contenedor en la red remota | Proxy: `Dockerfile`, agente `cartored-proxy` y `seccart2-crawl` del proxy |

No se incluyen datos de la red: mapas publicados, hosts, comunidades, usuarios, historial ni el almacén.

## Instalación (Ubuntu 24.04)

1. Paquetes:
   ```bash
   sudo apt install apache2 libapache2-mod-php php8.3-cli snmp fping curl python3-venv apache2-utils
   ```
2. SecureCartography2 1.2.1 en `/opt/seccart2` y sus comandos en `/usr/local/bin`:
   ```bash
   sudo python3 -m venv /opt/seccart2
   sudo /opt/seccart2/bin/pip install "seccart2==1.2.1"
   for c in seccart2 seccart2-creds seccart2-discover seccart2-audit; do sudo ln -sf /opt/seccart2/bin/$c /usr/local/bin/$c; done
   ```
   Crear su almacén de credenciales cifrado (`seccart2-creds`) y guardar la clave del almacén en
   `/root/.seccart2/vault.pass` (permisos `600`): la leen `seccart2-crawl` y `seccart-web-run`.
3. Copiar los archivos de este repositorio:
   ```bash
   sudo install -m 755 bin/* /usr/local/bin/
   sudo chmod 700 /usr/local/bin/seccart2-crawl
   sudo install -m 700 sbin/seccart-web-run sbin/seccart-web-auto /usr/local/sbin/
   sudo install -m 755 sbin/seccart-oui-update /usr/local/sbin/
   sudo mkdir -p /var/www/html/mapa/data && sudo cp -r web/* /var/www/html/mapa/
   sudo cp systemd/* /etc/systemd/system/
   sudo cp apache/seccart-mapa.conf /etc/apache2/conf-available/ && sudo a2enconf seccart-mapa
   ```
4. Carpetas de datos:
   ```bash
   sudo install -d -o root -g www-data -m 750 /var/lib/seccart-web /var/lib/seccart-web/historial /var/lib/seccart-web/puertos
   for d in cola cuentas inventario mapas posiciones sesiones; do sudo install -d -o root -g www-data -m 2770 /var/lib/seccart-web/$d; done
   sudo install -d -m 755 /var/lib/seccart-web/estado /var/lib/seccart-web/vlanmem /var/lib/seccart-web/olt /root/mapas
   ```
5. Primer usuario de la web (después se administran en la página **Usuarios**):
   ```bash
   sudo htpasswd -cB /etc/apache2/.htpasswd-mapa admin
   ```
   La primera vez que se entra, `lib.php` lo copia a `/var/lib/seccart-web/cuentas/usuarios.json`.
6. Servicios:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now seccart-web.path seccart-web-auto.timer seccart-oui.timer
   sudo systemctl start seccart-oui.service
   sudo systemctl reload apache2
   ```
7. Entrar a `http://<servidor>/mapa`, cargar al menos una comunidad SNMP (**Comunidades SNMP**), crear un
   mapa y agregarle sus routers y switches en **Hosts**.

## Opcional: VLANs por API de RouterOS

En los MikroTik, crear un usuario del grupo `read` (el mismo en todos) con el servicio `api` (8728) o
`api-ssl` (8729). Cargarlo en **Comunidades SNMP → Usuario API RouterOS** y activar
**Leer VLANs del bridge por API** en cada router/switch en **Hosts**. Si la API no responde, ese equipo
sigue con SNMP.

## Opcional: proxys para redes remotas

```
proxy (red remota) ── POST proxy.php?accion=trabajo  (cada 30 s, firmado) ──> servidor
                   <── trabajo.bin: mapas a recorrer + comunidades (cifrado con el token)
   recorre su red (seccart2-crawl + seccart2-anexar)
                   ── POST proxy.php?accion=resultado (cifrado) ──> cola ──> seccart-web-run publica
```

1. Imagen: la arma el workflow **Imagen del proxy** (pestaña Actions de GitHub) en `.tar` para `arm64`,
   `amd64` y `arm`. También se puede construir a mano: `docker build -f proxy/Dockerfile -t cartored-proxy .`
2. En la web, **Proxys → Crear proxy**: muestra el token una sola vez y los comandos de RouterOS.
3. En el MikroTik (RouterOS 7 con el paquete `container`): modo contenedor, `veth`, variables
   `CARTORED_SERVIDOR`, `CARTORED_PROXY`, `CARTORED_TOKEN` y una carpeta montada en `/data` (los pasos están
   en la página Proxys). En Docker: `docker run -d --network host -v cartored:/data -e CARTORED_...=... cartored-proxy`.
4. En **Hosts → Opciones del mapa**, elegir el proxy en **Quién recorre este mapa**.

Si el proxy no entrega el resultado en 30 minutos, el mapa queda con error y la actualización automática lo
vuelve a pedir. La web solo guarda la clave de firma; el token queda en `/root/seccart-proxy/<proxy>/token`.

## Licencia

GPL-3.0 (ver `LICENSE`), como SecureCartography2, del que deriva el visor. Las librerías de `web/vendor/`
mantienen su licencia MIT (`web/vendor/THIRD_PARTY_LICENSES.txt`).
