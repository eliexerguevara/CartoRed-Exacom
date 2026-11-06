<?php
// Visor del mapa: solo con sesion iniciada (viewer.html directo redirige aqui)
require __DIR__ . '/lib.php';
requiere_login();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/viewer.html');
