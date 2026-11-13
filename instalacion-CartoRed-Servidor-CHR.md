# CartoRed-Exacom: el servidor completo en un contenedor del CHR (MikroTik)

Esta guía sube **el servidor** (la web, el recorrido SNMP, la actualización automática y el historial) a un
contenedor de RouterOS 7. Es distinta de [instalacion-Cartored-RouterOS.md](instalacion-Cartored-RouterOS.md),
que instala solo el **proxy** para redes remotas.

Un solo contenedor hace lo que en el servidor normal hacen el instalador y systemd:

| En el servidor Ubuntu/Debian | En el contenedor (`servidor/entrypoint`) |
|---|---|
| Apache + PHP | Apache + PHP, proceso principal (si se cae, el contenedor termina) |
| `seccart-web.path` + `.service` | bucle que procesa la cola cuando aparece un pedido |
| `seccart-web-auto.timer` | bucle que cada minuto en punto encola los mapas que tocan |
| `seccart-oui.timer` | bucle que baja la lista de fabricantes por MAC una vez al mes |
| `/var/lib/seccart-web`, `/root/.seccart2`, `/root/mapas`, … | todo dentro de **un solo mount**: `/data` |

Los datos viven **fuera** del contenedor (el mount `/data`), así que actualizar o recrear el contenedor no los toca.

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Imagen del contenedor](#2-imagen-del-contenedor)
3. [Red: veth y bridge](#3-red-veth-y-bridge)
4. [Variables y mount](#4-variables-y-mount)
5. [Crear y arrancar el contenedor](#5-crear-y-arrancar-el-contenedor)
6. [Pasar los datos del servidor anterior](#6-pasar-los-datos-del-servidor-anterior)
7. [Que los equipos respondan al SNMP](#7-que-los-equipos-respondan-al-snmp)
8. [Acceso desde fuera y proxys](#8-acceso-desde-fuera-y-proxys)
9. [Actualizar y respaldar](#9-actualizar-y-respaldar)
10. [Solución de problemas](#10-solución-de-problemas)

---

## 1. Requisitos

- RouterOS 7 con el paquete **container** y el modo contenedor activado (`/system/device-mode`).
- **CHR x86_64** (la imagen existe para `amd64` y `arm64`).
- Un disco con **ext4** para los datos (el servidor usa permisos de Linux: `setgid`, dueños `root`/`www-data`).
  FAT/exFAT no sirven. En esta guía el disco se llama `disk-001`.
- ~1 GB libres para la imagen y unos cientos de MB más para los datos; RAM: 512 MB libres (más con mapas grandes).
- Salida a Internet desde el contenedor (para bajar la imagen y, cada mes, la lista de fabricantes por MAC).

## 2. Imagen del contenedor

La imagen se arma con GitHub Actions (workflow **Imagen del servidor**) y se publica en
`ghcr.io/eliexerguevara/cartored-server:latest`. Antes de la primera vez:

1. En GitHub: repositorio → **Actions** → **Imagen del servidor** → **Run workflow**. El workflow arranca la imagen
   de verdad y comprueba la web antes de publicarla.
2. Cuando termine, en tu perfil → **Packages** → `cartored-server` → **Package settings** → **Change visibility**
   → **Public**. RouterOS baja la imagen sin usuario, así que tiene que ser pública.

**Opción sin registro (archivo `.tar`):** en la misma ejecución, en **Artifacts**, descarga
`cartored-server-amd64`, descomprime el `.zip`, sube el `.tar` al CHR (Files) y en el paso 5 usa
`file=cartored-server-amd64.tar` en vez de `remote-image=`.

## 3. Red: veth y bridge

Usa el mismo esquema que tus otros contenedores. Para ver cómo lo tienes:

```
/interface/veth/print detail
/interface/bridge/port/print where interface~"veth"
/ip/address/print
```

Copia la red, la puerta de enlace y el bridge de uno de ellos, y elige una IP libre. Con los valores de ejemplo
(`172.19.0.30/24`, puerta de enlace `172.19.0.1`, bridge `bridge-containers`; **cámbialos por los tuyos**):

```
/interface/veth/add name=veth13-CartoRed address=172.19.0.30/24 gateway=172.19.0.1
/interface/bridge/port/add bridge=bridge-containers interface=veth13-CartoRed
```

> Los nombres con corchetes (`[JJNT]`) hay que escribirlos **entre comillas** en RouterOS. Los de esta guía no
> los llevan, pero si agregas uno, ponle comillas.

## 4. Variables y mount

### Variables (envlist `cartored-env`)

| Variable | Para qué |
|---|---|
| `CARTORED_USUARIO` | usuario de la web (2 a 32 letras, números, `.`, `-`, `_`) |
| `CARTORED_CLAVE` | contraseña (mínimo 8 caracteres) |
| `TZ` | zona horaria, por ejemplo `America/Argentina/Buenos_Aires` (por defecto UTC) |
| `CARTORED_SOLO_PROXY_PUBLICO` | opcional, `si`: desde fuera de las redes privadas solo se entrega `proxy.php` (ver [paso 8](#8-acceso-desde-fuera-y-proxys)) |
| `CARTORED_REDES_PRIVADAS` | opcional, redes permitidas en ese modo, separadas por espacios |

`CARTORED_USUARIO` y `CARTORED_CLAVE` **solo crean** el usuario si no existe: no pisan una contraseña que cambies
desde la web. Cuando el usuario ya esté creado puedes quitar `CARTORED_CLAVE` de la envlist. Para forzar una clave
nueva, agrega `CARTORED_CLAVE_FORZAR=si` por un arranque.

Los nombres de los parámetros de `/container/envs` han cambiado entre versiones de RouterOS. Mira cómo se llaman
en la tuya antes de escribirlos:

```
/container/envs/print
/container/envs/add ?
```

Con los nombres que te muestre (por ejemplo `list=` o `name=` para la lista, y `key=`, `value=`), agrega las variables.
También puedes hacerlo en WinBox: **Container → Envs → +**.

### Mount (`/data`)

```
/container/mounts/add list=cartored-data src=/disk-001/cartored/data dst=/data
```

RouterOS crea la carpeta de origen si no existe.

## 5. Crear y arrancar el contenedor

```
/container/add remote-image="ghcr.io/eliexerguevara/cartored-server:latest" \
    interface=veth13-CartoRed envlists=cartored-env mountlists=cartored-data \
    root-dir=/disk-001/cartored/root dns=8.8.8.8,1.1.1.1 hostname=cartored \
    logging=yes start-on-boot=yes
```

Si usas el `.tar`: reemplaza `remote-image=...` por `file=cartored-server-amd64.tar`.

Espera a que baje y extraiga (en `/container/print` pasa a `stopped`) y arráncalo:

```
/container/start [find where interface="veth13-CartoRed"]
/log/print where topics~"container"
```

El log debe terminar con `[cartored] Servidor listo en el puerto 80`. Entra a `http://172.19.0.30/mapa` con el
usuario y la contraseña que pusiste. Después: **Comunidades SNMP** → cargar la comunidad → crear un mapa →
agregar los equipos en **Hosts** (o [pasa los datos del servidor anterior](#6-pasar-los-datos-del-servidor-anterior)).

El contenedor trae un chequeo de salud: cuando la web responde, en `/container/print` aparece con el flag `H`.

## 6. Pasar los datos del servidor anterior

Mapas, hosts, comunidades SNMP, usuarios de la web, proxys e historial se llevan **juntos**. El almacén de
comunidades solo se abre con su `vault.pass`, así que no copies una cosa sin la otra.

**En el servidor anterior** (con el servicio detenido para que no cambie nada mientras copias):

```bash
sudo systemctl stop seccart-web.path seccart-web-auto.timer
sudo tar czf cartored-datos.tgz -C / \
    --transform 's,^var/lib/seccart-web,seccart-web,; s,^var/www/html/mapa/data,publicados,' \
    var/lib/seccart-web root/.seccart2 root/mapas root/seccart-proxy var/www/html/mapa/data
```

**En el CHR**, con el contenedor ya arrancado una vez (paso 5):

```bash
# desde tu PC o el servidor anterior: copiar el archivo al disco del CHR
scp cartored-datos.tgz <usuario-del-chr>@<ip-del-chr>:/disk-001/cartored/data/
```

```
/container/shell [find where interface="veth13-CartoRed"]
```

Dentro del contenedor:

```sh
cd /data && tar xzf cartored-datos.tgz && rm cartored-datos.tgz && exit
```

Reinicia el contenedor (`/container/stop` y `/container/start`). Entra con los usuarios y contraseñas **del servidor
anterior** (reemplazaron al usuario temporal). El tar conserva los dueños (`www-data` es el 33 en Debian y en Ubuntu).

Cuando compruebes que todo está bien, apaga el servidor anterior: si los dos siguen activos, los dos recorren la red.

## 7. Que los equipos respondan al SNMP

Los routers y switches responden a la **IP de origen** del contenedor (la del veth, `172.19.0.30` en el ejemplo),
no a la del servidor anterior. Si tus equipos limitan el SNMP por origen (en MikroTik, `/snmp/community`
→ `addresses=`), agrega la IP nueva. Comprueba también que el CHR tenga ruta hacia esas redes y que el firewall
deje pasar el tráfico del veth (SNMP `udp/161`, y la API de RouterOS `tcp/8728` o `8729` si la usas para las VLANs).

## 8. Acceso desde fuera y proxys

La web usa rutas bajo `/mapa`, y la cookie de sesión es de `/mapa/`: si la pones detrás de un proxy inverso
(por ejemplo el Caddy que ya tienes en el CHR), conserva ese prefijo:

```
handle /mapa* {
    reverse_proxy 172.19.0.30:80
}
```

Para que los **proxys** de redes remotas lleguen al servidor, publica el puerto con un dst-nat hacia el contenedor
y activa `CARTORED_SOLO_PROXY_PUBLICO=si` en la envlist: desde fuera de las redes privadas solo se entrega
`proxy.php` (cada pedido va firmado y cifrado). Sigue el paso 2 de
[instalacion-Cartored-RouterOS.md](instalacion-Cartored-RouterOS.md) (el *port forward* es el mismo, hacia la IP
del contenedor), sin el `seccart-publico.conf`: lo crea el contenedor.

## 9. Actualizar y respaldar

**Respaldo:** todo está en `/disk-001/cartored/data`. Cópiala (SFTP o desde el disco) cuando el contenedor esté
detenido, o aun encendido si aceptas que un recorrido en curso quede a medias.

**Actualizar el programa:**

1. Ejecuta el workflow **Imagen del servidor** (o espera a que corra solo con un cambio en `main`).
2. En el CHR:
   ```
   /container/stop [find where interface="veth13-CartoRed"]
   /container/repull [find where interface="veth13-CartoRed"]
   /container/start [find where interface="veth13-CartoRed"]
   ```
   (o el botón **Repull** de WinBox). Mapas, comunidades y usuarios no se tocan: están en `/data`.

## 10. Solución de problemas

| Síntoma | Qué mirar |
|---|---|
| `No hay usuarios en la web` en el log | Falta `CARTORED_USUARIO` / `CARTORED_CLAVE` en la envlist del primer arranque |
| `Hay datos en /root/.seccart2 pero falta vault.pass` | Se copió el almacén sin su `vault.pass`: cópialo del servidor anterior |
| `No existe /data` | Falta el mount, o no se asignó al contenedor (`mountlists=`) |
| `error creating src` al arrancar | El disco del mount no está disponible o no es escribible (`/disk/print`) |
| `start failed: no command specified` | RouterOS perdió los metadatos de la imagen. Vuelve a bajarla (`repull`), o fija a mano `entrypoint=/usr/local/bin/cartored-entrypoint` |
| La web abre pero un mapa no se actualiza | Log del contenedor; que los equipos acepten SNMP desde la IP del veth ([paso 7](#7-que-los-equipos-respondan-al-snmp)) |
| Las horas salen corridas | Falta `TZ` en la envlist |
| `exited with status 1` justo al arrancar | `/log/print where topics~"container"`: el entrypoint explica el motivo con `[cartored][error]` |

Para mirar dentro del contenedor: `/container/shell [find where interface="veth13-CartoRed"]`.
