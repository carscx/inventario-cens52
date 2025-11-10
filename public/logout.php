<?php
// public/logout.php

// Carga auth.php (solo para iniciar la sesión y poder destruirla)
require __DIR__ . '/../app/auth.php';

// Elimina la variable de autenticación
unset($_SESSION['auth_ok']);

// Opcional: destruye la sesión completa si solo se usa para esto
// session_destroy();

// Redirige a la página principal
header('Location: index.php');
exit;
?>