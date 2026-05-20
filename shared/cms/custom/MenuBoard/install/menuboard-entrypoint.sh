#!/bin/bash
# MenuBoard pre-start patcher.
# Idempotent: each step checks before modifying.
# Runs before the CMS entrypoint, then hands off via exec.

ROUTES_FILE="/var/www/cms/lib/routes-web.php"
SIDEBAR_FILE="/var/www/cms/views/authed-sidebar.twig"
TOPBAR_FILE="/var/www/cms/views/authed-topbar.twig"

echo "[MenuBoard] Running pre-start setup..."

# --- 1. Register the /menuboard/editor Slim route ---
if ! grep -q "menuboard.editor" "$ROUTES_FILE"; then
    echo "[MenuBoard] Patching routes-web.php..."
    cat >> "$ROUTES_FILE" << 'ROUTE'

// MenuBoard Editor
$app->get('/menuboard/editor', function() use ($app) {
    $app->render('menuboard-page.twig', [
        'currentUser' => $app->user,
        'clock'       => $app->dateService->getLocalDate(null, 'H:i T'),
    ]);
})->setName('menuboard.editor');
ROUTE
fi

# --- 2. Add the sidebar link before the Layouts entry ---
if ! grep -q "menuboard.editor" "$SIDEBAR_FILE"; then
    echo "[MenuBoard] Patching authed-sidebar.twig..."

    # Write the insert line to a temp file to keep quoting clean.
    # The Twig template contains double-quotes inside double-quotes so
    # we use a single-quoted heredoc and pass it via awk's FNR==NR trick.
    cat > /tmp/mb-sidebar-insert.txt << 'INSERT'
                <li class="sidebar-list"><a href="{{ urlFor("menuboard.editor") }}">MenuBoard Editor</a></li>
INSERT

    awk '
        FNR==NR { insert=$0; next }
        /urlFor\("layout\.view"\)/ && !done { print insert; done=1 }
        { print }
    ' /tmp/mb-sidebar-insert.txt "$SIDEBAR_FILE" > "${SIDEBAR_FILE}.tmp" \
        && mv "${SIDEBAR_FILE}.tmp" "$SIDEBAR_FILE"

    rm -f /tmp/mb-sidebar-insert.txt
fi

# --- 3. Add the horizontal topbar link before the Layouts entry ---
if [ -f "$TOPBAR_FILE" ] && ! grep -q "menuboard.editor" "$TOPBAR_FILE"; then
    echo "[MenuBoard] Patching authed-topbar.twig..."

    cat > /tmp/mb-topbar-insert.txt << 'INSERT'
                <li><a href="{{ urlFor("menuboard.editor") }}">MenuBoard Editor</a></li>
INSERT

    awk '
        FNR==NR { insert=$0; next }
        /urlFor\("layout\.view"\)/ && !done { print insert; done=1 }
        { print }
    ' /tmp/mb-topbar-insert.txt "$TOPBAR_FILE" > "${TOPBAR_FILE}.tmp" \
        && mv "${TOPBAR_FILE}.tmp" "$TOPBAR_FILE"

    rm -f /tmp/mb-topbar-insert.txt
fi

echo "[MenuBoard] Setup complete — handing off to CMS entrypoint."
exec /entrypoint.sh
