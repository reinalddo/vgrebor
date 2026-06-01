<?php
// Ejemplo de uso de URL amigables para juegos y paquetes
require_once 'includes/slugify.php';

// Para un juego:
// $juego_nombre = 'Call of Duty';
// $juego_slug = slugify($juego_nombre); // call-of-duty

// Para un paquete:
// $paquete_nombre = '80 Coins';
// $paquete_slug = slugify($paquete_nombre); // 80-coins

// Puedes usar estos slugs en tus URLs, por ejemplo:
// /juego/call-of-duty
// /juego/call-of-duty/paquete/80-coins
