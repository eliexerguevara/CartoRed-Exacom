<?php
// Comun a todas las paginas del mapa: sesion, ingreso con usuario y contraseña, menu superior.
// Usuarios: /var/lib/seccart-web/cuentas/usuarios.json  {"usuarios": {"nombre": {"hash": bcrypt, "creado": fecha}}}
// La primera vez se copian los usuarios del antiguo /etc/apache2/.htpasswd-mapa (mismas contraseñas).
const CUENTAS = '/var/lib/seccart-web/cuentas';
const USUARIOS = CUENTAS . '/usuarios.json';
const INTENTOS = CUENTAS . '/intentos.json';
const HTPASSWD = '/etc/apache2/.htpasswd-mapa';
const SESION_HORAS = 8;           // "Mantener la sesion abierta": dura 8 horas
const MAX_INTENTOS = 5;           // fallos seguidos antes del bloqueo
const BLOQUEO_MIN = 15;
const RE_USUARIO = '/^[A-Za-z0-9._-]{2,32}$/';

ini_set('session.save_path', '/var/lib/seccart-web/sesiones');   // fuera de /var/lib/php: no la limpia el cron cada 24 min
ini_set('session.gc_maxlifetime', (string)(SESION_HORAS * 3600));
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
ini_set('session.use_strict_mode', '1');
session_name('MAPASESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/mapa/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function guardar_json_seguro($f, $d) {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    chmod($tmp, 0660);
    rename($tmp, $f);
}
function leer_usuarios() {
    if (is_file(USUARIOS)) {
        $d = json_decode((string)file_get_contents(USUARIOS), true);
        return is_array($d['usuarios'] ?? null) ? $d['usuarios'] : [];
    }
    // primera vez: migrar los usuarios del acceso anterior (Apache), con sus mismas contraseñas bcrypt
    $u = [];
    foreach (@file(HTPASSWD, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        [$n, $hash] = array_pad(explode(':', $l, 2), 2, '');
        if (preg_match(RE_USUARIO, $n) && str_starts_with($hash, '$2')) $u[$n] = ['hash' => $hash, 'creado' => date('Y-m-d H:i'), 'origen' => 'apache'];
    }
    if ($u && is_dir(CUENTAS)) guardar_usuarios($u);
    return $u;
}
function guardar_usuarios($u) { ksort($u); guardar_json_seguro(USUARIOS, ['usuarios' => $u]); }

// Sesion valida: ingreso hace menos de 8 h (o, sin "mantener", mientras el navegador siga abierto y con actividad)
function usuario_actual() {
    $u = $_SESSION['usuario'] ?? '';
    if ($u === '') return '';
    $ahora = time();
    $limite = !empty($_SESSION['mantener']) ? ($_SESSION['ingreso'] ?? 0) + SESION_HORAS * 3600
                                            : ($_SESSION['actividad'] ?? 0) + 2 * 3600;
    if ($ahora > $limite || !isset(leer_usuarios()[$u])) { $_SESSION = []; session_regenerate_id(true); return ''; }
    $_SESSION['actividad'] = $ahora;
    return $u;
}
// Paginas: sin sesion se va al ingreso; pedidos del visor (JSON) reciben 401 para que lleve al ingreso
function requiere_login() {
    if (usuario_actual() !== '') return;
    $html = $_SERVER['REQUEST_METHOD'] === 'GET' && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')
            && !isset($_GET['token']) && !isset($_GET['estado']) && !isset($_GET['posiciones']);
    if ($html) {
        header('Location: login.php?volver=' . urlencode(basename($_SERVER['SCRIPT_NAME']) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }
    http_response_code(401);
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'login' => true, 'error' => 'La sesión venció: vuelve a ingresar.']);
    exit;
}
function volver_seguro($v) {
    // solo paginas de esta carpeta (nada de https://otro-sitio o //otro)
    return is_string($v) && preg_match('#^(index|admin|viewer|nuevo|usuarios|comunidades)\.php(\?[^\s<>"]*)?$#', $v) ? $v : 'index.php';
}

// Estilos comunes (menu, botones, formularios) y menu superior
function cabeza($titulo) { ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($titulo) ?> · Mapa de Red</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="estilo.css?v=1">
<?php }
function menu($activo) {
    $u = usuario_actual();
    $items = ['mapas' => ['index.php', 'Mapas'], 'hosts' => ['admin.php', 'Hosts'], 'comunidades' => ['comunidades.php', 'Comunidades SNMP'],
              'usuarios' => ['usuarios.php', 'Usuarios']]; ?>
<header class="barra">
  <a class="marca" href="index.php">
    <span class="logo"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="18" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="M11 7.2L6.2 15.8M13 7.2l4.8 8.6M7.5 18h9"/></svg></span>
    Mapa de Red</a>
  <nav aria-label="Principal" class="menu">
    <?php foreach ($items as $k => [$href, $txt]): ?>
      <a href="<?= $href ?>"<?= $k === $activo ? ' aria-current="page"' : '' ?>><?= h($txt) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="cuenta">
    <span class="avatar" aria-hidden="true"><?= h(strtoupper(substr($u, 0, 2))) ?></span><span><?= h($u) ?></span>
    <form method="post" action="login.php"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <button name="accion" value="salir" class="link">Salir</button></form>
  </div>
</header>
<?php }
