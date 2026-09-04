#!/usr/bin/env bash
#
# Did the redeploy actually land? — the mechanical half of F25.
#
# `docker compose up -d` is not a deployment. Both watchers set `stop_grace_period: 5m` and
# `WatchLoop` stops only after the pass in flight finishes, so a recreate can sit for minutes;
# compose renames the old container while it waits, and twice on 2026-08-31 that wedged — once
# failing outright (`Conflict. The container name "/scout-car-scout-1" is already in use`) with
# rent-scout left in `Created`, once sitting ~13 minutes beside a hex-prefixed leftover.
#
# NOTHING ANNOUNCED EITHER TIME, and that is the actual defect. `docker compose ps` without `-a`
# simply OMITS a non-running service, so the failure renders as a shorter list — the silent-absence
# shape hard rule 2 is about, one layer down in the deployment. A watcher that is DOWN is worse than
# one that is stale: a stale watcher still pushes, wrongly; a stopped one pushes nothing, and
# *nothing arriving* is exactly what a quiet market looks like.
#
# So this asserts three things a human reading `up -d`'s output cannot see:
#
#   1. every service compose declares has a container, and it is running;
#   2. that container runs the CURRENT image, not the one it was created from three deploys ago —
#      `src/` is baked in, so a green tree says nothing about what the watcher is executing;
#   3. no hex-prefixed leftover (`d9272b63ebf1_scout-car-scout-1`) is still lying around, because
#      that is what makes the NEXT recreate fail rather than this one.
#
# Read-only: it inspects, it never starts, stops or removes anything. Exit 0 = the deployment is
# what you think it is.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 2

IMAGE="${SCOUT_IMAGE:-scout:local}"
problems=0

say()  { printf '  %s\n' "$1"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; problems=$((problems + 1)); }
good() { printf '  \033[32m✓\033[0m %s\n' "$1"; }

if ! command -v docker > /dev/null 2>&1; then
  printf 'verify-deploy: docker introuvable — rien à vérifier.\n' >&2
  exit 2
fi

current="$(docker image inspect "$IMAGE" --format '{{.Id}}' 2>/dev/null)"
if [[ -z "$current" ]]; then
  printf "verify-deploy: l'image %s n'existe pas — construisez-la avant de déployer.\n" "$IMAGE" >&2
  exit 2
fi

say "image courante : $IMAGE $current"

# `-a` IS THE POINT OF THIS LINE. Without it a service that is down does not appear at all, which
# is precisely the state we are looking for.
mapfile -t rows < <(docker compose ps -a --format '{{.Service}}\t{{.Name}}\t{{.State}}' 2>/dev/null)

mapfile -t services < <(docker compose config --services 2>/dev/null)

if [[ ${#services[@]} -eq 0 ]]; then
  printf 'verify-deploy: aucun service dans compose.yaml — configuration illisible ?\n' >&2
  exit 2
fi

for service in "${services[@]}"; do
  line=""
  for row in "${rows[@]}"; do
    [[ "${row%%$'\t'*}" == "$service" ]] && line="$row" && break
  done

  if [[ -z "$line" ]]; then
    # The silent one: no container at all. `ps` without -a would simply not have mentioned it.
    bad "$service : AUCUN conteneur — le service est absent, pas seulement arrêté"
    continue
  fi

  name="$(printf '%s' "$line" | cut -f2)"
  state="$(printf '%s' "$line" | cut -f3)"

  if [[ "$state" != "running" ]]; then
    bad "$service ($name) : état « $state » — le watcher ne tourne pas"
    continue
  fi

  running_image="$(docker inspect --format '{{.Image}}' "$name" 2>/dev/null)"
  if [[ "$running_image" != "$current" ]]; then
    # A stale watcher is the failure this repo has paid for three times: green, pushed, and running
    # yesterday's code, with nothing in `git status` or a passing suite to say so.
    bad "$service ($name) : tourne sur une AUTRE image que $IMAGE — redéploiement non pris en compte"
    continue
  fi

  good "$service ($name) : running, image courante"
done

# The leftover is not this deploy's failure; it is the NEXT one's. Compose renames the old container
# out of the way and, when the recreate does not complete, leaves it behind holding the name.
leftovers="$(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^[0-9a-f]{12}_' || true)"
if [[ -n "$leftovers" ]]; then
  bad "conteneurs orphelins laissés par un recreate interrompu — ils feront échouer le prochain :"
  printf '      %s\n' $leftovers
  printf '      docker rm -f %s\n' $leftovers
else
  good "aucun conteneur orphelin d'un recreate interrompu"
fi

if (( problems > 0 )); then
  printf '\n  \033[31m%d problème(s)\033[0m — le déploiement n'"'"'est pas celui que vous croyez.\n\n' "$problems"
  exit 1
fi

printf '\n  déploiement vérifié : chaque service tourne, sur l'"'"'image courante.\n\n'
exit 0
