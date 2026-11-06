<?php
// Datos publicados de un mapa (data/<mapa>.json), solo con sesion iniciada
require __DIR__ . '/lib.php';
requiere_login();
session_write_close();
$m = $_GET['map'] ?? '';
$f = __DIR__ . '/data/' . $m . '.json';
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $m) || !is_file($f)) { http_response_code(404); header('Content-Type: application/json'); echo '{"error":"Mapa no publicado"}'; exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
readfile($f);
