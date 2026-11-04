/* Administration : interface unique (JS sans dépendance). */
(function () {
  'use strict';

  var root = document.getElementById('app');
  var csrf = '';
  var state = { tab: 'stats', days: 30 };

  /* ───────── Utilitaires ───────── */

  function el(tag, attrs) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (key === 'class') node.className = value;
      else if (key.slice(0, 2) === 'on') node.addEventListener(key.slice(2), value);
      else if (value === true) node.setAttribute(key, '');
      else if (value !== false && value != null) node.setAttribute(key, value);
    });
    for (var i = 2; i < arguments.length; i++) append(node, arguments[i]);
    return node;
  }

  function append(node, child) {
    if (child == null || child === false) return;
    if (Array.isArray(child)) child.forEach(function (c) { append(node, c); });
    else node.appendChild(child.nodeType ? child : document.createTextNode(String(child)));
  }

  function api(action, options) {
    options = options || {};
    var query = options.query ? '&' + new URLSearchParams(options.query) : '';
    var init = { method: options.body ? 'POST' : 'GET', credentials: 'same-origin', headers: {} };
    if (options.body) {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-Token'] = csrf;
      init.body = JSON.stringify(options.body);
    }
    return fetch('/admin/api.php?action=' + action + query, init).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          if (response.status === 401) boot();
          throw new Error(data.error || 'Erreur ' + response.status);
        }
        return data;
      });
    });
  }

  function notice(message, isError) {
    var box = document.getElementById('notice');
    if (!box) return;
    box.textContent = message;
    box.className = 'notice ' + (isError ? 'is-error' : 'is-ok');
    box.hidden = !message;
  }

  function run(task) {
    notice('');
    return task().catch(function (error) { notice(error.message, true); });
  }

  var number = new Intl.NumberFormat('fr-FR');
  function date(ts) { return new Date(ts * 1000).toLocaleDateString('fr-FR'); }

  /* ───────── Connexion et structure ───────── */

  function boot() {
    api('session').then(function (data) {
      csrf = data.csrf;
      if (data.authenticated) {
        localStorage.setItem('mvaj-ignore', '1'); // n'inclut pas mes propres visites dans les stats
        shell(data.email);
      } else {
        loginView();
      }
    }).catch(function (error) {
      root.replaceChildren(el('p', { class: 'loading' }, error.message));
    });
  }

  function loginView() {
    var form = el('form', { class: 'card login', onsubmit: function (event) {
      event.preventDefault();
      var values = new FormData(form);
      run(function () {
        return api('login', { body: { email: values.get('email'), password: values.get('password') } }).then(boot);
      });
    } },
      el('h1', {}, 'Administration'),
      el('p', { id: 'notice', class: 'notice', hidden: true }),
      el('label', {}, 'E-mail', el('input', { name: 'email', type: 'email', autocomplete: 'username', required: true })),
      el('label', {}, 'Mot de passe', el('input', { name: 'password', type: 'password', autocomplete: 'current-password', required: true })),
      el('button', { class: 'button', type: 'submit' }, 'Se connecter')
    );
    root.replaceChildren(form);
  }

  var tabs = [['stats', 'Statistiques'], ['articles', 'Articles'], ['newsletter', 'Newsletter']];

  function shell(email) {
    var content = el('main', { id: 'content' });
    var nav = el('nav', { class: 'tabs' }, tabs.map(function (tab) {
      return el('button', { type: 'button', class: tab[0] === state.tab ? 'is-active' : '', onclick: function () {
        state.tab = tab[0];
        shell(email);
      } }, tab[1]);
    }));
    root.replaceChildren(
      el('header', { class: 'top' },
        el('a', { class: 'brand', href: '/' }, 'Un Belge au Japon'),
        nav,
        el('span', { class: 'who' }, email,
          el('button', { class: 'link', type: 'button', onclick: function () { api('logout', { body: {} }).then(boot); } }, 'Quitter'))
      ),
      el('p', { id: 'notice', class: 'notice', hidden: true }),
      content
    );
    ({ stats: statsView, articles: articlesView, newsletter: newsletterView })[state.tab](content);
  }

  /* ───────── Statistiques ───────── */

  function statsView(content) {
    content.replaceChildren(el('p', { class: 'loading' }, 'Chargement…'));
    run(function () {
      return api('stats', { query: { days: state.days } }).then(function (data) {
        content.replaceChildren(
          el('div', { class: 'toolbar' }, [7, 30, 90, 365].map(function (days) {
            return el('button', { type: 'button', class: 'chip' + (days === state.days ? ' is-active' : ''), onclick: function () {
              state.days = days;
              statsView(content);
            } }, days + ' j');
          })),
          el('div', { class: 'kpis' },
            kpi('Visiteurs', data.totals.visitors, data.previous.visitors),
            kpi('Pages vues', data.totals.views, data.previous.views),
            kpi('Pages par visiteur', data.totals.visitors ? (data.totals.views / data.totals.visitors).toFixed(1) : '0')
          ),
          el('section', { class: 'card' }, el('h2', {}, 'Visites par jour'), chart(data.series)),
          el('div', { class: 'grid' },
            table('Pages les plus lues', data.pages),
            table('Sources', data.referrers),
            table('Langues', data.languages),
            table('Appareils', data.devices)
          ),
          el('p', { class: 'hint' }, 'Mesure sans cookie : un visiteur est compté une fois par jour, aucune donnée personnelle n’est conservée.')
        );
      });
    });
  }

  function kpi(label, value, previous) {
    var delta = null;
    if (previous != null) {
      delta = previous ? Math.round((value - previous) / previous * 100) : null;
    }
    return el('div', { class: 'kpi' },
      el('span', {}, label),
      el('strong', {}, typeof value === 'number' ? number.format(value) : value),
      delta == null ? null : el('small', { class: delta >= 0 ? 'up' : 'down' }, (delta >= 0 ? '+' : '') + delta + ' % vs période précédente')
    );
  }

  function chart(series) {
    var ns = 'http://www.w3.org/2000/svg';
    var width = 800, height = 180, gap = 2;
    var max = Math.max.apply(null, series.map(function (p) { return p.views; }).concat([1]));
    var barWidth = Math.max(1, width / series.length - gap);
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('class', 'chart');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Pages vues et visiteurs par jour');
    series.forEach(function (point, index) {
      var x = index * (width / series.length);
      [['views', 'bar-views'], ['visitors', 'bar-visitors']].forEach(function (pair) {
        var h = point[pair[0]] / max * (height - 4);
        var rect = document.createElementNS(ns, 'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', height - h);
        rect.setAttribute('width', barWidth);
        rect.setAttribute('height', h);
        rect.setAttribute('class', pair[1]);
        var title = document.createElementNS(ns, 'title');
        title.textContent = point.day + ' — ' + point.visitors + ' visiteurs, ' + point.views + ' pages vues';
        rect.appendChild(title);
        svg.appendChild(rect);
      });
    });
    return el('div', {}, svg, el('p', { class: 'legend' },
      el('span', { class: 'dot dot-views' }), 'Pages vues ', el('span', { class: 'dot dot-visitors' }), 'Visiteurs'));
  }

  function table(title, rows) {
    return el('section', { class: 'card' }, el('h2', {}, title),
      rows.length === 0 ? el('p', { class: 'hint' }, 'Aucune donnée.') :
        el('table', {}, el('thead', {}, el('tr', {}, el('th', {}, ''), el('th', { class: 'num' }, 'Visiteurs'), el('th', { class: 'num' }, 'Vues'))),
          el('tbody', {}, rows.map(function (row) {
            return el('tr', {}, el('td', { class: 'label' }, row.label), el('td', { class: 'num' }, number.format(row.visitors)), el('td', { class: 'num' }, number.format(row.views)));
          }))));
  }

  /* Image : réduite dans le navigateur (1600 px max), envoyée au dépôt, insérée en Markdown. */
  function imageTool(textarea) {
    var input = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true });
    input.addEventListener('change', function () {
      var file = input.files[0];
      input.value = '';
      if (!file) return;
      var alt = prompt('Description de l’image (texte alternatif, utile pour le SEO) :', '');
      if (alt === null) return;
      run(function () { return resizeImage(file).then(function (blob) {
        return blobToBase64(blob).then(function (data) {
          notice('Envoi de l’image…');
          return api('images.upload', { body: { name: file.name, data: data } });
        });
      }).then(function (result) {
        var markdown = '![' + alt.replace(/[\[\]]/g, '') + '](' + result.url + ')';
        var start = textarea.selectionStart;
        textarea.value = textarea.value.slice(0, start) + markdown + textarea.value.slice(textarea.selectionEnd);
        textarea.focus();
        notice('Image ajoutée au dépôt et insérée dans le texte. Elle sera visible après le déploiement.');
      }); });
    });
    return el('span', {}, input, el('button', { class: 'chip', type: 'button', onclick: function () { input.click(); } }, 'Insérer une image'));
  }

  function resizeImage(file) {
    if (file.type === 'image/gif') return Promise.resolve(file);
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, 1600 / Math.max(image.width, image.height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(image.width * scale);
        canvas.height = Math.round(image.height * scale);
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('Image illisible.')); }, 'image/webp', 0.85);
      };
      image.onerror = function () { reject(new Error('Image illisible.')); };
      image.src = url;
    });
  }

  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(String(reader.result).split(',')[1]); };
      reader.onerror = function () { reject(new Error('Lecture impossible.')); };
      reader.readAsDataURL(blob);
    });
  }

  /* ───────── Articles ───────── */

  function articlesView(content) {
    content.replaceChildren(el('p', { class: 'loading' }, 'Chargement…'));
    run(function () {
      return api('articles.list').then(function (data) {
        var sections = Array.from(new Set(data.articles.map(function (a) { return a.section; }))).sort();
        content.replaceChildren(
          el('div', { class: 'toolbar' }, el('button', { class: 'button', type: 'button', onclick: function () { newArticleView(content, sections); } }, 'Nouvel article')),
          el('section', { class: 'card' }, el('h2', {}, data.articles.length + ' articles'),
            el('table', {}, el('tbody', {}, data.articles.map(function (article) {
              return el('tr', {},
                el('td', {}, el('span', { class: 'tag' }, article.section)),
                el('td', { class: 'label' }, article.slug),
                el('td', {}, article.langs.join(' · ')),
                el('td', { class: 'num' }, el('button', { class: 'link', type: 'button', onclick: function () { editArticleView(content, article, article.langs[0]); } }, 'Modifier')));
            }))))
        );
      });
    });
  }

  function newArticleView(content, sections) {
    var today = new Date().toISOString().slice(0, 10);
    var list = function (value) { return String(value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean); };
    var bodyArea = el('textarea', { name: 'body', rows: 16, required: true, class: 'mono' });
    var form = el('form', { class: 'card form', onsubmit: function (event) {
      event.preventDefault();
      var v = new FormData(form);
      run(function () {
        return api('articles.create', { body: {
          section: v.get('section'), slug: v.get('slug'), title: v.get('title'), date: v.get('date'),
          description: v.get('description'), body: v.get('body'), draft: v.get('draft') === 'on',
          keywords: list(v.get('keywords')), categories: list(v.get('categories')), tags: list(v.get('tags'))
        } }).then(function () {
          articlesView(content);
          notice('Article créé. Le site se met à jour dans 2 à 3 minutes.');
        });
      });
    } },
      el('h2', {}, 'Nouvel article (version française)'),
      el('label', {}, 'Rubrique', el('input', { name: 'section', list: 'sections', value: sections[0] || 'info', required: true }),
        el('datalist', { id: 'sections' }, sections.map(function (s) { return el('option', { value: s }); }))),
      el('label', {}, 'Titre', el('input', { name: 'title', required: true, maxlength: 300 })),
      el('label', {}, 'Slug (minuscules, tirets)', el('input', { name: 'slug', required: true, pattern: '[a-z0-9]+(-[a-z0-9]+)*', maxlength: 100 })),
      el('label', {}, 'Date', el('input', { name: 'date', type: 'date', value: today, required: true })),
      el('label', {}, 'Résumé (SEO, 500 caractères max)', el('textarea', { name: 'description', rows: 2, maxlength: 500, required: true })),
      el('label', {}, 'Mots-clés (séparés par des virgules)', el('input', { name: 'keywords' })),
      el('label', {}, 'Catégories', el('input', { name: 'categories' })),
      el('label', {}, 'Étiquettes', el('input', { name: 'tags' })),
      el('label', { class: 'check' }, el('input', { name: 'draft', type: 'checkbox' }), 'Brouillon (non publié)'),
      el('label', {}, 'Contenu (Markdown)', bodyArea),
      el('div', { class: 'toolbar' }, imageTool(bodyArea),
        el('button', { class: 'button', type: 'submit' }, 'Créer'),
        el('button', { class: 'link', type: 'button', onclick: function () { articlesView(content); } }, 'Annuler'))
    );
    content.replaceChildren(form);
  }

  function editArticleView(content, article, lang) {
    content.replaceChildren(el('p', { class: 'loading' }, 'Chargement…'));
    var exists = article.langs.indexOf(lang) !== -1;
    var load = exists
      ? api('articles.get', { query: { section: article.section, slug: article.slug, lang: lang } })
      : api('articles.get', { query: { section: article.section, slug: article.slug, lang: article.langs[0] } })
          .then(function (data) { return { content: data.content, sha: '' }; });

    run(function () {
      return load.then(function (file) {
        var textarea = el('textarea', { rows: 28, class: 'mono', spellcheck: 'false' });
        textarea.value = file.content;
        var sha = file.sha;
        var langTabs = ['fr', 'ja'].map(function (code) {
          var label = code.toUpperCase() + (article.langs.indexOf(code) === -1 ? ' (à créer)' : '');
          return el('button', { type: 'button', class: 'chip' + (code === lang ? ' is-active' : ''), onclick: function () { editArticleView(content, article, code); } }, label);
        });
        content.replaceChildren(
          el('div', { class: 'toolbar' },
            el('button', { class: 'link', type: 'button', onclick: function () { articlesView(content); } }, '← Articles'),
            el('strong', {}, article.section + ' / ' + article.slug), langTabs),
          !exists ? el('p', { class: 'hint' }, 'Version ' + lang.toUpperCase() + ' absente : le texte ci-dessous est la copie de la version existante, à traduire puis enregistrer.') : null,
          el('section', { class: 'card' }, textarea),
          el('div', { class: 'toolbar' },
            imageTool(textarea),
            el('button', { class: 'button', type: 'button', onclick: function () {
              run(function () {
                return api('articles.save', { body: { section: article.section, slug: article.slug, lang: lang, content: textarea.value, sha: sha } }).then(function (result) {
                  sha = result.sha;
                  if (article.langs.indexOf(lang) === -1) article.langs.push(lang);
                  notice('Enregistré. Le site se met à jour dans 2 à 3 minutes.');
                });
              });
            } }, 'Enregistrer et publier'),
            el('button', { class: 'link danger', type: 'button', onclick: function () {
              if (!confirm('Supprimer définitivement « ' + article.slug + ' » et toutes ses traductions ?')) return;
              run(function () {
                return api('articles.delete', { body: { section: article.section, slug: article.slug } }).then(function () {
                  articlesView(content);
                  notice('Article supprimé. Le site se met à jour dans 2 à 3 minutes.');
                });
              });
            } }, 'Supprimer l’article'))
        );
      });
    });
  }

  /* ───────── Newsletter ───────── */

  function newsletterView(content) {
    content.replaceChildren(el('p', { class: 'loading' }, 'Chargement…'));
    run(function () {
      return Promise.all([api('newsletter.overview'), api('newsletter.articles').catch(function () { return { urls: [] }; })]).then(function (result) {
        var overview = result[0], urls = result[1].urls;
        var select = el('select', { name: 'url' }, el('option', { value: '' }, '— choisir un article en ligne —'),
          urls.map(function (url) { return el('option', { value: url }, url.replace(/^https?:\/\/[^/]+/, '')); }));
        var intro = el('textarea', { rows: 3, placeholder: 'Introduction (facultatif — sinon le résumé de l’article est utilisé)' });
        var progress = el('p', { class: 'hint' });

        function withUrl(task) {
          if (!select.value) { notice('Choisis un article.', true); return; }
          run(function () { return task(select.value); });
        }

        function step(id) {
          return api('newsletter.step', { body: { id: id } }).then(function (p) {
            progress.textContent = p.sent + ' envoyé(s) sur ' + p.total + (p.failed ? ' — ' + p.failed + ' échec(s)' : '');
            if (p.pending > 0 && (p.sent + p.failed) > 0) return step(id);
            if (p.error) throw new Error(p.error);
            newsletterView(content);
            notice('Envoi terminé : ' + p.sent + ' message(s).');
          });
        }

        var addForm = el('form', { class: 'toolbar', onsubmit: function (event) {
          event.preventDefault();
          var email = new FormData(addForm).get('email');
          run(function () { return api('newsletter.add', { body: { email: email } }).then(function () { newsletterView(content); }); });
        } }, el('input', { name: 'email', type: 'email', placeholder: 'Ajouter une adresse (déjà consentante)', required: true }), el('button', { class: 'button', type: 'submit' }, 'Ajouter'));

        content.replaceChildren(
          el('div', { class: 'kpis' },
            kpi('Abonnés actifs', overview.counts.active),
            kpi('En attente de confirmation', overview.counts.pending),
            kpi('Désinscrits', overview.counts.unsubscribed)),
          el('section', { class: 'card form' },
            el('h2', {}, 'Annoncer un article'),
            el('p', { class: 'hint' }, 'La liste vient du site en ligne : un article n’apparaît qu’une fois le déploiement terminé. Chaque abonné reçoit l’e-mail dans la langue de son inscription.'),
            select, intro,
            el('div', { class: 'toolbar' },
              el('button', { class: 'chip', type: 'button', onclick: function () {
                withUrl(function (url) { return api('newsletter.test', { body: { url: url, intro: intro.value } }).then(function () { notice('E-mail de test envoyé à ton adresse admin.'); }); });
              } }, 'M’envoyer un test'),
              el('button', { class: 'button', type: 'button', onclick: function () {
                if (!select.value || !confirm('Envoyer cet article à tous les abonnés actifs de la même langue ?')) return;
                withUrl(function (url) {
                  return api('newsletter.campaign', { body: { url: url, intro: intro.value } }).then(function (campaign) {
                    progress.textContent = '0 envoyé sur ' + campaign.total;
                    return campaign.total ? step(campaign.id) : notice('Aucun abonné actif dans cette langue.');
                  });
                });
              } }, 'Envoyer aux abonnés')),
            progress),
          el('section', { class: 'card' }, el('h2', {}, 'Abonnés'), addForm,
            el('table', {}, el('tbody', {}, overview.subscribers.map(function (s) {
              return el('tr', {}, el('td', { class: 'label' }, s.email), el('td', {}, s.lang), el('td', {}, el('span', { class: 'tag tag-' + s.status }, s.status)), el('td', {}, date(s.created_at)),
                el('td', { class: 'num' }, el('button', { class: 'link danger', type: 'button', onclick: function () {
                  if (!confirm('Supprimer ' + s.email + ' ?')) return;
                  run(function () { return api('newsletter.remove', { body: { id: s.id } }).then(function () { newsletterView(content); }); });
                } }, 'Supprimer')));
            })))),
          overview.campaigns.length ? el('section', { class: 'card' }, el('h2', {}, 'Envois récents'),
            el('table', {}, el('tbody', {}, overview.campaigns.map(function (c) {
              return el('tr', {}, el('td', {}, date(c.created_at)), el('td', { class: 'label' }, c.title), el('td', { class: 'num' }, c.sent + ' / ' + c.total + (c.failed ? ' (' + c.failed + ' échec)' : '')));
            })))) : null
        );
      });
    });
  }

  boot();
})();
