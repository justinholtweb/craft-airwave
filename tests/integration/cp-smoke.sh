#!/bin/bash
# Loads every Airwave control-panel screen as a logged-in admin and reports the status code.
# A template that only breaks when rendered is a template no PHP check will catch.
#
#   ddev exec bash /var/www/craft-airwave/tests/integration/cp-smoke.sh

JAR=/tmp/airwave-cp-jar.txt
rm -f "$JAR" /tmp/airwave-smoke-failures

CSRF=$(curl -s -b "$JAR" -c "$JAR" "http://localhost/index.php?p=actions/users/session-info" \
    -H "Accept: application/json" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("csrfTokenValue",""))')

LOGIN=$(curl -s -b "$JAR" -c "$JAR" -X POST "http://localhost/index.php?p=actions/users/login" \
    -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode "loginName=${AIRWAVE_CP_USER:-admin}" \
    --data-urlencode "password=${AIRWAVE_CP_PASS:-claudepassword}" \
    --data-urlencode "CRAFT_CSRF_TOKEN=$CSRF")

echo "login: $(echo "$LOGIN" | head -c 100)"
echo

FAILED=0

for PATH_ in "airwave" "airwave/feeds" "airwave/feeds/new" "airwave/items" "airwave/channels" "airwave/channels/new" "settings/plugins/airwave"; do
    CODE=$(curl -s -b "$JAR" -c "$JAR" -o /tmp/airwave-page.html -w "%{http_code}" "http://localhost/admin/$PATH_")
    TITLE=$(grep -oE "<title>[^<]*" /tmp/airwave-page.html | head -1 | sed "s/<title>//")

    if [ "$CODE" = "200" ]; then
        echo "  ✓ $CODE  $PATH_  — $TITLE"
    else
        FAILED=$((FAILED + 1))
        echo "  ✗ $CODE  $PATH_  — $TITLE"
        grep -oE "<title>[^<]*" /tmp/airwave-page.html | head -1
        grep -oE "exception[^<]{0,200}" /tmp/airwave-page.html | head -2
    fi
done

# The edit screens for something that exists. These are the templates worth smoke-testing: the
# feed one carries all four tabs, and an element index is a JavaScript shell that would render
# happily even if they were broken.
cd /var/www/html || exit 1

php /var/www/craft-airwave/tests/integration/ids.php | while read -r KIND HANDLE ID; do
    case "$KIND" in
        feed) URL="airwave/feeds/$ID" ;;
        channel) URL="airwave/channels/$ID" ;;
        *) continue ;;
    esac

    CODE=$(curl -s -b "$JAR" -o /tmp/airwave-page.html -w "%{http_code}" "http://localhost/admin/$URL")
    TITLE=$(grep -oE "<title>[^<]*" /tmp/airwave-page.html | head -1 | sed "s/<title>//")

    if [ "$CODE" = "200" ]; then
        echo "  ✓ $CODE  $URL  — $TITLE"
    else
        echo "  ✗ $CODE  $URL  — $TITLE"
        grep -oiE "(exception|error)[^<]{0,140}" /tmp/airwave-page.html | head -3
        echo "fail" >> /tmp/airwave-smoke-failures
    fi
done

# An item detail screen, if there is an item.
ITEM=$(php /var/www/craft-airwave/tests/integration/ids.php --item 2>/dev/null | head -1)

if [ -n "$ITEM" ]; then
    CODE=$(curl -s -b "$JAR" -o /tmp/airwave-page.html -w "%{http_code}" "http://localhost/admin/airwave/items/$ITEM")
    [ "$CODE" = "200" ] && echo "  ✓ $CODE  airwave/items/$ITEM" || { echo "  ✗ $CODE  airwave/items/$ITEM"; FAILED=$((FAILED+1)); }
fi

# The write paths. A controller that renders a form and cannot accept it back is half a feature,
# and every one of these posts is something a person does in the CP.
CSRF=$(curl -s -b "$JAR" -c "$JAR" "http://localhost/index.php?p=actions/users/session-info" \
    -H "Accept: application/json" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("csrfTokenValue",""))')

post() {
    curl -s -b "$JAR" -c "$JAR" -X POST "http://localhost/index.php?p=actions/$1" \
        -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" \
        --data-urlencode "CRAFT_CSRF_TOKEN=$CSRF" "${@:2}"
}

DISCOVER=$(post "airwave/feeds/discover" --data-urlencode "url=craftcms.com")

if echo "$DISCOVER" | grep -q '"success":true'; then
    echo "  ✓ POST airwave/feeds/discover — $(echo "$DISCOVER" | python3 -c 'import sys,json; print(len(json.load(sys.stdin).get("feeds",[])), "feeds found")' 2>/dev/null)"
else
    echo "  ✗ POST airwave/feeds/discover — $(echo "$DISCOVER" | head -c 200)"
    FAILED=$((FAILED + 1))
fi

SAVE=$(post "airwave/feeds/save" \
    --data-urlencode "title=Smoke test feed" \
    --data-urlencode "handle=aw-smoke-test" \
    --data-urlencode "url=https://craftcms.com/blog.rss" \
    --data-urlencode "enabled=1" \
    --data-urlencode "itemLimit=5" \
    --data-urlencode "displayOptions[layout]=cards" \
    --data-urlencode "displayOptions[limit]=4" \
    --data-urlencode "filters[include]=craft" \
    --data-urlencode "headers=X-Test: hello" \
    -o /dev/null -w "%{http_code}")

SMOKE_ID=$(php /var/www/craft-airwave/tests/integration/ids.php | awk '$2 == "aw-smoke-test" { print $3 }')

if [ -n "$SMOKE_ID" ]; then
    echo "  ✓ POST airwave/feeds/save — created feed $SMOKE_ID"

    REFRESH=$(post "airwave/feeds/refresh" --data-urlencode "sourceId=$SMOKE_ID")
    echo "$REFRESH" | grep -q '"success":true' \
        && echo "  ✓ POST airwave/feeds/refresh — $(echo "$REFRESH" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("message",""))' 2>/dev/null)" \
        || { echo "  ✗ POST airwave/feeds/refresh — $(echo "$REFRESH" | head -c 200)"; FAILED=$((FAILED + 1)); }

    DELETE=$(post "airwave/feeds/delete" --data-urlencode "sourceId=$SMOKE_ID")
    GONE=$(php /var/www/craft-airwave/tests/integration/ids.php | awk '$2 == "aw-smoke-test" { print $3 }')
    [ -z "$GONE" ] && echo "  ✓ POST airwave/feeds/delete" || { echo "  ✗ POST airwave/feeds/delete — still there"; FAILED=$((FAILED + 1)); }
else
    echo "  ✗ POST airwave/feeds/save — HTTP $SAVE, no feed created"
    FAILED=$((FAILED + 1))
fi

# A real front-end template, which is the only way to exercise the renderer, the asset bundle and
# the item markup the way a site actually uses them.
CODE=$(curl -s -o /tmp/airwave-front.html -w "%{http_code}" "http://localhost/airwave-render-test")

if [ "$CODE" = "200" ] && grep -q "airwave__item" /tmp/airwave-front.html; then
    echo "  ✓ $CODE  /airwave-render-test — $(grep -c 'airwave__item' /tmp/airwave-front.html) items rendered"
elif [ "$CODE" = "404" ]; then
    echo "  – skipped: no airwave-render-test template in this site"
else
    echo "  ✗ $CODE  /airwave-render-test"
    head -c 400 /tmp/airwave-front.html
    echo "fail" >> /tmp/airwave-smoke-failures
fi

# The loop above runs in a subshell, so its failures come back through a file rather than $FAILED.
if [ -f /tmp/airwave-smoke-failures ]; then
    FAILED=$((FAILED + $(wc -l < /tmp/airwave-smoke-failures)))
fi

echo
[ "$FAILED" = "0" ] && echo "all screens OK" || echo "$FAILED screens failed"
exit "$FAILED"
