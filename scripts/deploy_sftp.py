#!/usr/bin/env python3
"""Déploie public/ vers OVH (hébergement Perso) en SFTP via lftp.

Perso n'offre ni SSH ni rsync : la synchronisation est faite ici à l'aide d'un
manifeste SHA-256 (`.deploy-manifest`) stocké dans le dossier distant.

  * Seuls les fichiers nouveaux ou modifiés sont envoyés.
  * Seuls les fichiers présents dans le manifeste PRÉCÉDENT et absents du build
    sont supprimés : un fichier inconnu du manifeste n'est jamais touché.
  * Le manifeste est envoyé en dernier.

Variables d'environnement (GitHub Secrets) :
  DEPLOY_HOST, DEPLOY_USER, DEPLOY_PATH, DEPLOY_KNOWN_HOSTS  (obligatoires)
  DEPLOY_PORT          (défaut 22)
  DEPLOY_PASSWORD  ou  DEPLOY_SSH_KEY   (au moins un des deux)
  MAX_DELETIONS        (défaut 200) : garde-fou contre une purge massive
  DRY_RUN=true         : calcule et affiche le plan sans rien modifier

Options : --plan-only  calcule le plan hors-ligne (sans secrets ni connexion).
"""
import hashlib
import os
import shlex
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

MANIFEST = ".deploy-manifest"
FORBIDDEN_PATHS = {"", "/", ".", "..", "~", "www", "/www", "public_html", "/public_html", "home", "/home"}


def die(msg: str) -> None:
    print(f"::error::{msg}")
    sys.exit(1)


def build_manifest(public: Path) -> dict[str, str]:
    files: dict[str, str] = {}
    for f in sorted(public.rglob("*")):
        if f.is_file() and f.name != MANIFEST:
            files[f.relative_to(public).as_posix()] = hashlib.sha256(f.read_bytes()).hexdigest()
    return files


def parse_manifest(text: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for line in text.splitlines():
        if not line.strip():
            continue
        digest, _, rel = line.partition("  ")
        if len(digest) != 64 or not rel or rel.startswith("/") or ".." in rel.split("/"):
            die(f"manifeste distant corrompu (ligne : {line[:80]!r}) : déploiement interrompu")
        out[rel] = digest
    return out


def plan(new: dict[str, str], old: dict[str, str] | None):
    if old is None:
        return sorted(new), []  # premier déploiement : rien n'est supprimé
    upload = sorted(p for p, h in new.items() if old.get(p) != h)
    delete = sorted(p for p in old if p not in new)
    return upload, delete


def q(s: str) -> str:
    """Quote lftp : guillemets doubles, \\ et \" échappés."""
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'


def remote(base: str, rel: str) -> str:
    return f"{base.rstrip('/')}/{rel}"


def check_remote_path(path: str) -> str:
    p = path.strip()
    if p in FORBIDDEN_PATHS or ".." in p.split("/") or "\n" in p or "\r" in p:
        die(f"DEPLOY_PATH refusé (dangereux ou trop générique) : {p!r}")
    if len(p.strip("/").split("/")) < 1 or p.strip("/") in {"www", "public_html", "home"}:
        die(f"DEPLOY_PATH ne doit pas être la racine de l'hébergement : {p!r}")
    return p


class Lftp:
    def __init__(self, host, port, user, base_path, ssh_args, password):
        self.host, self.port, self.user = host, port, user
        self.base, self.ssh_args, self.password = base_path, ssh_args, password

    def run(self, commands: list[str], capture=False) -> subprocess.CompletedProcess:
        prelude = [
            "set cmd:fail-exit yes",
            "set net:max-retries 3",
            "set net:timeout 30",
            "set sftp:auto-confirm no",
            "set file:charset UTF-8",
            "set sftp:charset UTF-8",
            f"set sftp:connect-program {q('ssh -a -x ' + self.ssh_args)}",
            f"open -p {self.port} -u {q(self.user)},{q(self.password or 'unused')} sftp://{self.host}",
        ]
        script = "\n".join(prelude + commands + ["bye"]) + "\n"
        return subprocess.run(
            ["lftp", "-f", "/dev/stdin"], input=script, text=True, capture_output=capture, check=False
        )


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    plan_only = "--plan-only" in sys.argv
    public = Path(args[0] if args else "public").resolve()
    if not public.is_dir():
        die(f"dossier introuvable : {public}")

    new = build_manifest(public)
    if not new:
        die("public/ est vide : déploiement refusé")
    print(f"Build local : {len(new)} fichiers.")

    if plan_only:
        upload, delete = plan(new, None)
        print(f"[plan-only] premier déploiement simulé : {len(upload)} envois, {len(delete)} suppressions.")
        return 0

    env = os.environ
    missing = [k for k in ("DEPLOY_HOST", "DEPLOY_USER", "DEPLOY_PATH", "DEPLOY_KNOWN_HOSTS") if not env.get(k, "").strip()]
    if not env.get("DEPLOY_PASSWORD") and not env.get("DEPLOY_SSH_KEY"):
        missing.append("DEPLOY_PASSWORD ou DEPLOY_SSH_KEY")
    if missing:
        die("secrets manquants, aucun déploiement : " + ", ".join(missing))

    host, user = env["DEPLOY_HOST"].strip(), env["DEPLOY_USER"].strip()
    port = env.get("DEPLOY_PORT", "").strip() or "22"
    if not port.isdigit():
        die("DEPLOY_PORT invalide")
    base = check_remote_path(env["DEPLOY_PATH"])
    max_del = int(env.get("MAX_DELETIONS", "200") or "200")
    dry_run = env.get("DRY_RUN", "").lower() == "true"

    with tempfile.TemporaryDirectory() as tmp:
        tmpd = Path(tmp)
        known = tmpd / "known_hosts"
        known.write_text(env["DEPLOY_KNOWN_HOSTS"].strip() + "\n")
        ssh_args = f"-p {port} -o StrictHostKeyChecking=yes -o UserKnownHostsFile={shlex.quote(str(known))} -o BatchMode={'yes' if env.get('DEPLOY_SSH_KEY') else 'no'}"
        if env.get("DEPLOY_SSH_KEY"):
            key = tmpd / "id_deploy"
            key.write_text(env["DEPLOY_SSH_KEY"].strip() + "\n")
            key.chmod(stat.S_IRUSR | stat.S_IWUSR)
            ssh_args += f" -o IdentitiesOnly=yes -i {shlex.quote(str(key))}"
        else:
            # mot de passe : transmis par lftp ; ssh ne doit pas utiliser de clé
            ssh_args += " -o PubkeyAuthentication=no"

        ftp = Lftp(host, port, user, base, ssh_args, env.get("DEPLOY_PASSWORD"))

        # 1. Le dossier cible doit exister (jamais créé automatiquement) + lecture du manifeste
        listing = ftp.run([f"cd {q(base)}", "cls -1"], capture=True)
        if listing.returncode != 0:
            print(listing.stderr)
            die(f"connexion SFTP ou dossier distant introuvable : {base!r} (aucune modification effectuée)")
        names = set(listing.stdout.split("\n"))
        old = None
        if MANIFEST in {n.strip() for n in names}:
            got = ftp.run([f"get -O {q(str(tmpd))} {q(remote(base, MANIFEST))}"])
            if got.returncode != 0 or not (tmpd / MANIFEST).is_file():
                die("manifeste distant présent mais illisible : déploiement interrompu")
            old = parse_manifest((tmpd / MANIFEST).read_text(encoding="utf-8"))
            print(f"Manifeste distant : {len(old)} fichiers déployés précédemment.")
        else:
            print("Aucun manifeste distant : premier déploiement (aucune suppression, envoi complet).")
            print(f"Contenu actuel du dossier cible : {len(names - {''})} entrée(s) à la racine (conservées).")

        upload, delete = plan(new, old)
        print(f"Plan : {len(upload)} envoi(s), {len(delete)} suppression(s).")
        for p in delete[:50]:
            print(f"  - {p}")
        if len(delete) > max_del:
            die(f"{len(delete)} suppressions > MAX_DELETIONS={max_del} : refusé (relancer avec une limite plus haute si voulu)")
        if old is not None and len(old) >= 20 and len(new) < len(old) * 0.5:
            die("le build contient moins de la moitié des fichiers du déploiement précédent : refusé")
        if dry_run:
            print("DRY_RUN : aucune modification effectuée.")
            return 0

        # 2. Envois (dossiers créés au besoin, uniquement sous DEPLOY_PATH)
        cmds = [f"cd {q(base)}"]
        for d in sorted({str(Path(p).parent.as_posix()) for p in upload if "/" in p}):
            cmds.append(f"mkdir -p -f {q(d)}")
        for p in upload:
            parent = Path(p).parent.as_posix()
            target = "." if parent == "." else parent
            cmds.append(f"put -O {q(target)} {q(str(public / p))}")
        if len(cmds) > 1:
            res = ftp.run(cmds)
            if res.returncode != 0:
                die("échec pendant l'envoi des fichiers : manifeste NON mis à jour, relancer le workflow")

        # 3. Suppression des seuls fichiers précédemment déployés
        if delete:
            res = ftp.run([f"cd {q(base)}", "set cmd:fail-exit no"] + [f"rm -f {q(p)}" for p in delete])
            if res.returncode != 0:
                die("échec pendant la suppression des fichiers obsolètes")
            dirs = sorted({str(Path(p).parent.as_posix()) for p in delete if "/" in p}, key=len, reverse=True)
            ftp.run([f"cd {q(base)}", "set cmd:fail-exit no"] + [f"rmdir {q(d)}" for d in dirs])

        # 4. Manifeste en dernier
        man = tmpd / "new-manifest"
        man.write_text("".join(f"{h}  {p}\n" for p, h in sorted(new.items())), encoding="utf-8")
        res = ftp.run([f"cd {q(base)}", f"put {q(str(man))} -o {MANIFEST}"])
        if res.returncode != 0:
            die("échec de l'écriture du manifeste distant")

    print(f"Déploiement terminé : {len(upload)} envoyé(s), {len(delete)} supprimé(s).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
