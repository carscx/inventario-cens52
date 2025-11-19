<!-- app/header.php -->
<header class="page-header">
  <div class="main-header">
    <div class="logo">
      <a href="index.php">
        <img src="/assets/logo.png" alt="Logo Inventario">
      </a>
    </div>
    <h1>CENS Nº52 - René Favaloro</h1>
  </div>

  <div class="subheader">
    <h2>Inventario <span class="muted"><?php echo $header_title ?? 'Listado'; ?></span></h2>

    <div class="header-actions">

      <!-- Botón Toggle Tema (Sol/Luna) -->
      <button id="theme-toggle" class="secondary outline" title="Cambiar tema" style="border:none; padding:0.5rem;">
        <svg id="icon-moon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
        <svg id="icon-sun" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
      </button>

      <?php
      if (session_status() === PHP_SESSION_NONE)
        session_start();
      $is_logged = !empty($_SESSION['auth_ok']);
      ?>

      <?php if ($is_logged): ?>
        <!-- USUARIO LOGUEADO: Muestra Salir y Nuevo -->
        <a role="button" class="secondary outline" href="logout.php">Salir</a>

        <?php if (($show_new_button ?? true) === true): ?>
          <a role="button" class="contrast" href="nuevo.php">+ Nuevo ítem</a>
        <?php endif; ?>

      <?php else: ?>
        <!-- USUARIO INVITADO: Solo muestra Acceder -->
        <a role="button" class="contrast" href="login.php">Acceder</a>
      <?php endif; ?>

    </div>
  </div>
</header>

<script>
  (function () {
    const html = document.documentElement;
    const toggleBtn = document.getElementById('theme-toggle');
    const iconSun = document.getElementById('icon-sun');
    const iconMoon = document.getElementById('icon-moon');

    const savedTheme = localStorage.getItem('pico_theme');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    let currentTheme = savedTheme || systemTheme;

    applyTheme(currentTheme);

    function applyTheme(theme) {
      html.setAttribute('data-theme', theme);
      if (theme === 'dark') {
        iconSun.style.display = 'block';
        iconMoon.style.display = 'none';
      } else {
        iconSun.style.display = 'none';
        iconMoon.style.display = 'block';
      }
    }

    toggleBtn.addEventListener('click', function () {
      currentTheme = (currentTheme === 'dark') ? 'light' : 'dark';
      applyTheme(currentTheme);
      localStorage.setItem('pico_theme', currentTheme);
    });
  })();
</script>