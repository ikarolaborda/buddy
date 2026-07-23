#!/bin/bash
# Nightly memory-plane backup: snapshots every Qdrant Cloud collection to
# blob storage and archives the hub's JSON registries. Runs inside the
# azure-cli image as a scheduled Container Apps Job with a user-assigned
# identity (Storage Blob Data Contributor + Key Vault Secrets User).
set -euo pipefail

: "${QDRANT_URL:?}" "${QDRANT_API_KEY:?}" "${STORAGE_ACCOUNT:?}" "${AZURE_CLIENT_ID:?}"
CONTAINER="${BACKUP_CONTAINER:-memory-backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y-%m-%dT%H-%M-%SZ)"

az login --identity --client-id "$AZURE_CLIENT_ID" --output none 2>/dev/null \
  || az login --identity --username "$AZURE_CLIENT_ID" --output none

az storage container create --auth-mode login \
  --account-name "$STORAGE_ACCOUNT" --name "$CONTAINER" --output none

collections=$(curl -fsS -H "api-key: $QDRANT_API_KEY" "$QDRANT_URL/collections" \
  | python3 -c "import json,sys; print('\n'.join(c['name'] for c in json.load(sys.stdin)['result']['collections']))")

if [ -z "$collections" ]; then
  echo "WARNING: no collections found at $QDRANT_URL" >&2
fi

for col in $collections; do
  snap=$(curl -fsS -X POST -H "api-key: $QDRANT_API_KEY" \
    "$QDRANT_URL/collections/$col/snapshots" \
    | python3 -c "import json,sys; print(json.load(sys.stdin)['result']['name'])")

  curl -fsS -H "api-key: $QDRANT_API_KEY" \
    "$QDRANT_URL/collections/$col/snapshots/$snap" --output "/tmp/$snap"

  az storage blob upload --auth-mode login \
    --account-name "$STORAGE_ACCOUNT" --container-name "$CONTAINER" \
    --name "qdrant/$col/$STAMP.snapshot" --file "/tmp/$snap" --output none

  rm -f "/tmp/$snap"

  # Server-side snapshots consume cluster disk; keep only the blob copy.
  curl -fsS -X DELETE -H "api-key: $QDRANT_API_KEY" \
    "$QDRANT_URL/collections/$col/snapshots/$snap" --output /dev/null || true

  echo "backed up $col -> $CONTAINER/qdrant/$col/$STAMP.snapshot"
done

if [ -d /hub-data ]; then
  # The azure-cli image ships no tar binary; python is always present.
  python3 -c "import tarfile; t = tarfile.open('/tmp/hub-registries.tar.gz', 'w:gz'); t.add('/hub-data', arcname='.'); t.close()"
  az storage blob upload --auth-mode login \
    --account-name "$STORAGE_ACCOUNT" --container-name "$CONTAINER" \
    --name "hub-registries/$STAMP.tar.gz" --file "/tmp/hub-registries.tar.gz" --output none
  echo "backed up hub registries -> $CONTAINER/hub-registries/$STAMP.tar.gz"
fi

cutoff=$(python3 -c "from datetime import datetime,timedelta,timezone; print((datetime.now(timezone.utc)-timedelta(days=$RETENTION_DAYS)).strftime('%Y-%m-%dT%H:%M:%SZ'))")

az storage blob list --auth-mode login \
  --account-name "$STORAGE_ACCOUNT" --container-name "$CONTAINER" \
  --query "[?properties.creationTime < '$cutoff'].name" -o tsv \
  | while read -r blob; do
      [ -z "$blob" ] && continue
      az storage blob delete --auth-mode login \
        --account-name "$STORAGE_ACCOUNT" --container-name "$CONTAINER" \
        --name "$blob" --output none
      echo "pruned $blob"
    done

echo "memory backup complete: $STAMP"
