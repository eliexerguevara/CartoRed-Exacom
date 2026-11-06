# Instalación del proxy de CartoRed en RouterOS (MikroTik)

El proxy de CartoRed funciona como un **proxy activo de Zabbix**: vive dentro de la red remota, en un
contenedor del MikroTik, la recorre por SNMP y le entrega el resultado al servidor CartoRed.

```
        Red remota (sin IP pública, detrás de NAT)                       Internet / VPN              Servidor CartoRed
 ┌──────────────────────────────────────────────────────┐                                     ┌──────────────────────┐
 │ switches / routers ◄── SNMP ── [ contenedor proxy ]  │ ── HTTP(S) saliente, cada 30 s ──►  │ /mapa/proxy.php      │
 │                                 dentro del MikroTik  │ ◄── mapas a recorrer (cifrado) ──   │ (IP pública o VPN)   │
 └──────────────────────────────────────────────────────┘ ── resultado (cifrado) ─────────►   └──────────────────────┘
```

**Siempre es el proxy el que se conecta al servidor.** El servidor nunca se conecta al proxy. Por eso:

- **El proxy no necesita IP pública** ni puertos abiertos: le basta con salir a Internet o a la VPN, como
  cualquier equipo detrás de NAT.
- **El servidor sí debe ser alcanzable desde el proxy:** por IP pública (o un dominio), o por una VPN.

Cada pedido va **firmado** (HMAC-SHA256) y todo lo que viaja (comunidades SNMP, resultados) va **cifrado**
(AES-256-GCM) con un token propio de cada proxy. Por eso funciona también por HTTP sobre Internet.

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Preparar el servidor CartoRed (IP pública)](#2-preparar-el-servidor-cartored-ip-pública)
3. [Crear el proxy en la web](#3-crear-el-proxy-en-la-web)
4. [Conseguir la imagen del contenedor](#4-conseguir-la-imagen-del-contenedor)
5. [Preparar el MikroTik](#5-preparar-el-mikrotik)
6. [Red del contenedor](#6-red-del-contenedor)
7. [Probar que el MikroTik llega al servidor](#7-probar-que-el-mikrotik-llega-al-servidor)
8. [Crear y arrancar el contenedor](#8-crear-y-arrancar-el-contenedor)
9. [Verificar](#9-verificar)
10. [Asignarle mapas al proxy](#10-asignarle-mapas-al-proxy)
11. [Solución de problemas](#11-solución-de-problemas)
12. [Actualizar, cambiar el token o desinstalar](#12-actualizar-cambiar-el-token-o-desinstalar)
13. [RouterOS anterior a 7.20](#13-routeros-anterior-a-720)

---

## 1. Requisitos

| | Requisito |
|---|---|
| **MikroTik** | RouterOS **7** con el paquete **container**. |
| **Arquitectura** | **ARM64** (RB5009, CCR2004, CCR2116, hAP ax², hAP ax³, L009…), **x86** (CHR o x86) o ARM de 32 bits (hAP ac², RB4011…). Los MIPS (hEX, hAP lite…) **no** tienen contenedores. |
| **Almacenamiento** | ~700 MB libres: la imagen ocupa ~225 MB y al extraerse ~350 MB. Si la memoria interna es chica, usa un USB, un NVMe o una microSD (en esta guía, `usb1`). En CHR alcanza con el disco del sistema. |
| **RAM** | 256 MB libres (más si el mapa es grande). |
| **Red** | Salida hacia el servidor CartoRed por el puerto que uses (80 o 443). |
| **Hora** | La hora del MikroTik debe diferir menos de 5 minutos de la del servidor (NTP, paso 5.3). |
| **CHR** | La licencia *free* limita cada interfaz a 1 Mbit/s: funciona, pero el recorrido es lento. Con p1 o superior va normal. |

Para saber la arquitectura:

```
/system/resource/print
```

Mira `architecture-name`: `arm64`, `x86_64` o `arm`.

---

## 2. Preparar el servidor CartoRed (IP pública)

El proxy necesita una dirección para llegar al servidor. Esa dirección va en `CARTORED_SERVIDOR`, por ejemplo:

| Caso | `CARTORED_SERVIDOR` |
|---|---|
| El servidor tiene una IP pública propia | `http://203.0.113.10/mapa` |
| El servidor está detrás del router de la empresa (IP privada, p. ej. `10.253.252.47`) | `http://<IP-pública-de-la-empresa>/mapa`, con *port forward* (2.1) |
| Con un dominio | `http://cartored.miempresa.com/mapa` |
| El proxy y el servidor están unidos por una VPN | `http://10.253.252.47/mapa` (la IP privada del servidor); no hace falta nada de este paso |

> Si usas la VPN, salta al paso 3.

### 2.1 Port forward hacia el servidor (si el servidor tiene IP privada)

En el router de borde del servidor, publica el puerto 80 del servidor. Ejemplo en un MikroTik de borde,
con `ether1` hacia Internet y el servidor en `10.253.252.47`:

```
/ip/firewall/nat/add chain=dstnat in-interface=ether1 protocol=tcp dst-port=80 \
    action=dst-nat to-addresses=10.253.252.47 to-ports=80 comment="CartoRed proxys"
```

**Puerto distinto (recomendado):** para no exponer el 80, puedes publicar otro puerto, por ejemplo el 8090.
En ese caso, usa `dst-port=8090 ... to-ports=80` y `CARTORED_SERVIDOR=http://<IP-pública>:8090/mapa`.

**Proxys con IP fija:** si los proxys salen siempre por IPs fijas, agrega `src-address-list=proxys-cartored`
a la regla y carga esas IPs en la lista:

```
/ip/firewall/address-list/add list=proxys-cartored address=198.51.100.20
```

### 2.2 Exponer solo `proxy.php` a Internet (recomendado)

Con el *port forward*, toda la web del mapa (también la página de ingreso) queda visible desde Internet.
Los proxys solo usan `proxy.php`. Con esta regla de Apache:

- `proxy.php` queda abierto para todos (cada pedido igual va firmado).
- El resto de la web queda solo para las redes privadas.

En el servidor, crea `/etc/apache2/conf-available/seccart-publico.conf`.

> **El nombre importa.** Apache carga las configuraciones en orden alfabético, y esta debe cargarse **después**
> de `seccart-mapa.conf`. Con otro nombre (por ejemplo `seccart-mapa-publico.conf`) se carga antes y no
> bloquea nada.

```apache
# CartoRed: desde Internet solo se puede usar proxy.php (los proxys). El resto, solo redes privadas.
<Directory /var/www/html/mapa>
    Require ip 127.0.0.1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10
</Directory>
<Files "proxy.php">
    Require all granted
</Files>
```

Actívala:

```bash
sudo a2enconf seccart-publico
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Para comprobarlo, desde una IP que no esté en la lista: `login.php` debe responder `403` y `proxy.php`, `400`
(llega, pero falta la firma).

Si administras la web desde otra IP pública, agrégala a la línea `Require ip`.

> **Nota sobre el NAT.** Si el router de borde hace *masquerade* también hacia el servidor (*hairpin NAT*),
> los proxys llegan con una IP privada. La regla sigue dejando pasar a `proxy.php`, pero entonces la web
> queda abierta desde Internet. Revísalo con `curl -I http://<IP-pública>/mapa/login.php` desde fuera: debe
> responder `403`.

### 2.3 ¿HTTP o HTTPS?

- **HTTP funciona y es seguro para los proxys:** el token nunca viaja, y las comunidades y los resultados
  van cifrados y firmados.
- **HTTPS** solo sirve con un **certificado válido** (por ejemplo Let's Encrypt con un dominio): el proxy
  rechaza los certificados autofirmados. Si lo usas, `CARTORED_SERVIDOR=https://cartored.miempresa.com/mapa`.

### 2.4 Probar desde fuera

Desde una conexión externa (por ejemplo el celular con datos):

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST "http://<IP-pública>/mapa/proxy.php?accion=trabajo"
```

`400` significa que llega (falta la firma, es lo esperado). Si no responde, revisa el *port forward* y el
firewall.

---

## 3. Crear el proxy en la web

1. Entra a la web de CartoRed → **Proxys** → **Crear proxy**.
2. **Nombre**: letras, números, `-` y `_` (por ejemplo `proxy-sede-norte`). **Descripción**: opcional.
3. Pulsa **Crear**. La página muestra el **token una sola vez**: cópialo y guárdalo hasta terminar la
   instalación.

> Si pierdes el token, pulsa **Nuevo token** en la lista de proxys. El anterior deja de servir.

---

## 4. Conseguir la imagen del contenedor

La imagen es un archivo `.tar`: `cartored-proxy-arm64.tar`, `cartored-proxy-amd64.tar` (CHR / x86) o
`cartored-proxy-arm.tar`.

### Opción A: GitHub Actions (todas las arquitecturas)

El workflow **Imagen del proxy** del repositorio arma las tres imágenes:

1. En GitHub, abre el repositorio → pestaña **Actions** → **Imagen del proxy**.
2. Elige la última ejecución en verde. Si no hay ninguna, pulsa **Run workflow**.
3. Al final de la página, en **Artifacts**, descarga `cartored-proxy-<arquitectura>`.
4. Descomprime el `.zip` para obtener el `.tar`.

### Opción B: construirla en un Linux con Docker

Desde la raíz del repositorio:

```bash
# x86 / CHR (en un servidor x86 no hace falta nada más)
docker buildx build --platform linux/amd64 -f proxy/Dockerfile -t cartored-proxy:latest \
  --output type=docker,compression=uncompressed,force-compression=true,dest=cartored-proxy-amd64.tar .

# ARM64 desde un servidor x86: primero registrar la emulación (una vez por arranque)
docker run --privileged --rm tonistiigi/binfmt --install arm64
docker buildx build --platform linux/arm64 -f proxy/Dockerfile -t cartored-proxy:latest \
  --output type=docker,compression=uncompressed,force-compression=true,dest=cartored-proxy-arm64.tar .
```

Las capas sin comprimir (`compression=uncompressed`) son las que acepta RouterOS sin problemas.

---

## 5. Preparar el MikroTik

### 5.1 Paquete container

```
/system/package/print
```

Si `container` no aparece:

1. Descarga los **Extra packages** de tu arquitectura y de la **misma versión** de RouterOS desde
   [mikrotik.com/download](https://mikrotik.com/download).
2. Sube `container-7.xx-<arquitectura>.npk` a **Files** (Winbox: arrastrar el archivo).
3. Reinicia:

```
/system/reboot
```

### 5.2 Modo contenedor

```
/system/device-mode/update container=yes
```

Antes de 5 minutos, **confírmalo físicamente**:

- **Equipo físico:** pulsa el botón *reset* o *mode*, o desenchúfalo y vuelve a enchufarlo.
- **CHR:** apaga la máquina virtual desde el hipervisor y vuelve a encenderla. Un `/system/reboot` no sirve.

Comprueba que diga `container: yes`:

```
/system/device-mode/print
```

### 5.3 Hora (NTP)

```
/system/ntp/client/set enabled=yes servers=pool.ntp.org
/system/ntp/client/print
```

Si la red no sale a Internet para NTP, usa como `servers=` el servidor NTP de tu red o la IP del servidor
CartoRed (si tiene NTP).

### 5.4 Disco para el contenedor (si usas USB / NVMe)

```
/disk/print
```

Anota el nombre del disco (`usb1`, `nvme1`, `sd1`…) y reemplaza `usb1` en los pasos siguientes. En CHR o si
usas la memoria interna, quita el prefijo `usb1/` de las rutas.

---

## 6. Red del contenedor

El contenedor tiene su propia interfaz virtual (*veth*) en una red interna (`172.17.0.0/24`). El MikroTik le
hace NAT hacia la red local y hacia Internet.

```
/interface/veth/add name=veth-cartored address=172.17.0.2/24 gateway=172.17.0.1
/interface/bridge/add name=contenedores
/interface/bridge/port/add bridge=contenedores interface=veth-cartored
/ip/address/add address=172.17.0.1/24 interface=contenedores
/ip/firewall/nat/add chain=srcnat action=masquerade src-address=172.17.0.0/24 comment="CartoRed proxy"
```

**Firewall.** Si tienes reglas que descartan tráfico en `forward`, permite el del contenedor **antes** de esas
reglas:

```
/ip/firewall/filter/add chain=forward src-address=172.17.0.0/24 action=accept comment="CartoRed proxy" place-before=0
```

**SNMP en los equipos.** Las consultas SNMP salen con la IP que el MikroTik tiene hacia cada equipo, por el
*masquerade*. Si tus switches y routers solo aceptan SNMP desde ciertas IPs, agrega esa IP del MikroTik.

> La red `172.17.0.0/24` es solo un ejemplo: si ya la usas en tu red, cámbiala por otra libre en las cinco
> líneas.

---

## 7. Probar que el MikroTik llega al servidor

Antes de instalar, comprueba la conexión desde el propio MikroTik (cambia la URL por tu `CARTORED_SERVIDOR`):

```
/tool/fetch url="http://<IP-pública-o-dominio>/mapa/proxy.php?accion=trabajo" http-method=post output=none
```

| Resultado | Significado |
|---|---|
| `failure: ... 400 Bad Request` | **Correcto:** llega a CartoRed (rechaza el pedido porque falta la firma). |
| `failure: ... 403 Forbidden` | Llega, pero Apache lo bloquea: revisa la regla del paso 2.2. |
| `failure: connection timeout` / `could not connect` | No llega: revisa el *port forward*, el firewall o la VPN. |
| `404 Not Found` | La URL no es la del mapa: falta `/mapa` o el puerto. |

---

## 8. Crear y arrancar el contenedor

### 8.1 Subir la imagen

Sube el `.tar` a **Files** con Winbox (arrastrar), dentro de `usb1/`. También puedes usar `scp` desde un PC,
con el servicio SSH del MikroTik activo:

```bash
scp cartored-proxy-arm64.tar admin@<IP-del-MikroTik>:/usb1/
```

### 8.2 Variables, carpeta de datos y contenedor

Reemplaza los tres valores marcados:

- `CARTORED_SERVIDOR`: la URL del paso 2.
- `CARTORED_PROXY`: el nombre del paso 3.
- `CARTORED_TOKEN`: el token del paso 3.

```
/container/envs/add list=cartored key=CARTORED_SERVIDOR value="http://<IP-pública-o-dominio>/mapa"
/container/envs/add list=cartored key=CARTORED_PROXY value="proxy-sede-norte"
/container/envs/add list=cartored key=CARTORED_TOKEN value="<TOKEN>"
/container/mounts/add name=cartored-datos src=usb1/cartored/datos dst=/data
/container/config/set tmpdir=usb1/cartored/tmp
/container/add file=usb1/cartored-proxy-arm64.tar interface=veth-cartored root-dir=usb1/cartored/root \
    envlists=cartored mountlists=cartored-datos start-on-boot=yes logging=yes
```

Si la versión de RouterOS es anterior a 7.20, mira el [paso 13](#13-routeros-anterior-a-720).

Opcional: `CARTORED_INTERVALO` (por defecto 30) cambia cada cuántos segundos consulta el proxy.

### 8.3 Esperar la extracción y arrancar

```
/container/print
```

Repítelo hasta que el estado pase de `extracting` a `stopped` (uno o dos minutos). Luego:

```
/container/start [find where interface=veth-cartored]
```

Cuando esté en `running`, ya puedes borrar el `.tar`:

```
/file/remove usb1/cartored-proxy-arm64.tar
```

---

## 9. Verificar

**En el MikroTik:**

```
/log/print where topics~"container"
```

Debe aparecer:

```
cartored-proxy 1.0: proxy "proxy-sede-norte", servidor http://.../mapa, cada 30 s
```

Si no hay más mensajes, está bien: el proxy solo escribe cuando recorre un mapa o cuando hay un error.

**En la web de CartoRed** → **Proxys**: en menos de un minuto, el proxy pasa a **Conectado**, con la hora del
último contacto y la IP desde la que llega (la IP pública de la red remota).

---

## 10. Asignarle mapas al proxy

1. En **Mapas → Nuevo mapa**, crea el mapa de esa red.
2. En **Hosts**, agrega sus routers y switches con las **IP internas de esa red**: son las que alcanza el proxy.
3. En **Hosts → Opciones** del mapa, en **Quién recorre este mapa**, elige el proxy y pulsa **Guardar opciones**.
4. Pulsa **⟳ Actualizar**. El estado queda en *Esperando al proxy…*. En la siguiente consulta (menos de 30 s)
   el proxy recorre la red y el mapa se publica como cualquier otro, con historial, VLANs, RSTP y puertos.

La actualización automática del mapa (cada 5 a 30 minutos) también pasa por el proxy.

> **IPs repetidas.** La lista de hosts de CartoRed es una sola y se identifica por IP. Si la red remota usa las
> mismas IP privadas que otra red ya cargada, los hosts van a chocar. En ese caso conviene que cada red use un
> rango distinto.

---

## 11. Solución de problemas

En el log del contenedor (`/log/print where topics~"container"`):

| Mensaje | Causa y solución |
|---|---|
| `faltan las variables CARTORED_...` | Falta alguna variable: revisa `/container/envs/print`. |
| `sin conexión con el servidor` | No llega al servidor: repite el [paso 7](#7-probar-que-el-mikrotik-llega-al-servidor). |
| `el servidor respondió 403: Proxy o token no válidos` | El nombre o el token no coinciden con los de la web: copia el token otra vez o genera uno con **Nuevo token**. |
| `el servidor respondió 403: La hora del proxy difiere…` | La hora está mal: configura NTP ([5.3](#53-hora-ntp)). |
| `el servidor respondió 403` (HTML de Apache) | La regla del [paso 2.2](#22-exponer-solo-proxyphp-a-internet-recomendado) bloquea `proxy.php`: revisa la sección `<Files "proxy.php">`. |
| `el servidor respondió 503` | El proxy se acaba de crear: el servidor lo prepara en unos segundos. Se resuelve solo. |
| `error: InvalidTag` | El token del contenedor no es el actual: cárgale el último token. |
| `mapa ...: error: ...` | El recorrido falló en la red remota. El detalle aparece también en la web, en el estado del mapa. |
| `sin conexión con el servidor: ... certificate verify failed` | Usas HTTPS con un certificado autofirmado: usa uno válido o HTTP ([2.3](#23-http-o-https)). |

En la web:

| Qué ves | Causa |
|---|---|
| Proxy **Nunca se conectó** | El contenedor no arrancó o no llega al servidor: revisa el log del MikroTik. |
| Proxy **Sin contacto** | Funcionaba y se cortó: MikroTik apagado, sin Internet o contenedor detenido. |
| Mapa con error *El proxy no entregó el resultado en 30 minutos* | El proxy estuvo sin contacto o el recorrido no terminó. La actualización automática lo vuelve a pedir. |
| Equipos con *no responde SNMP* | La comunidad no es la correcta o el equipo no acepta SNMP desde la IP del MikroTik ([paso 6](#6-red-del-contenedor)). |

Estado del contenedor:

```
/container/print detail
```

---

## 12. Actualizar, cambiar el token o desinstalar

**Actualizar la imagen.** Los datos de `usb1/cartored/datos` se conservan:

```
/container/stop [find where interface=veth-cartored]
/container/remove [find where interface=veth-cartored]
```

Sube el `.tar` nuevo y repite el último comando del [paso 8.2](#82-variables-carpeta-de-datos-y-contenedor)
(`/container/add ...`) y el [8.3](#83-esperar-la-extracción-y-arrancar).

**Cambiar el token.** En la web, **Proxys → Nuevo token**. En el MikroTik:

```
/container/envs/set [find where key=CARTORED_TOKEN] value="<TOKEN-NUEVO>"
/container/stop [find where interface=veth-cartored]
/container/start [find where interface=veth-cartored]
```

**Desinstalar:**

```
/container/stop [find where interface=veth-cartored]
/container/remove [find where interface=veth-cartored]
/container/envs/remove [find where list=cartored]
/container/mounts/remove [find where name=cartored-datos]
/ip/firewall/nat/remove [find where comment="CartoRed proxy"]
/ip/firewall/filter/remove [find where comment="CartoRed proxy"]
/ip/address/remove [find where interface=contenedores]
/interface/bridge/port/remove [find where interface=veth-cartored]
/interface/bridge/remove [find where name=contenedores]
/interface/veth/remove [find where name=veth-cartored]
```

Luego, en la web, cambia los mapas de ese proxy a otro (o a **Este servidor**) y elimina el proxy en
**Proxys**.

**Seguridad.** Cualquier administrador del MikroTik ve el token con `/container/envs/print`. Deja el acceso
al MikroTik solo a quien corresponda. Si sospechas que el token se filtró, genera uno nuevo.

---

## 13. RouterOS anterior a 7.20

En las versiones anteriores a 7.20 cambian algunos nombres de parámetros:

| 7.20 o más nuevo | Anterior a 7.20 |
|---|---|
| `/container/envs/add list=cartored ...` | `/container/envs/add name=cartored ...` |
| `/container/add ... envlists=cartored` | `/container/add ... envlist=cartored` |
| `/container/add ... mountlists=cartored-datos` | `/container/add ... mounts=cartored-datos` |
| `/container/envs/remove [find where list=cartored]` | `/container/envs/remove [find where name=cartored]` |

Si RouterOS rechaza un parámetro (`expected end of command` o `no such argument`), usa el de la otra columna.

---

## Otra opción: Docker en un Linux de la red remota

Si en la red remota hay un Linux con Docker en vez de un MikroTik:

```bash
docker load -i cartored-proxy-amd64.tar
docker run -d --name cartored-proxy --restart unless-stopped --network host -v cartored-datos:/data \
  -e CARTORED_SERVIDOR="http://<IP-pública-o-dominio>/mapa" \
  -e CARTORED_PROXY="proxy-sede-norte" \
  -e CARTORED_TOKEN="<TOKEN>" \
  cartored-proxy:latest
docker logs -f cartored-proxy
```

Con `--network host`, el SNMP sale con la IP del propio Linux.
