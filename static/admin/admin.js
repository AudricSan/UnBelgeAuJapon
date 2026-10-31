(() => {
  const apiUrl = "/admin/api.php";
  const state = { csrf: "", user: null, roles: [], articles: [] };
  const panels = ["bootstrap-panel", "login-panel", "password-panel", "app-panel"];
  const status = document.querySelector("#status-message");

  const savedTheme = localStorage.getItem("pref-theme");
  const theme = savedTheme === "dark" || savedTheme === "light"
    ? savedTheme
    : (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
  document.documentElement.dataset.theme = theme;

  function showMessage(message, isError = false) {
    status.textContent = message || "";
    status.classList.toggle("is-error", isError);
    status.classList.toggle("is-success", !isError && Boolean(message));
  }

  function setVisible(id, visible) {
    document.querySelector(`#${id}`).hidden = !visible;
  }

  async function request(action, options = {}) {
    const method = options.method || "GET";
    const url = method === "GET"
      ? `${apiUrl}?${new URLSearchParams({ action, ...(options.params || {}) })}`
      : `${apiUrl}?action=${encodeURIComponent(action)}`;
    const headers = { Accept: "application/json" };
    const init = { method, credentials: "same-origin", headers, cache: "no-store" };
    if (method !== "GET") {
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = state.csrf;
      init.body = JSON.stringify(options.body || {});
    }
    const response = await fetch(url, init);
    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error("Le serveur a renvoyé une réponse invalide.");
    }
    if (!response.ok) throw new Error(data.error || "La demande a échoué.");
    return data;
  }

  function formValue(form, name) {
    return String(new FormData(form).get(name) || "").trim();
  }

  function splitList(value) {
    return value.split(/[\n,]/).map((item) => item.trim()).filter(Boolean);
  }

  function setDateDefaults() {
    const now = new Date();
    const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
    document.querySelector('#article-form [name="date"]').value = today;
    document.querySelector('#translation-form [name="date"]').value = today;
  }

  function slugify(value) {
    return value.normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
  }

  function hasRole(role) {
    return state.roles.includes("admin") || state.roles.includes(role);
  }

  function setupTabs() {
    const definitions = [
      ["create-view", "editor"],
      ["translate-view", "translator"],
      ["users-view", "admin"],
    ];
    const tabs = [...document.querySelectorAll(".tab-button")];
    for (const [viewId, role] of definitions) {
      const button = tabs.find((item) => item.dataset.tab === viewId);
      if (button) button.hidden = !hasRole(role);
    }

    for (const button of tabs) {
      button.addEventListener("click", () => showView(button.dataset.tab));
    }

    const first = definitions.find(([viewId, role]) => hasRole(role));
    if (first) showView(first[0]);
  }

  function showView(viewId) {
    for (const section of ["create-view", "translate-view", "users-view"]) {
      setVisible(section, section === viewId);
    }
    for (const button of document.querySelectorAll(".tab-button")) {
      const active = button.dataset.tab === viewId;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", String(active));
    }
    if (viewId === "translate-view" || viewId === "users-view") loadArticles().catch((error) => showMessage(error.message, true));
    if (viewId === "users-view" && hasRole("admin")) loadUsers().catch((error) => showMessage(error.message, true));
  }

  function showAuthenticatedApp() {
    for (const id of panels) setVisible(id, false);
    setVisible("app-panel", true);
    document.querySelector("#account-name").textContent = state.user.display_name;
    const roleContainer = document.querySelector("#account-roles");
    roleContainer.replaceChildren();
    for (const role of state.roles) {
      const badge = document.createElement("span");
      badge.className = "role-badge";
      badge.textContent = ({ editor: "Éditeur", translator: "Traducteur", admin: "Admin" })[role] || role;
      roleContainer.append(badge);
    }
    document.querySelector("#user-management").hidden = !hasRole("admin");
    document.querySelector("#article-management").hidden = !hasRole("admin");
    setupTabs();
  }

  async function refreshSession() {
    try {
      const session = await request("session");
      state.csrf = session.csrf;
      state.user = session.user;
      state.roles = session.roles || [];
      for (const id of panels) setVisible(id, false);
      if (session.needsBootstrap && !session.authenticated) {
        setVisible("bootstrap-panel", true);
        showMessage("Initialise l’accès administrateur pour commencer.");
      } else if (!session.authenticated) {
        setVisible("login-panel", true);
        showMessage("");
      } else if (session.mustChangePassword) {
        setVisible("password-panel", true);
        showMessage("Choisis un mot de passe personnel pour continuer.");
      } else {
        showMessage("");
        showAuthenticatedApp();
      }
    } catch (error) {
      for (const id of panels) setVisible(id, false);
      showMessage(error.message, true);
    }
  }

  async function handleForm(form, action, bodyFactory, successMessage) {
    if (!form.reportValidity()) return;
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
      await request(action, { method: "POST", body: bodyFactory() });
      form.reset();
      await refreshSession();
      showMessage(successMessage);
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      if (button) button.disabled = false;
    }
  }

  document.querySelector("#bootstrap-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const password = formValue(form, "password");
    if (password !== formValue(form, "confirmPassword")) {
      showMessage("Les deux mots de passe ne correspondent pas.", true);
      return;
    }
    await handleForm(form, "bootstrap", () => ({
      bootstrapKey: formValue(form, "bootstrapKey"),
      displayName: formValue(form, "displayName"),
      email: formValue(form, "email"),
      password,
    }), "Compte administrateur créé.");
  });

  document.querySelector("#login-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    await handleForm(form, "login", () => ({
      email: formValue(form, "email"),
      password: formValue(form, "password"),
    }), "Connexion réussie.");
  });

  document.querySelector("#password-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const newPassword = formValue(form, "newPassword");
    if (newPassword !== formValue(form, "confirmPassword")) {
      showMessage("Les deux nouveaux mots de passe ne correspondent pas.", true);
      return;
    }
    await handleForm(form, "password.change", () => ({
      oldPassword: formValue(form, "oldPassword"),
      newPassword,
    }), "Mot de passe modifié.");
  });

  async function logout() {
    try {
      await request("logout", { method: "POST" });
    } catch (error) {
      showMessage(error.message, true);
    }
    state.user = null;
    state.roles = [];
    await refreshSession();
  }

  document.querySelector("#logout-button").addEventListener("click", logout);
  document.querySelector("#password-logout").addEventListener("click", logout);

  const articleForm = document.querySelector("#article-form");
  const titleField = articleForm.elements.title;
  const slugField = articleForm.elements.slug;
  let userEditedSlug = false;
  titleField.addEventListener("input", () => {
    if (!userEditedSlug) slugField.value = slugify(titleField.value);
  });
  slugField.addEventListener("input", () => {
    userEditedSlug = true;
    slugField.value = slugify(slugField.value);
  });

  articleForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const body = {
      section: form.elements.section.value,
      slug: formValue(form, "slug"),
      title: formValue(form, "title"),
      date: form.elements.date.value,
      description: formValue(form, "description"),
      keywords: splitList(formValue(form, "keywords")),
      categories: splitList(formValue(form, "categories")),
      tags: splitList(formValue(form, "tags")),
      draft: form.elements.draft.checked,
      body: formValue(form, "body"),
    };
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await request("articles.create", { method: "POST", body });
      form.reset();
      userEditedSlug = false;
      setDateDefaults();
      showMessage("Article enregistré dans GitHub. Le déploiement peut maintenant le publier.");
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  async function loadArticles() {
    const result = await request("articles.list");
    state.articles = result.articles || [];
    renderArticleSelect();
    if (hasRole("admin")) renderDeleteArticles();
  }

  function articleLabel(article) {
    const languages = article.languages.includes("ja") ? "FR + JA" : "FR";
    return `${article.section} / ${article.slug} — ${languages}`;
  }

  function renderArticleSelect() {
    const select = document.querySelector('#translation-form [name="article"]');
    const current = select.value;
    select.replaceChildren(new Option("Choisir un article", ""));
    for (const article of state.articles.filter((item) => item.languages.includes("fr"))) {
      const value = `${article.section}::${article.slug}`;
      select.add(new Option(articleLabel(article), value));
    }
    if ([...select.options].some((item) => item.value === current)) select.value = current;
  }

  document.querySelector('#translation-form [name="article"]').addEventListener("change", async (event) => {
    const [section, slug] = event.currentTarget.value.split("::");
    const preview = document.querySelector("#source-preview");
    if (!section || !slug) {
      preview.textContent = "Sélectionne un article pour afficher le texte français.";
      return;
    }
    preview.textContent = "Chargement de l’article…";
    try {
      const source = await request("articles.get", { params: { section, slug } });
      preview.textContent = source.source;
      const date = source.source.match(/^date:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})\s*$/m);
      if (date) document.querySelector('#translation-form [name="date"]').value = date[1];
    } catch (error) {
      preview.textContent = "Impossible de charger l’article source.";
      showMessage(error.message, true);
    }
  });

  document.querySelector("#translation-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const [section, slug] = form.elements.article.value.split("::");
    if (!section || !slug) {
      showMessage("Choisis l’article à traduire.", true);
      return;
    }
    const body = {
      section,
      slug,
      title: formValue(form, "title"),
      date: form.elements.date.value,
      description: formValue(form, "description"),
      keywords: splitList(formValue(form, "keywords")),
      categories: splitList(formValue(form, "categories")),
      tags: splitList(formValue(form, "tags")),
      body: formValue(form, "body"),
      draft: false,
    };
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      await request("articles.translate", { method: "POST", body });
      showMessage("Traduction japonaise enregistrée dans GitHub.");
      await loadArticles();
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  function createRoleInput(role, checked, name = "roles") {
    const label = document.createElement("label");
    label.className = "role-option";
    const input = document.createElement("input");
    input.type = "checkbox";
    input.name = name;
    input.value = role;
    input.checked = checked;
    const text = document.createElement("span");
    text.textContent = ({ editor: "Éditeur", translator: "Traducteur", admin: "Admin" })[role] || role;
    label.append(input, text);
    return label;
  }

  document.querySelector("#user-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const roles = [...form.querySelectorAll('input[name="roles"]:checked')].map((input) => input.value);
    if (roles.length === 0) {
      showMessage("Attribue au moins un rôle à ce compte.", true);
      return;
    }
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await request("users.create", { method: "POST", body: {
        displayName: formValue(form, "displayName"),
        email: formValue(form, "email"),
        roles,
      } });
      form.reset();
      showTemporaryPassword(result.temporaryPassword);
      showMessage("Compte ajouté. Transmets le mot de passe temporaire à son utilisateur.");
      await loadUsers();
    } catch (error) {
      showMessage(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  function showTemporaryPassword(password) {
    const container = document.querySelector("#temporary-password");
    container.replaceChildren();
    const message = document.createElement("p");
    message.textContent = "Mot de passe temporaire — copie-le maintenant, il ne sera plus affiché : ";
    const code = document.createElement("code");
    code.textContent = password;
    const copy = document.createElement("button");
    copy.type = "button";
    copy.className = "button button-secondary";
    copy.textContent = "Copier";
    copy.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(password);
        showMessage("Mot de passe copié.");
      } catch {
        showMessage("La copie automatique a échoué. Sélectionne le mot de passe et copie-le.", true);
      }
    });
    message.append(code);
    container.append(message, copy);
    container.hidden = false;
  }

  async function loadUsers() {
    if (!hasRole("admin")) return;
    const result = await request("users.list");
    renderUsers(result.users || []);
  }

  function renderUsers(users) {
    const container = document.querySelector("#users-list");
    container.replaceChildren();
    if (users.length === 0) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = "Aucun utilisateur.";
      container.append(empty);
      return;
    }
    for (const user of users) {
      const row = document.createElement("article");
      row.className = "user-row";
      const details = document.createElement("div");
      details.className = "user-details";
      const name = document.createElement("strong");
      name.textContent = user.display_name;
      const email = document.createElement("span");
      email.textContent = user.email;
      details.append(name, email);

      const roleGroup = document.createElement("fieldset");
      roleGroup.className = "role-picker compact";
      roleGroup.setAttribute("aria-label", `Rôles de ${user.display_name}`);
      for (const role of ["editor", "translator", "admin"]) {
        roleGroup.append(createRoleInput(role, user.roles.includes(role), `role-${user.id}`));
      }

      const actions = document.createElement("div");
      actions.className = "user-actions";
      const save = document.createElement("button");
      save.type = "button";
      save.className = "button button-secondary";
      save.textContent = "Enregistrer les rôles";
      save.addEventListener("click", async () => {
        const roles = [...roleGroup.querySelectorAll("input:checked")].map((input) => input.value);
        save.disabled = true;
        try {
          await request("users.update", { method: "POST", body: { userId: user.id, roles } });
          showMessage(`Rôles enregistrés pour ${user.display_name}.`);
          await loadUsers();
        } catch (error) {
          showMessage(error.message, true);
        } finally {
          save.disabled = false;
        }
      });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "button button-danger";
      remove.textContent = "Supprimer le compte";
      remove.addEventListener("click", async () => {
        if (!window.confirm(`Supprimer le compte de ${user.display_name} ?`)) return;
        remove.disabled = true;
        try {
          await request("users.delete", { method: "POST", body: { userId: user.id } });
          showMessage(`Compte de ${user.display_name} supprimé.`);
          await loadUsers();
        } catch (error) {
          showMessage(error.message, true);
        } finally {
          remove.disabled = false;
        }
      });
      actions.append(save, remove);
      row.append(details, roleGroup, actions);
      container.append(row);
    }
  }

  function renderDeleteArticles() {
    const container = document.querySelector("#delete-articles-list");
    container.replaceChildren();
    if (state.articles.length === 0) {
      const empty = document.createElement("p");
      empty.className = "muted";
      empty.textContent = "Aucun article trouvé dans le dépôt.";
      container.append(empty);
      return;
    }
    for (const article of state.articles) {
      const row = document.createElement("article");
      row.className = "article-row";
      const label = document.createElement("strong");
      label.textContent = articleLabel(article);
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "button button-danger";
      remove.textContent = "Supprimer";
      remove.addEventListener("click", async () => {
        if (!window.confirm(`Supprimer « ${article.slug} » et toutes ses traductions du dépôt ?`)) return;
        remove.disabled = true;
        try {
          await request("articles.delete", { method: "POST", body: { section: article.section, slug: article.slug } });
          showMessage(`Article « ${article.slug} » supprimé du dépôt.`);
          await loadArticles();
        } catch (error) {
          showMessage(error.message, true);
        } finally {
          remove.disabled = false;
        }
      });
      row.append(label, remove);
      container.append(row);
    }
  }

  setDateDefaults();
  refreshSession();
})();
