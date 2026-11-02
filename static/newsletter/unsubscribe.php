<?php
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Se désinscrire de la newsletter</title>
    <style>
      body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        background: #f8fafc;
        color: #0f172a;
      }
      .card {
        width: min(560px, calc(100vw - 2rem));
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 2rem;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
      }
      h1 { margin-top: 0; font-size: clamp(1.8rem, 4vw, 2.4rem); }
      form {
        display: grid;
        gap: 0.9rem;
        margin-top: 1.25rem;
      }
      input, button {
        font: inherit;
        min-height: 2.9rem;
        border-radius: 10px;
      }
      input {
        border: 1px solid #cbd5e1;
        padding: 0.7rem 0.9rem;
      }
      button {
        border: none;
        background: #0f172a;
        color: white;
        cursor: pointer;
        font-weight: 600;
      }
      .status {
        min-height: 1.5rem;
        margin-top: 0.5rem;
        color: #334155;
      }
      a { color: #0f172a; }
    </style>
  </head>
  <body>
    <main class="card">
      <h1>Se désinscrire</h1>
      <p>Renseignez votre adresse e-mail pour arrêter la réception de la newsletter.</p>
      <form id="unsubscribe-form">
        <label for="unsubscribe-email" class="sr-only">E-mail</label>
        <input id="unsubscribe-email" name="email" type="email" placeholder="votre@email.com" required>
        <button type="submit">Me désinscrire</button>
      </form>
      <p id="unsubscribe-status" class="status" aria-live="polite"></p>
      <p><a href="/">Retourner à l’accueil</a></p>
    </main>

    <script>
      const form = document.getElementById('unsubscribe-form');
      const emailInput = document.getElementById('unsubscribe-email');
      const status = document.getElementById('unsubscribe-status');
      const params = new URLSearchParams(window.location.search);
      const initialEmail = params.get('email');
      if (initialEmail) {
        emailInput.value = initialEmail;
      }

      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const email = document.getElementById('unsubscribe-email').value.trim();
        if (!email) {
          status.textContent = 'Veuillez renseigner une adresse e-mail valide.';
          return;
        }

        status.textContent = 'Traitement en cours...';
        try {
          const response = await fetch('../admin/api.php?action=newsletter.unsubscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok) {
            throw new Error(data.error || 'La désinscription a échoué.');
          }
          form.reset();
          status.textContent = 'Vous êtes bien désinscrit·e de la newsletter.';
        } catch (error) {
          status.textContent = error.message || 'La désinscription a échoué.';
        }
      });
    </script>
  </body>
</html>
