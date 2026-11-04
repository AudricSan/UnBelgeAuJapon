<?php
declare(strict_types=1);

/**
 * Articles : lecture/écriture des fichiers Markdown du dépôt via l'API GitHub.
 * Chaque enregistrement est un commit sur la branche : le workflow GitHub
 * Actions compile et déploie le site tout seul.
 */

const ARTICLE_LANGS = ['fr', 'ja', 'en'];

function ghConfig(): array
{
    $gh = config()['github'] ?? [];
    foreach (['owner', 'repository', 'branch', 'token'] as $key) {
        if (!isset($gh[$key]) || !is_string($gh[$key]) || trim($gh[$key]) === '') {
            fail(503, 'La connexion au dépôt GitHub n’est pas configurée.');
        }
    }
    if (!preg_match('/^[A-Za-z0-9-]+$/', $gh['owner']) || !preg_match('/^[A-Za-z0-9._-]+$/', $gh['repository'])) {
        fail(503, 'Configuration GitHub invalide.');
    }
    return $gh;
}

/** @return array{0:int,1:array} statut HTTP et corps JSON décodé */
function ghRequest(string $method, string $endpoint, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        fail(503, 'L’extension PHP cURL est requise.');
    }
    $gh = ghConfig();
    $curl = curl_init('https://api.github.com/repos/' . rawurlencode($gh['owner']) . '/' . rawurlencode($gh['repository']) . $endpoint);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $gh['token'],
            'Content-Type: application/json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: MaVieAuJapon-Admin',
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (!is_string($response)) {
        fail(502, 'Connexion à GitHub impossible.');
    }
    $data = json_decode($response, true);
    return [$status, is_array($data) ? $data : []];
}

function ghOk(int $status): bool
{
    return $status >= 200 && $status < 300;
}

function ghBranch(): string
{
    return rawurlencode(ghConfig()['branch']);
}

function ghEncodePath(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/** @return array{content:string,sha:string}|null */
function ghReadFile(string $path): ?array
{
    [$status, $data] = ghRequest('GET', '/contents/' . ghEncodePath($path) . '?ref=' . ghBranch());
    if ($status === 404) {
        return null;
    }
    if (!ghOk($status) || ($data['type'] ?? '') !== 'file' || !isset($data['content'], $data['sha'])) {
        fail(502, 'Lecture du fichier dans GitHub impossible.');
    }
    $content = base64_decode(str_replace("\n", '', (string) $data['content']), true);
    if ($content === false) {
        fail(502, 'Le fichier du dépôt est illisible.');
    }
    return ['content' => $content, 'sha' => (string) $data['sha']];
}

function ghWriteFile(string $path, string $content, string $message, ?string $sha): string
{
    $payload = ['message' => $message, 'content' => base64_encode($content), 'branch' => ghConfig()['branch']];
    if ($sha !== null) {
        $payload['sha'] = $sha;
    }
    [$status, $data] = ghRequest('PUT', '/contents/' . ghEncodePath($path), $payload);
    if ($status === 409 || $status === 422) {
        fail(409, 'Le fichier a changé sur GitHub entre-temps. Recharge-le avant d’enregistrer.');
    }
    if (!ghOk($status) || !isset($data['content']['sha'])) {
        fail(502, 'Enregistrement dans GitHub impossible.');
    }
    return (string) $data['content']['sha'];
}

/** @return array{commit:string,tree:string} */
function ghHead(): array
{
    [$status, $ref] = ghRequest('GET', '/git/ref/heads/' . ghBranch());
    if (!ghOk($status) || !isset($ref['object']['sha'])) {
        fail(502, 'Lecture de la branche GitHub impossible.');
    }
    $commitSha = (string) $ref['object']['sha'];
    [$status, $commit] = ghRequest('GET', '/git/commits/' . rawurlencode($commitSha));
    if (!ghOk($status) || !isset($commit['tree']['sha'])) {
        fail(502, 'Lecture de la branche GitHub impossible.');
    }
    return ['commit' => $commitSha, 'tree' => (string) $commit['tree']['sha']];
}

/** @return list<string> chemins de tous les fichiers du dépôt */
function ghFiles(): array
{
    [$status, $data] = ghRequest('GET', '/git/trees/' . rawurlencode(ghHead()['tree']) . '?recursive=1');
    if (!ghOk($status) || !is_array($data['tree'] ?? null) || ($data['truncated'] ?? false) === true) {
        fail(502, 'Lecture de la liste des articles impossible.');
    }
    $paths = [];
    foreach ($data['tree'] as $node) {
        if (($node['type'] ?? '') === 'blob' && is_string($node['path'] ?? null)) {
            $paths[] = $node['path'];
        }
    }
    return $paths;
}

/* ───────── Articles ───────── */

function articleSegment(mixed $value, string $label): string
{
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $value)) {
        fail(400, $label . ' invalide (lettres, chiffres, tirets).');
    }
    return $value;
}

function articleLang(mixed $value): string
{
    if (!is_string($value) || !in_array($value, ARTICLE_LANGS, true)) {
        fail(400, 'Langue invalide.');
    }
    return $value;
}

function articlePath(string $section, string $slug, string $lang): string
{
    return "content/Blog/$section/$slug/index.$lang.md";
}

/** @return list<array{section:string,slug:string,langs:list<string>}> */
function articlesList(): array
{
    $found = [];
    foreach (ghFiles() as $path) {
        if (preg_match('#^content/Blog/([^/]+)/([^/]+)/index\.(fr|ja|en)\.md$#', $path, $m)) {
            $found[$m[1] . '/' . $m[2]]['langs'][] = $m[3];
            $found[$m[1] . '/' . $m[2]]['section'] = $m[1];
            $found[$m[1] . '/' . $m[2]]['slug'] = $m[2];
        }
    }
    ksort($found);
    return array_values($found);
}

function articleGet(string $section, string $slug, string $lang): array
{
    $file = ghReadFile(articlePath($section, $slug, $lang));
    if ($file === null) {
        fail(404, 'Version introuvable.');
    }
    return $file;
}

function articleContent(mixed $content): string
{
    if (!is_string($content)) {
        fail(400, 'Contenu invalide.');
    }
    $content = str_replace(["\r\n", "\r"], "\n", ltrim($content, "\xEF\xBB\xBF"));
    if (strlen($content) > 500_000 || str_contains($content, "\0")) {
        fail(400, 'Contenu trop volumineux.');
    }
    if (!str_starts_with($content, "---\n") || !preg_match('/\n---\n/', substr($content, 3))) {
        fail(400, 'L’article doit commencer par un en-tête entre deux lignes « --- ».');
    }
    return rtrim($content) . "\n";
}

/** Enregistre un fichier existant ($sha fourni) ou crée une nouvelle version ($sha null). */
function articleSave(string $section, string $slug, string $lang, mixed $content, ?string $sha): string
{
    $path = articlePath($section, $slug, $lang);
    $content = articleContent($content);
    if ($sha === null && ghReadFile($path) !== null) {
        fail(409, 'Cette version existe déjà.');
    }
    $verb = $sha === null ? 'Créer' : 'Modifier';
    return ghWriteFile($path, $content, "$verb $slug ($lang)", $sha);
}

function yamlText(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function yamlList(string $name, mixed $items): string
{
    $clean = [];
    foreach (is_array($items) ? $items : [] as $item) {
        $item = trim((string) $item);
        if ($item !== '' && strlen($item) <= 120 && !preg_match('/[\x00-\x1F]/', $item)) {
            $clean[] = $item;
        }
    }
    $clean = array_values(array_unique($clean));
    return $clean === [] ? "$name: []" : "$name:\n" . implode("\n", array_map(static fn(string $i): string => '  - ' . yamlText($i), $clean));
}

/** Crée la version française d'un nouvel article à partir du formulaire. */
function articleCreate(array $in): array
{
    $section = articleSegment($in['section'] ?? null, 'Rubrique');
    $slug = articleSegment($in['slug'] ?? null, 'Slug');
    if ($slug !== strtolower($slug)) {
        fail(400, 'Le slug doit être en minuscules.');
    }
    $title = trim((string) ($in['title'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $date = (string) ($in['date'] ?? '');
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($title === '' || strlen($title) > 300 || $description === '' || strlen($description) > 500) {
        fail(400, 'Titre et résumé sont obligatoires (résumé : 500 caractères maximum).');
    }
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        fail(400, 'Date invalide.');
    }

    $markdown = "---\n"
        . 'title: ' . yamlText($title) . "\n"
        . "date: $date\n"
        . "author: \"AudricSan\"\n"
        . 'description: ' . yamlText($description) . "\n"
        . yamlList('keywords', $in['keywords'] ?? []) . "\n"
        . yamlList('categories', $in['categories'] ?? []) . "\n"
        . yamlList('tags', $in['tags'] ?? []) . "\n"
        . 'slug: ' . yamlText($slug) . "\n"
        . 'draft: ' . (($in['draft'] ?? false) === true ? 'true' : 'false') . "\n"
        . "---\n\n"
        . trim((string) ($in['body'] ?? '')) . "\n";

    $sha = articleSave($section, $slug, 'fr', $markdown, null);
    return ['section' => $section, 'slug' => $slug, 'sha' => $sha];
}

/** Supprime toutes les versions d'un article en un seul commit. */
function articleDelete(string $section, string $slug): int
{
    $prefix = "content/Blog/$section/$slug/";
    $paths = array_values(array_filter(ghFiles(), static fn(string $p): bool => str_starts_with($p, $prefix)));
    if ($paths === []) {
        fail(404, 'Article introuvable.');
    }
    $head = ghHead();
    [$status, $tree] = ghRequest('POST', '/git/trees', [
        'base_tree' => $head['tree'],
        'tree' => array_map(static fn(string $p): array => ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => null], $paths),
    ]);
    if (!ghOk($status) || !isset($tree['sha'])) {
        fail(502, 'Suppression impossible.');
    }
    [$status, $commit] = ghRequest('POST', '/git/commits', ['message' => "Supprimer $slug", 'tree' => $tree['sha'], 'parents' => [$head['commit']]]);
    if (!ghOk($status) || !isset($commit['sha'])) {
        fail(502, 'Suppression impossible.');
    }
    [$status] = ghRequest('PATCH', '/git/refs/heads/' . ghBranch(), ['sha' => $commit['sha'], 'force' => false]);
    if (!ghOk($status)) {
        fail(409, 'La branche a changé pendant la suppression. Recharge et réessaie.');
    }
    return count($paths);
}

/** Envoie une image dans static/img/articles/ (commit GitHub) et renvoie son URL publique. */
function imageUpload(mixed $name, mixed $data): array
{
    $binary = is_string($data) ? base64_decode($data, true) : false;
    if ($binary === false || $binary === '' || strlen($binary) > 4_000_000) {
        fail(400, 'Image invalide ou trop lourde (4 Mo maximum).');
    }
    $info = @getimagesizefromstring($binary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($info === false || !isset($extensions[$info['mime']])) {
        fail(400, 'Format accepté : JPEG, PNG, WebP ou GIF.');
    }
    $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) $name, PATHINFO_FILENAME)) ?? '');
    $base = trim(substr($base, 0, 60), '-') ?: 'image';
    $file = $base . '-' . bin2hex(random_bytes(3)) . '.' . $extensions[$info['mime']];
    ghWriteFile('static/img/articles/' . $file, $binary, 'Ajouter image ' . $file, null);
    return ['url' => '/img/articles/' . $file, 'width' => $info[0], 'height' => $info[1]];
}
