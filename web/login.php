<?php
// Ingreso al sistema (reemplaza la ventana de usuario y contraseña del navegador)
require __DIR__ . '/lib.php';

function leer_intentos() { $d = json_decode((string)@file_get_contents(INTENTOS), true); return is_array($d) ? $d : []; }
function clave_intento($u) { return ($_SERVER['REMOTE_ADDR'] ?? '-') . '|' . strtolower($u); }

$error = ''; $usuario = ''; $volver = volver_seguro($_GET['volver'] ?? $_POST['volver'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'ingresar';
    if ($accion === 'salir') {
        if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { $_SESSION = []; session_regenerate_id(true); }
        header('Location: login.php?salio=1'); exit;
    }
    $usuario = trim($_POST['usuario'] ?? ''); $clave = (string)($_POST['clave'] ?? '');
    $intentos = leer_intentos();
    $ahora = time();
    foreach ($intentos as $k => $v) if (($v['hasta'] ?? 0) < $ahora && ($v['ultimo'] ?? 0) < $ahora - BLOQUEO_MIN * 60) unset($intentos[$k]);
    $k = clave_intento($usuario);
    $reg = $intentos[$k] ?? ['n' => 0, 'hasta' => 0, 'ultimo' => 0];
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'La página estuvo abierta mucho tiempo. Vuelve a intentarlo.';
    } elseif ($reg['hasta'] > $ahora) {
        $error = 'Demasiados intentos fallidos. Espera ' . max(1, (int)ceil(($reg['hasta'] - $ahora) / 60)) . ' minutos y vuelve a intentar.';
    } else {
        $usuarios = leer_usuarios();
        $hash = preg_match(RE_USUARIO, $usuario) ? ($usuarios[$usuario]['hash'] ?? '') : '';
        // se verifica siempre contra un hash (aunque el usuario no exista) para no revelar cuales existen por el tiempo
        $ok = password_verify($clave, $hash !== '' ? $hash : '$2y$10$I630TfNzL7sPTIvN9EnSouipNsDnOj1kQDKtee.krInGS2YbXcRDm');
        if ($ok && $hash !== '') {
            unset($intentos[$k]);
            @guardar_json_seguro(INTENTOS, $intentos);
            session_regenerate_id(true);
            $_SESSION['usuario'] = $usuario; $_SESSION['ingreso'] = $_SESSION['actividad'] = $ahora;
            $_SESSION['mantener'] = !empty($_POST['mantener']);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            if ($_SESSION['mantener']) setcookie(session_name(), session_id(), ['expires' => $ahora + SESION_HORAS * 3600,
                'path' => '/mapa/', 'httponly' => true, 'samesite' => 'Lax']);
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) { $usuarios[$usuario]['hash'] = password_hash($clave, PASSWORD_DEFAULT); @guardar_usuarios($usuarios); }
            header('Location: ' . $volver); exit;
        }
        $reg['n']++; $reg['ultimo'] = $ahora;
        if ($reg['n'] >= MAX_INTENTOS) { $reg['hasta'] = $ahora + BLOQUEO_MIN * 60; $reg['n'] = 0;
            $error = 'Usuario o contraseña incorrectos. Por seguridad, el ingreso queda bloqueado ' . BLOQUEO_MIN . ' minutos.'; }
        else { $quedan = MAX_INTENTOS - $reg['n'];
            $error = 'Usuario o contraseña incorrectos.' . ($quedan <= 3 ? " Te quedan $quedan intento" . ($quedan > 1 ? 's' : '') . ' antes de un bloqueo de ' . BLOQUEO_MIN . ' minutos.' : ''); }
        $intentos[$k] = $reg;
        @guardar_json_seguro(INTENTOS, $intentos);
    }
} elseif (usuario_actual() !== '') { header('Location: ' . $volver); exit; }
$sin_cuentas = !is_dir(CUENTAS) || !leer_usuarios();
?><!DOCTYPE html>
<html lang="es">
<head>
<?php cabeza('Ingresar'); ?>
<style>
  .ingreso { min-height: 100vh; display: flex; flex-wrap: wrap; }
  .lado { flex: 1 1 520px; min-width: 0; padding: 48px 56px; display: flex; flex-direction: column; justify-content: space-between; gap: 40px;
          background: var(--barra); border-right: 1px solid #232833; }
  .lado h1 { margin: 0; font-size: 32px; line-height: 1.2; font-weight: 600; max-width: 520px; }
  .lado p { margin: 0; color: var(--sub); font-size: 16px; line-height: 1.6; max-width: 520px; }
  .form-lado { flex: 1 1 460px; min-width: 0; padding: 48px 24px; display: flex; align-items: center; justify-content: center; }
  form.ing { width: 100%; max-width: 400px; display: flex; flex-direction: column; gap: 20px; }
  form.ing input:not([type=checkbox]) { height: 46px; font-size: 15px; }
  .clave { display: flex; align-items: center; border: 1px solid var(--borde2); border-radius: 8px; background: var(--panel2); }
  .clave:focus-within { outline: 2px solid var(--acento-borde); outline-offset: 1px; }
  .clave input { flex: 1; min-width: 0; border: 0 !important; background: transparent; outline: none !important; }
  .clave button { border: 0; width: 46px; height: 44px; min-height: 0; padding: 0; color: var(--sub); }
  form.ing.con-error .clave { border-color: var(--err); }
  .pie { display: flex; justify-content: space-between; gap: 12px; padding-top: 18px; border-top: 1px solid #232833; font-size: 13px; color: var(--sub); }
  @media (max-width: 700px) { .lado { padding: 28px 20px; gap: 20px; } .lado svg, .lado p { display: none; } .lado h1 { font-size: 22px; } }
</style>
</head>
<body>
<div class="ingreso">
  <section class="lado">
    <div class="marca"><span class="logo"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="18" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="M11 7.2L6.2 15.8M13 7.2l4.8 8.6M7.5 18h9"/></svg></span>
      <span>Mapa de Red<br><span class="sub" style="font-weight:400;font-size:13px">SecureCartography2</span></span></div>
    <div style="display:flex;flex-direction:column;gap:24px">
      <svg viewBox="0 0 520 240" width="100%" style="max-width:520px" aria-hidden="true">
        <g stroke="#334155" stroke-width="2"><line x1="260" y1="40" x2="120" y2="120"/><line x1="260" y1="40" x2="400" y2="120"/>
          <line x1="120" y1="120" x2="60" y2="200"/><line x1="120" y1="120" x2="180" y2="200"/><line x1="400" y1="120" x2="340" y2="200"/><line x1="400" y1="120" x2="460" y2="200"/></g>
        <line x1="120" y1="120" x2="400" y2="120" stroke="#22c55e" stroke-width="2" stroke-dasharray="6 6"/>
        <circle cx="260" cy="40" r="20" fill="#2563eb"/><rect x="96" y="104" width="48" height="32" rx="6" fill="#0d9488"/><rect x="376" y="104" width="48" height="32" rx="6" fill="#0d9488"/>
        <rect x="42" y="186" width="36" height="28" rx="6" fill="#475569"/><rect x="162" y="186" width="36" height="28" rx="6" fill="#c2410c"/>
        <rect x="322" y="186" width="36" height="28" rx="6" fill="#475569"/><rect x="442" y="186" width="36" height="28" rx="6" fill="#7e22ce"/>
      </svg>
      <h1>Topología, VLANs y caminos de tu red en un solo lugar.</h1>
      <p>Los mapas se arman solos leyendo LLDP, CDP, ARP y la tabla de puentes de cada equipo por SNMP.</p>
    </div>
    <p style="font-size:13px">Uso interno · Acceso solo para personal autorizado</p>
  </section>
  <section class="form-lado">
    <form class="ing<?= $error ? ' con-error' : '' ?>" method="post" action="login.php">
      <div><h2 style="margin:0;font-size:26px;font-weight:600">Ingresar</h2>
        <p class="sub" style="margin:6px 0 0;font-size:15px">Usa tu usuario del sistema.</p></div>
      <?php if ($error): ?><div class="msg err" role="alert"><?= h($error) ?></div><?php endif; ?>
      <?php if (isset($_GET['salio'])): ?><div class="msg ok" role="status">Cerraste la sesión.</div><?php endif; ?>
      <?php if ($sin_cuentas): ?><div class="msg err">No hay usuarios configurados en el servidor.</div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="volver" value="<?= h($volver) ?>">
      <label class="campo" for="usr">Usuario
        <input id="usr" name="usuario" autocomplete="username" required autofocus value="<?= h($usuario) ?>" maxlength="32"></label>
      <div class="campo" style="display:flex;flex-direction:column;gap:6px;font-weight:500">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px"><label for="pwd">Contraseña</label>
          <span class="sub" style="font-size:13px;font-weight:400">¿La olvidaste? Pídela al administrador</span></div>
        <div class="clave"><input id="pwd" name="clave" type="password" autocomplete="current-password" required>
          <button type="button" aria-label="Mostrar contraseña" aria-pressed="false" onclick="const i=document.getElementById('pwd'),v=i.type==='password';i.type=v?'text':'password';this.setAttribute('aria-pressed',v);this.setAttribute('aria-label',v?'Ocultar contraseña':'Mostrar contraseña')">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      </div>
      <label class="casilla" style="font-size:14px;color:var(--sub2);align-items:center"><input type="checkbox" name="mantener" value="1" style="margin:0" checked>
        Mantener la sesión abierta <?= SESION_HORAS ?> horas en este equipo</label>
      <button class="prim" style="height:48px;font-size:16px">Ingresar</button>
      <div class="pie"><span>Servidor <span class="mono"><?= h($_SERVER['SERVER_ADDR'] ?? '') ?></span></span><span>Tras <?= MAX_INTENTOS ?> intentos fallidos se bloquea <?= BLOQUEO_MIN ?> min</span></div>
    </form>
  </section>
</div>
</body>
</html>
