<?php
// Comunidades SNMP guardadas (cifradas en el servidor; aqui solo se ven los nombres)
require __DIR__ . '/lib.php';
requiere_login();
$comunidades = json_decode((string)@file_get_contents('/var/lib/seccart-web/comunidades.json'), true) ?: [];
$api = json_decode((string)@file_get_contents('/var/lib/seccart-web/api.json'), true) ?: ['configurado' => false, 'usuario' => ''];
$flash = $_SESSION['flash'] ?? ''; $flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);
?><!DOCTYPE html>
<html lang="es">
<head><?php cabeza('Comunidades SNMP'); ?></head>
<body>
<?php menu('comunidades'); ?>
<main class="pagina" style="max-width:900px">
  <div class="titulo"><div><h1>Comunidades SNMP</h1>
    <p class="sub" style="margin:4px 0 0;font-size:15px">Se prueban en los hosts que no tienen comunidad propia y en los equipos descubiertos.</p></div></div>
  <?php if ($flash): ?><div class="msg ok" role="status"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>
  <section class="tarjeta">
    <div class="cab"><h2>Guardadas · <?= count($comunidades) ?></h2>
      <span class="sub" style="font-size:13px">Se guardan cifradas: aquí solo se ven los nombres, nunca el valor.</span></div>
    <div class="cuerpo">
      <?php foreach ($comunidades as $c): ?><span class="chip" style="font-size:13px;padding:5px 12px"><?= h($c) ?></span><?php endforeach; ?>
      <?php if (!$comunidades): ?><span class="sub">Ninguna todavía.</span><?php endif; ?>
    </div>
  </section>
  <form class="tarjeta" method="post" action="admin.php">
    <div class="cab"><h2>Agregar comunidad</h2></div>
    <div class="cuerpo" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:end">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="comunidad">
      <label class="campo">Nombre para reconocerla<input name="cred_nombre" placeholder="ej: snmp-sede-norte" pattern="[A-Za-z0-9_-]{1,40}" required
        title="Letras, números, - y _"></label>
      <label class="campo">Comunidad<input name="cred_community" type="password" autocomplete="off" required maxlength="64"></label>
      <button class="prim">Agregar comunidad</button>
    </div>
  </form>
  <form class="tarjeta" method="post" action="admin.php">
    <div class="cab"><h2>Usuario API RouterOS (solo lectura)</h2>
      <span class="sub" style="font-size:13px"><?= !empty($api['configurado'])
        ? 'Guardado: <b>' . h($api['usuario']) . '</b> (la contraseña no se muestra)' : 'Sin usuario: los equipos usan solo SNMP.' ?></span></div>
    <div class="cuerpo">
      <p class="sub" style="margin:0 0 12px;font-size:13px">Sirve para leer las VLANs tagged / untagged configuradas en el bridge,
        tengan o no tráfico. Crea en los MikroTik un usuario del grupo <b>read</b> (el mismo en todos) con el servicio
        <b>api</b> (8728) o <b>api-ssl</b> (8729) habilitado, y activa <b>Leer VLANs del bridge por API</b> en cada router/switch
        en la página Hosts. Se guarda cifrado como las comunidades.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;align-items:end">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="api_usuario">
        <label class="campo">Usuario<input name="api_usuario" autocomplete="off" required maxlength="40" pattern="[A-Za-z0-9._@\-]{1,40}"
          value="<?= h($api['usuario'] ?? '') ?>"></label>
        <label class="campo">Contraseña<input name="api_password" type="password" autocomplete="new-password" required maxlength="128"></label>
        <button class="prim"><?= !empty($api['configurado']) ? 'Cambiar usuario API' : 'Guardar usuario API' ?></button>
      </div>
    </div>
  </form>
  <?php if (!empty($api['configurado'])): ?>
  <form method="post" action="admin.php" style="margin-top:-6px" onsubmit="return confirm('¿Quitar el usuario API? Los equipos vuelven a usar solo SNMP.')">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="api_quitar">
    <button class="peligro">Quitar usuario API</button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
