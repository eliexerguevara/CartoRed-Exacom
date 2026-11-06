<?php
// Historial de un mapa (7 dias, lo guarda seccart-web-run en cada actualizacion)
//   historial.php?map=<mapa>            -> {"ok":true,"items":[{"id","fecha","equipos","enlaces"}, ...]}
//   historial.php?map=<mapa>&id=<id>    -> el mapa tal como estaba en ese momento
require __DIR__ . '/lib.php';
requiere_login();
const HISTORIAL = '/var/lib/seccart-web/historial';

$mapa = $_GET['map'] ?? '';
header('Cache-Control: no-store');
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $mapa)) { http_response_code(400); exit; }

if (isset($_GET['id'])) {
    $id = (string)$_GET['id'];
    $f = HISTORIAL . "/$mapa/$id.json.gz";
    if (!preg_match('/^\d{8}-\d{6}$/', $id) || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: application/json');
    header('Cache-Control: private, max-age=604800');   // un instante guardado no cambia
    readgzfile($f);
    exit;
}

$items = json_decode((string)@file_get_contents(HISTORIAL . "/$mapa/indice.json"), true);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'items' => is_array($items) ? array_values($items) : []]);
