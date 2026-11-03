#!/usr/bin/env bash
# Tableau de bord technique (T1, 03/11/2026) : relevé des mesures de l'hôte, puis collecte dans le conteneur.
# Lancé toutes les 5 minutes par ops/systemd/tech-metrics.timer (utilisateur arkanoa). Aucune valeur secrète n'est lue ni transmise.
# Mesures de l'hôte, en JSON sur l'entrée standard de `app:tech-metrics:collect --host-json=-` :
#   disk   : total et utilisé (octets) de / et du dossier des sauvegardes ;
#   docker : octets récupérables (images inutilisées, cache de construction) d'après `docker system df` ;
#   timers : dernier déclenchement, résultat du service et prochaine échéance de chaque timer surveillé ;
#   backup : horodatage de la dernière sauvegarde réussie (journal du script), taille et nombre de fichiers du dernier jeu.
set -uo pipefail
APP_DIR="${APP_DIR:-/home/arkanoa/app_v2}"
BACKUP_ROOT="${BACKUP_ROOT:-/home/arkanoa/backups}"
CONTAINER="${CONTAINER:-symfony_app_v2}"
TIMERS="${TIMERS:-pos-terminal-reconcile backup-daily form-submissions-purge tech-metrics}"

python3 -I - "$BACKUP_ROOT" $TIMERS <<'PY' | /usr/bin/docker exec -i -w /var/www "$CONTAINER" php bin/console app:tech-metrics:collect --host-json=- --no-interaction
import json, os, re, subprocess, sys, time

backup_root, timers = sys.argv[1], sys.argv[2:]

def run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=30).stdout
    except Exception:
        return ""

out = {"disk": [], "docker": {}, "timers": [], "backup": {}}

seen_devices = set()
for mount in ["/", backup_root]:
    try:
        if not os.path.isdir(mount):
            continue
        device = os.stat(mount).st_dev  # le dossier des sauvegardes sur le disque racine n'est compté qu'une fois
        if device in seen_devices:
            continue
        seen_devices.add(device)
        st = os.statvfs(mount)
        total = st.f_blocks * st.f_frsize
        out["disk"].append({"mount": mount, "total": total, "used": total - st.f_bfree * st.f_frsize})
    except OSError:
        pass

UNITS = {"B": 1, "kB": 1e3, "KB": 1e3, "MB": 1e6, "GB": 1e9, "TB": 1e12, "KiB": 1024, "MiB": 1024**2, "GiB": 1024**3}
def to_bytes(text):
    m = re.match(r"\s*([0-9.]+)\s*([A-Za-z]+)", text or "")
    return int(float(m.group(1)) * UNITS.get(m.group(2), 1)) if m else 0

reclaimable = {}
for line in run(["/usr/bin/docker", "system", "df", "--format", "{{json .}}"]).splitlines():
    try:
        row = json.loads(line)
        reclaimable[row.get("Type", "?")] = to_bytes(str(row.get("Reclaimable", "0B")).split("(")[0])
    except Exception:
        pass
if reclaimable:
    out["docker"] = {"reclaimable": sum(reclaimable.values()), "images": reclaimable.get("Images", 0), "build_cache": reclaimable.get("Build Cache", 0)}

def usec(value):
    # systemd affiche une date lisible ("Tue 2026-11-03 02:40:04 UTC") ou vide/"n/a"
    value = (value or "").strip()
    if not value or value in ("n/a", "0"):
        return None
    try:
        return int(subprocess.run(["date", "-d", value, "+%s"], capture_output=True, text=True, timeout=5).stdout.strip())
    except Exception:
        return None

for unit in timers:
    props = {}
    for line in run(["systemctl", "show", f"{unit}.timer", "-p", "LastTriggerUSec", "-p", "NextElapseUSecRealtime"]).splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            props[k] = v
    result = ""
    for line in run(["systemctl", "show", f"{unit}.service", "-p", "Result"]).splitlines():
        if line.startswith("Result="):
            result = line.split("=", 1)[1]
    if props:
        out["timers"].append({"unit": f"{unit}.timer", "last_trigger": usec(props.get("LastTriggerUSec")), "next": props.get("NextElapseUSecRealtime") or None, "result": result or None})

log = os.path.join(backup_root, "logs", "backup.log")
last_success = None
if os.path.isfile(log):
    try:
        with open(log, "rb") as fh:
            fh.seek(max(0, os.path.getsize(log) - 65536))
            for line in fh.read().decode("utf-8", "replace").splitlines():
                m = re.match(r"\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC\] .*réussie", line)
                if m:
                    last_success = int(subprocess.run(["date", "-u", "-d", m.group(1) + " UTC", "+%s"], capture_output=True, text=True).stdout.strip() or 0) or None
    except Exception:
        pass
data_dir = os.path.join(backup_root, "data")
bytes_total, files, databases = 0, 0, 0
if os.path.isdir(data_dir):
    sets = sorted(d for d in os.listdir(data_dir) if os.path.isdir(os.path.join(data_dir, d)))
    if sets:
        latest = os.path.join(data_dir, sets[-1])
        for root, _, names in os.walk(latest):
            for name in names:
                try:
                    bytes_total += os.path.getsize(os.path.join(root, name))
                    files += 1
                    if name.endswith((".dump", ".sql", ".sql.gz", ".pgdump")):
                        databases += 1
                except OSError:
                    pass
out["backup"] = {"last_success": last_success, "bytes": bytes_total, "files": files, "databases": databases}

print(json.dumps(out))
PY
