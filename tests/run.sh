#!/bin/sh
# Every test runs on plain PHP with no WordPress. Non-zero exit means a real
# regression, not a style complaint.
cd "$(dirname "$0")" || exit 1
fail=0
for t in *.php; do
  [ "$t" = "wp-stubs.php" ] && continue
  printf '%-28s' "$t"
  if php "$t" >/tmp/bftd-test-out 2>&1; then echo "PASS"; else echo "FAIL"; cat /tmp/bftd-test-out; fail=1; fi
done
# Static checks that need no PHP runtime.
for t in *.py; do
  [ -e "$t" ] || continue
  printf '%-28s' "$t"
  if ( cd .. && python3 "tests/$t" ) >/tmp/bftd-test-out 2>&1; then echo "PASS"; else echo "FAIL"; cat /tmp/bftd-test-out; fail=1; fi
done

# Anything that is only true in a browser. Three bugs this session were
# invisible to every source-level check and obvious the moment a page was
# driven, so the ones that matter are driven. Skipped, loudly, where there is no
# browser to drive.
for t in browser/*.mjs; do
  [ -e "$t" ] || continue
  printf '%-28s' "$(basename "$t")"
  if [ ! -x /opt/pw-browsers/chromium ] || ! command -v node >/dev/null 2>&1; then
    echo "SKIP (no browser here)"
  elif node "$t" >/tmp/bftd-test-out 2>&1; then echo "PASS"; else echo "FAIL"; cat /tmp/bftd-test-out; fail=1; fi
done

# Every PHP file must parse.
printf '%-28s' "php -l (all files)"
if find .. -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors' | grep -q .; then
  echo "FAIL"; find .. -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors'; fail=1
else
  echo "PASS"
fi

exit $fail
