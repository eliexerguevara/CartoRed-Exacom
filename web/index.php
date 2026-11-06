<?php
// Mapas: una tarjeta por mapa con su estado, cantidad de equipos y enlaces, y accesos directos
require __DIR__ . '/lib.php';
requiere_login();
const BASE_W = '/var/lib/seccart-web';

$inventario = json_decode((string)@file_get_contents(BASE_W . '/inventario/hosts.json'), true)['hosts'] ?? [];
$mapas = [];
foreach (glob(__DIR__ . '/data/*.json') ?: [] as $f) {
    $id = basename($f, '.json');
    $mapas[$id] = ['id' => $id, 'titulo' => $id, 'fecha' => filemtime($f), 'web' => false];
}
foreach (glob(BASE_W . '/mapas/*.json') ?: [] as $f) {
    $id = basename($f, '.json');
    $c = json_decode((string)@file_get_contents($f), true) ?: [];
    $mapas[$id] = ($mapas[$id] ?? ['id' => $id, 'fecha' => 0]) + ['titulo' => $id];
    $mapas[$id]['titulo'] = trim($c['titulo'] ?? '') ?: $id;
    $mapas[$id]['web'] = true;
    $mapas[$id]['auto'] = (int)($c['auto_min'] ?? 0);
}
$en_cola = [];
foreach (glob(BASE_W . '/cola/*.json') ?: [] as $f) { $s = json_decode((string)@file_get_contents($f), true); if (!empty($s['mapa'])) $en_cola[$s['mapa']] = true; }

// Resumen de cada mapa publicado (se guarda en la sesion mientras el archivo no cambie)
function resumen_mapa($id, $fecha) {
    $k = "res_$id"; if (($_SESSION[$k]['fecha'] ?? 0) === $fecha) return $_SESSION[$k];
    $d = json_decode((string)@file_get_contents(__DIR__ . "/data/$id.json"), true) ?: [];
    $r = ['fecha' => $fecha, 'equipos' => count($d), 'enlaces' => 0, 'router' => 0, 'switch' => 0, 'antena' => 0, 'servidor' => 0, 'otro' => 0];
    $pares = [];
    foreach ($d as $n => $v) {
        $nd = $v['node_details'] ?? []; $md = $nd['metadata'] ?? [];
        $txt = implode(' ', [$nd['vendor'] ?? '', $md['marca'] ?? '', $nd['platform'] ?? '', $n]);
        $t = ($md['fijo'] ?? false) ? ($md['tipo'] ?? '') : '';
        if (preg_match('/ubiquiti|\bubnt\b|mimosa|airos|airmax|litebeam|nanobeam|powerbeam|nanostation/i', $txt) && !preg_match('/edge\s?switch|edge\s?router|unifi\s?switch/i', $txt)) $r['antena']++;
        elseif ($t === 'router' || $t === 'switch') $r[$t]++;
        elseif (preg_match('/supermicro|super\s?micro|\bHPE?\b|hewlett|proliant/i', $txt) && !preg_match('/switch|officeconnect|procurve|aruba/i', $txt)) $r['servidor']++;
        else $r['otro']++;
        foreach ($v['peers'] ?? [] as $p => $pd) {
            if (!empty($pd['indirecto']) || !isset($d[$p])) continue;
            $pares[$n < $p ? "$n\x00$p" : "$p\x00$n"] = true;
        }
    }
    $r['enlaces'] = count($pares);
    return $_SESSION[$k] = $r;
}
foreach ($mapas as $id => &$m) {
    $m['hosts'] = array_values(array_filter($inventario, fn($x) => in_array($id, $x['mapas'] ?? [], true)));
    $m['res'] = $m['fecha'] ? resumen_mapa($id, $m['fecha']) : null;
    $m['estado'] = json_decode((string)@file_get_contents(BASE_W . "/estado/$id.json"), true) ?: [];
    $m['cola'] = isset($en_cola[$id]);
}
unset($m);
$mapas = array_values($mapas);
usort($mapas, fn($a, $b) => strnatcasecmp($a['titulo'], $b['titulo']));
$flash = $_SESSION['flash'] ?? ''; $flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);
$csrf = $_SESSION['csrf'];

function hace($f) {
    if (!$f) return '';
    $t = is_numeric($f) ? (int)$f : strtotime($f);
    if (!$t) return '';
    return date('Y-m-d', $t) === date('Y-m-d') ? 'hoy ' . date('H:i', $t)
         : (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day')) ? 'ayer ' . date('H:i', $t) : date('d/m/Y H:i', $t));
}
function duracion($a, $b) { $s = strtotime($b) - strtotime($a); return $s > 0 ? ($s < 90 ? "$s s" : round($s / 60) . ' min') : ''; }
$COLOR = ['router' => 'var(--router)', 'switch' => 'var(--switch)', 'antena' => 'var(--antena)', 'servidor' => 'var(--servidor)', 'otro' => 'var(--acceso)'];
$NOMBRE = ['router' => 'routers', 'switch' => 'switches', 'antena' => 'antenas', 'servidor' => 'servidores', 'otro' => 'otros equipos'];
?><!DOCTYPE html>
<html lang="es">
<head>
<?php cabeza('Mapas'); ?>
<style>
  .grilla { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
  .mapa { display: flex; flex-direction: column; }
  .mapa.corre { border-color: #1e3a8a; }
  .mapa.nuevo { border-style: dashed; border-color: #3a4150; background: #14171d; }
  .comp { display: flex; height: 10px; border-radius: 99px; overflow: hidden; background: #232833; gap: 2px; }
  .comp span { display: block; height: 10px; }
  .ley { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 12px; color: var(--sub); }
  .ley i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; vertical-align: -1px; }
  .cifras { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
  .cifras b { display: block; font-size: 22px; font-weight: 600; } .cifras span { font-size: 13px; color: var(--sub); }
  .barra-av { height: 6px; border-radius: 99px; background: #232833; overflow: hidden; }
  .barra-av div { height: 6px; width: 40%; background: #3b82f6; border-radius: 99px; animation: ir 1.6s ease-in-out infinite; }
  @keyframes ir { from { margin-left: -40%; } to { margin-left: 100%; } }
  @media (prefers-reduced-motion: reduce) { .barra-av div { animation: none; width: 100%; opacity: .5; } }
  .pasos { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
  .pasos li { display: flex; gap: 10px; align-items: center; color: var(--sub); }
  .pasos .c { width: 20px; height: 20px; border-radius: 50%; border: 2px solid #3a4150; flex: none; display: flex; align-items: center; justify-content: center; font-size: 11px; }
  .pasos .si .c { background: #14532d; border-color: #14532d; color: var(--ok-txt); } .pasos .ahora { color: var(--txt); } .pasos .ahora .c { border-color: var(--link); }
  details.mas { position: relative; } details.mas summary { list-style: none; } details.mas summary::-webkit-details-marker { display: none; }
  details.mas .lista { position: absolute; right: 0; bottom: 48px; z-index: 5; min-width: 190px; padding: 6px; border-radius: 10px;
                       border: 1px solid var(--borde2); background: #1b1f27; box-shadow: 0 10px 30px rgba(0,0,0,.4); display: flex; flex-direction: column; }
  details.mas .lista button { justify-content: flex-start; border: 0; width: 100%; }
  details.mas .lista button:hover { background: #262c37; }
</style>
</head>
<body>
<?php menu('mapas'); ?>
<main class="pagina">
  <div class="titulo">
    <div><h1>Mapas</h1><p class="sub" style="margin:4px 0 0;font-size:15px">Cada mapa tiene su propia lista de hosts y se actualiza por separado.</p></div>
    <div class="acciones">
      <label style="display:flex;align-items:center;gap:8px;height:42px;padding:0 12px;border-radius:8px;border:1px solid var(--borde2);background:var(--panel);color:var(--sub)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <span class="oculto-lect">Buscar mapa</span>
        <input id="buscar" placeholder="Buscar mapa" oninput="filtrar(this.value)" style="width:200px;border:0;background:transparent;padding:0;min-height:0;outline:none"></label>
      <a class="btn prim" href="nuevo.php" style="height:42px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Nuevo mapa</a>
    </div>
  </div>
  <?php if ($flash): ?><div class="msg ok" role="status"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if (!$mapas): ?><div class="tarjeta vacio">Todavía no hay mapas. Usa <b>Nuevo mapa</b> para crear el primero.</div><?php endif; ?>

  <div class="grilla">
  <?php foreach ($mapas as $m):
    $e = $m['estado']; $res = $m['res'];
    $corre = $m['cola'] || ($e['estado'] ?? '') === 'ejecutando';
    $nucleo = count(array_filter($m['hosts'], fn($x) => in_array($x['tipo'] ?? '', ['router', 'switch'], true)));
    $idj = h(json_encode($m['id'])); $nombre_js = h(json_encode($m['titulo'])); ?>
    <article class="tarjeta mapa<?= $corre ? ' corre' : '' ?><?= !$res ? ' nuevo' : '' ?>" data-nombre="<?= h(mb_strtolower($m['titulo'] . ' ' . $m['id'])) ?>"
             data-id="<?= h($m['id']) ?>" data-corre="<?= $corre ? '1' : '' ?>">
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:14px;flex:1">
        <div style="display:flex;align-items:center;gap:10px">
          <h2 style="margin:0;font-size:18px;font-weight:600;overflow:hidden;text-overflow:ellipsis"><?= h($m['titulo']) ?></h2>
          <span style="margin-left:auto" class="estado-pill">
          <?php if ($corre): ?><span class="pill run"><span class="p"></span><?= ($e['estado'] ?? '') !== 'ejecutando' ? 'En cola' : 'Actualizando' ?></span>
          <?php elseif (($e['estado'] ?? '') === 'error'): ?><span class="pill err"><span class="p"></span>Error al actualizar</span>
          <?php elseif ($res): ?><span class="pill ok"><span class="p"></span>Actualizado</span>
          <?php else: ?><span class="pill warn">Sin publicar</span><?php endif; ?></span>
        </div>
        <?php if ($corre): ?><div class="barra-av" aria-hidden="true"><div></div></div><?php endif; ?>

        <?php if ($res): ?>
          <div class="cifras">
            <div><b><?= $res['equipos'] ?></b><span>equipos</span></div>
            <div><b><?= $res['enlaces'] ?></b><span>enlaces</span></div>
            <div><b><?= count($m['hosts']) ?></b><span>hosts cargados</span></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div class="comp" role="img" aria-label="<?= h(implode(', ', array_filter(array_map(fn($k) => $res[$k] ? $res[$k] . ' ' . $NOMBRE[$k] : '', array_keys($COLOR))))) ?>">
              <?php foreach ($COLOR as $k => $c) if ($res[$k]): ?><span style="flex:<?= $res[$k] ?>;background:<?= $c ?>"></span><?php endif; ?></div>
            <div class="ley"><?php foreach ($COLOR as $k => $c) if ($res[$k]): ?><span><i style="background:<?= $c ?>"></i><?= $res[$k] ?> <?= $NOMBRE[$k] ?></span><?php endif; ?></div>
          </div>
          <p class="sub linea" style="margin:0;font-size:13px">
            <?php if ($corre): ?>Leyendo los equipos…
            <?php elseif (($e['estado'] ?? '') === 'error'): ?>Falló la última actualización (<?= h(hace($e['fin'] ?? $e['inicio'] ?? '')) ?>). Revisa el historial en Hosts.
            <?php else: ?>Actualizado <?= h(hace($e['fin'] ?? $m['fecha'])) ?><?= !empty($e['inicio']) && !empty($e['fin']) && duracion($e['inicio'], $e['fin']) ? ' · tardó ' . duracion($e['inicio'], $e['fin']) : '' ?><?php endif; ?><?= !empty($m['auto']) ? ' · automático cada ' . (int)$m['auto'] . ' min' : '' ?></p>
        <?php else: ?>
          <ol class="pasos">
            <li class="si"><span class="c">✓</span>Mapa creado</li>
            <li class="<?= $nucleo ? 'si' : 'ahora' ?>"><span class="c"><?= $nucleo ? '✓' : '' ?></span><?= $nucleo ? "$nucleo router/switch cargados" : 'Agregar al menos un router o switch' ?></li>
            <li class="<?= $nucleo ? 'ahora' : '' ?>"><span class="c"></span><span class="linea"><?= $corre ? 'Dibujando…' : 'Actualizar para dibujarlo' ?></span></li>
          </ol>
        <?php endif; ?>

        <div class="acciones" style="margin-top:auto">
          <?php if ($res): ?><a class="btn prim" style="flex:1;height:44px" href="viewer.php?map=<?= urlencode($m['id']) ?>">Abrir mapa</a><?php endif; ?>
          <?php if ($m['web']): ?>
            <a class="btn" style="height:44px<?= !$res ? ';flex:1;border-color:var(--acento);color:#93c5fd;font-weight:600' : '' ?>" href="admin.php?mapa=<?= urlencode($m['id']) ?>"><?= $res ? 'Hosts' : 'Agregar hosts' ?></a>
            <button type="button" class="icono" style="height:44px;width:44px" aria-label="Actualizar <?= h($m['titulo']) ?>" title="Actualizar ahora"
                    onclick="actualizar(this, <?= $idj ?>)" <?= $corre || (!$nucleo && !$res) ? 'disabled' : '' ?>>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"/></svg></button>
            <details class="mas"><summary class="btn icono" style="height:44px;width:44px" aria-label="Más opciones de <?= h($m['titulo']) ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></summary>
              <div class="lista">
                <button type="button" onclick="renombrar(<?= $idj ?>, <?= $nombre_js ?>)">Cambiar nombre</button>
                <button type="button" onclick="location.href='admin.php?mapa=<?= urlencode($m['id']) ?>#opciones'">Opciones del mapa</button>
                <button type="button" class="peligro" onclick="eliminar(<?= $idj ?>, <?= $nombre_js ?>)">Eliminar mapa</button>
              </div></details>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="sub" id="sin-res" hidden>Ningún mapa coincide con la búsqueda.</p>
</main>
<form method="post" action="admin.php" id="f-acc" hidden>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="volver" value="index.php">
  <input type="hidden" name="accion"><input type="hidden" name="mapa"><input type="hidden" name="titulo">
</form>
<script>
const CSRF = <?= json_encode($csrf) ?>;
function filtrar(t) {
  t = t.trim().toLowerCase(); let n = 0;
  document.querySelectorAll('.mapa').forEach(c => { const v = !t || c.dataset.nombre.includes(t); c.hidden = !v; n += v; });
  document.getElementById('sin-res').hidden = n > 0;
}
function enviar(accion, mapa, titulo) {
  const f = document.getElementById('f-acc');
  f.elements.accion.value = accion; f.elements.mapa.value = mapa; f.elements.titulo.value = titulo || ''; f.submit();
}
function renombrar(id, actual) {
  const t = prompt('Nuevo nombre del mapa (la dirección del mapa no cambia):', actual);
  if (t && t.trim() && t.trim() !== actual) enviar('renombrar_mapa', id, t.trim());
}
function eliminar(id, nombre) {
  if (confirm(`¿Eliminar el mapa "${nombre}"? Se borra el mapa publicado y su orden guardado. Los hosts siguen en la lista. No se puede deshacer.`)) enviar('eliminar_mapa', id);
}
async function actualizar(b, id) {
  b.disabled = true;
  const r = await fetch('admin.php', { method: 'POST', body: new URLSearchParams({ csrf: CSRF, accion: 'actualizar', ajax: '1', mapa: id }) });
  if (r.status === 401) { location.href = 'login.php'; return; }
  const j = await r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
  if (!j.ok) { alert(j.error || 'No se pudo actualizar'); b.disabled = false; return; }
  const c = b.closest('.mapa'); c.dataset.corre = '1'; c.classList.add('corre');
  c.querySelector('.estado-pill').innerHTML = '<span class="pill run"><span class="p"></span>En cola</span>';
  setTimeout(() => seguir(c), 2000);
}
// Mapas que se estan actualizando: se muestra lo que esta haciendo y al terminar se recarga la pagina
async function seguir(c) {
  try {
    const r = await fetch('admin.php?estado=' + encodeURIComponent(c.dataset.id), { cache: 'no-store' });
    if (r.status === 401) { location.href = 'login.php'; return; }
    const e = await r.json();
    const l = c.querySelector('.linea');
    const ult = (e.log || []).filter(x => x.trim()).slice(-1)[0] || '';
    if (e.estado === 'ejecutando') {
      c.querySelector('.estado-pill').innerHTML = '<span class="pill run"><span class="p"></span>Actualizando</span>';
      if (l && ult) l.textContent = ult.replace(/^\$ \S*\//, '').slice(0, 110);
    } else if (!e.en_cola) { location.reload(); return; }
  } catch (err) {}
  setTimeout(() => seguir(c), 4000);
}
document.querySelectorAll('.mapa[data-corre="1"]').forEach(seguir);
document.addEventListener('click', e => document.querySelectorAll('details.mas[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }));
</script>
</body>
</html>
