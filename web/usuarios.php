<?php
// Usuarios que pueden ingresar al sistema
require __DIR__ . '/lib.php';
requiere_login();
$yo = usuario_actual();
$usuarios = leer_usuarios();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); exit('Solicitud no válida'); }
    $accion = $_POST['accion'] ?? ''; $n = trim($_POST['usuario'] ?? '');
    $c1 = (string)($_POST['clave'] ?? ''); $c2 = (string)($_POST['clave2'] ?? '');
    $err = '';
    if ($accion === 'crear' || $accion === 'clave') {
        if (!preg_match(RE_USUARIO, $n)) $err = 'Usuario inválido: 2 a 32 letras, números, punto, - o _.';
        elseif ($accion === 'crear' && isset($usuarios[$n])) $err = "El usuario \"$n\" ya existe.";
        elseif ($accion === 'clave' && !isset($usuarios[$n])) $err = 'Ese usuario no existe.';
        elseif (strlen($c1) < 8) $err = 'La contraseña debe tener al menos 8 caracteres.';
        elseif ($c1 !== $c2) $err = 'Las dos contraseñas no coinciden.';
        if (!$err) {
            $usuarios[$n] = ['hash' => password_hash($c1, PASSWORD_DEFAULT), 'creado' => $usuarios[$n]['creado'] ?? date('Y-m-d H:i'),
                             'cambio' => date('Y-m-d H:i')];
            guardar_usuarios($usuarios);
            $_SESSION['flash'] = $accion === 'crear' ? "Usuario \"$n\" creado." : "Contraseña de \"$n\" cambiada.";
        }
    } elseif ($accion === 'borrar') {
        if (!isset($usuarios[$n])) $err = 'Ese usuario no existe.';
        elseif ($n === $yo) $err = 'No puedes eliminar tu propio usuario mientras lo usas.';
        elseif (count($usuarios) < 2) $err = 'Debe quedar al menos un usuario.';
        else { unset($usuarios[$n]); guardar_usuarios($usuarios); $_SESSION['flash'] = "Usuario \"$n\" eliminado."; }
    }
    if ($err) $_SESSION['flash_err'] = $err;
    header('Location: usuarios.php'); exit;
}
$flash = $_SESSION['flash'] ?? ''; $flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);
$csrf = $_SESSION['csrf'];
?><!DOCTYPE html>
<html lang="es">
<head><?php cabeza('Usuarios'); ?>
<style> dialog { background: var(--panel); color: var(--txt); border: 1px solid var(--borde); border-radius: 12px; padding: 0; width: min(440px, calc(100vw - 32px)); }
  dialog::backdrop { background: rgba(0,0,0,.6); } dialog form { display: flex; flex-direction: column; gap: 14px; padding: 20px; } </style>
</head>
<body>
<?php menu('usuarios'); ?>
<main class="pagina" style="max-width:900px">
  <div class="titulo"><div><h1>Usuarios</h1>
    <p class="sub" style="margin:4px 0 0;font-size:15px">Personas que pueden ingresar. Todos pueden ver y modificar los mapas.</p></div>
    <div class="acciones"><button class="prim" onclick="abrir('crear','')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Crear usuario</button></div></div>
  <?php if ($flash): ?><div class="msg ok" role="status"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>
  <section class="tarjeta"><div class="tabla-wrap"><table>
    <thead><tr><th>Usuario</th><th>Creado</th><th>Último cambio de contraseña</th><th><span class="oculto-lect">Acciones</span></th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $n => $u): ?>
      <tr><td><span style="display:flex;align-items:center;gap:10px"><span class="avatar" aria-hidden="true"><?= h(strtoupper(substr($n, 0, 2))) ?></span>
        <b style="font-weight:500"><?= h($n) ?></b><?= $n === $yo ? ' <span class="chip act">tú</span>' : '' ?></span></td>
        <td class="sub"><?= h($u['creado'] ?? '') ?></td><td class="sub"><?= h($u['cambio'] ?? '—') ?></td>
        <td style="text-align:right"><div class="acciones" style="justify-content:flex-end">
          <button type="button" onclick="abrir('clave', <?= h(json_encode($n)) ?>)">Cambiar contraseña</button>
          <?php if ($n !== $yo): ?><form method="post" style="margin:0" onsubmit="return confirm('¿Eliminar el usuario <?= h(addslashes($n)) ?>? Ya no podrá ingresar.')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="usuario" value="<?= h($n) ?>">
            <button class="peligro" name="accion" value="borrar">Eliminar</button></form><?php endif; ?>
        </div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
</main>
<dialog id="dlg"><form method="post">
  <h2 id="d-tit" style="margin:0;font-size:18px">Crear usuario</h2>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" id="d-acc">
  <label class="campo">Usuario<input name="usuario" id="d-usr" required maxlength="32" pattern="[A-Za-z0-9._\-]{2,32}" autocomplete="off"></label>
  <label class="campo">Contraseña nueva<input name="clave" type="password" required minlength="8" autocomplete="new-password"></label>
  <label class="campo">Repite la contraseña<input name="clave2" type="password" required minlength="8" autocomplete="new-password"></label>
  <p class="sub" style="margin:0;font-size:13px">Mínimo 8 caracteres.</p>
  <div class="acciones" style="justify-content:flex-end"><button type="button" onclick="document.getElementById('dlg').close()">Cancelar</button>
    <button class="prim">Guardar</button></div>
</form></dialog>
<script>
function abrir(acc, n) {
  document.getElementById('d-acc').value = acc;
  document.getElementById('d-tit').textContent = acc === 'crear' ? 'Crear usuario' : 'Cambiar contraseña de ' + n;
  const u = document.getElementById('d-usr'); u.value = n; u.readOnly = acc !== 'crear';
  document.querySelectorAll('#dlg input[type=password]').forEach(i => i.value = '');
  document.getElementById('dlg').showModal();
}
</script>
</body>
</html>
