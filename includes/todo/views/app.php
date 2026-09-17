<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= \Todo\Application::h($csrf) ?>"><title>Gemeinsam · Aufgaben & Agenten</title><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script></head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="/" aria-label="Gemeinsam Startseite">g<span>Gemeinsam<small>DEIN ARBEITSRAUM</small></span></a>
    <button class="primary new-task" data-command="new-task"><span>＋</span> Neue Aufgabe <kbd>N</kbd></button>
    <nav aria-label="Hauptnavigation">
      <button data-page="attention" class="active"><span>◉</span> Meine Aufmerksamkeit <b id="attention-count">0</b></button>
      <button data-page="projects"><span>▦</span> Projekte</button>
      <button data-page="tasks"><span>☷</span> Aufgaben</button>
      <button data-page="activity"><span>↗</span> Agentenaktivität</button>
      <div class="nav-label">ARBEITSWEISE</div>
      <button data-page="skills"><span>◇</span> Skills</button>
      <button data-page="integrations"><span>⊞</span> Integrationen</button>
      <button data-page="settings"><span>⚙</span> Einstellungen</button>
    </nav>
    <div id="project-shortcuts" class="project-shortcuts"></div>
    <div class="sidebar-bottom"><span class="avatar">DU</span><div>Mein Workspace<small>Nutzer & Agenten</small></div><form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= \Todo\Application::h($csrf) ?>"><button title="Abmelden" aria-label="Abmelden">↪</button></form></div>
  </aside>
  <main class="main">
    <header class="topbar"><div class="breadcrumb">Mein Workspace <span>/</span> <span id="breadcrumb">Meine Aufmerksamkeit</span></div><span class="private-label"><i></i> Dein privater Arbeitsraum</span></header>
    <section class="page-heading"><div><div class="eyebrow" id="eyebrow">GEMEINSAM WEITERKOMMEN</div><h1 id="page-title">Meine Aufmerksamkeit</h1><p id="page-description">Alles, was jetzt deine Entscheidung braucht.</p></div><button class="primary" data-command="new-task">＋ Neue Aufgabe</button></section>
    <div id="toolbar" class="toolbar"></div>
    <div id="content" class="content" aria-live="polite"><div class="empty">Dein Arbeitsraum wird geladen …</div></div>
  </main>
  <aside id="detail" class="detail" aria-label="Aufgabendetails" hidden></aside>
</div>
<dialog id="editor"><form id="editor-form"><div class="dialog-heading"><h2 id="editor-title"></h2><button type="button" data-command="close-dialog" aria-label="Schließen">×</button></div><div id="editor-fields"></div><div class="actions"><button class="primary" type="submit">Speichern</button><button type="button" data-command="close-dialog">Abbrechen</button></div><p class="form-error" role="alert"></p></form></dialog>
<div id="toast" role="status" hidden></div>
</body></html>
