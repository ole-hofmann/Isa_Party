<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$cfg = config();
start_session();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$loginError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (try_login((string)$_POST['password'])) {
        header('Location: ./');
        exit;
    }
    sleep(1); // bremst Rateversuche
    $loginError = true;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$price = (int)$cfg['price'];
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d0a12">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<title><?= e($cfg['title']) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎃</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Creepster&display=swap">
<link rel="stylesheet" href="assets/style.css?v=2">
</head>
<?php if (!is_logged_in()): ?>
<body class="login-page">
  <main class="login">
    <div class="pumpkin" aria-hidden="true">🎃</div>
    <h1><?= e($cfg['title']) ?></h1>
    <p class="sub">Nur für die Türsteher*innen</p>
    <form method="post">
      <input type="password" name="password" placeholder="Passwort" autocomplete="current-password" autofocus required>
      <?php if ($loginError): ?><p class="error">Falsches Passwort 👻</p><?php endif ?>
      <button class="btn ok big" type="submit">Rein da</button>
    </form>
  </main>
</body>
<?php else: ?>
<body data-price="<?= $price ?>">
  <header class="top">
    <div class="title-row">
      <h1><span aria-hidden="true">🎃</span> <?= e($cfg['title']) ?></h1>
      <details class="menu">
        <summary aria-label="Menü">⋮</summary>
        <div class="menu-panel">
          <a href="api.php?action=export">Liste als CSV laden</a>
          <a href="?logout=1">Abmelden</a>
        </div>
      </details>
    </div>
    <div class="stats">
      <div><strong id="stat-in">–</strong><span>drin</span></div>
      <div><strong id="stat-open">–</strong><span>fehlen noch</span></div>
      <div><strong id="stat-cash">–</strong><span>Kasse</span></div>
    </div>
    <div class="search-wrap">
      <input id="search" type="search" placeholder="Name suchen …" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
      <button id="clear" class="clear" type="button" aria-label="Suche leeren" hidden>✕</button>
    </div>
    <div class="filters" role="tablist">
      <button type="button" data-filter="all" class="active">Alle</button>
      <button type="button" data-filter="open">Offen</button>
      <button type="button" data-filter="in">Drin</button>
    </div>
    <div id="offline" class="offline" hidden>⚠️ Keine Verbindung – Anzeige evtl. nicht aktuell</div>
  </header>

  <main>
    <ul id="list" class="list"></ul>
    <div id="empty" class="empty" hidden>
      <p class="ghost-big" aria-hidden="true">👻</p>
      <p id="empty-text">Nicht auf der Liste.</p>
      <div id="suggest" class="suggest"></div>
      <button id="btn-add-open" class="btn ghost" type="button">＋ Trotzdem hinzufügen</button>
    </div>
  </main>

  <dialog id="sheet" class="sheet">
    <p id="sheet-tag" class="tag"></p>
    <h2 id="sheet-name"></h2>
    <p id="sheet-info" class="info"></p>
    <button id="btn-pay" class="btn ok big" type="button">💶 <?= $price ?> € erhalten – rein!</button>
    <button id="btn-undo" class="btn danger" type="button">Einlass rückgängig machen</button>
    <div class="btn-row">
      <button id="btn-rename" class="btn ghost" type="button">✏️ Umbenennen</button>
      <button id="btn-delete" class="btn ghost" type="button">🗑️ Löschen</button>
    </div>
    <button class="btn ghost" type="button" data-close>Abbrechen</button>
  </dialog>

  <dialog id="add" class="sheet">
    <h2 id="add-title">Gast hinzufügen</h2>
    <p id="add-info" class="info"></p>
    <form id="add-form">
      <input id="add-name" type="text" placeholder="Vor- und Nachname" autocomplete="off" required minlength="2">
      <button id="add-submit" class="btn ok big" type="submit">Hinzufügen</button>
    </form>
    <button class="btn ghost" type="button" data-close>Abbrechen</button>
  </dialog>

  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>

  <script src="assets/app.js?v=2"></script>
</body>
<?php endif ?>
</html>
