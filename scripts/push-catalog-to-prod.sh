#!/usr/bin/env bash
#
# Push the prepared catalog (synonyms + embeddings) from the LOCAL database to
# PROD — without git. It surgically updates only catalog.synonyms and
# catalog.embedding, matched by `code`, inside one transaction. No truncate, no
# foreign-key churn.
#
# Prerequisites:
#   - the catalog rows already exist on prod (run data:import-catalog there once);
#     codes match 1:1 between local and prod (same XİF MN registry).
#   - SSH access to the prod host (key-based), and the prod pgvector container.
#
# Note: the UPDATE rewrites every embedding, so the HNSW index is rebuilt — it is
# heavy and can make prod DB queries lag for a minute or two while it runs. It is
# atomic (commit at the end), so a failure leaves prod untouched.
#
# Usage:
#   scripts/push-catalog-to-prod.sh [-y]
#   PROD_HOST=root@1.2.3.4 PROD_PG=az_prod-pgsql-1 scripts/push-catalog-to-prod.sh
#
set -euo pipefail

LOCAL_PG="${LOCAL_PG:-az-pgsql-1}"
PROD_HOST="${PROD_HOST:-root@157.90.152.161}"
PROD_PG="${PROD_PG:-az_prod-pgsql-1}"
DB="${DB:-app}"
DB_USER="${DB_USER:-app}"

ASSUME_YES=0
[ "${1:-}" = "-y" ] && ASSUME_YES=1

LOCAL_DUMP="$(mktemp -t catalog_syn_emb.XXXXXX.sql.gz)"
REMOTE_DUMP="/tmp/catalog_syn_emb.$$.sql.gz"
trap 'rm -f "$LOCAL_DUMP"' EXIT

echo "==> LOCAL catalog (rows | synonyms | embeddings):"
docker exec "$LOCAL_PG" psql -U "$DB_USER" -d "$DB" -tAc \
  'select count(*), count(*) filter (where synonyms is not null), count(*) filter (where embedding is not null) from catalog'

echo "==> PROD catalog BEFORE:"
ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$PROD_HOST" \
  "docker exec '$PROD_PG' psql -U '$DB_USER' -d '$DB' -tAc 'set statement_timeout=60000; select count(*), count(*) filter (where synonyms is not null), count(*) filter (where embedding is not null) from catalog'"

if [ "$ASSUME_YES" -ne 1 ]; then
  read -r -p "==> Update PROD catalog (synonyms + embeddings) on ${PROD_HOST}? [y/N] " ans
  case "$ans" in y|Y|yes) ;; *) echo "aborted."; exit 1 ;; esac
fi

echo "==> Dumping (code, synonyms, embedding) from local ..."
{
  echo "begin;"
  echo "create temp table syn_stage(code text, synonyms text, embedding vector(1024));"
  printf '\\copy syn_stage from stdin csv\n'
  docker exec "$LOCAL_PG" psql -U "$DB_USER" -d "$DB" -c \
    "\copy (select code, synonyms, embedding from catalog order by id) to stdout csv"
  printf '\\.\n'
  echo "update catalog c set synonyms = s.synonyms, embedding = s.embedding from syn_stage s where c.code = s.code;"
  echo "commit;"
} | gzip >"$LOCAL_DUMP"
echo "    dump: $(du -h "$LOCAL_DUMP" | cut -f1)"

echo "==> Copying to prod ..."
scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$LOCAL_DUMP" "$PROD_HOST:$REMOTE_DUMP"

echo "==> Loading on prod (heavy UPDATE + HNSW rebuild; may take a minute) ..."
ssh -o BatchMode=yes "$PROD_HOST" \
  "gunzip -c '$REMOTE_DUMP' | docker exec -i '$PROD_PG' psql -U '$DB_USER' -d '$DB' -v ON_ERROR_STOP=1 -q; status=\$?; rm -f '$REMOTE_DUMP'; exit \$status"

echo "==> PROD catalog AFTER:"
ssh -o BatchMode=yes "$PROD_HOST" \
  "docker exec '$PROD_PG' psql -U '$DB_USER' -d '$DB' -tAc 'set statement_timeout=60000; select count(*), count(*) filter (where synonyms is not null), count(*) filter (where embedding is not null) from catalog'"

echo "==> Done."
