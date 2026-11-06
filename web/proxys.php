<?php
// Proxys (como los proxys activos de Zabbix): equipos dentro de otra red que la recorren por SNMP y le entregan
// el resultado a este servidor. La web guarda el nombre y la clave de firma (inventario/proxys.json); el token
// se muestra una sola vez y va al servicio (root), que lo guarda fuera del alcance de la web.
require __DIR__ . '/lib.php';
requiere_login();
const BASE = '/var/lib/seccart-web';
const PROXYS = BASE . '/inventario/proxys.json';
const RE_NOMBRE = '/^[A-Za-z0-9_-]{1,40}$/';
const RE_DESC = '/^[^<>\x00-\x1f]{0,80}$/u';
const EN_LINEA_SEG = 120;   // el proxy consulta cada 30 s: sin contacto en 2 min = desconectado

function leer_proxys() {
    $d = json_decode((string)@file_get_contents(PROXYS), true);
    $r = [];
    foreach (is_array($d['proxys'] ?? null) ? $d['proxys'] : [] as $p)
        if (is_array($p) && preg_match(RE_NOMBRE, $p['nombre'] ?? '')) $r[$p['nombre']] = $p;
    ksort($r);
    return $r;
}
function guardar_proxys($p) { guardar_json_seguro(PROXYS, ['proxys' => array_values($p)]); }
function encolar($sol) {
    $f = BASE . '/cola/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
    guardar_json_seguro($f, $sol);
}
function nuevo_token() { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
// mapas que recorre cada proxy (opcion "Quién recorre este mapa" en Hosts > Opciones)
function mapas_por_proxy() {
    $r = [];
    foreach (glob(BASE . '/mapas/*.json') ?: [] as $f) {
        $c = json_decode((string)file_get_contents($f), true);
        if (is_array($c) && !empty($c['proxy'])) $r[$c['proxy']][] = ['id' => basename($f, '.json'), 'titulo' => $c['titulo'] ?? basename($f, '.json')];
    }
    return $r;
}

$proxys = leer_proxys();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); exit('Solicitud no válida'); }
    $accion = $_POST['accion'] ?? ''; $n = trim($_POST['nombre'] ?? '');
    $err = '';
    if ($accion === 'crear' || $accion === 'token') {
        $desc = trim($_POST['descripcion'] ?? '');
        if (!preg_match(RE_NOMBRE, $n)) $err = 'Nombre inválido: letras, números, - y _ (sin espacios).';
        elseif ($accion === 'crear' && isset($proxys[$n])) $err = "Ya hay un proxy \"$n\".";
        elseif ($accion === 'token' && !isset($proxys[$n])) $err = 'Ese proxy no existe.';
        elseif (!preg_match(RE_DESC, $desc)) $err = 'Descripción inválida (hasta 80 caracteres, sin < ni >).';
        if (!$err) {
            $token = nuevo_token();
            $p = $proxys[$n] ?? ['nombre' => $n, 'creado' => date('Y-m-d H:i'), 'descripcion' => $desc];
            $p['k_auth'] = hash_hmac('sha256', 'cartored-auth', $token);
            $p['token_fecha'] = date('Y-m-d H:i');
            $proxys[$n] = $p;
            guardar_proxys($proxys);
            encolar(['accion' => 'proxy_token', 'nombre' => $n, 'token' => $token]);
            $_SESSION['proxy_nuevo'] = ['nombre' => $n, 'token' => $token, 'cambio' => $accion === 'token'];
        }
    } elseif ($accion === 'eliminar') {
        $usa = mapas_por_proxy()[$n] ?? [];
        if (!isset($proxys[$n])) $err = 'Ese proxy no existe.';
        elseif ($usa) $err = "El proxy \"$n\" todavía recorre " . implode(', ', array_map(fn($m) => '"' . $m['titulo'] . '"', $usa))
                             . '. Cámbialo en Hosts > Opciones de cada mapa antes de eliminarlo.';
        else { unset($proxys[$n]); guardar_proxys($proxys); encolar(['accion' => 'proxy_quitar', 'nombre' => $n]);
               $_SESSION['flash'] = "Proxy \"$n\" eliminado. Su token ya no sirve."; }
    }
    if ($err) $_SESSION['flash_err'] = $err;
    header('Location: proxys.php'); exit;
}
$flash = $_SESSION['flash'] ?? ''; $flash_err = $_SESSION['flash_err'] ?? '';
$nuevo = $_SESSION['proxy_nuevo'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_err'], $_SESSION['proxy_nuevo']);
$csrf = $_SESSION['csrf'];
$usan = mapas_por_proxy();
$servidor = 'http://' . preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST'] ?? 'servidor') . '/mapa';

function contacto($n) {
    $c = json_decode((string)@file_get_contents(BASE . "/proxy/$n/contacto.json"), true);
    return is_array($c) ? $c : [];
}
function hace($t) {
    $s = time() - (int)$t;
    if ($s < 90) return "hace $s s";
    if ($s < 5400) return 'hace ' . round($s / 60) . ' min';
    if ($s < 172800) return 'hace ' . round($s / 3600) . ' h';
    return date('Y-m-d H:i', (int)$t);
}
?><!DOCTYPE html>
<html lang="es">
<head><?php cabeza('Proxys'); ?>
<style> dialog { background: var(--panel); color: var(--txt); border: 1px solid var(--borde); border-radius: 12px; padding: 0; width: min(460px, calc(100vw - 32px)); }
  dialog::backdrop { background: rgba(0,0,0,.6); } dialog form { display: flex; flex-direction: column; gap: 14px; padding: 20px; }
  pre.cmd { background: #0b0f16; border: 1px solid var(--borde); border-radius: 8px; padding: 12px 14px; margin: 0; font: 12.5px/1.55 'IBM Plex Mono', monospace;
            white-space: pre-wrap; word-break: break-all; color: #d6deeb; }
  .token { font: 15px 'IBM Plex Mono', monospace; background: #0b0f16; border: 1px solid #a16207; border-radius: 8px; padding: 10px 12px; word-break: break-all; user-select: all; }
  ol.pasos { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 14px; font-size: 14px; }
  ol.pasos li > p { margin: 0 0 8px; } </style>
</head>
<body>
<?php menu('proxys'); ?>
<main class="pagina" style="max-width:1000px">
  <div class="titulo"><div><h1>Proxys</h1>
    <p class="sub" style="margin:4px 0 0;font-size:15px">Para mapear redes que este servidor no alcanza: el proxy vive dentro de esa red (por ejemplo,
      en un contenedor de un MikroTik), la recorre por SNMP y le entrega el resultado a este servidor, como los proxys activos de Zabbix.</p></div>
    <div class="acciones"><button class="prim" onclick="document.getElementById('dlg').showModal()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Crear proxy</button></div></div>
  <?php if ($flash): ?><div class="msg ok" role="status"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>

  <?php if ($nuevo): ?>
  <section class="tarjeta" style="border-color:#a16207">
    <div class="cab"><h2><?= $nuevo['cambio'] ? 'Token nuevo' : 'Proxy creado' ?>: <?= h($nuevo['nombre']) ?></h2></div>
    <div class="cuerpo" style="display:flex;flex-direction:column;gap:12px">
      <p style="margin:0;font-size:14px"><b>Copia el token ahora: no se vuelve a mostrar.</b> Si lo pierdes, genera otro con <b>Nuevo token</b>
        <?= $nuevo['cambio'] ? '(el proxy deja de funcionar hasta que le cargues este token)' : '' ?>.</p>
      <div class="token"><?= h($nuevo['token']) ?></div>
      <p class="sub" style="margin:0;font-size:13px">Variables del contenedor (pasos de instalación más abajo):</p>
      <pre class="cmd">/container/envs/add list=cartored key=CARTORED_SERVIDOR value="<?= h($servidor) ?>"
/container/envs/add list=cartored key=CARTORED_PROXY value="<?= h($nuevo['nombre']) ?>"
/container/envs/add list=cartored key=CARTORED_TOKEN value="<?= h($nuevo['token']) ?>"</pre>
      <?php if ($nuevo['cambio']): ?><p class="sub" style="margin:0;font-size:13px">Si el contenedor ya existía: cambia el valor de CARTORED_TOKEN en
        <b>/container/envs</b> y reinicia el contenedor (<b>/container/stop</b> y <b>/container/start</b>).</p><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="tarjeta"><div class="tabla-wrap"><table>
    <thead><tr><th>Proxy</th><th>Estado</th><th>Último contacto</th><th>Mapas que recorre</th><th><span class="oculto-lect">Acciones</span></th></tr></thead>
    <tbody>
    <?php foreach ($proxys as $n => $p): $c = contacto($n); $u = (int)($c['ultimo'] ?? 0); ?>
      <tr><td><b style="font-weight:500"><?= h($n) ?></b>
            <?php if (!empty($p['descripcion'])): ?><div class="sub" style="font-size:13px"><?= h($p['descripcion']) ?></div><?php endif; ?></td>
        <td><?php if (!$u): ?><span class="pill gris"><span class="p"></span>Nunca se conectó</span>
            <?php elseif (time() - $u <= EN_LINEA_SEG): ?><span class="pill ok"><span class="p"></span>Conectado</span>
            <?php else: ?><span class="pill err"><span class="p"></span>Sin contacto</span><?php endif; ?></td>
        <td class="sub" style="font-size:13px"><?= $u ? h(hace($u)) . '<br>desde ' . h($c['ip'] ?? '') . (!empty($c['version']) ? ' · v' . h($c['version']) : '') : '—' ?></td>
        <td><?php foreach ($usan[$n] ?? [] as $m): ?><a class="chip" href="admin.php?mapa=<?= h(urlencode($m['id'])) ?>#opciones"><?= h($m['titulo']) ?></a> <?php endforeach; ?>
            <?php if (empty($usan[$n])): ?><span class="sub" style="font-size:13px">Ninguno: elígelo en Hosts &gt; Opciones del mapa</span><?php endif; ?></td>
        <td style="text-align:right"><div class="acciones" style="justify-content:flex-end">
          <form method="post" style="margin:0" onsubmit="return confirm('¿Generar un token nuevo para <?= h(addslashes($n)) ?>? El token actual deja de servir.')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="nombre" value="<?= h($n) ?>">
            <input type="hidden" name="descripcion" value="<?= h($p['descripcion'] ?? '') ?>">
            <button name="accion" value="token">Nuevo token</button></form>
          <form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar el proxy <?= h(addslashes($n)) ?>?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="nombre" value="<?= h($n) ?>">
            <button class="peligro" name="accion" value="eliminar">Eliminar</button></form>
        </div></td></tr>
    <?php endforeach; ?>
    <?php if (!$proxys): ?><tr><td colspan="5" class="sub" style="text-align:center;padding:24px">Ningún proxy todavía. Pulsa <b>Crear proxy</b>.</td></tr><?php endif; ?>
    </tbody></table></div></section>

  <section class="tarjeta">
    <div class="cab"><h2>Instalarlo en un MikroTik (RouterOS 7, paquete container)</h2></div>
    <div class="cuerpo">
      <ol class="pasos">
        <li><p>Requisitos: RouterOS 7 con el paquete <b>container</b>, arquitectura ARM64 (RB5009, CCR2004/2116, hAP ax…) o x86 (CHR), y
          un disco o USB con ~500 MB libres (en el ejemplo, <b>usb1</b>). El MikroTik debe llegar a <b><?= h($servidor) ?></b> (VPN o ruta).
          Activar el modo contenedor (pide pulsar el botón reset o apagar y prender el equipo para confirmar):</p>
          <pre class="cmd">/system/device-mode/update container=yes</pre></li>
        <li><p>Red del contenedor:</p>
          <pre class="cmd">/interface/veth/add name=veth-cartored address=172.17.0.2/24 gateway=172.17.0.1
/interface/bridge/add name=contenedores
/interface/bridge/port/add bridge=contenedores interface=veth-cartored
/ip/address/add address=172.17.0.1/24 interface=contenedores
/ip/firewall/nat/add chain=srcnat action=masquerade src-address=172.17.0.0/24 comment=cartored-proxy</pre>
          <p class="sub" style="margin:8px 0 0;font-size:13px">Las consultas SNMP salen con la IP del MikroTik: los equipos deben aceptar SNMP desde ella.</p></li>
        <li><p>Variables (con el token que mostró esta página al crear el proxy) y carpeta de datos:</p>
          <pre class="cmd">/container/envs/add list=cartored key=CARTORED_SERVIDOR value="<?= h($servidor) ?>"
/container/envs/add list=cartored key=CARTORED_PROXY value="NOMBRE-DEL-PROXY"
/container/envs/add list=cartored key=CARTORED_TOKEN value="TOKEN"
/container/mounts/add name=cartored-datos src=usb1/cartored-datos dst=/data</pre></li>
        <li><p>Subir la imagen <b>cartored-proxy-arm64.tar</b> (o <b>-amd64</b> para CHR) a <b>usb1/</b> con Winbox (Files) y crear el contenedor:</p>
          <pre class="cmd">/container/config/set tmpdir=usb1/tmp
/container/add file=usb1/cartored-proxy-arm64.tar interface=veth-cartored root-dir=usb1/cartored-root envlists=cartored mountlists=cartored-datos start-on-boot=yes logging=yes
/container/start [find where interface=veth-cartored]</pre>
          <p class="sub" style="margin:8px 0 0;font-size:13px">RouterOS anterior a 7.20: en envs y mounts se usa <b>name=</b> en lugar de <b>list=</b>, y en el contenedor
            <b>envlist=</b> y <b>mounts=</b>. Lo que hace el proxy se ve en <b>/log print where topics~"container"</b>.</p></li>
        <li><p>En <b>Hosts</b>, crea el mapa de esa red con sus routers/switches (las IP internas de esa red) y en <b>Opciones del mapa</b> elige
          este proxy en <b>Quién recorre este mapa</b>. Este estado pasa a <b>Conectado</b> en menos de un minuto.</p></li>
      </ol>
    </div>
  </section>
</main>
<dialog id="dlg"><form method="post">
  <h2 style="margin:0;font-size:18px">Crear proxy</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="crear">
  <label class="campo">Nombre<input name="nombre" required maxlength="40" pattern="[A-Za-z0-9_\-]{1,40}" placeholder="ej: proxy-sede-norte" autocomplete="off"
    title="Letras, números, - y _ (sin espacios)"></label>
  <label class="campo">Descripción (opcional)<input name="descripcion" maxlength="80" placeholder="ej: RB5009 de la sede norte"></label>
  <p class="sub" style="margin:0;font-size:13px">Al crearlo se muestra su token una sola vez.</p>
  <div class="acciones" style="justify-content:flex-end"><button type="button" onclick="document.getElementById('dlg').close()">Cancelar</button>
    <button class="prim">Crear</button></div>
</form></dialog>
</body>
</html>
