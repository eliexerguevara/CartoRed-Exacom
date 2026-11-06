<?php
// Hosts (SecureCartography2), al estilo de Zabbix: una lista unica de hosts; cada host dice en que mapas aparece.
// Hosts:  /var/lib/seccart-web/inventario/hosts.json  (nombre, tipo, interfaz SNMP, puertos bloqueados, mapas)
// Mapas:  /var/lib/seccart-web/mapas/<id>.json        (nombre visible, opciones, descubiertos ocultos)
// Las solicitudes van a /var/lib/seccart-web/cola; el servicio seccart-web (root) recorre, anexa y publica.
require __DIR__ . '/lib.php';
requiere_login();
const BASE = '/var/lib/seccart-web';
const INVENTARIO = BASE . '/inventario/hosts.json';
const ENLACES = BASE . '/inventario/enlaces.json';   // enlaces manuales: [{a, pa, b, pb}] por IP
const PUBLICADOS = '/var/www/html/mapa/data';
const POSICIONES = BASE . '/posiciones';            // orden guardado con el boton "Guardar" del visor
const RE_MAPA = '/^[A-Za-z0-9_-]{1,40}$/';
const RE_EQUIPO = '/^[A-Za-z0-9 ._()\-]{1,60}$/';
const RE_CRED = '/^[A-Za-z0-9_-]{1,40}$/';
const RE_COMM = '/^[\x21-\x7e]{1,64}$/';
const TIPOS = ['router', 'switch', 'acceso', 'otro', 'olt'];
const RE_PUERTO = '/^[\x20-\x7e]{1,64}$/';
const RE_TITULO = '/^[^<>\x00-\x1f]{1,60}$/u';
const RE_MAC = '/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/';

$csrf = $_SESSION['csrf'];

function ip_ok($ip) { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; }
function mapas() {
    $r = [];
    foreach (glob(BASE . '/mapas/*.json') ?: [] as $f) $r[] = basename($f, '.json');
    sort($r);
    return $r;
}
function leer_json($f, $def) {
    if (!is_file($f)) return $def;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : $def;
}
function escribir_atomico($f, $contenido) {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, $contenido);
    chmod($tmp, 0660);
    rename($tmp, $f);
}
function encolar($sol) {
    $f = BASE . '/cola/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
    escribir_atomico($f, json_encode($sol));
}
function json_out($d) { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($d); exit; }
const JSON_GUARDAR = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

// Lista unica de hosts (la crea el servicio al migrar desde las listas por mapa)
function leer_hosts() { return array_values(leer_json(INVENTARIO, ['hosts' => []])['hosts'] ?? []); }
function guardar_hosts($hosts) { escribir_atomico(INVENTARIO, json_encode(['hosts' => array_values($hosts)], JSON_GUARDAR)); }
// Enlaces manuales entre equipos (para los que no tienen SNMP)
function leer_enlaces() { return array_values(leer_json(ENLACES, ['enlaces' => []])['enlaces'] ?? []); }
function guardar_enlaces($e) { escribir_atomico(ENLACES, json_encode(['enlaces' => array_values($e)], JSON_GUARDAR)); }
function toca($e, $ip) { return ($e['a'] ?? '') === $ip || ($e['b'] ?? '') === $ip; }
function hosts_de_mapa($hosts, $mapa) { return array_values(array_filter($hosts, fn($x) => in_array($mapa, $x['mapas'] ?? [], true))); }

// Configuracion de un mapa (sin hosts): titulo, opciones y descubiertos ocultos
function leer_cfg($mapa) { $c = leer_json(BASE . "/mapas/$mapa.json", []); unset($c['hosts'], $c['nucleo'], $c['acceso']); return $c; }
function guardar_cfg($mapa, $cfg) {
    $cfg['nombre'] = $mapa;
    unset($cfg['hosts']);
    escribir_atomico(BASE . "/mapas/$mapa.json", json_encode($cfg, JSON_GUARDAR));
}
function titulo_de($mapa) { $c = leer_json(BASE . "/mapas/$mapa.json", []); return trim($c['titulo'] ?? '') ?: $mapa; }
function titulos() { $r = []; foreach (mapas() as $m) $r[$m] = titulo_de($m); return $r; }
// "Sede Norte (Fibra)" -> "sede-norte-fibra" (identificador para la direccion del mapa)
function slug($t) {
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    $a = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($a !== false ? $a : $t)), '-');
    return substr($a !== '' ? $a : 'mapa', 0, 36);
}

// --- Token para el visor (boton Actualizar y solapa Config) ---
if (isset($_GET['token'])) json_out(['csrf' => $csrf, 'mapas' => mapas(), 'titulos' => titulos(),
                                     'comunidades' => leer_json(BASE . '/comunidades.json', [])]);

// --- Hosts en .csv: formato (plantilla) y descarga ---
const CSV_COLUMNAS = ['NOMBRE', 'IP', 'TIPO DE DISPOSITIVO', 'COMUNIDAD', 'VERSION SNMP', 'BLOQUEO PPP', 'MAPAS'];
function csv_celda($v) { $v = (string)$v; return preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v; }   // sin formulas en Excel
if (isset($_GET['csv']) && in_array($_GET['csv'], ['plantilla', 'hosts'], true)) {
    $tit = titulos();
    $nt = ['router' => 'Router', 'switch' => 'Switch', 'acceso' => 'Acceso', 'otro' => 'Otro', 'olt' => 'OLT'];
    $filas = [];
    if ($_GET['csv'] === 'plantilla') {
        $m1 = $tit ? array_values($tit)[0] : 'Mi mapa';
        $filas = [['Router-Principal', '192.168.1.1', 'Router', 'public', 'v2c', 'SI', $m1],
                  ['SW-Distribucion-01', '192.168.1.2', 'Switch', '', 'v2c', 'SI', $m1],
                  ['SW-Acceso-01', '192.168.1.10', 'Acceso', '', 'v2c', 'SI', $m1],
                  ['NVR-Camaras', '192.168.1.50', 'Otro', '', 'v1', 'NO', '']];
        $archivo = 'formato-hosts.csv';
    } else {
        $solo = preg_match(RE_MAPA, $_GET['mapa'] ?? '') && in_array($_GET['mapa'], mapas(), true) ? $_GET['mapa'] : '';
        foreach (leer_hosts() as $x) {
            if ($solo !== '' && !in_array($solo, $x['mapas'] ?? [], true)) continue;
            $filas[] = [$x['nombre'] ?? '', $x['ip'] ?? '', $nt[$x['tipo'] ?? ''] ?? 'Acceso', $x['community'] ?? '',
                        $x['version'] ?? 'v2c', ($x['bloqueo_ppp'] ?? true) === false ? 'NO' : 'SI',
                        implode(', ', array_map(fn($mm) => $tit[$mm] ?? $mm, $x['mapas'] ?? []))];
        }
        $archivo = 'hosts' . ($solo !== '' ? "-$solo" : '') . '-' . date('Ymd-Hi') . '.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                 // BOM: Excel abre bien los acentos
    fputcsv($out, CSV_COLUMNAS, ';', '"', '');
    foreach ($filas as $f) fputcsv($out, array_map('csv_celda', $f), ';', '"', '');
    exit;
}

// --- Orden guardado del mapa (boton "Guardar" del visor) ---
if (isset($_GET['posiciones']) && preg_match(RE_MAPA, $_GET['posiciones'])) {
    $p = leer_json(POSICIONES . '/' . $_GET['posiciones'] . '.json', []);
    $c = in_array($_GET['posiciones'], mapas(), true) ? leer_cfg($_GET['posiciones']) : [];
    $fondo = !empty($c['fondo']['tipo']) ? $c['fondo'] : null;
    json_out(['fecha' => $p['fecha'] ?? '', 'pos' => (object)($p['pos'] ?? []), 'fondo' => $fondo]);
}

// --- Estado de la ultima actualizacion (JSON) ---
if (isset($_GET['estado']) && preg_match(RE_MAPA, $_GET['estado'])) {
    $e = leer_json(BASE . '/estado/' . $_GET['estado'] . '.json', ['estado' => 'sin datos']);
    $e['en_cola'] = 0;   // solo las solicitudes de este mapa
    foreach (glob(BASE . '/cola/*.json') ?: [] as $fc) {
        $s = json_decode((string)@file_get_contents($fc), true);
        if (($s['mapa'] ?? '') === $_GET['estado']) $e['en_cola']++;
    }
    $e['auto_min'] = (int)(leer_json(BASE . '/mapas/' . $_GET['estado'] . '.json', [])['auto_min'] ?? 0);
    json_out($e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $origen = parse_url($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    if (!hash_equals($csrf, $_POST['csrf'] ?? '') || ($origen && $origen !== parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST))) {
        http_response_code(403); exit('Solicitud no válida');
    }
    $accion = $_POST['accion'] ?? '';
    $m = $_POST['mapa'] ?? '';
    $existe = preg_match(RE_MAPA, $m) && is_file(BASE . "/mapas/$m.json");
    $volver = 'Location: admin.php?mapa=' . urlencode($existe ? $m : '');
    if (!empty($_POST['volver'])) $volver = 'Location: ' . volver_seguro($_POST['volver']);
    $forzar = '';   // importacion desde el asistente: todos los hosts del .csv van al mapa nuevo

    if ($accion === 'nuevo_mapa') {
        // Asistente (nuevo.php): nombre, origen de los hosts y opciones de descubrimiento
        $t = trim($_POST['titulo'] ?? '');
        $origen = $_POST['origen'] ?? (($_POST['copiar_de'] ?? '') !== '' ? 'copiar' : 'vacio');
        $volver_err = 'Location: nuevo.php';
        if (!preg_match(RE_TITULO, $t)) { $_SESSION['flash_err'] = 'Escribe un nombre para el mapa (hasta 60 caracteres, sin < ni >).'; header($volver_err); exit; }
        if (!in_array($origen, ['vacio', 'lista', 'copiar', 'csv'], true)) $origen = 'vacio';
        if ($origen !== 'vacio' && !is_file(INVENTARIO)) { $_SESSION['flash_err'] = 'La lista de hosts todavía no se creó; espera unos segundos.'; header($volver_err); exit; }
        $elegidos = array_values(array_filter((array)($_POST['hosts'] ?? []), fn($x) => is_string($x) && ip_ok($x)));
        $base = $_POST['copiar_de'] ?? '';
        if ($origen === 'lista' && !$elegidos) { $_SESSION['flash_err'] = 'Elige al menos un host de la lista, o usa "Empezar vacío".'; header($volver_err); exit; }
        if ($origen === 'copiar' && !(preg_match(RE_MAPA, $base) && is_file(BASE . "/mapas/$base.json"))) { $_SESSION['flash_err'] = 'Elige el mapa del que se copian los hosts.'; header($volver_err); exit; }
        if ($origen === 'csv' && !is_uploaded_file($_FILES['csv']['tmp_name'] ?? '')) { $_SESSION['flash_err'] = 'Elige el archivo .csv.'; header($volver_err); exit; }
        $id = slug($t); $i = 2;
        while (is_file(BASE . "/mapas/$id.json") || is_file(PUBLICADOS . "/$id.json")) $id = substr(slug($t), 0, 33) . '-' . $i++;
        $cfg = isset($_POST['profundidad'])
            ? ['profundidad' => max(0, min(3, (int)$_POST['profundidad'])), 'auto_vecinos' => !empty($_POST['auto_vecinos']),
               'barrido' => !empty($_POST['barrido']), 'ocultar_ppp' => !empty($_POST['ocultar_ppp'])]
            : ['profundidad' => 0, 'auto_vecinos' => false, 'barrido' => true, 'ocultar_ppp' => true];
        $cfg['auto_min'] = !empty($_POST['auto']) ? 10 : 0;
        $n = 0;
        if ($origen === 'copiar' || $origen === 'lista') {
            $hosts = leer_hosts();
            foreach ($hosts as &$x) {
                $va = $origen === 'copiar' ? in_array($base, $x['mapas'] ?? [], true) : in_array($x['ip'] ?? '', $elegidos, true);
                if ($va) { $x['mapas'][] = $id; $n++; }
            }
            unset($x);
            guardar_hosts($hosts);
        }
        $cfg['titulo'] = $t;
        guardar_cfg($id, $cfg);
        if ($origen === 'csv') {
            // sigue en "importar_csv" con el mapa nuevo
            $accion = 'importar_csv'; $forzar = $m = $id; $existe = true; unset($_POST['validar']);
            $volver = 'Location: admin.php?mapa=' . urlencode($id);
        } else {
            $dibujar = !empty($_POST['dibujar']) && $n > 0;
            if ($dibujar) encolar(['accion' => 'actualizar', 'mapa' => $id]);
            $_SESSION['flash'] = "Mapa \"$t\" creado" . ($n ? " con $n hosts" : '') . '.' .
                ($dibujar ? ' Se está dibujando: tarda unos minutos.' : ($n ? ' Pulsa ⟳ Actualizar para dibujarlo.' : ' Agrega al menos un router o switch con "Crear host" o desde un host existente (menú Mapas).'));
            header($dibujar ? 'Location: index.php' : 'Location: admin.php?mapa=' . urlencode($id)); exit;
        }
    }

    if ($accion === 'host_guardar') {
        // Alta o edicion de un host (desde esta pagina o desde la solapa Config del visor)
        $nom = trim($_POST['nombre'] ?? ''); $ip = trim($_POST['ip'] ?? ''); $orig = trim($_POST['ip_original'] ?? '');
        $tipo = $_POST['tipo'] ?? 'acceso'; $ver = $_POST['version'] ?? 'v2c';
        $puerto = trim($_POST['puerto'] ?? '161'); $com = $_POST['community'] ?? '';
        $todos = mapas();
        $err = null;
        if (!is_file(INVENTARIO)) $err = 'La lista de hosts todavía no se creó; espera unos segundos y vuelve a intentar.';
        elseif (!preg_match(RE_EQUIPO, $nom)) $err = 'Nombre inválido (letras, números, espacios, . _ - ( )).';
        elseif (!ip_ok($ip)) $err = 'IP inválida.';
        elseif (!in_array($tipo, TIPOS, true)) $err = 'Tipo de dispositivo inválido.';
        elseif (!ctype_digit($puerto) || (int)$puerto < 1 || (int)$puerto > 65535) $err = 'Puerto SNMP inválido (1-65535).';
        elseif (!in_array($ver, ['v1', 'v2c'], true)) $err = 'Versión SNMP inválida.';
        elseif ($com !== '' && !preg_match(RE_COMM, $com)) $err = 'Community inválida (sin espacios, máx. 64 caracteres).';
        if (!$err) {
            $hosts = leer_hosts();
            // edicion: se reemplaza el host con la IP original; alta: no se reemplaza nada (una IP repetida es error)
            $resto = $orig === '' ? $hosts : array_values(array_filter($hosts, fn($x) => ($x['ip'] ?? '') !== $orig));
            foreach ($resto as $x) {
                if (($x['ip'] ?? '') === $ip) { $err = "La IP $ip ya la tiene el host \"{$x['nombre']}\"."; break; }
                if (strcasecmp($x['nombre'] ?? '', $nom) === 0) { $err = "Ya existe un host llamado \"$nom\"."; break; }
            }
        }
        // enlaces manuales de la solapa Enlaces: cada uno {pa, b, pb} con este host como extremo "a"
        $enl_nuevos = null;
        if (!$err && !empty($_POST['enlaces_enviados'])) {
            $enl_nuevos = [];
            foreach ((array)($_POST['enlaces'] ?? []) as $txt) {
                $e = is_string($txt) ? json_decode($txt, true) : null;
                $b = trim($e['b'] ?? ''); $pa = trim($e['pa'] ?? ''); $pb = trim($e['pb'] ?? '');
                if (!is_array($e) || !ip_ok($b)) { $err = 'Enlace inválido: elige el equipo de destino.'; break; }
                if ($b === $ip) { $err = 'Un enlace no puede ir del host a sí mismo.'; break; }
                if (($pa !== '' && !preg_match(RE_PUERTO, $pa)) || ($pb !== '' && !preg_match(RE_PUERTO, $pb))) { $err = 'Nombre de puerto inválido en un enlace.'; break; }
                $enl_nuevos[] = ['a' => $ip, 'pa' => $pa, 'b' => $b, 'pb' => $pb,
                                 'rol' => ($e['rol'] ?? '') === 'respaldo' ? 'respaldo' : 'principal'];
            }
            if (count($enl_nuevos) > 200) $err = 'Demasiados enlaces para un host (máx. 200).';
        }
        if ($err) json_out(['ok' => false, 'error' => $err]);
        $nuevo = count($resto) === count($hosts);
        $previo = $nuevo ? [] : (array_values(array_filter($hosts, fn($x) => ($x['ip'] ?? '') === $orig))[0] ?? []);
        $host = ['nombre' => $nom, 'tipo' => $tipo, 'ip' => $ip, 'puerto' => (int)$puerto, 'version' => $ver, 'community' => $com];
        // Mapas donde aparece: del menu de la ventana; desde el visor (no lo envia) se conservan, o el mapa del visor si es nuevo
        if (!empty($_POST['mapas_enviados'])) $host['mapas'] = array_values(array_intersect($todos, (array)($_POST['mapas'] ?? [])));
        else $host['mapas'] = $previo['mapas'] ?? ($existe ? [$m] : []);
        if (!empty($_POST['puertos_enviados'])) {
            $bl = array_values(array_unique(array_filter((array)($_POST['bloqueados'] ?? []),
                  fn($x) => is_string($x) && preg_match(RE_PUERTO, $x))));
        } else $bl = $previo['bloqueados'] ?? [];
        if (in_array($tipo, ['router', 'switch'], true) && $bl) $host['bloqueados'] = array_slice($bl, 0, 300);
        // "usar solo enlaces manuales" (no aplica a router/switch, que se recorren por SNMP)
        // "solo dibujar": se dibuja en el mapa pero nunca se consulta (ni SNMP ni ping); no para router/switch
        $sin = !empty($_POST['sin_escaneo_enviado']) ? !empty($_POST['sin_escaneo']) : !empty($previo['sin_escaneo']);
        if ($sin && !in_array($tipo, ['router', 'switch'], true)) $host['sin_escaneo'] = true;
        $solo = !empty($_POST['enlaces_enviados']) ? !empty($_POST['solo_manuales']) : !empty($previo['solo_manuales']);
        if ($solo && !in_array($tipo, ['router', 'switch'], true)) $host['solo_manuales'] = true;
        if ($tipo === 'olt') $host['solo_manuales'] = true;     // OLT: sus conexiones siempre son manuales
        // bloqueo PPP (por defecto SI): solo se guarda cuando es NO
        $ppp = !empty($_POST['ppp_enviado']) ? !empty($_POST['bloqueo_ppp']) : (($previo['bloqueo_ppp'] ?? true) !== false);
        if (!$ppp) $host['bloqueo_ppp'] = false;
        // VLANs del bridge por la API de RouterOS (solo router/switch; apagado por defecto)
        $api = !empty($_POST['api_enviado']) ? !empty($_POST['api_vlans']) : !empty($previo['api_vlans']);
        if ($api && in_array($tipo, ['router', 'switch'], true)) $host['api_vlans'] = true;
        // conservar la posicion del host editado
        $pos = $orig === '' ? false : array_search($orig, array_column($hosts, 'ip'), true);
        if ($pos === false) $resto[] = $host; else array_splice($resto, $pos, 0, [$host]);
        guardar_hosts($resto);
        // enlaces manuales: se reemplazan los de este host; si solo cambio la IP, se renombra en sus enlaces
        $enlaces = leer_enlaces(); $otros = [];
        if ($enl_nuevos !== null || ($orig !== '' && $orig !== $ip)) {
            // alta: se suman a los que ya hubiera con esa IP (p.ej. de cuando era un equipo descubierto)
            $viejos = $orig === '' ? [] : array_filter($enlaces, fn($e) => toca($e, $orig));
            foreach ($viejos as $e) $otros[] = ($e['a'] === $orig) ? $e['b'] : $e['a'];
            $resto_e = $orig === '' ? $enlaces : array_values(array_filter($enlaces, fn($e) => !toca($e, $orig)));
            if ($enl_nuevos === null)
                foreach ($viejos as $e) $enl_nuevos[] = ['a' => $e['a'] === $orig ? $ip : $e['a'], 'pa' => $e['pa'] ?? '',
                                                         'b' => $e['b'] === $orig ? $ip : $e['b'], 'pb' => $e['pb'] ?? '',
                                                         'rol' => $e['rol'] ?? 'principal'];
            // sin duplicados (mismo par y mismos puertos, en cualquier sentido)
            $vistos = [];
            foreach (array_merge($resto_e, $enl_nuevos) as $e) {
                $k1 = "{$e['a']}|{$e['pa']}|{$e['b']}|{$e['pb']}"; $k2 = "{$e['b']}|{$e['pb']}|{$e['a']}|{$e['pa']}";
                if (isset($vistos[$k1]) || isset($vistos[$k2])) continue;
                $vistos[$k1] = $e;
            }
            guardar_enlaces(array_values($vistos));
            foreach ($enl_nuevos as $e) $otros[] = $e['b'];
        }
        // mapas afectados: donde estaba, donde esta ahora y los del otro extremo de sus enlaces
        $afectados = array_merge($previo['mapas'] ?? [], $host['mapas']);
        foreach ($resto as $x) if (in_array($x['ip'] ?? '', $otros, true)) $afectados = array_merge($afectados, $x['mapas'] ?? []);
        $afectados = array_values(array_intersect(array_unique($afectados), $todos));
        $msg = ($nuevo ? "Host \"$nom\" agregado." : "Host \"$nom\" actualizado.");
        if (!$host['mapas']) $msg .= ' No está en ningún mapa.';
        if (!empty($_POST['aplicar']) && $afectados) {
            foreach ($afectados as $am) encolar(['accion' => 'actualizar', 'mapa' => $am]);
            $msg .= count($afectados) > 1 ? ' Actualizando ' . count($afectados) . ' mapas…' : ' Actualizando el mapa…';
        } elseif ($afectados) $msg .= ' Pulsa ⟳ Actualizar mapa para aplicarlo' . (count($afectados) > 1 ? ' (en cada mapa afectado).' : '.');
        $_SESSION['flash'] = $msg;
        json_out(['ok' => true, 'mensaje' => $msg, 'nuevo' => $nuevo]);

    } elseif ($accion === 'importar_csv') {
        // Alta / actualizacion masiva de hosts. Clave: la IP (existe = se actualiza; si no, se agrega)
        $f = $_FILES['csv']['tmp_name'] ?? '';
        $falla = function ($t) use ($volver) { $_SESSION['flash_err'] = $t; header($volver); exit; };
        if (!is_file(INVENTARIO)) $falla('La lista de hosts todavía no se creó.');
        if (!$f || !is_uploaded_file($f)) $falla('Elige un archivo .csv para subir.');
        if (filesize($f) > 2 * 1024 * 1024) $falla('El archivo es demasiado grande (máximo 2 MB).');
        $txt = file_get_contents($f);
        if (strncmp($txt, "\xEF\xBB\xBF", 3) === 0) $txt = substr($txt, 3);
        if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        $lineas = array_values(array_filter(preg_split('/\r\n|\n|\r/', $txt), fn($l) => trim($l, " \t;,") !== ''));
        if (count($lineas) < 2) $falla('El archivo no tiene filas de hosts (debajo de la fila de títulos).');
        if (count($lineas) > 5001) $falla('Máximo 5000 hosts por archivo.');
        $cab = $lineas[0];
        $nsc = substr_count($cab, ';'); $nco = substr_count($cab, ','); $ntb = substr_count($cab, "\t");
        $sep = ($nsc >= $nco && $nsc >= $ntb) ? ';' : ($ntb > $nco ? "\t" : ',');
        $norm = fn($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper(strtr(trim((string)$s),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N'])));
        $low = fn($s) => mb_strtolower(trim((string)$s));
        $alias = ['NOMBRE' => 'nombre', 'IP' => 'ip', 'DIRECCIONIP' => 'ip', 'TIPODEDISPOSITIVO' => 'tipo', 'TIPO' => 'tipo',
                  'COMUNIDAD' => 'community', 'COMMUNITY' => 'community', 'COMUNIDADSNMP' => 'community',
                  'VERSIONSNMP' => 'version', 'VERSION' => 'version', 'SNMP' => 'version',
                  'BLOQUEOPPP' => 'ppp', 'BLOCKEOPPP' => 'ppp', 'BLOQUEARPPP' => 'ppp', 'PPP' => 'ppp', 'MAPAS' => 'mapas', 'MAPA' => 'mapas'];
        $col = [];
        foreach (str_getcsv($cab, $sep, '"', '') as $i => $hd) {
            $k = $alias[$norm($hd)] ?? null;
            if ($k && !isset($col[$k])) $col[$k] = $i;
        }
        if (!isset($col['nombre'], $col['ip'])) $falla('Faltan las columnas NOMBRE e IP en la primera fila. Descarga el formato para ver las columnas.');
        $tipos = ['ROUTER' => 'router', 'SWITCH' => 'switch', 'SWITCHDEDISTRIBUCION' => 'switch', 'DISTRIBUCION' => 'switch',
                  'ACCESO' => 'acceso', 'SWITCHDEACCESO' => 'acceso', 'OTRO' => 'otro', 'OLT' => 'olt'];
        $por_tit = [];
        foreach (titulos() as $id => $t) { $por_tit[$low($id)] = $id; $por_tit[$low($t)] = $id; }
        $hosts = leer_hosts(); $por_ip = [];
        foreach ($hosts as $i => $x) $por_ip[$x['ip'] ?? ''] = $i;
        $nuevos = $act = 0; $errores = $avisos = $vistos_ip = $vistos_nom = [];
        foreach (array_slice($lineas, 1) as $n => $l) {
            $fila = $n + 2; $c = str_getcsv($l, $sep, '"', '');
            $v = function ($k) use ($col, $c) {
                if (!isset($col[$k])) return null;
                $s = trim((string)($c[$col[$k]] ?? ''));
                return preg_match("/^'[=+\-@]/", $s) ? substr($s, 1) : $s;
            };
            $nom = $v('nombre'); $ip = $v('ip');
            $tt = $v('tipo'); $tipo = ($tt === null || $tt === '') ? null : ($tipos[$norm($tt)] ?? false);
            $com = $v('community');
            $vt = $v('version'); $vn = $norm($vt);
            $ver = ($vt === null || $vt === '') ? null : (in_array($vn, ['1', 'V1', 'SNMPV1'], true) ? 'v1'
                 : (in_array($vn, ['2', '2C', 'V2', 'V2C', 'SNMPV2', 'SNMPV2C'], true) ? 'v2c' : false));
            $pt = $v('ppp'); $pn = $norm($pt);
            $ppp = ($pt === null || $pt === '') ? null : (in_array($pn, ['SI', 'S', 'YES', 'Y', '1', 'TRUE', 'VERDADERO', 'X'], true) ? true
                 : (in_array($pn, ['NO', 'N', '0', 'FALSE', 'FALSO'], true) ? false : 'x'));
            $e = null;
            if (!preg_match(RE_EQUIPO, $nom)) $e = 'nombre inválido o vacío (letras, números, espacios, . _ - ( ))';
            elseif (!ip_ok($ip)) $e = "IP inválida \"$ip\"";
            elseif ($tipo === false) $e = "tipo \"$tt\" desconocido (Router, Switch, Acceso u Otro)";
            elseif ($ver === false) $e = "versión SNMP \"$vt\" desconocida (v2c o v1)";
            elseif ($com !== null && $com !== '' && !preg_match(RE_COMM, $com)) $e = 'comunidad inválida (sin espacios, máx. 64)';
            elseif ($ppp === 'x') $e = "BLOQUEO PPP \"$pt\" debe ser SI o NO";
            elseif (isset($vistos_ip[$ip])) $e = "IP $ip repetida en el archivo (fila {$vistos_ip[$ip]})";
            elseif (isset($vistos_nom[$low($nom)])) $e = "nombre \"$nom\" repetido en el archivo (fila {$vistos_nom[$low($nom)]})";
            else foreach ($hosts as $x) if (strcasecmp($x['nombre'] ?? '', $nom) === 0 && ($x['ip'] ?? '') !== $ip) {
                $e = "ya existe otro host \"$nom\" con la IP {$x['ip']}"; break;
            }
            if ($e) { $errores[] = "fila $fila: $e"; continue; }
            $vistos_ip[$ip] = $fila; $vistos_nom[$low($nom)] = $fila;
            $mapas_h = null; $mt = $v('mapas');
            if ($mt !== null) {
                $mapas_h = [];
                $partes = $mt === '' ? [] : (isset($por_tit[$low($mt)]) ? [$mt] : preg_split('/\s*[,;|]\s*/', $mt));
                foreach ($partes as $p) {
                    if (trim($p) === '') continue;
                    if (isset($por_tit[$low($p)])) $mapas_h[] = $por_tit[$low($p)];
                    else $avisos[] = "fila $fila: el mapa \"$p\" no existe (créalo primero)";
                }
                $mapas_h = array_values(array_unique($mapas_h));
            }
            $i = $por_ip[$ip] ?? null;
            $h = $i === null ? ['nombre' => $nom, 'tipo' => 'acceso', 'ip' => $ip, 'puerto' => 161, 'version' => 'v2c',
                                'community' => '', 'mapas' => []] : $hosts[$i];
            $h['nombre'] = $nom;
            if ($tipo) $h['tipo'] = $tipo;
            if ($ver) $h['version'] = $ver;
            if ($com !== null) $h['community'] = $com;
            if ($ppp === true) unset($h['bloqueo_ppp']); elseif ($ppp === false) $h['bloqueo_ppp'] = false;
            if ($mapas_h !== null) $h['mapas'] = $mapas_h;
            if ($forzar !== '' && !in_array($forzar, $h['mapas'] ?? [], true)) $h['mapas'][] = $forzar;
            if (in_array($h['tipo'], ['router', 'switch'], true)) unset($h['solo_manuales']); else unset($h['bloqueados']);
            if ($i === null) { $hosts[] = $h; $por_ip[$ip] = count($hosts) - 1; $nuevos++; } else { $hosts[$i] = $h; $act++; }
        }
        $lista = fn($a) => implode(' · ', array_slice($a, 0, 15)) . (count($a) > 15 ? ' · … y ' . (count($a) - 15) . ' más' : '');
        if (!empty($_POST['validar'])) {
            $msg = "Revisión del .csv (no se guardó nada): se agregarían $nuevos hosts y se actualizarían $act.";
        } else {
            if ($nuevos || $act) {
                copy(INVENTARIO, BASE . '/inventario/hosts.antes-csv.json'); chmod(BASE . '/inventario/hosts.antes-csv.json', 0660);   // copia de lo anterior
                guardar_hosts($hosts);
            }
            $msg = "Importación: $nuevos hosts agregados y $act actualizados." . (($nuevos || $act) ? ' Pulsa ⟳ Actualizar en cada mapa para aplicarlo.' : '');
            if ($forzar !== '') {
                $msg = 'Mapa "' . titulo_de($forzar) . "\" creado. Del .csv: $nuevos hosts agregados y $act actualizados.";
                if (($nuevos || $act) && !empty($_POST['dibujar'])) {
                    encolar(['accion' => 'actualizar', 'mapa' => $forzar]);
                    $msg .= ' Se está dibujando: tarda unos minutos.';
                    $volver = 'Location: index.php';
                }
            }
        }
        if ($avisos) $msg .= ' Avisos: ' . $lista($avisos);
        $_SESSION['flash'] = $msg;
        if ($errores) $_SESSION['flash_err'] = count($errores) . ' filas con error (no se importaron): ' . $lista($errores);
        header($volver); exit;

    } elseif ($accion === 'host_borrar') {
        $ip = $_POST['ip'] ?? '';
        guardar_hosts(array_filter(leer_hosts(), fn($x) => ($x['ip'] ?? '') !== $ip));
        if ($ip !== '' && is_file(ENLACES)) guardar_enlaces(array_filter(leer_enlaces(), fn($e) => !toca($e, $ip)));
        $_SESSION['flash'] = "Host $ip eliminado de todos los mapas. Pulsa ⟳ Actualizar mapa para aplicarlo.";
        json_out(['ok' => true]);

    } elseif ($accion === 'hosts_masivo') {
        $ips = array_values(array_unique(array_filter((array)($_POST['ips'] ?? []), fn($x) => is_string($x) && ip_ok($x))));
        $op = $_POST['op'] ?? ''; $val = (string)($_POST['valor'] ?? '');
        $todos = mapas();
        if (!$ips) json_out(['ok' => false, 'error' => 'No elegiste ningún host.']);
        if (in_array($op, ['agregar_mapa', 'quitar_mapa'], true) && !in_array($val, $todos, true)) json_out(['ok' => false, 'error' => 'Elige un mapa.']);
        if ($op === 'comunidad' && $val !== '' && !preg_match(RE_COMM, $val)) json_out(['ok' => false, 'error' => 'Comunidad inválida (sin espacios, máx. 64 caracteres).']);
        if ($op === 'version' && !in_array($val, ['v1', 'v2c'], true)) json_out(['ok' => false, 'error' => 'Versión SNMP inválida.']);
        if (!in_array($op, ['agregar_mapa', 'quitar_mapa', 'comunidad', 'version', 'ppp_si', 'ppp_no', 'eliminar'], true)) json_out(['ok' => false, 'error' => 'Acción desconocida.']);
        $hosts = leer_hosts(); $n = 0; $quedan = [];
        foreach ($hosts as $x) {
            if (!in_array($x['ip'] ?? '', $ips, true)) { $quedan[] = $x; continue; }
            $n++;
            if ($op === 'eliminar') continue;
            if ($op === 'agregar_mapa') $x['mapas'] = array_values(array_unique(array_merge($x['mapas'] ?? [], [$val])));
            elseif ($op === 'quitar_mapa') $x['mapas'] = array_values(array_diff($x['mapas'] ?? [], [$val]));
            elseif ($op === 'comunidad') $x['community'] = $val;
            elseif ($op === 'version') $x['version'] = $val;
            elseif ($op === 'ppp_si') unset($x['bloqueo_ppp']);
            elseif ($op === 'ppp_no') $x['bloqueo_ppp'] = false;
            $quedan[] = $x;
        }
        guardar_hosts($quedan);
        if ($op === 'eliminar' && is_file(ENLACES)) guardar_enlaces(array_filter(leer_enlaces(), fn($e) => !in_array($e['a'] ?? '', $ips, true) && !in_array($e['b'] ?? '', $ips, true)));
        $que = ['agregar_mapa' => 'agregados al mapa "' . titulo_de($val) . '"', 'quitar_mapa' => 'quitados del mapa "' . titulo_de($val) . '"',
                'comunidad' => $val === '' ? 'ahora prueban todas las comunidades guardadas' : 'con comunidad nueva', 'version' => "ahora usan SNMP$val",
                'ppp_si' => 'con Bloqueo PPP', 'ppp_no' => 'sin Bloqueo PPP', 'eliminar' => 'eliminados de la lista y de todos los mapas'][$op];
        $_SESSION['flash'] = "$n host" . ($n === 1 ? '' : 's') . " $que. Pulsa ⟳ Actualizar mapa para aplicarlo.";
        json_out(['ok' => true]);

    } elseif ($accion === 'opciones') {
        if ($existe) {
            $cfg = leer_cfg($m);
            $cfg['profundidad'] = max(0, min(3, (int)($_POST['profundidad'] ?? 0)));
            $cfg['auto_vecinos'] = !empty($_POST['auto_vecinos']);
            $cfg['barrido'] = !empty($_POST['barrido']);
            $cfg['ocultar_ppp'] = !empty($_POST['ocultar_ppp']);
            guardar_cfg($m, $cfg);
            $_SESSION['flash'] = 'Opciones del mapa guardadas.';
        }
        header($volver); exit;

    } elseif ($accion === 'auto') {
        if ($existe) {
            $cfg = leer_cfg($m);
            $n = (int)($_POST['auto_min'] ?? 0);
            $cfg['auto_min'] = in_array($n, [5, 10, 15, 30, 60], true) ? $n : 0;
            guardar_cfg($m, $cfg);
            $_SESSION['flash'] = $cfg['auto_min'] ? "Actualización automática cada {$cfg['auto_min']} minutos. Cada actualización queda en el historial (7 días)."
                                                   : 'Actualización automática apagada. El historial guardado se conserva 7 días.';
        }
        header($volver . '#opciones'); exit;

    } elseif ($accion === 'fondo') {
        if ($existe) {
            $cfg = leer_cfg($m);
            $tipo = $_POST['fondo_tipo'] ?? '';
            if (!in_array($tipo, ['', 'calles', 'oscuro', 'satelite'], true)) $tipo = '';
            $centro = trim($_POST['centro'] ?? '');
            $ok = preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $centro, $mm)
                  && abs((float)$mm[1]) <= 85 && abs((float)$mm[2]) <= 180;
            if ($tipo !== '' && !$ok) {
                $_SESSION['flash_err'] = 'Escribe el centro del mapa como "latitud, longitud" (por ejemplo 10.4806, -66.9036).';
            } else {
                $f = $cfg['fondo'] ?? [];
                $f['tipo'] = $tipo;
                if ($ok) {
                    $nuevo = round((float)$mm[1], 7) != round((float)($f['lat'] ?? 999), 7) || round((float)$mm[2], 7) != round((float)($f['lon'] ?? 999), 7);
                    $f['lat'] = round((float)$mm[1], 7); $f['lon'] = round((float)$mm[2], 7);
                    if ($nuevo || !isset($f['z0'])) $f['z0'] = 16;
                }
                $cfg['fondo'] = $f;
                guardar_cfg($m, $cfg);
                $_SESSION['flash'] = $tipo === '' ? 'Fondo de mapa desactivado.' : 'Fondo de mapa activado. Ábrelo con "Ver mapa"; con "Ajustar mapa" lo alineas con los equipos.';
            }
        }
        header($volver . '#opciones'); exit;

    } elseif ($accion === 'fondo_ajuste') {
        if (!$existe) json_out(['ok' => false, 'error' => 'Mapa desconocido']);
        $lat = $_POST['lat'] ?? ''; $lon = $_POST['lon'] ?? ''; $z0 = $_POST['z0'] ?? ''; $tam = $_POST['tam'] ?? '1';
        if (!is_numeric($lat) || !is_numeric($lon) || !is_numeric($z0) || abs((float)$lat) > 85 || abs((float)$lon) > 180 || $z0 < 4 || $z0 > 24 || !is_numeric($tam) || $tam < 0.2 || $tam > 3)
            json_out(['ok' => false, 'error' => 'Ajuste no válido']);
        $cfg = leer_cfg($m);
        if (empty($cfg['fondo']['tipo'])) json_out(['ok' => false, 'error' => 'El fondo de mapa no está activado']);
        $cfg['fondo']['lat'] = round((float)$lat, 7); $cfg['fondo']['lon'] = round((float)$lon, 7); $cfg['fondo']['z0'] = round((float)$z0, 4);
        $cfg['fondo']['tam'] = round((float)$tam, 2);
        guardar_cfg($m, $cfg);
        json_out(['ok' => true]);

    } elseif ($accion === 'eliminar_mapa') {
        if ($existe) {
            $t = titulo_de($m);
            unlink(BASE . "/mapas/$m.json");
            if (is_file(POSICIONES . "/$m.json")) unlink(POSICIONES . "/$m.json");
            $hosts = leer_hosts();
            foreach ($hosts as &$x) $x['mapas'] = array_values(array_diff($x['mapas'] ?? [], [$m]));
            unset($x);
            guardar_hosts($hosts);
            encolar(['accion' => 'eliminar', 'mapa' => $m]);   // el servicio borra lo publicado
            $_SESSION['flash'] = "Mapa \"$t\" eliminado. Los hosts siguen en la lista.";
        }
        header(!empty($_POST['volver']) ? $volver : 'Location: admin.php'); exit;

    } elseif ($accion === 'renombrar_mapa') {
        $t = trim($_POST['titulo'] ?? '');
        if (!$existe) $_SESSION['flash_err'] = 'Mapa inválido.';
        elseif (!preg_match(RE_TITULO, $t)) $_SESSION['flash_err'] = 'Nombre inválido (hasta 60 caracteres, sin < ni >).';
        else { $cfg = leer_cfg($m); $cfg['titulo'] = $t; guardar_cfg($m, $cfg); $_SESSION['flash'] = "Mapa renombrado a \"$t\"."; }
        header($volver); exit;

    } elseif ($accion === 'ocultar' || $accion === 'mostrar') {
        // Equipos descubiertos automaticamente: ocultarlos (o mostrarlos de nuevo) en este mapa
        $ip = trim($_POST['ip'] ?? ''); $mac = strtolower(trim($_POST['mac'] ?? '')); $nom = trim($_POST['nombre'] ?? '');
        if (!$existe) json_out(['ok' => false, 'error' => 'Este mapa no se administra desde la web.']);
        if (!ip_ok($ip)) $ip = '';
        if (!preg_match(RE_MAC, $mac)) $mac = '';
        if ($ip === '' && $mac === '') json_out(['ok' => false, 'error' => 'El equipo no tiene IP ni MAC para identificarlo.']);
        $cfg = leer_cfg($m); $ocultar = $accion === 'ocultar';
        $cfg['ocultos'] = array_values(array_filter($cfg['ocultos'] ?? [],
            fn($o) => !(($ip !== '' && ($o['ip'] ?? '') === $ip) || ($mac !== '' && ($o['mac'] ?? '') === $mac))));
        if ($ocultar) $cfg['ocultos'][] = ['ip' => $ip, 'mac' => $mac,
            'nombre' => preg_match('/^[^<>\x00-\x1f]{1,80}$/u', $nom) ? $nom : ($ip ?: $mac)];
        guardar_cfg($m, $cfg);
        $msg = ($ocultar ? 'Equipo oculto' : 'Equipo visible de nuevo') . ' en este mapa. Pulsa ⟳ Actualizar mapa para aplicarlo.';
        $_SESSION['flash'] = $msg;
        json_out(['ok' => true, 'mensaje' => $msg]);

    } elseif ($accion === 'api_usuario') {
        $u = trim($_POST['api_usuario'] ?? ''); $p = $_POST['api_password'] ?? '';
        if (!preg_match('/^[A-Za-z0-9._@-]{1,40}$/', $u) || !preg_match('/^[\x20-\x7e]{1,128}$/', $p))
            $_SESSION['flash_err'] = 'Usuario (letras, números, . _ @ -) o contraseña inválidos.';
        else { encolar(['accion' => 'api_usuario', 'usuario' => $u, 'password' => $p]);
               $_SESSION['flash'] = "Usuario API \"$u\" enviado. Se guarda cifrado en unos segundos."; }
        header('Location: comunidades.php'); exit;

    } elseif ($accion === 'api_quitar') {
        encolar(['accion' => 'api_quitar']);
        $_SESSION['flash'] = 'Usuario API quitado. Los equipos vuelven a usar solo SNMP.';
        header('Location: comunidades.php'); exit;

    } elseif ($accion === 'comunidad') {
        $nom = trim($_POST['cred_nombre'] ?? ''); $com = $_POST['cred_community'] ?? '';
        if (!preg_match(RE_CRED, $nom) || !preg_match(RE_COMM, $com)) $_SESSION['flash_err'] = 'Nombre (letras, números, - y _) o comunidad inválidos.';
        else { encolar(['accion' => 'comunidad', 'nombre' => $nom, 'community' => $com]);
               $_SESSION['flash'] = "Comunidad \"$nom\" enviada. Aparecerá en la lista en unos segundos."; }
        header('Location: comunidades.php'); exit;

    } elseif ($accion === 'guardar_posiciones') {
        if (!preg_match(RE_MAPA, $m) || !is_file(PUBLICADOS . "/$m.json")) json_out(['ok' => false, 'error' => 'Mapa desconocido']);
        if (!is_dir(POSICIONES)) json_out(['ok' => false, 'error' => 'Falta la carpeta de posiciones en el servidor']);
        $d = json_decode($_POST['posiciones'] ?? '', true);
        if (!is_array($d) || count($d) > 5000) json_out(['ok' => false, 'error' => 'Posiciones no válidas']);
        $pos = [];
        foreach ($d as $id => $xy) {
            $id = (string)$id;
            if ($id === '' || strlen($id) > 200 || !is_array($xy) || count($xy) !== 2) continue;
            [$x, $y] = array_values($xy);
            if (!is_numeric($x) || !is_numeric($y) || abs($x) > 1e6 || abs($y) > 1e6) continue;
            $pos[$id] = [round((float)$x, 1), round((float)$y, 1)];
        }
        $fecha = date('Y-m-d H:i');
        escribir_atomico(POSICIONES . "/$m.json", json_encode(['fecha' => $fecha, 'pos' => (object)$pos], JSON_UNESCAPED_UNICODE));
        json_out(['ok' => true, 'n' => count($pos), 'fecha' => $fecha]);

    } elseif ($accion === 'borrar_posiciones') {
        if (preg_match(RE_MAPA, $m) && is_file(POSICIONES . "/$m.json")) unlink(POSICIONES . "/$m.json");
        json_out(['ok' => true]);

    } elseif ($accion === 'actualizar') {
        if ($existe) encolar(['accion' => 'actualizar', 'mapa' => $m]);
        if (!empty($_POST['ajax'])) json_out($existe ? ['ok' => true]
            : ['ok' => false, 'error' => 'Este mapa no se administra desde la web (no tiene hosts cargados).']);
        $_SESSION['flash'] = $existe ? 'Actualización del mapa en curso.' : 'Ese mapa no tiene configuración.';
        header($volver); exit;
    }
    http_response_code(400); exit('Acción desconocida');
}

$flash = $_SESSION['flash'] ?? ''; $flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_err']);
$lista_mapas = mapas();
$mapa = isset($_GET['mapa']) && preg_match(RE_MAPA, $_GET['mapa']) && in_array($_GET['mapa'], $lista_mapas, true)
    ? $_GET['mapa'] : ($lista_mapas[0] ?? '');
$cfg = $mapa ? leer_cfg($mapa) : [];
$hosts = leer_hosts();
$sin_inventario = !is_file(INVENTARIO);
$enlaces = leer_enlaces();

// Disponibilidad por IP segun los mapas publicados (primero el mapa actual) y descubiertos del mapa actual
$disp = []; $descubiertos = [];
$ips_hosts = array_column($hosts, 'ip');
foreach (array_unique(array_merge($mapa ? [$mapa] : [], $lista_mapas)) as $pm) {
    foreach (leer_json(PUBLICADOS . "/$pm.json", []) as $nombre => $d) {
        $nd = $d['node_details'] ?? null;
        if (!$nd) continue;
        $md = $nd['metadata'] ?? [];
        $snmp = $md['snmp'] ?? [];
        $info = ['nodo' => $nombre, 'snmp' => ($snmp['version'] ?? '-') !== '-' ? 'ok' : 'no',
                 'estado' => $md['estado'] ?? ((($md['discovered_via'] ?? '') === 'snmp') ? 'SNMP ok (recorrido)' : ''),
                 'version' => $snmp['version'] ?? '', 'community' => $snmp['community'] ?? '',
                 'mac' => ($md['mac'] ?? '') !== '' && ($md['mac'] ?? '') !== '-' ? $md['mac'] : ($md['mac_base'] ?? ''),
                 'marca' => $md['marca'] ?? ($nd['vendor'] ?? ''), 'modelo' => $nd['platform'] ?? ''];
        $ipn = $nd['ip'] ?? '';
        if ($ipn !== '' && !isset($disp[$ipn])) $disp[$ipn] = $info;
        if ($pm === $mapa && ($md['fijo'] ?? null) === false && ($ipn === '' || !in_array($ipn, $ips_hosts, true)))
            $descubiertos[] = ['ip' => $ipn] + $info;
    }
}
// Puertos escaneados de cada router/switch (de cualquier mapa; primero el actual)
$inv = ['fecha' => '', 'equipos' => []];
foreach (array_unique(array_merge($mapa ? [$mapa] : [], $lista_mapas)) as $pm) {
    $p = leer_json(BASE . "/puertos/$pm.json", []);
    $inv['equipos'] += $p['equipos'] ?? [];
    if (!$inv['fecha']) $inv['fecha'] = $p['fecha'] ?? '';
}
$comunidades = leer_json(BASE . '/comunidades.json', []);
$titulos = titulos();
$titulo = $mapa ? ($titulos[$mapa] ?? $mapa) : '';
$J = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?><!DOCTYPE html>
<html lang="es">
<head>
<?php cabeza('Hosts'); ?>
<style>
  td.nom a { font-weight: 500; color: var(--txt); } td.nom a:hover { color: #93c5fd; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 12px; font-weight: 500; }
  .b-ok { background: #0f2a1a; color: var(--ok-txt); } .b-no { background: #2a1215; color: var(--err-txt); }
  .b-gris { background: #232a36; color: var(--sub2); }
  .b-bloq { background: #2a1215; color: var(--err-txt); }
  .mchip { display: inline-block; font-size: 12px; padding: 2px 9px; margin: 1px 4px 1px 0; border-radius: 99px; background: #232a36; color: var(--sub2); }
  .mchip.act { background: var(--acento-bg); color: #bfdbfe; }
  .estado { font-weight: 600; } .estado.ok { color: var(--ok-txt); } .estado.error { color: var(--err-txt); } .estado.ejecutando { color: var(--warn-txt); }
  pre { background: var(--panel2); border: 1px solid var(--borde); border-radius: 8px; padding: 12px; max-height: 420px; overflow: auto;
        font: 12px/1.5 'IBM Plex Mono', ui-monospace, monospace; white-space: pre-wrap; word-break: break-all; margin: 12px 0 0; }
  /* contadores que filtran */
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
  .kpis button { flex-direction: column; align-items: flex-start; gap: 2px; min-height: 0; padding: 14px 16px; border-radius: 10px;
                 border: 1px solid var(--borde); background: var(--panel); text-align: left; }
  .kpis button[aria-pressed="true"] { border: 2px solid var(--acento-borde); background: var(--acento-bg); padding: 13px 15px; }
  .kpis small { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--sub); }
  .kpis small i { width: 8px; height: 8px; border-radius: 50%; } .kpis b { font-size: 26px; font-weight: 600; }
  /* solapas */
  .solapas { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #232833; }
  .solapas button { border: 0; border-radius: 0; border-bottom: 2px solid transparent; min-height: 44px; padding: 0 14px; color: var(--sub); }
  .solapas button[aria-selected="true"] { border-bottom-color: var(--acento-borde); color: var(--txt); font-weight: 600; }
  .solapas .n { color: var(--sub); font-weight: 400; margin-left: 4px; }
  .solapas .nuevo { padding: 1px 7px; border-radius: 99px; background: #1e3a8a; color: #bfdbfe; font-size: 12px; margin-left: 6px; font-weight: 500; }
  [data-panel][hidden] { display: none !important; }
  /* filtros */
  .filtros { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
  .buscador { display: flex; align-items: center; gap: 8px; flex: 1 1 260px; max-width: 360px; height: 42px; padding: 0 12px;
              border-radius: 8px; border: 1px solid var(--borde2); background: var(--panel); color: var(--sub); }
  .buscador input { flex: 1; min-width: 0; border: 0; background: transparent; padding: 0; min-height: 0; outline: none; }
  .chips-f { display: flex; flex-wrap: wrap; gap: 6px; }
  .chips-f button { min-height: 36px; padding: 0 12px; border-radius: 99px; font-size: 13px; color: var(--sub2); }
  .chips-f button[aria-pressed="true"] { border-color: var(--acento-borde); background: var(--acento-bg); color: #bfdbfe; }
  .masivo { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; padding: 10px 14px; border-radius: 10px;
            background: var(--acento-bg); border: 1px solid #1e3a8a; }
  .masivo button, .masivo select { min-height: 36px; font-size: 13px; border-color: #3b5bab; }
  .masivo select { background: #0f1830; }
  tr.marcado td { background: #1a2233; }
  .ico { display: inline-block; width: 26px; height: 22px; border-radius: 5px; flex: none; vertical-align: middle; }
  .ico.router { width: 24px; height: 24px; border-radius: 50%; background: var(--router); } .ico.switch { background: var(--switch); }
  .ico.acceso { background: var(--acceso); } .ico.otro { border: 2px dashed #71717a; } .ico.antena { background: var(--antena); } .ico.servidor { background: var(--servidor); } .ico.olt { background: #be185d; }
  .disp { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; } .disp i { width: 8px; height: 8px; border-radius: 50%; flex: none; }
  .disp.ok { color: var(--ok-txt); } .disp.ok i { background: var(--ok); } .disp.no { color: var(--err-txt); } .disp.no i { background: var(--err); }
  .disp.sin { color: var(--sub); } .disp.sin i { background: var(--sub); } .disp.warn { color: var(--warn-txt); } .disp.warn i { background: var(--warn); }
  .grid-op { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px 24px; }
  .seg { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-radius: 8px; border: 1px solid var(--borde2); overflow: hidden; }
  .seg label { display: flex; align-items: center; justify-content: center; text-align: center; min-height: 44px; padding: 4px; font-size: 14px; color: var(--sub2); cursor: pointer; border-left: 1px solid var(--borde2); }
  .seg label:first-child { border-left: 0; } .seg label:has(input:checked) { background: var(--acento); color: #fff; font-weight: 600; }
  .seg input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  .seg label:has(input:focus-visible) { outline: 2px solid #93c5fd; outline-offset: -3px; }
  /* dialogos */
  dialog { background: var(--panel); color: var(--txt); border: 1px solid var(--borde); padding: 0; }
  dialog::backdrop { background: rgba(0,0,0,.55); }
  dialog.centro { border-radius: 12px; width: min(720px, calc(100vw - 32px)); }
  /* panel lateral del host */
  dialog#dlg { margin: 0 0 0 auto; width: min(620px, 100vw); height: 100vh; max-height: 100vh; border-radius: 0; border-width: 0 0 0 1px; }
  dialog#dlg[open] { display: flex; }
  #dlg-form { display: flex; flex-direction: column; width: 100%; height: 100%; margin: 0; }
  .dlg-tit { display: flex; align-items: flex-start; gap: 14px; padding: 20px 24px 0; }
  .dlg-tit h2 { margin: 0; font-size: 20px; font-weight: 600; }
  .tabs { display: flex; gap: 4px; padding: 0 24px; margin-top: 14px; border-bottom: 1px solid var(--borde); }
  .tabs .tab { border: 0; border-radius: 0; border-bottom: 2px solid transparent; min-height: 44px; padding: 0 12px; color: var(--sub); }
  .tabs .tab.act { border-bottom-color: var(--acento-borde); color: var(--txt); font-weight: 600; }
  .tabs .tab:disabled { opacity: .4; }
  .dlg-cuerpo { flex: 1; overflow: auto; }
  .pane-p[hidden] { display: none !important; }
  .datos { padding: 20px 24px; display: flex; flex-direction: column; gap: 24px; }
  .datos fieldset { margin: 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 14px; }
  .datos legend { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--sub); margin-bottom: 14px; }
  .tipos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
  .tipos label { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px 4px; border-radius: 8px; border: 1px solid var(--borde2);
                 background: var(--panel2); color: var(--sub2); font-size: 13px; cursor: pointer; text-align: center; }
  .tipos label:has(input:checked) { border: 2px solid var(--acento-borde); background: var(--acento-bg); color: var(--txt); padding: 9px 3px; }
  .tipos input { position: absolute; opacity: 0; width: 1px; height: 1px; }
  .tipos label:has(input:focus-visible) { outline: 2px solid #93c5fd; }
  .ayuda { color: var(--sub); font-size: 13px; line-height: 1.45; }
  .iface { display: grid; grid-template-columns: minmax(140px, 1fr) 90px 120px; gap: 12px; }
  .comm { display: flex; gap: 8px; } .comm input { flex: 1; min-width: 0; }
  .info-snmp { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 8px; font-size: 13px; line-height: 1.45; }
  .info-snmp.ok { background: #0f2a1a; border: 1px solid #14532d; color: #bbf7d0; } .info-snmp.no { background: #2a1215; border: 1px solid #7f1d1d; color: var(--err-txt); }
  .info-snmp.sin { background: var(--panel2); border: 1px solid var(--borde); color: var(--sub); }
  .mapas-sel { display: flex; flex-wrap: wrap; gap: 8px; }
  .mapas-sel button { min-height: 36px; padding: 0 12px; border-radius: 99px; font-size: 13px; color: var(--sub2); }
  .mapas-sel button[aria-pressed="true"] { border-color: var(--acento-borde); background: var(--acento-bg); color: #bfdbfe; }
  .dlg-pie { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 14px 24px; border-top: 1px solid var(--borde); background: #14171d; }
  .dlg-pie .der { margin-left: auto; }
  .dlg-pie .chk { display: flex; align-items: center; gap: 8px; color: var(--sub); font-size: 13px; width: 100%; }
  .puertos-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; padding: 16px 24px 6px; }
  .puertos-bar input#p-filtro { flex: 1; min-width: 200px; }
  .puertos-wrap { overflow: auto; margin: 0 24px 16px; border: 1px solid var(--borde); border-radius: 8px; }
  .puertos-wrap th { position: sticky; top: 0; background: #1b1f27; }
  .puertos-wrap td { white-space: normal; font-size: 13px; }
  tr.bloq td { background: rgba(229,83,75,.08); } tr.bloq td.pn { text-decoration: line-through; color: var(--err-txt); }
  .e-sel { width: 100%; min-width: 200px; } .e-p { width: 100%; min-width: 110px; }
  .flecha { color: #a78bfa; text-align: center; }
  .up { color: var(--ok-txt); } .down { color: var(--sub); }
  .chk { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; } .chk input { margin-top: 3px; flex: none; }
  #dlg-err { margin: 0; }
  @media (max-width: 640px) { .iface { grid-template-columns: 1fr 1fr; } .tipos { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
</head>

<body>
<?php menu('hosts'); ?>
<main class="pagina">
  <div class="titulo">
    <h1>Hosts</h1>
    <?php if ($lista_mapas): ?>
    <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--sub)">en
      <select aria-label="Mapa" onchange="location.href='admin.php?mapa=' + encodeURIComponent(this.value) + location.hash" style="font-size:15px;font-weight:500">
        <?php foreach ($titulos as $id => $tt): ?><option value="<?= h($id) ?>" <?= $id === $mapa ? 'selected' : '' ?>><?= h($tt) ?></option><?php endforeach; ?>
      </select></label>
    <?php endif; ?>
    <div class="acciones">
      <?php if ($mapa): ?>
        <?php if (is_file(PUBLICADOS . "/$mapa.json")): ?><a class="btn" style="height:44px" href="viewer.php?map=<?= urlencode($mapa) ?>">Ver mapa</a><?php endif; ?>
        <button style="height:44px" onclick="actualizar(this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"/></svg>Actualizar mapa</button>
        <button style="height:44px" onclick="document.getElementById('dlg-csv').showModal()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M4 20h16"/></svg>Importar / exportar .csv</button>
        <button class="prim" style="height:44px" onclick="abrirHost(null)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Crear host</button>
      <?php else: ?><a class="btn prim" href="nuevo.php">Nuevo mapa</a><?php endif; ?>
    </div>
  </div>
<?php if ($flash): ?><div class="msg ok" role="status"><?= h($flash) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="msg err" role="alert"><?= h($flash_err) ?></div><?php endif; ?>

<?php if (!$mapa): ?>
  <div class="tarjeta vacio">Todavía no hay mapas administrados desde la web. <a href="nuevo.php">Crea el primero</a>.</div>
<?php else: ?>
  <div class="kpis" role="group" aria-label="Filtrar por disponibilidad">
    <button type="button" data-d="" aria-pressed="true"><small>Todos los hosts</small><b id="k-todos">0</b></button>
    <button type="button" data-d="ok" aria-pressed="false"><small><i style="background:var(--ok)"></i>Responden SNMP</small><b id="k-ok">0</b></button>
    <button type="button" data-d="no" aria-pressed="false"><small><i style="background:var(--err)"></i>No responden</small><b id="k-no">0</b></button>
    <button type="button" data-d="sin" aria-pressed="false"><small><i style="background:var(--sub)"></i>Sin consultar todavía</small><b id="k-sin">0</b></button>
  </div>

  <div class="solapas" role="tablist" aria-label="Secciones del mapa">
    <button type="button" role="tab" data-s="hosts" aria-selected="true">Hosts<span class="n" id="s-hosts"></span></button>
    <button type="button" role="tab" data-s="descubiertos" aria-selected="false">Descubiertos<?= $descubiertos ? '<span class="nuevo">' . count($descubiertos) . '</span>' : '' ?></button>
    <button type="button" role="tab" data-s="ocultos" aria-selected="false">Ocultos<span class="n"><?= count($cfg['ocultos'] ?? []) ?: '' ?></span></button>
    <button type="button" role="tab" data-s="enlaces" aria-selected="false">Enlaces manuales<span class="n" id="n-enl"></span></button>
    <button type="button" role="tab" data-s="opciones" aria-selected="false">Opciones del mapa</button>
    <button type="button" role="tab" data-s="historial" aria-selected="false">Última actualización<span class="n" id="s-hist"></span></button>
  </div>

  <section data-panel="hosts" style="display:flex;flex-direction:column;gap:14px">
    <div class="filtros">
      <label class="buscador"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <span class="oculto-lect">Buscar host</span><input id="f-texto" placeholder="Nombre, IP o marca" oninput="pintar()"></label>
      <div class="chips-f" role="group" aria-label="Tipo" id="f-tipo">
        <button type="button" data-t="" aria-pressed="true">Todos</button><button type="button" data-t="router" aria-pressed="false">Routers</button>
        <button type="button" data-t="switch" aria-pressed="false">Switches</button><button type="button" data-t="acceso" aria-pressed="false">Acceso</button>
        <button type="button" data-t="otro" aria-pressed="false">Otros</button><button type="button" data-t="antena" aria-pressed="false">Antenas</button>
        <button type="button" data-t="servidor" aria-pressed="false">Servidores</button><button type="button" data-t="olt" aria-pressed="false">OLT</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;margin-left:auto;font-size:13px;color:var(--sub)">Mostrar
        <select id="f-mapa" onchange="pintar()">
          <option value="<?= h($mapa) ?>">Hosts de este mapa</option><option value="*">Todos los hosts</option><option value="-">Hosts sin mapa</option>
        </select></label>
    </div>
    <div class="masivo" id="masivo" hidden>
      <b id="m-n">0 seleccionados</b>
      <span style="display:flex;gap:6px;align-items:center">
        <label class="oculto-lect" for="m-mapa">Mapa</label>
        <select id="m-mapa"><?php foreach ($titulos as $id => $tt): ?><option value="<?= h($id) ?>"><?= h($tt) ?></option><?php endforeach; ?></select>
        <button type="button" onclick="masivo('agregar_mapa', $('m-mapa').value)">Agregar a ese mapa</button></span>
      <button type="button" onclick="masivo('quitar_mapa', MAPA)">Quitar de este mapa</button>
      <button type="button" onclick="masivoComunidad()">Cambiar comunidad</button>
      <button type="button" onclick="masivo('ppp_no')">Sin bloqueo PPP</button>
      <button type="button" onclick="masivo('ppp_si')">Con bloqueo PPP</button>
      <button type="button" class="peligro" onclick="masivo('eliminar')">Eliminar</button>
      <button type="button" class="link" style="margin-left:auto" onclick="selec.clear();pintar()">Cancelar selección</button>
    </div>
    <div class="tarjeta"><div class="tabla-wrap">
      <table style="min-width:1000px">
        <thead><tr><th style="width:40px;padding-left:16px"><input type="checkbox" id="sel-todos" aria-label="Seleccionar todos los visibles" onchange="selTodos(this.checked)"></th>
          <th>Nombre ▲</th><th>IP</th><th>Estado</th><th>SNMP</th><th>Marca / modelo</th><th>Mapas</th><th>Puertos</th><th><span class="oculto-lect">Editar</span></th></tr></thead>
        <tbody id="tbody"></tbody>
      </table></div>
      <div class="vacio" id="vacio" hidden>No hay hosts con este filtro. Usa <b>Crear host</b>, o en un host existente marca este mapa.</div>
    </div>
    <p class="sub" id="contador" style="margin:0;font-size:13px"></p>
  </section>

  <section data-panel="descubiertos" hidden class="tarjeta">
    <div class="cab"><h2>Descubiertos automáticamente · <?= count($descubiertos) ?></h2>
      <span class="sub" style="font-size:13px">Salen con borde azul en el mapa. Agrégalos como host para fijarlos, u ocúltalos de este mapa.</span></div>
    <?php if ($descubiertos): ?>
    <div class="tabla-wrap"><table>
      <thead><tr><th>Nombre en el mapa</th><th>IP</th><th>MAC</th><th>Marca / modelo</th><th>SNMP</th><th></th></tr></thead>
      <tbody id="tdesc"></tbody></table></div>
    <?php else: ?><div class="vacio">Ningún equipo descubierto en la última actualización.</div><?php endif; ?>
  </section>

  <section data-panel="ocultos" hidden class="tarjeta">
    <div class="cab"><h2>Ocultos en este mapa · <?= count($cfg['ocultos'] ?? []) ?></h2>
      <span class="sub" style="font-size:13px">No se dibujan, ni lo que solo cuelga de ellos.</span></div>
    <?php if (!empty($cfg['ocultos'])): ?>
    <div class="tabla-wrap"><table>
      <thead><tr><th>Nombre</th><th>IP</th><th>MAC</th><th></th></tr></thead>
      <tbody id="tocultos"></tbody></table></div>
    <?php else: ?><div class="vacio">Ningún equipo oculto. Se ocultan desde Descubiertos o con "Ocultar del mapa" en la solapa Config del visor.</div><?php endif; ?>
  </section>

  <section data-panel="enlaces" hidden class="tarjeta">
    <div class="cab"><h2>Enlaces manuales</h2>
      <span class="sub" style="font-size:13px">Para equipos sin SNMP: se crean en la solapa <b>Enlaces manuales</b> de cada host y se dibujan en violeta.</span></div>
    <div class="tabla-wrap"><table>
      <thead><tr><th>Equipo</th><th>Puerto</th><th></th><th>Equipo</th><th>Puerto</th><th>Tipo</th><th>Mapas con los dos</th></tr></thead>
      <tbody id="tenl"></tbody></table></div>
    <div class="vacio" id="enl-vacio" hidden>Ningún enlace manual todavía. Abre un host, solapa <b>Enlaces manuales</b>, y agrega a qué equipo está conectado.</div>
  </section>

  <section data-panel="opciones" hidden style="display:flex;flex-direction:column;gap:20px">
    <form method="post" class="tarjeta">
      <div class="cab"><h2>Descubrimiento</h2><button class="prim" style="margin-left:auto">Guardar opciones</button></div>
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:18px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>">
        <input type="hidden" name="accion" value="opciones">
        <fieldset style="margin:0;padding:0;border:0;display:flex;flex-direction:column;gap:10px;max-width:640px">
          <legend style="font-size:14px;font-weight:500;margin-bottom:10px">Profundidad del recorrido LLDP / CDP</legend>
          <div class="seg">
            <?php foreach ([0 => 'Solo mis hosts', 1 => '+ vecinos', 2 => '2 saltos', 3 => '3 saltos'] as $v => $tt): ?>
              <label><input type="radio" name="profundidad" value="<?= $v ?>" <?= (int)($cfg['profundidad'] ?? 0) === $v ? 'checked' : '' ?>><?= h($tt) ?></label>
            <?php endforeach; ?>
          </div>
          <p class="sub" style="margin:0;font-size:13px">Cuántos equipos de distancia de tus hosts se recorren. Más saltos = mapa más grande y actualización más lenta.</p>
        </fieldset>
        <div class="grid-op">
          <label class="casilla"><input type="checkbox" name="auto_vecinos" value="1" <?= ($cfg['auto_vecinos'] ?? true) ? 'checked' : '' ?>>
            <span><b>Agregar vecinos automáticamente</b><span>Equipos que anuncian LLDP/CDP aunque no sean hosts (IP desde el ARP / IP neighbor de los routers). Salen con borde azul.</span></span></label>
          <label class="casilla"><input type="checkbox" name="barrido" value="1" <?= ($cfg['barrido'] ?? true) ? 'checked' : '' ?>>
            <span><b>Barrido de ping antes de leer el ARP</b><span>Encuentra equipos sin SNMP (cámaras, tableros). Suma unos segundos.</span></span></label>
          <label class="casilla"><input type="checkbox" name="ocultar_ppp" value="1" <?= ($cfg['ocultar_ppp'] ?? true) ? 'checked' : '' ?>>
            <span><b>Ocultar clientes y túneles PPP / VPN</b><span>PPPoE, PPTP, L2TP, SSTP y OpenVPN. Los enlaces por PPP nunca se dibujan; con esto tampoco aparecen esos equipos.</span></span></label>
        </div>
      </div>
    </form>
    <form method="post" class="tarjeta">
      <div class="cab"><h2>Actualización automática e historial</h2><button class="prim" style="margin-left:auto">Guardar</button></div>
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:14px;max-width:760px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>">
        <input type="hidden" name="accion" value="auto">
        <div class="seg">
          <?php foreach ([0 => 'Apagada', 5 => 'Cada 5 min', 10 => 'Cada 10 min', 15 => 'Cada 15 min', 30 => 'Cada 30 min'] as $v => $tt): ?>
            <label><input type="radio" name="auto_min" value="<?= $v ?>" <?= (int)($cfg['auto_min'] ?? 0) === $v ? 'checked' : '' ?>><?= h($tt) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="sub" style="margin:0;font-size:13px">El servidor vuelve a leer la red solo, sin tener la página abierta. Cada actualización (automática o con ⟳ Actualizar)
          se guarda en el historial durante 7 días: en el visor, el botón <b>Historial</b> muestra cómo estaba la red en cada momento y qué cambió.</p>
      </div>
    </form>
    <?php $fd = $cfg['fondo'] ?? []; $ft = $fd['tipo'] ?? ''; ?>
    <form method="post" class="tarjeta">
      <div class="cab"><h2>Fondo del mapa</h2><button class="prim" style="margin-left:auto">Guardar fondo</button></div>
      <div class="cuerpo" style="display:flex;flex-direction:column;gap:16px;max-width:760px">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>">
        <input type="hidden" name="accion" value="fondo">
        <p class="sub" style="margin:0;font-size:13px">Muestra un mapa de calles o satelital detrás de los equipos, que se mueve y acerca junto con ellos.
          Arrastra cada equipo a su ubicación y pulsa <b>Guardar</b> en el visor.</p>
        <div class="seg">
          <?php foreach (['' => 'Sin fondo', 'calles' => 'Calles', 'oscuro' => 'Calles (oscuro)', 'satelite' => 'Satélite'] as $v => $tt): ?>
            <label><input type="radio" name="fondo_tipo" value="<?= h($v) ?>" <?= $ft === $v ? 'checked' : '' ?>><?= h($tt) ?></label>
          <?php endforeach; ?>
        </div>
        <label class="campo" style="display:flex;flex-direction:column;gap:6px;max-width:420px">
          <span style="font-size:14px;font-weight:500">Centro del mapa (latitud, longitud)</span>
          <input name="centro" class="mono" placeholder="10.4806, -66.9036" maxlength="60"
            value="<?= isset($fd['lat']) ? h($fd['lat'] . ', ' . $fd['lon']) : '' ?>">
        </label>
        <p class="sub" style="margin:0;font-size:13px">En Google Maps, haz clic derecho sobre el lugar (por ejemplo tu nodo principal) y pulsa las coordenadas para copiarlas; pégalas aquí.
          Después, en el visor, el botón <b>Ajustar mapa</b> permite mover y acercar el fondo hasta que coincida con los equipos.
          Las calles vienen de OpenStreetMap y el satélite de Esri: el navegador necesita salida a Internet.</p>
      </div>
    </form>
    <form method="post" class="tarjeta">
      <div class="cab"><h2>Nombre del mapa</h2></div>
      <div class="cuerpo acciones">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>">
        <input name="titulo" value="<?= h($titulo) ?>" maxlength="60" required style="min-width:260px;flex:1;max-width:420px" aria-label="Nombre del mapa">
        <button name="accion" value="renombrar_mapa">Cambiar nombre</button>
        <span class="sub" style="font-size:13px">Dirección: <span class="mono">viewer.php?map=<?= h($mapa) ?></span> (no cambia)</span>
      </div>
    </form>
    <form method="post" class="tarjeta" style="border-color:#4b1d1d">
      <div class="cab"><h2>Eliminar el mapa</h2></div>
      <div class="cuerpo acciones">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>"><input type="hidden" name="volver" value="index.php">
        <span class="sub" style="flex:1;min-width:240px">Se borran el mapa publicado y su orden guardado. Los hosts siguen en la lista. No se puede deshacer.</span>
        <button name="accion" value="eliminar_mapa" class="peligro"
          onclick="return confirm('¿Eliminar el mapa &quot;' + <?= h(json_encode($titulo)) ?> + '&quot;? No se puede deshacer.')">Eliminar mapa</button>
      </div>
    </form>
  </section>

  <section data-panel="historial" hidden class="tarjeta">
    <div class="cab"><h2>Última actualización del mapa</h2></div>
    <div class="cuerpo"><div id="estado" class="sub">Cargando…</div><pre id="log" hidden></pre></div>
  </section>
<?php endif; ?>
</main>

<dialog id="dlg-csv" class="centro">
  <form method="post" enctype="multipart/form-data" style="margin:0">
    <div class="dlg-tit" style="padding-bottom:12px;border-bottom:1px solid var(--borde)"><h2 style="flex:1">Importar / exportar hosts (.csv)</h2>
      <button type="button" class="icono" aria-label="Cerrar" onclick="this.closest('dialog').close()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
    <div style="padding:18px 24px;display:flex;flex-direction:column;gap:18px">
      <div><b style="font-weight:600">Descargar</b>
        <div class="acciones" style="margin-top:10px">
          <a class="btn" href="?csv=plantilla">Formato vacío</a>
          <a class="btn" href="?csv=hosts&amp;mapa=<?= urlencode($mapa) ?>">Hosts de este mapa</a>
          <a class="btn" href="?csv=hosts">Todos los hosts</a></div>
        <p class="sub" style="margin:8px 0 0;font-size:13px">El archivo descargado incluye las comunidades: guárdalo con cuidado.</p></div>
      <div style="border-top:1px solid var(--borde);padding-top:18px;display:flex;flex-direction:column;gap:12px"><b style="font-weight:600">Subir</b>
        <p class="sub" style="margin:0;font-size:13px;line-height:1.55">Columnas: <b>NOMBRE</b>; <b>IP</b>; <b>TIPO DE DISPOSITIVO</b> (Router, Switch, Acceso u Otro);
          <b>COMUNIDAD</b> (vacía = probar todas); <b>VERSION SNMP</b> (v2c o v1); <b>BLOQUEO PPP</b> (SI o NO);
          <b>MAPAS</b> (nombres separados por coma). Cada host se identifica por su IP: si ya existe se actualiza; si no, se agrega. Separador ; o , (se detecta solo).</p>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="mapa" value="<?= h($mapa) ?>">
        <input type="hidden" name="accion" value="importar_csv">
        <input type="file" name="csv" accept=".csv,text/csv,text/plain" required aria-label="Archivo .csv de hosts" style="padding:8px">
        <label class="chk"><input type="checkbox" name="validar" value="1"><span>Solo revisar (no guardar nada)</span></label>
      </div>
    </div>
    <div class="dlg-pie"><button type="button" class="der" onclick="this.closest('dialog').close()">Cancelar</button><button class="prim">Subir .csv</button></div>
  </form>
</dialog>

<dialog id="dlg" aria-labelledby="dlg-titulo">
  <form id="dlg-form" onsubmit="return false">
    <div class="dlg-tit">
      <span class="ico" id="dlg-ico" style="width:44px;height:44px;border-radius:10px" aria-hidden="true"></span>
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:4px">
        <h2 id="dlg-titulo">Host</h2><span id="dlg-estado" class="disp sin" style="font-size:13px"></span></div>
      <button type="button" class="icono" onclick="cerrar()" aria-label="Cerrar" style="border:0;width:44px;height:44px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
    <div class="tabs" role="tablist"><button type="button" class="tab act" data-p="host" onclick="solapa('host')">Datos</button>
      <button type="button" class="tab" data-p="puertos" id="tab-puertos" onclick="solapa('puertos')">Puertos <span id="n-bloq"></span></button>
      <button type="button" class="tab" data-p="enlaces" onclick="solapa('enlaces')">Enlaces manuales <span id="n-enl-h"></span></button></div>

    <div class="dlg-cuerpo">
    <div class="pane-p" data-p="enlaces" hidden>
      <p class="sub" style="margin:16px 24px 10px;font-size:13px">Conexiones dibujadas a mano, para equipos que no tienen SNMP (o que no se ubican solos).
        Se suman a los enlaces detectados y se dibujan en violeta (punteada si son de respaldo).</p>
      <div class="puertos-wrap"><table>
        <thead><tr><th>Puerto de este host</th><th>Conectado a</th><th>Puerto del otro</th><th>Tipo</th><th style="width:60px"></th></tr></thead>
        <tbody id="e-tbody"></tbody></table></div>
      <label class="chk" id="e-solo-l" style="margin:0 24px 12px;font-size:13px"><input type="checkbox" id="e-solo">
        <span>Usar solo los enlaces manuales para este host (ignorar lo detectado por tabla de MAC o LLDP).
          Márcalo si el mapa lo ubica en un lugar equivocado, por ejemplo un equipo detrás de un switch sin SNMP.</span></label>
      <div style="padding:0 24px 18px" class="acciones"><button type="button" onclick="agregarEnlace()">+ Agregar enlace</button>
        <span class="sub" style="font-size:13px">El puerto es opcional. Se aplica al guardar y actualizar el mapa.</span></div>
      <datalist id="dl-pa"></datalist>
    </div>

    <div class="pane-p" data-p="puertos" hidden>
      <div class="puertos-bar">
        <input id="p-filtro" placeholder="Buscar puerto, descripción o equipo" oninput="pintarPuertos()" aria-label="Buscar puerto">
        <label class="chk" style="align-items:center;font-size:13px"><input type="checkbox" id="p-logicos" onchange="pintarPuertos()" style="margin:0"> Mostrar interfaces lógicas</label>
      </div>
      <p class="sub" id="p-info" style="margin:0 24px 10px;font-size:13px"></p>
      <div class="puertos-wrap"><table>
        <thead><tr><th style="width:70px">Bloquear</th><th>Puerto</th><th>Descripción</th><th>Estado</th><th>Equipos detrás</th><th>MACs</th></tr></thead>
        <tbody id="p-tbody"></tbody></table></div>
    </div>

    <div class="pane-p datos" data-p="host">
      <div id="dlg-err" class="msg err" role="alert" hidden></div>
      <fieldset><legend>Identificación</legend>
        <label class="campo" for="h-nombre">Nombre</label>
        <input id="h-nombre" maxlength="60" required style="margin-top:-8px">
        <div style="display:flex;flex-direction:column;gap:8px">
          <span style="font-size:14px;font-weight:500" id="h-tipo-l">Tipo de dispositivo</span>
          <input type="hidden" id="h-tipo">
          <div class="tipos" role="radiogroup" aria-labelledby="h-tipo-l">
            <label><input type="radio" name="h-tipo-r" value="router" onchange="elegirTipo(this.value)"><span class="ico router" aria-hidden="true"></span>Router</label>
            <label><input type="radio" name="h-tipo-r" value="switch" onchange="elegirTipo(this.value)"><span class="ico switch" aria-hidden="true"></span>Switch</label>
            <label><input type="radio" name="h-tipo-r" value="acceso" onchange="elegirTipo(this.value)"><span class="ico acceso" aria-hidden="true"></span>Acceso</label>
            <label><input type="radio" name="h-tipo-r" value="otro" onchange="elegirTipo(this.value)"><span class="ico otro" aria-hidden="true"></span>Otro</label>
            <label><input type="radio" name="h-tipo-r" value="olt" onchange="elegirTipo(this.value)"><span class="ico olt" aria-hidden="true"></span>OLT</label>
          </div>
          <div class="ayuda" id="h-ayuda"></div>
          <label class="chk" id="h-sin-l" style="margin:4px 0 0;font-size:13px"><input type="checkbox" id="h-sin" onchange="ayudaTipo()">
            <span><b>Solo dibujar en el mapa (no escanear)</b> — para VMware u otros equipos que no se deben consultar.
              No se le envía SNMP ni ping; se ubica con lo que ya saben el router y los switches (ARP y tabla de MAC) o con sus enlaces manuales.</span></label>
        </div>
      </fieldset>

      <fieldset><legend>Conexión SNMP</legend>
        <div class="iface">
          <label class="campo">IP de gestión<input id="h-ip" placeholder="10.8.100.1" required inputmode="decimal" class="mono"></label>
          <label class="campo">Puerto<input id="h-puerto" value="161" inputmode="numeric" maxlength="5"></label>
          <label class="campo">Versión<select id="h-version"><option value="v2c">SNMPv2c</option><option value="v1">SNMPv1</option></select></label>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px">
          <label for="h-comm" style="font-size:14px;font-weight:500">Comunidad</label>
          <div class="comm">
            <input id="h-comm" type="password" autocomplete="off" placeholder="Vacía = probar todas las comunidades guardadas" maxlength="64">
            <button type="button" onclick="verComm(this)">Mostrar</button>
          </div>
        </div>
        <div class="info-snmp sin" id="h-snmp-info"></div>
      </fieldset>

      <fieldset><legend>En el mapa</legend>
        <div style="display:flex;flex-direction:column;gap:8px">
          <span style="font-size:14px;font-weight:500" id="h-mapas-l">Mapas donde aparece</span>
          <div class="mapas-sel" id="h-mapas-lista" role="group" aria-labelledby="h-mapas-l"></div>
          <div class="ayuda">Se consulta y se dibuja solo en los mapas marcados. Un router que no está en un mapa no aporta su ARP a ese mapa.</div>
        </div>
        <label class="casilla" style="padding-top:12px;border-top:1px solid #232833"><input type="checkbox" id="h-ppp">
          <span><b>Bloqueo PPP</b><span>No dibuja sus conexiones PPP / PPPoE / PPTP / L2TP / SSTP / OpenVPN ni los equipos que solo cuelgan por ellas. Desmárcalo para verlas como túnel entrecortado.</span></span></label>
        <label class="casilla" id="h-api-l" style="padding-top:12px;border-top:1px solid #232833"><input type="checkbox" id="h-api">
          <span><b>Leer VLANs del bridge por API (RouterOS)</b><span>Toma las VLANs tagged / untagged configuradas en cada puerto
            (/interface bridge vlan), tengan o no tráfico, y las quita apenas se borran. Usa el usuario API de la página
            <a href="comunidades.php">Comunidades</a> (solo lectura). Si la API no responde, sigue con SNMP.</span></span></label>
      </fieldset>
    </div>
    </div>

    <div class="dlg-pie">
      <label class="chk" style="align-items:center"><input type="checkbox" id="h-aplicar" style="margin:0"> Actualizar el mapa al guardar</label>
      <button type="button" class="prim" id="b-guardar" onclick="guardar()" style="height:44px;padding:0 20px">Agregar</button>
      <button type="button" onclick="cerrar()" style="height:44px">Cancelar</button>
      <button type="button" id="b-clonar" onclick="clonar()" style="height:44px">Clonar</button>
      <button type="button" id="b-borrar" class="peligro der" onclick="borrar()" style="height:44px;border:0">Eliminar host</button>
    </div>
  </form>
</dialog>


<script>
const CSRF = <?= json_encode($csrf) ?>, MAPA = <?= json_encode($mapa) ?>;
const HOSTS = <?= json_encode($hosts, $J) ?>;
const TITULOS = <?= json_encode((object)$titulos, $J) ?>;
const DISP = <?= json_encode((object)$disp, $J) ?>;
const DESC = <?= json_encode($descubiertos, $J) ?>;
const OCULTOS = <?= json_encode(array_values($cfg['ocultos'] ?? []), $J) ?>;
const ENLACES = <?= json_encode($enlaces, $J) ?>;
let enlacesHost = [];   // enlaces del host abierto: {pa, b, pb}
let selMapas = new Set();   // mapas marcados en la ventana del host
const PUERTOS = <?= json_encode((object)($inv['equipos'] ?? []), $J) ?>, FECHA_PUERTOS = <?= json_encode($inv['fecha'] ?? '') ?>;
const NOMBRE_TIPO = { router: 'Router', switch: 'Switch', acceso: 'Switch de acceso', otro: 'Otro', olt: 'OLT' };
const AYUDA = {
  router: 'Se lee su tabla ARP (IP ↔ MAC) y su IP neighbor (MikroTik) para poner IP a los equipos; también sus vecinos LLDP/CDP y su tabla de MAC.',
  switch: 'Se leen sus vecinos LLDP/CDP y su tabla de MAC: en qué puerto está cada equipo y cuál es el enlace activo y el de respaldo (RSTP).',
  acceso: 'Se ubica por su MAC (ARP del router + tabla de MAC de los switches). Si responde SNMP, se lee su nombre y modelo.',
  otro: 'Igual que un switch de acceso; se dibuja como equipo final.',
  olt: 'Se dibuja como OLT y sus conexiones son siempre manuales (solapa Enlaces). Si tiene SNMP, solo se leen sus uplinks (nombre, estado y velocidad), sin recorrer sus tablas. Si no tiene SNMP, marca "Solo dibujar".',
};
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const $ = id => document.getElementById(id);
let editando = null;   // IP original del host abierto (null = nuevo)
let bloqueados = new Set();   // puertos bloqueados del host abierto

function solapa(p) {
  if (p === 'puertos' && $('tab-puertos').disabled) p = 'host';
  document.querySelectorAll('.tabs .tab').forEach(t => t.classList.toggle('act', t.dataset.p === p));
  document.querySelectorAll('.pane-p').forEach(x => x.hidden = x.dataset.p !== p);
  if (p === 'puertos') pintarPuertos();
  if (p === 'enlaces') pintarEnlacesHost();
}
// Equipos a los que se puede enlazar: hosts y equipos con IP de los mapas publicados
function equiposEnlazables() {
  const r = new Map();
  HOSTS.forEach(h => r.set(h.ip, { ip: h.ip, nombre: h.nombre, host: true }));
  Object.entries(DISP).forEach(([ip, d]) => { if (!r.has(ip)) r.set(ip, { ip, nombre: d.nodo, host: false }); });
  return [...r.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es', { numeric: true }));
}
function nombreDe(ip) { const h = HOSTS.find(x => x.ip === ip); return h ? h.nombre : (DISP[ip] ? DISP[ip].nodo : ip); }
function opcionesPuerto(ip) { const inv = PUERTOS[ip]; return inv ? inv.puertos.filter(p => !p.logico).map(p => p.nombre) : []; }
function pintarEnlacesHost() {
  const mi = $('h-ip').value.trim();
  $('dl-pa').innerHTML = opcionesPuerto(mi).map(p => `<option value="${esc(p)}">`).join('');
  const eq = equiposEnlazables().filter(x => x.ip !== mi);
  $('e-tbody').innerHTML = enlacesHost.map((e, i) => {
    const conocido = eq.some(x => x.ip === e.b);
    const ops = (conocido || !e.b ? '' : `<option value="${esc(e.b)}" selected>${esc(e.b)}</option>`) +
      `<optgroup label="Hosts">${eq.filter(x => x.host).map(x => `<option value="${esc(x.ip)}" ${x.ip === e.b ? 'selected' : ''}>${esc(x.nombre)} · ${esc(x.ip)}</option>`).join('')}</optgroup>` +
      `<optgroup label="Descubiertos en los mapas">${eq.filter(x => !x.host).map(x => `<option value="${esc(x.ip)}" ${x.ip === e.b ? 'selected' : ''}>${esc(x.nombre)} · ${esc(x.ip)}</option>`).join('')}</optgroup>`;
    const pb = opcionesPuerto(e.b);
    return `<tr>
      <td><input class="e-p mono" list="dl-pa" value="${esc(e.pa)}" maxlength="64" placeholder="ej: ether5" oninput="enlacesHost[${i}].pa=this.value"></td>
      <td><select class="e-sel" onchange="enlacesHost[${i}].b=this.value;pintarEnlacesHost()"><option value="">— elegir equipo —</option>${ops}</select></td>
      <td><input class="e-p mono" list="dl-pb-${i}" value="${esc(e.pb)}" maxlength="64" placeholder="opcional" oninput="enlacesHost[${i}].pb=this.value">
        <datalist id="dl-pb-${i}">${pb.map(p => `<option value="${esc(p)}">`).join('')}</datalist></td>
      <td><select onchange="enlacesHost[${i}].rol=this.value" aria-label="Tipo de enlace">
        <option value="principal" ${e.rol !== 'respaldo' ? 'selected' : ''}>Principal</option>
        <option value="respaldo" ${e.rol === 'respaldo' ? 'selected' : ''}>Respaldo</option></select></td>
      <td><button type="button" class="peligro" onclick="enlacesHost.splice(${i},1);pintarEnlacesHost()" aria-label="Quitar enlace">Quitar</button></td></tr>`;
  }).join('') || '<tr><td colspan="5" class="sub">Este host no tiene enlaces manuales.</td></tr>';
  $('n-enl-h').innerHTML = enlacesHost.length ? `<span class="badge b-gris">${enlacesHost.length}</span>` : '';
}
function agregarEnlace() { enlacesHost.push({ pa: '', b: '', pb: '', rol: 'principal' }); pintarEnlacesHost(); }
function pintarEnlaces() {
  if (!$('tenl')) return;
  const mapasDe = ip => { const h = HOSTS.find(x => x.ip === ip); return h ? (h.mapas || []) : null; };
  $('n-enl').textContent = ENLACES.length || '';
  $('enl-vacio').hidden = ENLACES.length > 0;
  const idx = ip => HOSTS.findIndex(x => x.ip === ip);
  const celda = ip => idx(ip) >= 0 ? `<a href="#" onclick="abrirHost(${idx(ip)}, 'enlaces');return false">${esc(nombreDe(ip))}</a> <span class="sub mono">${esc(ip)}</span>`
                                   : `${esc(nombreDe(ip))} <span class="sub mono">${esc(ip)}</span>`;
  $('tenl').innerHTML = ENLACES.map(e => {
    const ma = mapasDe(e.a), mb = mapasDe(e.b);
    // un extremo descubierto (no host) puede estar en cualquier mapa donde se lo encuentre
    const comunes = ma && mb ? ma.filter(m => mb.includes(m)) : (ma || mb || []);
    return `<tr><td>${celda(e.a)}</td><td class="mono">${esc(e.pa || '')}</td><td class="flecha">⟷</td>
      <td>${celda(e.b)}</td><td class="mono">${esc(e.pb || '')}</td>
      <td>${e.rol === 'respaldo' ? '<span class="badge b-gris" style="border-style:dashed">Respaldo</span>' : 'Principal'}</td>
      <td>${comunes.filter(m => TITULOS[m]).map(m => `<span class="mchip${m === MAPA ? ' act' : ''}">${esc(TITULOS[m])}</span>`).join('') || '<span class="sub">ninguno</span>'}</td></tr>`;
  }).join('');
}
function actualizarTabPuertos() {
  const red = ['router', 'switch'].includes($('h-tipo').value);
  $('tab-puertos').disabled = !red;
  $('tab-puertos').title = red ? '' : 'Solo para hosts de tipo Router o Switch';
  $('n-bloq').innerHTML = bloqueados.size ? `<span class="badge b-bloq">${bloqueados.size}</span>` : '';
}
function pintarPuertos() {
  const ip = $('h-ip').value.trim(), inv = PUERTOS[ip];
  const f = $('p-filtro').value.trim().toLowerCase(), logicos = $('p-logicos').checked;
  const lista = inv ? inv.puertos.slice() : [];
  // bloqueados que ya no aparecen en el ultimo escaneo: se muestran igual para poder desbloquearlos
  bloqueados.forEach(b => { if (!lista.some(p => p.nombre === b)) lista.push({ nombre: b, alias: '', estado: '?', vecinos: [], macs: 0, viejo: true }); });
  $('p-info').textContent = !ip ? 'Escribe la IP del host en la solapa Host.'
    : !inv ? 'Todavía no hay puertos escaneados de este equipo: guarda el host y pulsa ⟳ Actualizar mapa; al terminar aparecerán aquí.'
    : `Puertos leídos por SNMP en la última actualización (${FECHA_PUERTOS}). Un puerto bloqueado no se dibuja, y tampoco los equipos que solo se alcanzan por ese puerto.`;
  const filas = lista.filter(p => (logicos || !p.logico || bloqueados.has(p.nombre))
      && (!f || [p.nombre, p.alias, ...(p.vecinos || [])].join(' ').toLowerCase().includes(f)));
  $('p-tbody').innerHTML = filas.map(p => {
    const b = bloqueados.has(p.nombre);
    return `<tr class="${b ? 'bloq' : ''}">
      <td><input type="checkbox" ${b ? 'checked' : ''} data-p="${esc(p.nombre)}" onchange="marcar(this)" aria-label="Bloquear ${esc(p.nombre)}"></td>
      <td class="pn mono">${esc(p.nombre)}</td><td class="sub">${esc(p.alias || '')}${p.viejo ? ' <i>(ya no aparece en el equipo)</i>' : ''}</td>
      <td class="${p.estado === 'up' ? 'up' : 'down'}">${esc(p.estado)}</td>
      <td>${esc((p.vecinos || []).join(', '))}</td><td class="sub">${p.macs || ''}</td></tr>`;
  }).join('') || '<tr><td colspan="6" class="sub">Sin puertos para mostrar.</td></tr>';
}
function marcar(cb) {
  if (cb.checked) bloqueados.add(cb.dataset.p); else bloqueados.delete(cb.dataset.p);
  cb.closest('tr').classList.toggle('bloq', cb.checked);
  actualizarTabPuertos();
}

function badge(ip) {
  const d = DISP[ip];
  if (!d) return '<span class="badge b-gris" title="Aún no aparece en el mapa publicado">SNMP</span>';
  return d.snmp === 'ok' ? '<span class="badge b-ok" title="Responde SNMP">SNMP</span>'
                         : `<span class="badge b-no" title="${esc(d.estado || 'No responde SNMP')}">SNMP</span>`;
}
function disponibilidad(ip) { const d = DISP[ip]; return !d ? 'sin' : d.snmp; }

// Tipo para mostrar: antenas (Ubiquiti / Mimosa) y servidores (SUPERMICRO / HP) segun la marca leida
function tipoVisual(h) {
  const d = DISP[h.ip] || {}, txt = [d.marca, d.modelo, h.nombre].join(' ');
  if (h.tipo === 'olt') return 'olt';
  if (/ubiquiti|\bubnt\b|mimosa|airos|airmax|litebeam|nanobeam|powerbeam|nanostation/i.test(txt) && !/edge\s?switch|edge\s?router|unifi\s?switch/i.test(txt)) return 'antena';
  if (!['router', 'switch'].includes(h.tipo) && /vmware|esxi|vcenter|vsphere/i.test(txt)) return 'servidor';
  if (!['router', 'switch'].includes(h.tipo) && /supermicro|super\s?micro|\bHPE?\b|hewlett|proliant/i.test(txt) && !/switch|officeconnect|procurve|aruba/i.test(txt)) return 'servidor';
  return h.tipo;
}
const NOMBRE_VISUAL = { router: 'Router', switch: 'Switch de distribución', acceso: 'Switch de acceso', otro: 'Otro', antena: 'Antena', servidor: 'Servidor', olt: 'OLT' };
function estadoHost(h) {
  const d = DISP[h.ip];
  if (h.sin_escaneo && !['router', 'switch'].includes(h.tipo)) return { c: 'sin', t: 'Solo dibujado · no se escanea' };
  if (!d) return { c: 'sin', t: 'Sin consultar todavía' };
  if (d.snmp === 'ok') return { c: 'ok', t: 'Responde SNMP' };
  if (/arp|ubicad/i.test(d.estado || '')) return { c: 'warn', t: 'Sin SNMP · ubicado por ARP' };
  return { c: 'no', t: d.estado ? d.estado.replace(/^Sin respuesta/i, 'No responde') : 'No responde SNMP' };
}
let filtroTipo = '', filtroDisp = '';
const selec = new Set();
function pintar() {
  if (!$('tbody')) return;
  const t = $('f-texto').value.trim().toLowerCase();
  const base = HOSTS.map((h, i) => ({ h, i, d: DISP[h.ip] || {} }))
    .filter(({ h, d }) => enFiltroMapa(h) && (!filtroTipo || tipoVisual(h) === filtroTipo)
      && (!t || [h.nombre, h.ip, d.marca, d.modelo].join(' ').toLowerCase().includes(t)));
  const cuenta = { ok: 0, no: 0, sin: 0 };
  base.forEach(({ h }) => cuenta[disponibilidad(h.ip)]++);
  $('k-todos').textContent = base.length; $('k-ok').textContent = cuenta.ok; $('k-no').textContent = cuenta.no; $('k-sin').textContent = cuenta.sin;
  const filas = base.filter(({ h }) => !filtroDisp || disponibilidad(h.ip) === filtroDisp)
    .sort((a, b) => a.h.nombre.localeCompare(b.h.nombre, 'es', { numeric: true }));
  $('tbody').innerHTML = filas.map(({ h, i, d }) => {
    const ms = (h.mapas || []).filter(m => TITULOS[m]), tv = tipoVisual(h), e = estadoHost(h), m = selec.has(h.ip);
    return `<tr class="${m ? 'marcado' : ''}">
      <td style="padding-left:16px"><input type="checkbox" ${m ? 'checked' : ''} aria-label="Seleccionar ${esc(h.nombre)}" onchange="marcarFila('${esc(h.ip)}', this.checked)"></td>
      <td class="nom"><span style="display:flex;align-items:center;gap:10px"><span class="ico ${esc(tv)}" aria-hidden="true"></span>
        <span style="display:flex;flex-direction:column"><a href="#" onclick="abrirHost(${i});return false">${esc(h.nombre)}</a>
        <span class="sub" style="font-size:12px">${esc(NOMBRE_VISUAL[tv] || tv)}</span></span></span></td>
      <td class="mono">${esc(h.ip)}${(h.puerto || 161) != 161 ? ':' + esc(h.puerto) : ''}</td>
      <td><span class="disp ${e.c}" title="${esc(d.estado || '')}"><i></i>${esc(e.t)}</span></td>
      <td style="color:var(--sub2)">${h.sin_escaneo && !['router', 'switch'].includes(h.tipo) ? '<span class="badge b-gris" title="No se le envía SNMP ni ping">Sin escaneo</span>'
        : (h.version === 'v1' ? 'v1' : 'v2c') + ' · ' + (h.community ? 'comunidad propia' : '<span class="sub">probar todas</span>')}</td>
      <td style="color:var(--sub2)" title="${esc(d.modelo || '')}">${esc([d.marca, (d.modelo || '').slice(0, 36)].filter(Boolean).join(' · ')) || '<span class="sub">—</span>'}</td>
      <td>${ms.length ? ms.map(x => `<span class="mchip${x === MAPA ? ' act' : ''}">${esc(TITULOS[x])}</span>`).join('') : '<span class="sub">ninguno</span>'}</td>
      <td>${(h.bloqueados || []).length ? `<span class="badge b-bloq" title="${esc(h.bloqueados.join(', '))}">${h.bloqueados.length} bloqueado${h.bloqueados.length > 1 ? 's' : ''}</span>` : ''}
        ${h.bloqueo_ppp === false ? '<span class="badge b-gris" title="Se dibujan sus conexiones PPP">PPP visible</span>' : ''}
        ${h.api_vlans && ['router', 'switch'].includes(h.tipo) ? '<span class="badge b-gris" title="VLANs del bridge leídas por la API de RouterOS">API VLAN</span>' : ''}</td>
      <td style="text-align:right;padding-right:16px"><a href="#" onclick="abrirHost(${i});return false">Editar</a></td>
    </tr>`;
  }).join('');
  $('vacio').hidden = filas.length > 0;
  $('contador').textContent = `Mostrando ${filas.length} de ${HOSTS.length} hosts · Haz clic en un nombre para ver su ficha.`;
  $('s-hosts').textContent = base.length;
  // seleccion
  [...selec].forEach(ip => { if (!HOSTS.some(h => h.ip === ip)) selec.delete(ip); });
  $('masivo').hidden = !selec.size;
  $('m-n').textContent = selec.size + (selec.size === 1 ? ' seleccionado' : ' seleccionados');
  const vis = filas.map(f => f.h.ip);
  $('sel-todos').checked = vis.length > 0 && vis.every(ip => selec.has(ip));
  $('sel-todos').dataset.vis = JSON.stringify(vis);
}
function marcarFila(ip, v) { if (v) selec.add(ip); else selec.delete(ip); pintar(); }
function selTodos(v) { JSON.parse($('sel-todos').dataset.vis || '[]').forEach(ip => v ? selec.add(ip) : selec.delete(ip)); pintar(); }
async function masivo(op, valor) {
  const n = selec.size, nom = { agregar_mapa: `agregar ${n} hosts al mapa "${TITULOS[valor] || ''}"`, quitar_mapa: `quitar ${n} hosts del mapa "${TITULOS[MAPA]}"`,
    comunidad: `cambiar la comunidad de ${n} hosts`, ppp_si: `activar Bloqueo PPP en ${n} hosts`, ppp_no: `quitar Bloqueo PPP en ${n} hosts`,
    eliminar: `ELIMINAR ${n} hosts de la lista y de todos los mapas (también sus enlaces manuales)` }[op];
  if (!confirm(`¿${nom.charAt(0).toUpperCase() + nom.slice(1)}?`)) return;
  const cuerpo = new URLSearchParams({ csrf: CSRF, mapa: MAPA, accion: 'hosts_masivo', op, valor: valor || '' });
  selec.forEach(ip => cuerpo.append('ips[]', ip));
  const r = await fetch(location.origin + location.pathname, { method: 'POST', body: cuerpo });
  const j = await r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
  if (!j.ok) { alert(j.error || 'No se pudo aplicar'); return; }
  location.reload();
}
function masivoComunidad() {
  const v = prompt(`Comunidad SNMP para los ${selec.size} hosts elegidos.\nDéjala vacía para que prueben todas las comunidades guardadas.`, '');
  if (v !== null) masivo('comunidad', v.trim());
}

function pintarDescubiertos() {
  if (!$('tdesc')) return;
  $('tdesc').innerHTML = DESC.map((d, i) => `<tr>
    <td>${esc(d.nodo)}</td><td class="mono">${esc(d.ip)}</td><td class="mono">${esc(d.mac)}</td>
    <td class="sub">${esc([d.marca, (d.modelo || '').slice(0, 40)].filter(Boolean).join(' · '))}</td>
    <td>${d.snmp === 'ok' ? '<span class="badge b-ok">SNMP</span>' : '<span class="badge b-no">SNMP</span>'}</td>
    <td class="acciones"><button onclick="agregarDescubierto(${i})" ${d.ip ? '' : 'disabled title="Sin IP no se puede agregar como host"'}>Agregar como host</button>
      <button onclick="visibilidad('ocultar', DESC[${i}])">Ocultar</button></td></tr>`).join('');
}
function pintarOcultos() {
  if (!$('tocultos')) return;
  $('tocultos').innerHTML = OCULTOS.map((o, i) => `<tr><td>${esc(o.nombre)}</td><td class="mono">${esc(o.ip || '-')}</td>
    <td class="mono">${esc(o.mac || '-')}</td><td><button onclick="visibilidad('mostrar', OCULTOS[${i}])">Mostrar de nuevo</button></td></tr>`).join('');
}
async function visibilidad(accion, eq) {
  const j = await post({ accion, ip: eq.ip || '', mac: eq.mac || '', nombre: eq.nodo || eq.nombre || '' });
  if (!j.ok) { alert(j.error || 'No se pudo cambiar'); return; }
  location.reload();
}
function enFiltroMapa(h) {
  const f = $('f-mapa').value, ms = (h.mapas || []).filter(m => TITULOS[m]);
  return f === '*' || (f === '-' ? !ms.length : ms.includes(f));
}
// Menu desplegable "Mapas" de la ventana del host (casillas)
function pintarMulti() {
  const ids = Object.keys(TITULOS);
  $('h-mapas-lista').innerHTML = ids.map(id => `<button type="button" aria-pressed="${selMapas.has(id)}" onclick="marcarMapa('${esc(id)}')">${selMapas.has(id) ? '✓ ' : ''}${esc(TITULOS[id])}</button>`).join('')
    || '<span class="sub">No hay mapas.</span>';
}
function marcarMapa(id) { if (selMapas.has(id)) selMapas.delete(id); else selMapas.add(id); pintarMulti(); }
function alternarMulti() {}

function elegirTipo(v) { $('h-tipo').value = v; ayudaTipo(); }
function ayudaTipo() { $('h-ayuda').textContent = AYUDA[$('h-tipo').value] || ''; actualizarTabPuertos();
  document.querySelectorAll('input[name="h-tipo-r"]').forEach(r => r.checked = r.value === $('h-tipo').value);
  const tv = tipoVisual({ ip: $('h-ip').value.trim(), tipo: $('h-tipo').value, nombre: $('h-nombre').value });
  $('dlg-ico').className = 'ico ' + tv;
  $('e-solo-l').style.display = ['router', 'switch'].includes($('h-tipo').value) ? 'none' : '';
  const esOlt = $('h-tipo').value === 'olt';
  $('e-solo').disabled = esOlt;
  if (esOlt) $('e-solo').checked = true;
  const nucleo = ['router', 'switch'].includes($('h-tipo').value);
  $('h-sin-l').style.display = nucleo ? 'none' : '';
  $('h-api-l').style.display = nucleo ? '' : 'none';
  const sin = !nucleo && $('h-sin').checked;
  ['h-puerto', 'h-version', 'h-comm'].forEach(id => $(id).disabled = sin);
  if (sin) $('h-ayuda').textContent = 'Solo se dibuja: no se le consulta nada. Se ubica por el ARP del router y la tabla de MAC de los switches, o con la solapa Enlaces.'; }

function llenar(h, titulo, esNuevo) {
  $('dlg-err').hidden = true;
  $('dlg-titulo').textContent = titulo;
  bloqueados = new Set(h.bloqueados || []);
  $('p-filtro').value = ''; $('p-logicos').checked = false;
  solapa('host');
  $('h-nombre').value = h.nombre || ''; $('h-tipo').value = h.tipo || 'acceso';
  $('h-ip').value = h.ip || ''; $('h-puerto').value = h.puerto || 161; $('h-version').value = h.version || 'v2c';
  $('h-comm').value = h.community || ''; $('h-comm').type = 'password';
  $('h-ppp').checked = h.bloqueo_ppp !== false;
  $('h-api').checked = !!h.api_vlans;
  selMapas = new Set((h.mapas || []).filter(m => TITULOS[m]));
  // enlaces de este host, vistos desde el (este host = puerto local)
  enlacesHost = !h.ip ? [] : ENLACES.filter(e => e.a === h.ip || e.b === h.ip)
    .map(e => e.a === h.ip ? { pa: e.pa || '', b: e.b, pb: e.pb || '', rol: e.rol || 'principal' }
                           : { pa: e.pb || '', b: e.a, pb: e.pa || '', rol: e.rol || 'principal' });
  $('e-solo').checked = !!h.solo_manuales;
  $('h-sin').checked = !!h.sin_escaneo; ayudaTipo();
  pintarEnlacesHost();
  alternarMulti(false); pintarMulti();
  document.querySelector('.comm button').textContent = 'Mostrar';
  $('b-guardar').textContent = esNuevo ? 'Agregar' : 'Guardar';
  $('b-clonar').hidden = $('b-borrar').hidden = esNuevo;
  const d = DISP[h.ip];
  const info = $('h-snmp-info');
  info.className = 'info-snmp ' + (!h.ip || !d ? 'sin' : d.snmp === 'ok' ? 'ok' : 'no');
  info.textContent = !h.ip ? 'Al guardar, se consulta en la próxima actualización del mapa.' : !d ? 'Todavía no se consultó: aparece después de ⟳ Actualizar mapa.'
    : d.snmp === 'ok' ? `En la última actualización respondió con ${d.version === 'v1' ? 'SNMPv1' : 'SNMPv2c'}${d.community && d.community !== h.community ? ' y otra comunidad guardada' : ''}${d.marca || d.modelo ? ' · ' + [d.marca, (d.modelo || '').slice(0, 50)].filter(Boolean).join(' ') : ''}.`
    : `En la última actualización: ${d.estado || 'no respondió SNMP'}.`;
  const e = estadoHost(h);
  $('dlg-estado').className = 'disp ' + (esNuevo ? 'sin' : e.c);
  $('dlg-estado').innerHTML = esNuevo ? 'Host nuevo' : `<i></i>${esc(e.t)}`;
  ayudaTipo();
}
function abrirHost(i, sol) {
  editando = i === null ? null : HOSTS[i].ip;
  llenar(i === null ? { tipo: 'switch', puerto: 161, version: 'v2c', mapas: MAPA ? [MAPA] : [] } : HOSTS[i], i === null ? 'Nuevo host' : HOSTS[i].nombre, i === null);
  $('dlg').showModal(); $('h-nombre').focus();
  if (sol) solapa(sol);
}
function agregarDescubierto(i) {
  const d = DESC[i];
  editando = null;
  llenar({ nombre: d.nodo.replace(/\s*\(IP duplicada.*\)$/, '').slice(0, 60), ip: d.ip, tipo: /RouterOS/i.test(d.modelo) && !/CRS/i.test(d.modelo) ? 'router' : 'acceso',
           puerto: 161, version: 'v2c', community: d.snmp === 'ok' ? d.community : '', mapas: [MAPA] }, 'Nuevo host', true);
  $('dlg').showModal(); $('h-nombre').focus();
}
function clonar() {
  editando = null;
  const nombre = $('h-nombre').value;
  $('dlg-titulo').textContent = 'Nuevo host (clonado)'; $('b-guardar').textContent = 'Agregar';
  $('dlg-estado').className = 'disp sin'; $('dlg-estado').textContent = 'Host nuevo';
  $('b-clonar').hidden = $('b-borrar').hidden = true;
  $('h-nombre').value = nombre + ' (copia)'; $('h-ip').value = ''; bloqueados = new Set(); enlacesHost = []; pintarEnlacesHost(); actualizarTabPuertos(); solapa('host'); $('h-snmp-info').textContent = '';
  $('h-ip').focus();
}
function cerrar() { $('dlg').close(); }
$('dlg').addEventListener('click', e => { if (e.target === $('dlg')) cerrar(); });   // clic en el fondo oscuro
function verComm(b) { const c = $('h-comm'), o = c.type === 'password'; c.type = o ? 'text' : 'password'; b.textContent = o ? 'Ocultar' : 'Mostrar'; }
function error(t) { solapa('host'); const e = $('dlg-err'); e.textContent = t; e.hidden = false; }

async function post(datos, bloq, mapas, enl) {
  const cuerpo = new URLSearchParams({ csrf: CSRF, mapa: MAPA, ...datos });
  (enl || []).forEach(e => cuerpo.append('enlaces[]', JSON.stringify(e)));
  (bloq || []).forEach(b => cuerpo.append('bloqueados[]', b));
  (mapas || []).forEach(m => cuerpo.append('mapas[]', m));
  const r = await fetch(location.origin + location.pathname, { method: 'POST', body: cuerpo });
  return r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status }));
}
async function guardar() {
  const datos = { accion: 'host_guardar', ip_original: editando || '', nombre: $('h-nombre').value.trim(), tipo: $('h-tipo').value,
                  ip: $('h-ip').value.trim(), puerto: $('h-puerto').value.trim() || '161', version: $('h-version').value,
                  community: $('h-comm').value, aplicar: $('h-aplicar').checked ? '1' : '', puertos_enviados: '1',
                  mapas_enviados: '1', enlaces_enviados: '1', solo_manuales: $('e-solo').checked ? '1' : '',
                  ppp_enviado: '1', bloqueo_ppp: $('h-ppp').checked ? '1' : '',
                  api_enviado: '1', api_vlans: $('h-api').checked && ['router', 'switch'].includes($('h-tipo').value) ? '1' : '',
                  sin_escaneo_enviado: '1', sin_escaneo: $('h-sin').checked && !['router', 'switch'].includes($('h-tipo').value) ? '1' : '' };
  if (!datos.nombre || !datos.ip) return error('Nombre e IP son obligatorios.');
  const enl = enlacesHost.map(e => ({ pa: e.pa.trim(), b: e.b, pb: e.pb.trim(), rol: e.rol === 'respaldo' ? 'respaldo' : 'principal' }));
  if (enl.some(e => !e.b)) return error('Hay un enlace sin equipo de destino (solapa Enlaces): elige uno o quítalo.');
  $('b-guardar').disabled = true;
  try {
    const j = await post(datos, ['router', 'switch'].includes(datos.tipo) ? [...bloqueados] : [], [...selMapas], enl);
    if (!j.ok) return error(j.error || 'No se pudo guardar');
    location.reload();
  } catch (e) { error('No se pudo guardar: ' + e.message); }
  finally { $('b-guardar').disabled = false; }
}
async function borrar() {
  if (!confirm(`¿Eliminar el host "${$('h-nombre').value}" (${editando}) de la lista y de todos sus mapas? Dejará de consultarse; si sigue conectado puede volver a aparecer como descubierto.`)) return;
  const j = await post({ accion: 'host_borrar', ip: editando });
  if (!j.ok) return error(j.error || 'No se pudo eliminar');
  location.reload();
}
async function actualizar(b) {
  b.disabled = true; solapaPagina('historial');
  const j = await post({ accion: 'actualizar', ajax: '1' });
  if (!j.ok) { alert(j.error || 'No se pudo actualizar'); b.disabled = false; return; }
  refrescar(); setTimeout(() => b.disabled = false, 3000);
}

const NOMBRES = { ok: 'Completado', error: 'Error', ejecutando: 'En curso…' };
let estabaCorriendo = false;
async function refrescar() {
  if (!MAPA || !$('estado')) return;
  try {
    const e = await (await fetch(location.origin + location.pathname + '?estado=' + encodeURIComponent(MAPA), { cache: 'no-store' })).json();
    const div = $('estado'), log = $('log');
    if (e.estado === 'sin datos') {
      div.innerHTML = e.en_cola ? '<span class="estado ejecutando">En cola…</span>' : 'Todavía no se actualizó desde la web.';
    } else {
      const est = e.en_cola && e.estado !== 'ejecutando' ? 'ejecutando' : e.estado;
      div.innerHTML = `<span class="estado ${est}">${NOMBRES[est] || esc(est)}</span> · inicio ${esc(e.inicio || '-')}` +
        (e.fin ? ` · fin ${esc(e.fin)}` : '') + (e.resumen ? `<br>${esc(e.resumen)}` : '');
      if (e.log && e.log.length) { log.hidden = false; log.textContent = e.log.join('\n'); }
      $('s-hist').innerHTML = est === 'ejecutando' ? '<span class="nuevo">en curso</span>' : est === 'error' ? '<span class="nuevo" style="background:#7f1d1d;color:#fecaca">error</span>' : '';
      if (est === 'ejecutando') { estabaCorriendo = true; setTimeout(refrescar, 3000); return; }
      if (estabaCorriendo) { location.reload(); return; }   // termino: recargar la disponibilidad
    }
    if (e.en_cola) { estabaCorriendo = true; setTimeout(refrescar, 3000); }
  } catch (err) { setTimeout(refrescar, 5000); }
}
function solapaPagina(s) {
  if (!document.querySelector(`[data-panel="${s}"]`)) s = 'hosts';
  document.querySelectorAll('.solapas [data-s]').forEach(b => b.setAttribute('aria-selected', b.dataset.s === s));
  document.querySelectorAll('[data-panel]').forEach(p => p.hidden = p.dataset.panel !== s);
  history.replaceState(null, '', s === 'hosts' ? location.pathname + location.search : '#' + s);
}
document.querySelectorAll('.solapas [data-s]').forEach(b => b.onclick = () => solapaPagina(b.dataset.s));
document.querySelectorAll('.kpis [data-d]').forEach(b => b.onclick = () => {
  filtroDisp = b.dataset.d; document.querySelectorAll('.kpis [data-d]').forEach(x => x.setAttribute('aria-pressed', x === b)); pintar();
});
document.querySelectorAll('#f-tipo [data-t]').forEach(b => b.onclick = () => {
  filtroTipo = b.dataset.t; document.querySelectorAll('#f-tipo [data-t]').forEach(x => x.setAttribute('aria-pressed', x === b)); pintar();
});
if (MAPA) solapaPagina(location.hash.slice(1) || 'hosts');
pintar(); pintarDescubiertos(); pintarOcultos(); pintarEnlaces(); refrescar();
<?php if (isset($_GET['importar'])): ?>$('dlg-csv').showModal();<?php endif; ?>
</script>
</body>
</html>
