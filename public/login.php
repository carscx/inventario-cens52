<?php
// public/login.php

// Carga el bootstrap (por si acaso) y nuestro nuevo auth.php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php'; // Inicia sesión y define AUTH_PASSWORD

$error = null;
// Guarda la URL de retorno (la que venía en ?return_to=...)
$return_to = $_GET['return_to'] ?? 'index.php';

// Config del Header
$header_title = '';
$show_new_button = false;
$show_login_button = false;

// Si el usuario YA está logueado, lo mandamos a la página de inicio
if (!empty($_SESSION['auth_ok'])) {
  header('Location: index.php');
  exit;
}

// Lógica de procesamiento del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass = $_POST['password'] ?? '';
  $return_to = $_POST['return_to'] ?? 'index.php';

  // Usamos hash_equals() para una comparación segura (anti-ataques de tiempo)
  if (hash_equals(AUTH_PASSWORD, $pass)) {
    // ¡Contraseña correcta!
    session_regenerate_id(true); // Regenera ID de sesión por seguridad
    $_SESSION['auth_ok'] = true; // Marca al usuario como autenticado

    // Redirige a la página a la que quería ir (ej. editar.php?id=5)
    header('Location: ' . $return_to);
    exit;
  } else {
    // Contraseña incorrecta
    $error = 'Contraseña incorrecta.';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Acceso requerido</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="/assets/ui.css">
</head>
<body>
  <main class="app-container" style="max-width: 500px; margin-top: 2rem;">
    <?php require __DIR__ . '/../app/header.php'; ?>
    <h2>Acceso protegido</h2>
    <p>Debes ingresar la contraseña de modificación para continuar.</p>

    <?php if ($error): ?>
      <article class="notice"><strong>Error:</strong> <?= htmlspecialchars($error) ?></article>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($return_to) ?>">

      <label>Contraseña
        <input type="password" name="password" required autofocus>
      </label>
      <button type="submit">Acceder</button>
    </form>
  </main>
</body>
</html>