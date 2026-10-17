#!/usr/bin/env bash
# Run a command; on failure publish its last lines as one GitHub error annotation,
# so the reason is readable from the run summary / checks API without the raw log.
#   bash .github/scripts/run-annotated.sh "Label" command args...
label="$1"; shift
log="$(mktemp)"
"$@" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}
if [ "$status" -ne 0 ]; then
	msg=$(tail -n 60 "$log" | sed -e 's/%/%25/g' -e 's/\r//g' | awk 'BEGIN{ORS="%0A"} {print}')
	echo "::error title=${label} failed (exit ${status})::${msg}"
fi
rm -f "$log"
exit "$status"
