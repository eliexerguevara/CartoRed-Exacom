<?php
// Asistente para crear un mapa: nombre, de donde salen los hosts y hasta donde descubrir
require __DIR__ . '/lib.php';
requiere_login();
const BASE_W = '/var/lib/seccart-web';
$hosts = json_decode((string)@file_get_contents(BASE_W . '/inventario/hosts.json'), true)['hosts'] ?? [];
usort($hosts, fn($a, $b) => strnatcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));
$titulos = [];
foreach (glob(BASE_W . '/mapas/*.json') ?: [] as $f) {
    $c = json_decode((string)@file_get_contents($f), true) ?: [];
    $titulos[basename($f, '.json')] = trim($c['titulo'] ?? '') ?: basename($f, '.json');
}
natcasesort($titulos);
$flash_err = $_SESSION['flash_err'] ?? ''; unset($_SESSION['flash_err']);
$NT = ['router' => 'Router', 'switch' => 'Switch', 'acceso' => 'Acceso', 'otro' => 'Otro'];
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?><!DOCTYPE html>
<html lang="es">
<head>
<?php cabeza('Nuevo mapa'); ?>
<style>
  .cols { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
  .cols > form { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
  .resumen { flex: 1 1 300px; min-width: 0; position: sticky; top: 16px; }
  .paso { padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; }
  .paso > .cab2 { display: flex; align-items: center; gap: 12px; }
  .paso h2 { margin: 0; font-size: 18px; font-weight: 600; }
  .num { width: 28px; height: 28px; border-radius: 50%; background: var(--acento); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; flex: none; }
  .origenes { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; border: 0; margin: 0; padding: 0; }
  .origenes label { display: flex; flex-direction: column; gap: 6px; padding: 14px; border-radius: 10px; border: 1px solid var(--borde2); background: var(--panel2); cursor: pointer; }
  .origenes label:has(input:checked) { border: 2px solid var(--acento-borde); background: var(--acento-bg); padding: 13px; }
  .origenes b { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 500; }
  .origenes span { font-size: 13px; color: var(--sub); line-height: 1.45; }
  .lista-h { border-radius: 10px; border: 1px solid var(--borde); overflow: hidden; }
  .lista-h .bus { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 12px; background: var(--panel2); border-bottom: 1px solid var(--borde); }
  .lista-h .filas { max-height: 320px; overflow: auto; }
  .lista-h label { display: flex; align-items: center; gap: 12px; padding: 8px 14px; min-height: 44px; border-bottom: 1px solid #20242d; font-size: 14px; cursor: pointer; }
  .lista-h label:hover { background: #1a1e26; }
  .pt { width: 12px; height: 12px; border-radius: 3px; flex: none; } .pt.router { border-radius: 50%; background: var(--router); }
  .pt.switch { background: var(--switch); } .pt.acceso { background: var(--acceso); } .pt.otro { border: 2px dashed #71717a; }
  .seg { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-radius: 8px; border: 1px solid var(--borde2); overflow: hidden; }
  .seg label { display: flex; align-items: center; justify-content: center; text-align: center; min-height: 44px; padding: 4px; font-size: 14px; color: var(--sub2); cursor: pointer; border-left: 1px solid var(--borde2); }
  .seg label:first-child { border-left: 0; }
  .seg label:has(input:checked) { background: var(--acento); color: #fff; font-weight: 600; }
  .seg input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  .seg label:has(input:focus-visible) { outline: 2px solid #93c5fd; outline-offset: -3px; }
  .opc { display: flex; flex-direction: column; border-top: 1px solid #232833; }
  .opc .casilla { padding: 14px 0; border-bottom: 1px solid #232833; } .opc .casilla:last-child { border-bottom: 0; }
  dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 10px 16px; font-size: 14px; } dt { color: var(--sub); } dd { margin: 0; }
  [data-solo] { display: none; } [data-solo].ver { display: flex; }
</style>
</head>
<body>
<?php menu('mapas'); ?>
<main class="pagina" style="max-width:1180px">
  <div><a href="index.php" style="font-size:14px">← Mapas</a>
    <h1 style="margin:6px 0 4px;font-size:26px;font-weight:600">Nuevo mapa</h1>
    <p class="sub" style="margin:0;font-size:15px">Tres pasos. Todo se puede cambiar después en las opciones del mapa.</p></div>
  <?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>

  <div class="cols">
    <form id="f" method="post" action="admin.php" enctype="multipart/form-data" oninput="resumir()" onchange="resumir()">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="accion" value="nuevo_mapa">
      <input type="hidden" name="dibujar" id="dibujar" value="1">

      <section class="tarjeta paso">
        <div class="cab2"><span class="num">1</span><h2>Nombre</h2></div>
        <label class="campo" for="titulo">Nombre del mapa</label>
        <input id="titulo" name="titulo" required maxlength="60" placeholder="ej: Cámaras sede norte" style="height:46px;font-size:15px;margin-top:-8px">
        <p class="sub" style="margin:0;font-size:13px">Dirección del mapa: <span class="mono" id="dir" style="color:var(--sub2)">viewer.php?map=…</span></p>
      </section>

      <section class="tarjeta paso">
        <div class="cab2"><span class="num">2</span><h2>¿Qué equipos lee el mapa?</h2></div>
        <p class="sub" style="margin:0">Basta con los routers y switches principales: el resto se descubre solo.</p>
        <fieldset class="origenes"><legend class="oculto-lect">Origen de los hosts</legend>
          <label><b><input type="radio" name="origen" value="vacio">Empezar vacío</b><span>Los agregas después en Hosts.</span></label>
          <label><b><input type="radio" name="origen" value="lista" checked>Elegir de la lista</b><span>Hosts que ya cargaste en otros mapas.</span></label>
          <label><b><input type="radio" name="origen" value="copiar" <?= $titulos ? '' : 'disabled' ?>>Copiar un mapa</b><span>Todos los hosts de otro mapa.</span></label>
          <label><b><input type="radio" name="origen" value="csv">Subir .csv</b><span>Con el <a href="admin.php?csv=plantilla">formato descargable</a>.</span></label>
        </fieldset>

        <div class="lista-h" data-solo="lista" style="flex-direction:column">
          <div class="bus">
            <label style="display:flex;align-items:center;gap:8px;flex:1;min-width:200px;height:40px;padding:0 10px;border-radius:8px;border:1px solid var(--borde2);color:var(--sub);min-height:0;border-bottom:1px solid var(--borde2)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
              <span class="oculto-lect">Buscar host</span>
              <input id="bus" placeholder="Buscar por nombre o IP" oninput="buscar(this.value)" style="flex:1;min-width:0;border:0;background:transparent;padding:0;min-height:0;outline:none"></label>
            <button type="button" onclick="marcarVisibles(true)">Marcar visibles</button>
            <span class="sub" id="n-sel" style="font-size:13px">0 elegidos</span>
          </div>
          <div class="filas">
            <?php foreach ($hosts as $x): $t = $x['tipo'] ?? 'acceso'; ?>
              <label data-b="<?= h(mb_strtolower(($x['nombre'] ?? '') . ' ' . ($x['ip'] ?? ''))) ?>"><input type="checkbox" name="hosts[]" value="<?= h($x['ip'] ?? '') ?>" data-tipo="<?= h($t) ?>">
                <span class="pt <?= h($t) ?>" aria-hidden="true"></span><span style="flex:1"><?= h($x['nombre'] ?? '') ?></span>
                <span class="sub"><?= h($NT[$t] ?? $t) ?></span><span class="mono" style="color:var(--sub2);width:120px"><?= h($x['ip'] ?? '') ?></span></label>
            <?php endforeach; ?>
            <?php if (!$hosts): ?><div class="vacio">Todavía no hay hosts cargados. Elige "Empezar vacío" o "Subir .csv".</div><?php endif; ?>
          </div>
        </div>
        <label class="campo" data-solo="copiar">Mapa del que se copian los hosts
          <select name="copiar_de"><?php foreach ($titulos as $id => $t): ?><option value="<?= h($id) ?>"><?= h($t) ?></option><?php endforeach; ?></select>
          <span class="sub" style="font-weight:400;font-size:13px">Los hosts quedan en los dos mapas; lo que cambies en uno se ve en el otro.</span></label>
        <div data-solo="csv" style="flex-direction:column;gap:8px">
          <label class="campo">Archivo .csv<input type="file" name="csv" accept=".csv,text/csv,text/plain" style="padding:8px"></label>
          <span class="sub" style="font-size:13px">Columnas: NOMBRE; IP; TIPO DE DISPOSITIVO; COMUNIDAD; VERSION SNMP; BLOQUEO PPP; MAPAS. Todos los hosts del archivo se agregan a este mapa.</span>
        </div>
      </section>

      <section class="tarjeta paso">
        <div class="cab2"><span class="num">3</span><h2>¿Hasta dónde descubrir?</h2></div>
        <fieldset style="margin:0;padding:0;border:0;display:flex;flex-direction:column;gap:10px">
          <legend style="font-size:14px;font-weight:500;margin-bottom:10px">Profundidad del recorrido LLDP / CDP</legend>
          <div class="seg">
            <label><input type="radio" name="profundidad" value="0">Solo mis hosts</label>
            <label><input type="radio" name="profundidad" value="1" checked>+ vecinos</label>
            <label><input type="radio" name="profundidad" value="2">2 saltos</label>
            <label><input type="radio" name="profundidad" value="3">3 saltos</label>
          </div>
          <p class="sub" id="ayuda-prof" style="margin:0;font-size:13px"></p>
        </fieldset>
        <div class="opc">
          <label class="casilla"><input type="checkbox" name="auto_vecinos" value="1" checked>
            <span><b>Agregar vecinos automáticamente</b><span>Equipos que anuncian LLDP/CDP aunque no sean hosts. Salen con borde azul.</span></span></label>
          <label class="casilla"><input type="checkbox" name="barrido" value="1" checked>
            <span><b>Barrido de ping antes de leer el ARP</b><span>Encuentra equipos sin SNMP (cámaras, tableros). Suma unos segundos.</span></span></label>
          <label class="casilla"><input type="checkbox" name="ocultar_ppp" value="1" checked>
            <span><b>Ocultar clientes y túneles PPP / VPN</b><span>PPPoE, PPTP, L2TP, SSTP y OpenVPN. Solo se dibujan cables Ethernet.</span></span></label>
          <label class="casilla"><input type="checkbox" name="auto" value="1" checked>
            <span><b>Actualizar automáticamente cada 10 minutos</b><span>Guarda un historial de 7 días para ver cómo cambia la red. Se puede cambiar en Opciones del mapa.</span></span></label>
        </div>
      </section>
    </form>

    <aside class="tarjeta resumen paso" style="gap:16px">
      <h2 style="font-size:16px">Resumen</h2>
      <dl>
        <dt>Nombre</dt><dd id="r-nom" class="sub">sin nombre</dd>
        <dt>Hosts</dt><dd id="r-hosts"></dd>
        <dt>Alcance</dt><dd id="r-alc"></dd>
        <dt>Extras</dt><dd id="r-ext"></dd>
      </dl>
      <p class="sub" style="margin:0;padding:12px;border-radius:8px;background:var(--panel2);font-size:13px;line-height:1.5">
        El primer dibujo tarda entre 3 y 10 minutos según la cantidad de equipos. Puedes cerrar la página: sigue en el servidor.</p>
      <p id="r-aviso" class="msg err" style="margin:0" hidden></p>
      <button type="button" class="prim" style="height:48px;font-size:15px" onclick="crear(true)">Crear y dibujar el mapa</button>
      <button type="button" style="height:44px" onclick="crear(false)">Crear sin dibujar todavía</button>
    </aside>
  </div>
</main>
<script>
const TITULOS = <?= json_encode((object)$titulos, $J) ?>;
const f = document.getElementById('f');
const AYUDA = ['Lee solo los hosts que elijas.', 'Lee tus hosts y además los equipos conectados directamente a ellos.',
               'Hasta 2 equipos de distancia de tus hosts. Mapa más grande y actualización más lenta.', 'Hasta 3 equipos de distancia. Para redes grandes: tarda bastante más.'];
function slug(t) { return (t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'mapa').slice(0, 36); }
function buscar(t) { t = t.trim().toLowerCase(); f.querySelectorAll('.filas label').forEach(l => l.hidden = t && !l.dataset.b.includes(t)); }
function marcarVisibles(v) { f.querySelectorAll('.filas label:not([hidden]) input').forEach(i => i.checked = v); resumir(); }
function resumir() {
  const t = f.titulo.value.trim(), o = f.origen.value, p = +f.profundidad.value;
  document.getElementById('dir').textContent = 'viewer.php?map=' + (t ? slug(t) : '…');
  const rn = document.getElementById('r-nom'); rn.textContent = t || 'sin nombre'; rn.className = t ? '' : 'sub';
  document.querySelectorAll('[data-solo]').forEach(e => e.classList.toggle('ver', e.dataset.solo === o));
  const sel = [...f.querySelectorAll('input[name="hosts[]"]:checked')];
  document.getElementById('n-sel').textContent = sel.length + ' elegido' + (sel.length === 1 ? '' : 's');
  const cuenta = tipo => sel.filter(i => i.dataset.tipo === tipo).length;
  const partes = [['router', 'router', 'routers'], ['switch', 'switch', 'switches']].map(([k, s, pl]) => cuenta(k) ? cuenta(k) + ' ' + (cuenta(k) === 1 ? s : pl) : '').filter(Boolean);
  document.getElementById('r-hosts').textContent =
    o === 'vacio' ? 'Ninguno por ahora' : o === 'copiar' ? 'Los de "' + (TITULOS[f.copiar_de.value] || '') + '"'
    : o === 'csv' ? (f.csv.files.length ? 'Del archivo ' + f.csv.files[0].name : 'Elige el archivo .csv')
    : sel.length + ' elegido' + (sel.length === 1 ? '' : 's') + (partes.length ? ' (' + partes.join(', ') + ')' : '');
  document.getElementById('r-alc').textContent = ['Solo mis hosts', 'Hosts + vecinos directos', '2 saltos', '3 saltos'][p];
  document.getElementById('ayuda-prof').textContent = AYUDA[p];
  const ex = [f.auto_vecinos.checked && 'vecinos automáticos', f.barrido.checked && 'barrido de ping', f.ocultar_ppp.checked && 'sin PPP/VPN', f.auto.checked && 'automático cada 10 min'].filter(Boolean);
  document.getElementById('r-ext').textContent = ex.length ? ex.join(', ') : 'ninguno';
}
function crear(dibujar) {
  const av = document.getElementById('r-aviso'), o = f.origen.value;
  const sel = f.querySelectorAll('input[name="hosts[]"]:checked').length;
  let err = !f.titulo.value.trim() ? 'Escribe un nombre para el mapa.'
          : o === 'lista' && !sel ? 'Elige al menos un host de la lista, o usa "Empezar vacío".'
          : o === 'csv' && !f.csv.files.length ? 'Elige el archivo .csv.' : '';
  if (dibujar && !err && o === 'vacio') err = 'Un mapa vacío no se puede dibujar todavía: usa "Crear sin dibujar todavía".';
  av.hidden = !err; av.textContent = err;
  if (err) { if (!f.titulo.value.trim()) f.titulo.focus(); return; }
  document.getElementById('dibujar').value = dibujar ? '1' : '';
  f.submit();
}
resumir();
</script>
</body>
</html>
