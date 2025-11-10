<?php
// app/auth.php

/**
 * Archivo central de autenticación.
 * Inicia la sesión y define la lógica de protección.
 */

// 1. Definición de la contraseña
// ¡Cambia esto en el futuro si es necesario!
define('AUTH_PASSWORD', 'Cens52-2025');

// 2. Asegura que la sesión esté iniciada
// (De forma segura, sin iniciarla dos veces)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Función de protección (el "guardia").
 *
 * Comprueba si el usuario está autenticado (si $_SESSION['auth_ok'] es true).
 * Si no lo está, lo redirige a 'login.php' y guarda la página
 * a la que intentaba acceder (ej. editar.php?id=5) para volver
 * a ella después del login.
 */
function require_auth(): void {
    if (empty($_SESSION['auth_ok'])) {
        // Guarda la URL a la que se intentaba acceder
        $return_to = $_SERVER['REQUEST_URI'] ?? '/';

        // Redirige a la página de login
        header('Location: /login.php?return_to=' . urlencode($return_to));
        exit;
    }
}
