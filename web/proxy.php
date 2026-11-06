<?php
// Punto de contacto de los proxys (proxy activo, como en Zabbix). No usa sesion: cada pedido va firmado
// (HMAC-SHA256) con la clave del proxy que guarda inventario/proxys.json. Lo que viaja va cifrado con el
// token del proxy: aqui no se puede leer, solo se entrega (trabajo.bin) o se guarda para root (entrada/).
//   POST proxy.php?accion=trabajo    el proxy pregunta si hay mapas para recorrer (204 = nada)
//   POST proxy.php?accion=resultado  el proxy entrega un recorrido; se encola "proxy_resultado"
// Cabeceras: X-Proxy (nombre), X-Tiempo (unix), X-Firma = HMAC(clave, "nombre\ntiempo\naccion\nsha256(cuerpo)")
const BASE = '/var/lib/seccart-web';
const PROXYS = BASE . '/inventario/proxys.json';
const RE_NOMBRE = '/^[A-Za-z0-9_-]{1,40}$/';
const MAX_CUERPO = 7340032;   // 7 MB (post_max_size del servidor: 8 MB)

header('Cache-Control: no-store');
function fin($cod, $msg = '') { http_response_code($cod); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }
function escribir($f, $datos, $modo = 0660) {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, $datos);
    chmod($tmp, $modo);
    rename($tmp, $f);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fin(405, 'Solo POST');
$accion = $_GET['accion'] ?? '';
if (!in_array($accion, ['trabajo', 'resultado'], true)) fin(400, 'Acción desconocida');
$nombre = $_SERVER['HTTP_X_PROXY'] ?? ''; $ts = $_SERVER['HTTP_X_TIEMPO'] ?? ''; $firma = $_SERVER['HTTP_X_FIRMA'] ?? '';
if (!preg_match(RE_NOMBRE, $nombre) || !ctype_digit($ts) || !preg_match('/^[0-9a-f]{64}$/', $firma)) fin(400, 'Faltan cabeceras');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_CUERPO) fin(413, 'Resultado demasiado grande');
$cuerpo = (string)file_get_contents('php://input', false, null, 0, MAX_CUERPO + 1);
if (strlen($cuerpo) > MAX_CUERPO) fin(413, 'Resultado demasiado grande');

$proxys = json_decode((string)@file_get_contents(PROXYS), true)['proxys'] ?? [];
$p = null;
foreach (is_array($proxys) ? $proxys : [] as $x) if (is_array($x) && ($x['nombre'] ?? '') === $nombre) $p = $x;
$clave = is_array($p) ? (string)($p['k_auth'] ?? '') : '';
$esperada = hash_hmac('sha256', "$nombre\n$ts\n$accion\n" . hash('sha256', $cuerpo), $clave !== '' ? $clave : random_bytes(32));
if (!preg_match('/^[0-9a-f]{64}$/', $clave) || !hash_equals($esperada, $firma)) { usleep(500000); fin(403, 'Proxy o token no válidos'); }
if (abs(time() - (int)$ts) > 300) fin(403, 'La hora del proxy difiere más de 5 minutos de la del servidor');

$dir = BASE . "/proxy/$nombre";
if (!is_dir("$dir/entrada")) fin(503, 'El servidor todavía está preparando este proxy: reintenta en un minuto');

// ultimo contacto (lo muestra la pagina Proxys)
$info = json_decode((string)@file_get_contents("$dir/contacto.json"), true);
$info = is_array($info) ? $info : [];
$info['ultimo'] = time();
$info['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
if ($accion === 'trabajo') {
    $d = json_decode($cuerpo, true);
    if (is_array($d)) {
        $info['version'] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($d['version'] ?? '')), 0, 20);
        $info['equipo'] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($d['equipo'] ?? '')), 0, 64);
    }
} else {
    $info['ultimo_resultado'] = time();
}
escribir("$dir/contacto.json", json_encode($info));

if ($accion === 'trabajo') {
    $t = "$dir/trabajo.bin";
    if (!is_file($t)) fin(204);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($t));
    readfile($t);
    exit;
}

// resultado: se guarda tal cual (cifrado) y se le avisa al servicio
if (strlen($cuerpo) < 32) fin(400, 'Resultado vacío');
if (count(glob("$dir/entrada/*.bin") ?: []) >= 10) fin(429, 'Hay resultados sin procesar: reintenta más tarde');
$archivo = bin2hex(random_bytes(12)) . '.bin';
escribir("$dir/entrada/$archivo", $cuerpo);
escribir(BASE . '/cola/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json',
         json_encode(['accion' => 'proxy_resultado', 'proxy' => $nombre, 'archivo' => $archivo]));
fin(200, 'ok');
