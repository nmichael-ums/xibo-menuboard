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

# --- 4. Inject MenuBoard REST API v1 routes into the Xibo .htaccess ---
# Xibo's VirtualHost (AllowOverride All) processes the .htaccess before any
# server-level conf.d rules can fire, so this is the only place rewrites work.
# Rules are inserted just before the Xibo catch-all block so they take priority.
HTACCESS_FILE="/var/www/cms/web/.htaccess"
if ! grep -q "menuboard/v1/" "$HTACCESS_FILE"; then
    echo "[MenuBoard] Patching .htaccess with /menuboard/v1/ routes..."
    cat > /tmp/mb-htaccess-insert.txt << 'INSERT'

# MenuBoard REST API v1 — route clean paths to the api.php dispatcher.
# In .htaccess context, patterns match the URI without the leading '/'.
# Rewrite target uses an absolute path so Apache re-runs Alias translation.
# Stores
RewriteCond %{REQUEST_METHOD} =GET
RewriteRule ^menuboard/v1/stores/?$ /menuboard-editor/api.php?action=stores [QSA,L]
RewriteCond %{REQUEST_METHOD} =POST
RewriteRule ^menuboard/v1/stores/?$ /menuboard-editor/api.php?action=store [QSA,L]
RewriteRule ^menuboard/v1/stores/([0-9]+)/?$ /menuboard-editor/api.php?action=store&storeId=$1 [QSA,L]
# Items
RewriteCond %{REQUEST_METHOD} =GET
RewriteRule ^menuboard/v1/items/?$ /menuboard-editor/api.php?action=items [QSA,L]
RewriteCond %{REQUEST_METHOD} =POST
RewriteRule ^menuboard/v1/items/?$ /menuboard-editor/api.php?action=item [QSA,L]
RewriteRule ^menuboard/v1/items/([0-9]+)/?$ /menuboard-editor/api.php?action=item&id=$1 [QSA,L]
# Prices
RewriteRule ^menuboard/v1/prices/?$ /menuboard-editor/api.php?action=prices [QSA,L]
# API Keys
RewriteCond %{REQUEST_METHOD} =GET
RewriteRule ^menuboard/v1/api-keys/?$ /menuboard-editor/api.php?action=api_keys [QSA,L]
RewriteCond %{REQUEST_METHOD} =POST
RewriteRule ^menuboard/v1/api-keys/?$ /menuboard-editor/api.php?action=api_key [QSA,L]
RewriteRule ^menuboard/v1/api-keys/([0-9]+)/?$ /menuboard-editor/api.php?action=api_key&keyId=$1 [QSA,L]
# POS endpoints — bulk before general to avoid prefix match clash
RewriteRule ^menuboard/v1/pos/catalog/?$ /menuboard-editor/api.php?action=pos_items [QSA,L]
RewriteRule ^menuboard/v1/pos/prices/bulk/?$ /menuboard-editor/api.php?action=pos_prices_bulk [QSA,L]
RewriteRule ^menuboard/v1/pos/prices/?$ /menuboard-editor/api.php?action=pos_prices [QSA,L]
RewriteRule ^menuboard/v1/pos/availability/?$ /menuboard-editor/api.php?action=pos_availability [QSA,L]

INSERT

    awk '
        FNR==NR { insert=insert $0 "\n"; next }
        /^# all others/ && !done { printf "%s", insert; done=1 }
        { print }
    ' /tmp/mb-htaccess-insert.txt "$HTACCESS_FILE" > "${HTACCESS_FILE}.tmp" \
        && mv "${HTACCESS_FILE}.tmp" "$HTACCESS_FILE"

    rm -f /tmp/mb-htaccess-insert.txt
fi

echo "[MenuBoard] Setup complete — handing off to CMS entrypoint."
exec /entrypoint.sh
