#!/usr/bin/env bash
#
# Apply pending SQL migrations (sql/migrate-*.sql), tracked in schema_migrations.
#
# Reads the same DB env the app uses (DB_HOST/DB_NAME/DB_USER/DB_PASS).
# Every migration file is idempotent, but this runner also records which
# versions have run so re-runs are cheap and auditable.
#
#   DB_PASS=... scripts/migrate.sh
#
# SAFETY: on an EXISTING database that has never been tracked, this refuses to
# run and prints a one-time baseline-seed command. That prevents re-running the
# one-shot DATA migration (THB->USD) which would corrupt balances if applied
# twice. Schema migrations (admin/blacklist/dhru/payment-intent) are guarded and
# safe to re-run; the data migration is not.
set -euo pipefail

HOST="${DB_HOST:-localhost}"
NAME="${DB_NAME:-imei_checker}"
USER="${DB_USER:-root}"
PASS="${DB_PASS:-}"
SQLDIR="$(cd "$(dirname "$0")/../sql" && pwd)"

mysql_do() {
    if [ -n "$PASS" ]; then
        mysql -h "$HOST" -u "$USER" -p"$PASS" "$NAME" "$@"
    else
        mysql -h "$HOST" -u "$USER" "$NAME" "$@"
    fi
}
q() { mysql_do -N -B -e "$1"; }

has_users=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$NAME' AND table_name='users';")
has_track=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$NAME' AND table_name='schema_migrations';")

if [ "$has_users" = "1" ] && [ "$has_track" = "0" ]; then
    cat <<EOF
Refusing to run: this looks like an EXISTING database with no schema_migrations
table. To avoid re-applying the one-shot THB->USD data migration, first record
the migrations already applied, then re-run this script:

  mysql -h $HOST -u $USER -p $NAME -e "
    CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
    INSERT IGNORE INTO schema_migrations (version) VALUES ('thb-to-usd'),('admin'),('blacklist'),('dhru-async');"

(Only include versions you have actually applied. A brand-new DB created from
schema.sql already has schema_migrations seeded and skips this check.)
EOF
    exit 1
fi

mysql_do -e 'CREATE TABLE IF NOT EXISTS `schema_migrations` (`version` VARCHAR(191) NOT NULL, `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`version`));'

applied_any=0
for f in "$SQLDIR"/migrate-*.sql; do
    [ -e "$f" ] || continue
    v="$(basename "$f" .sql)"; v="${v#migrate-}"
    done_already=$(q "SELECT COUNT(*) FROM schema_migrations WHERE version='$v';")
    if [ "$done_already" = "0" ]; then
        echo "applying: $v"
        mysql_do < "$f"
        mysql_do -e "INSERT IGNORE INTO schema_migrations (version) VALUES ('$v');"
        applied_any=1
    else
        echo "skip (applied): $v"
    fi
done

[ "$applied_any" = "0" ] && echo "Nothing to apply - schema is up to date."
echo "done."
