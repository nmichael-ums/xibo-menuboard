<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $dbHost = $_SERVER['MYSQL_HOST'] ?? 'mysql';
    $dbPort = $_SERVER['MYSQL_PORT'] ?? '3306';
    $dbName = $_SERVER['MYSQL_DATABASE'] ?? 'cms';
    $dbUser = $_SERVER['MYSQL_USER'] ?? 'cms';
    $dbPass = $_SERVER['MYSQL_PASSWORD'] ?? '';
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function bodyJson() {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['error' => 'Invalid JSON body'], 400);
    }
    return $data;
}

// =============================================================================
// PUBLISH HELPER — clear widget cache, bump layout modifiedDt, notify displays
// Pass $newBgMediaId to also resync lkwidgetmedia (theme background changed).
// =============================================================================

function getLibraryPath(PDO $pdo): string {
    $row = $pdo->query("SELECT value FROM setting WHERE setting = 'LIBRARY_LOCATION' LIMIT 1")->fetch();
    return $row ? rtrim($row['value'], '/') . '/' : '/var/www/cms/library/';
}

function publishWidgets(PDO $pdo, array $widgetIds, string $libraryPath, ?int $newBgMediaId = null): array {
    $layoutWidgets = []; // layoutId -> [widgetId, ...]

    foreach ($widgetIds as $wid) {
        $wid = (int)$wid;

        if ($newBgMediaId !== null) {
            $pdo->prepare("DELETE FROM lkwidgetmedia WHERE widgetId = ?")->execute([$wid]);
            if ($newBgMediaId > 0) {
                $pdo->prepare("INSERT IGNORE INTO lkwidgetmedia (widgetId, mediaId) VALUES (?, ?)")
                    ->execute([$wid, $newBgMediaId]);
            }
        }

        // Bump widget.modifiedDt so getModifiedDate() returns a time newer than the
        // cached HTML file — Xibo's getResourceOrCache() will see the cache as stale
        // and regenerate it with fresh theme/price data on the next RequiredFiles call.
        $pdo->prepare("UPDATE widget SET modifiedDt = UNIX_TIMESTAMP() WHERE widgetId = :wid")
            ->execute([':wid' => $wid]);

        // Delete the existing cache file so regeneration is unconditional even if the
        // clock skew or a race would otherwise make modifiedDt check ambiguous.
        $widgetCache = $libraryPath . 'widget/' . $wid . '/';
        if (is_dir($widgetCache)) {
            foreach (glob($widgetCache . '*') as $f) { @unlink($f); }
        }

        $lRow = $pdo->prepare(
            "SELECT r.layoutId FROM region r
             JOIN playlist p ON p.regionId = r.regionId
             WHERE p.playlistId = (SELECT playlistId FROM widget WHERE widgetId = :wid LIMIT 1)
             LIMIT 1"
        );
        $lRow->execute([':wid' => $wid]);
        $layoutData = $lRow->fetch();
        if ($layoutData) {
            $layoutWidgets[(int)$layoutData['layoutId']][] = $wid;
        }
    }

    // Patch a <cacheKey> timestamp into each widget's XLF options block.
    // This changes the XLF file MD5, which is the signal Xibo players use to
    // know they need to re-download the layout and re-fetch widget HTML via
    // GetResource — where the stale modifiedDt causes fresh HTML generation.
    // We do NOT touch layout.modifiedDt; that field triggers Xibo background
    // tasks that would overwrite the XLF and cause a perpetual download loop.
    $cacheKey = (string)time();
    foreach ($layoutWidgets as $layoutId => $wids) {
        $xlfPath = $libraryPath . $layoutId . '.xlf';
        if (file_exists($xlfPath)) {
            $doc = new DOMDocument();
            if ($doc->load($xlfPath)) {
                $xpath = new DOMXPath($doc);
                foreach ($wids as $wid) {
                    foreach ($xpath->query("//media[@id='{$wid}']/options") as $opts) {
                        $node = $xpath->query('cacheKey', $opts)->item(0);
                        if ($node) {
                            $node->nodeValue = $cacheKey;
                        } else {
                            $opts->appendChild($doc->createElement('cacheKey', $cacheKey));
                        }
                    }
                }
                $doc->save($xlfPath);
            }
        }

        $pdo->prepare("UPDATE display SET mediaInventoryStatus = 3 WHERE defaultlayoutid = :lid")
            ->execute([':lid' => $layoutId]);
    }

    return array_keys($layoutWidgets);
}

function widgetsByTheme(PDO $pdo, int $themeId): array {
    $stmt = $pdo->prepare(
        "SELECT w.widgetId FROM widget w
         JOIN widgetoption wo ON wo.widgetId = w.widgetId
           AND wo.`option` = 'themeId' AND CAST(wo.value AS UNSIGNED) = :tid
         JOIN playlist p ON p.playlistId = w.playlistId
         JOIN region r   ON r.regionId   = p.regionId
         JOIN layout l   ON l.layoutId   = r.layoutId
         WHERE w.type = 'menuboard'
           AND (l.parentId IS NULL OR l.parentId = '' OR l.parentId = 0)"
    );
    $stmt->execute([':tid' => $themeId]);
    return array_column($stmt->fetchAll(), 'widgetId');
}

// Only notifies widgets whose theme's layoutJson actually references one of the
// changed itemIds — avoids invalidating boards that don't display those items.
function widgetsByStoreAndItems(PDO $pdo, int $storeId, array $itemIds): array {
    if (empty($itemIds)) return [];
    $stmt = $pdo->prepare(
        "SELECT w.widgetId, mt.layoutJson
           FROM widget w
           JOIN widgetoption wo_store ON wo_store.widgetId = w.widgetId
             AND wo_store.`option` = 'storeId' AND CAST(wo_store.value AS UNSIGNED) = :sid
           JOIN widgetoption wo_theme ON wo_theme.widgetId = w.widgetId
             AND wo_theme.`option` = 'themeId'
           LEFT JOIN menuboard_themes mt
             ON mt.themeId = CAST(wo_theme.value AS UNSIGNED)
           JOIN playlist p ON p.playlistId = w.playlistId
           JOIN region r   ON r.regionId   = p.regionId
           JOIN layout l   ON l.layoutId   = r.layoutId
          WHERE w.type = 'menuboard'
            AND (l.parentId IS NULL OR l.parentId = '' OR l.parentId = 0)"
    );
    $stmt->execute([':sid' => $storeId]);
    $affected = [];
    foreach ($stmt->fetchAll() as $row) {
        $slots       = json_decode($row['layoutJson'] ?? '[]', true) ?: [];
        $slotItemIds = array_map('intval', array_column($slots, 'itemId'));
        if (array_intersect($itemIds, $slotItemIds)) {
            $affected[] = $row['widgetId'];
        }
    }
    return $affected;
}

// =============================================================================
// API KEY AUTH HELPERS
// =============================================================================

function ensureApiKeyTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menuboard_api_keys` (
            `keyId`      INT          NOT NULL AUTO_INCREMENT,
            `keyLabel`   VARCHAR(255) NOT NULL,
            `keyHash`    VARCHAR(64)  NOT NULL,
            `storeId`    INT                   DEFAULT NULL,
            `isActive`   TINYINT(1)   NOT NULL DEFAULT 1,
            `createdAt`  DATETIME              DEFAULT CURRENT_TIMESTAMP,
            `lastUsedAt` DATETIME              DEFAULT NULL,
            PRIMARY KEY (`keyId`),
            UNIQUE KEY `uq_hash` (`keyHash`),
            KEY `idx_active` (`isActive`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// Validates Bearer token, updates lastUsedAt, returns key row. Exits with 401 on failure.
function requirePosAuth(PDO $pdo): array {
    ensureApiKeyTable($pdo);
    // Apache may strip Authorization before it reaches $_SERVER — check all sources.
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '')
           ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        respond(['error' => 'Missing or invalid Authorization header. Expected: Authorization: Bearer <key>'], 401);
    }
    $hash = hash('sha256', $m[1]);
    $stmt = $pdo->prepare(
        "SELECT keyId, keyLabel, storeId, groupId FROM menuboard_api_keys WHERE keyHash = :h AND isActive = 1 LIMIT 1"
    );
    $stmt->execute([':h' => $hash]);
    $key = $stmt->fetch();
    if (!$key) respond(['error' => 'Invalid or revoked API key'], 401);
    $pdo->prepare("UPDATE menuboard_api_keys SET lastUsedAt = NOW() WHERE keyId = :kid")
        ->execute([':kid' => $key['keyId']]);
    return $key;
}

// Resolve storeId or groupId from a request body to an array of storeIds, enforcing key scope.
function resolveStoreIds(PDO $pdo, array $body, array $key): array {
    $storeId = isset($body['storeId']) && $body['storeId'] !== null && $body['storeId'] !== ''
               ? (int)$body['storeId'] : null;
    $groupId = isset($body['groupId']) && $body['groupId'] !== null && $body['groupId'] !== ''
               ? (int)$body['groupId'] : null;

    if ($storeId === null && $groupId === null) {
        respond(['error' => 'storeId or groupId is required'], 400);
    }

    if ($storeId !== null) {
        if ($key['storeId'] !== null && (int)$key['storeId'] !== $storeId) {
            respond(['error' => 'API key not authorized for this store'], 403);
        }
        if ($key['groupId'] !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM menuboard_store_group_members WHERE groupId = ? AND storeId = ?");
            $chk->execute([$key['groupId'], $storeId]);
            if (!$chk->fetch()) respond(['error' => 'API key not authorized for this store'], 403);
        }
        return [$storeId];
    }

    // groupId path
    if ($key['storeId'] !== null) {
        respond(['error' => 'This API key is scoped to a single store and cannot target a group'], 403);
    }
    if ($key['groupId'] !== null && (int)$key['groupId'] !== $groupId) {
        respond(['error' => 'API key not authorized for this group'], 403);
    }
    $stmt = $pdo->prepare(
        "SELECT m.storeId FROM menuboard_store_group_members m
           JOIN menuboard_stores s ON s.storeId = m.storeId AND s.isActive = 1
          WHERE m.groupId = :gid ORDER BY s.storeName"
    );
    $stmt->execute([':gid' => $groupId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'storeId'));
    if (empty($ids)) respond(['error' => 'Group not found or has no active stores'], 404);
    return $ids;
}

// Batch-resolve an array of item objects (each has posCode or itemId) to itemIds.
// Returns ['resolved' => [inputIndex => itemId], 'notFound' => [...]]
function resolvePosItems(PDO $pdo, array $items): array {
    $posCodes = [];
    $itemIds  = [];
    foreach ($items as $i) {
        if (!empty($i['posCode']))  $posCodes[] = trim((string)$i['posCode']);
        elseif (!empty($i['itemId'])) $itemIds[] = (int)$i['itemId'];
    }

    $codeMap = [];
    if (!empty($posCodes)) {
        $ph   = implode(',', array_fill(0, count($posCodes), '?'));
        $stmt = $pdo->prepare("SELECT itemId, posCode FROM menuboard_items WHERE posCode IN ($ph) AND isActive = 1");
        $stmt->execute($posCodes);
        foreach ($stmt->fetchAll() as $r) $codeMap[$r['posCode']] = (int)$r['itemId'];
    }

    $idSet = [];
    if (!empty($itemIds)) {
        $ph   = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $pdo->prepare("SELECT itemId FROM menuboard_items WHERE itemId IN ($ph) AND isActive = 1");
        $stmt->execute($itemIds);
        foreach ($stmt->fetchAll() as $r) $idSet[(int)$r['itemId']] = true;
    }

    $resolved = [];
    $notFound = [];
    foreach ($items as $idx => $i) {
        if (!empty($i['posCode'])) {
            $code = trim((string)$i['posCode']);
            if (isset($codeMap[$code])) $resolved[$idx] = $codeMap[$code];
            else $notFound[] = ['posCode' => $code];
        } elseif (!empty($i['itemId'])) {
            $id = (int)$i['itemId'];
            if (isset($idSet[$id])) $resolved[$idx] = $id;
            else $notFound[] = ['itemId' => $id];
        } else {
            $notFound[] = ['index' => $idx, 'error' => 'missing posCode and itemId'];
        }
    }
    return ['resolved' => $resolved, 'notFound' => $notFound];
}

// =============================================================================
// THEMES
// =============================================================================

if ($method === 'GET' && $action === 'themes') {
    $rows = $pdo->query(
        "SELECT themeId, themeName, backgroundUrl
           FROM menuboard_themes
          ORDER BY themeName"
    )->fetchAll();
    respond($rows);
}

if ($method === 'GET' && $action === 'theme') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM menuboard_themes WHERE themeId = :id");
    $stmt->execute([':id' => $id]);
    $row  = $stmt->fetch();
    if (!$row) respond(['error' => 'Theme not found'], 404);
    $row['layoutJson'] = json_decode($row['layoutJson'] ?? '[]', true) ?: [];
    respond($row);
}

if ($method === 'POST' && $action === 'theme') {
    $body = bodyJson();
    $name = trim($body['themeName'] ?? '');
    $bg   = trim($body['backgroundUrl'] ?? '') ?: null;
    if ($name === '') respond(['error' => 'themeName is required'], 400);
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_themes (clientId, themeName, backgroundUrl, layoutJson)
         VALUES (0, :name, :bg, '[]')"
    );
    $stmt->execute([':name' => $name, ':bg' => $bg]);
    respond(['themeId' => (int)$pdo->lastInsertId(), 'themeName' => $name], 201);
}

if ($method === 'PUT' && $action === 'theme') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = bodyJson();
    // Fetch old backgroundMediaId so we can detect whether the background changed.
    $stmt = $pdo->prepare("SELECT backgroundMediaId FROM menuboard_themes WHERE themeId = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) respond(['error' => 'Theme not found'], 404);
    $oldMediaId = (int)($existing['backgroundMediaId'] ?? 0);

    $name       = trim($body['themeName']     ?? '');
    $bg         = trim($body['backgroundUrl'] ?? '') ?: null;
    $layoutJson = json_encode($body['layoutJson'] ?? []);
    // Extract mediaId from library URL so the player can resolve the file locally.
    $mediaId    = null;
    if ($bg && preg_match('#/library/download/(\d+)/#', $bg, $m)) {
        $mediaId = (int)$m[1];
    }
    $stmt = $pdo->prepare(
        "UPDATE menuboard_themes
            SET themeName       = CASE WHEN :name != '' THEN :name2 ELSE themeName END,
                backgroundUrl   = :bg,
                backgroundMediaId = :mediaId,
                layoutJson      = :layout
          WHERE themeId = :id"
    );
    $stmt->execute([
        ':name'    => $name,
        ':name2'   => $name,
        ':bg'      => $bg,
        ':mediaId' => $mediaId,
        ':layout'  => $layoutJson,
        ':id'      => $id,
    ]);

    $widgetIds = widgetsByTheme($pdo, $id);
    // Only resync lkwidgetmedia when the background image actually changed —
    // unnecessary deletes cause the player to see a RequiredFiles mismatch.
    $newMediaId = (int)($mediaId ?? 0);
    $bgChanged  = ($newMediaId !== $oldMediaId);
    $publishedLayouts = publishWidgets($pdo, $widgetIds, getLibraryPath($pdo), $bgChanged ? $newMediaId : null);
    respond([
        'success'         => true,
        'message'         => 'Theme saved successfully',
        'widgetsUpdated'  => count($widgetIds),
        'publishedLayouts'=> count($publishedLayouts),
    ]);
}

if ($method === 'DELETE' && $action === 'theme') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM menuboard_themes WHERE themeId = :id");
    $stmt->execute([':id' => $id]);
    respond(['success' => true]);
}

if ($method === 'PUT' && $action === 'duplicate') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = bodyJson();
    $name = trim($body['themeName'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM menuboard_themes WHERE themeId = :id");
    $stmt->execute([':id' => $id]);
    $src  = $stmt->fetch();
    if (!$src) respond(['error' => 'Theme not found'], 404);
    if ($name === '') $name = $src['themeName'] . ' (copy)';
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_themes (clientId, themeName, backgroundUrl, layoutJson)
         VALUES (:cid, :name, :bg, :layout)"
    );
    $stmt->execute([
        ':cid'    => $src['clientId'],
        ':name'   => $name,
        ':bg'     => $src['backgroundUrl'],
        ':layout' => $src['layoutJson'],
    ]);
    respond(['themeId' => (int)$pdo->lastInsertId(), 'themeName' => $name], 201);
}

// =============================================================================
// LIBRARY IMAGES
// =============================================================================

if ($method === 'GET' && $action === 'library') {
    $search = trim($_GET['search'] ?? '');
    $start  = (int)($_GET['start'] ?? 0);
    $length = min((int)($_GET['length'] ?? 20), 50);

    $where  = "WHERE type = 'image' AND retired = 0";
    $params = [];
    if ($search !== '') {
        $where   .= " AND name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM media $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $params[':limit']  = $length;
    $params[':offset'] = $start;

    $stmt = $pdo->prepare(
        "SELECT mediaId, name, originalFileName, fileSize
           FROM media
          $where
          ORDER BY name
          LIMIT :limit OFFSET :offset"
    );
    // PDO needs integer binding for LIMIT/OFFSET
    $stmt->bindValue(':limit',  $length, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $start,  PDO::PARAM_INT);
    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%');
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Build a URL for each image
    // Xibo serves library files at /library/download/:mediaId/:type
    foreach ($rows as &$row) {
        $row['url'] = '/library/download/' . $row['mediaId'] . '/image';
    }
    unset($row);

    respond(['total' => $total, 'images' => $rows]);
}

// =============================================================================
// MENU ITEMS
// =============================================================================

if ($method === 'GET' && $action === 'items') {
    $all   = !empty($_GET['all']);
    $where = $all ? '' : 'WHERE isActive = 1';
    $stmt  = $pdo->query(
        "SELECT itemId, clientId, concept, posCode, name, category, description, isActive
           FROM menuboard_items
           $where
          ORDER BY concept, category, name"
    );
    respond($stmt->fetchAll());
}

if ($method === 'GET' && $action === 'item') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM menuboard_items WHERE itemId = :id");
    $stmt->execute([':id' => $id]);
    $row  = $stmt->fetch();
    if (!$row) respond(['error' => 'Item not found'], 404);
    respond($row);
}

if ($method === 'POST' && $action === 'item') {
    $body = bodyJson();
    $name = trim($body['name'] ?? '');
    if ($name === '') respond(['error' => 'name is required'], 400);
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_items (clientId, concept, posCode, name, description, category, isActive)
         VALUES (:cid, :concept, :posCode, :name, :desc, :cat, 1)"
    );
    $stmt->execute([
        ':cid'     => (int)($body['clientId']   ?? 0),
        ':concept' => trim($body['concept']      ?? ''),
        ':posCode' => trim($body['posCode']      ?? ''),
        ':name'    => $name,
        ':desc'    => trim($body['description'] ?? ''),
        ':cat'     => trim($body['category']    ?? ''),
    ]);
    respond(['itemId' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT' && $action === 'item') {
    $id   = (int)($_GET['id'] ?? 0);
    $body = bodyJson();
    // Only update fields that were explicitly sent in the request
    $sets   = [];
    $params = [':id' => $id];
    if (array_key_exists('concept',     $body)) { $sets[] = 'concept     = :concept'; $params[':concept'] = trim($body['concept']); }
    if (array_key_exists('posCode',     $body)) { $sets[] = 'posCode     = :posCode'; $params[':posCode'] = trim($body['posCode']); }
    if (array_key_exists('name',        $body)) { $sets[] = 'name        = :name';    $params[':name']    = trim($body['name']); }
    if (array_key_exists('description', $body)) { $sets[] = 'description = :desc';    $params[':desc']    = trim($body['description']); }
    if (array_key_exists('category',    $body)) { $sets[] = 'category    = :cat';     $params[':cat']     = trim($body['category']); }
    if (array_key_exists('isActive',    $body)) { $sets[] = 'isActive    = :active';  $params[':active']  = (int)(bool)$body['isActive']; }
    if (empty($sets)) respond(['error' => 'Nothing to update'], 400);
    $pdo->prepare("UPDATE menuboard_items SET " . implode(', ', $sets) . " WHERE itemId = :id")
        ->execute($params);
    respond(['success' => true]);
}

if ($method === 'DELETE' && $action === 'item') {
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM menuboard_items WHERE itemId = :id")->execute([':id' => $id]);
    // Also remove associated prices
    $pdo->prepare("DELETE FROM menuboard_prices WHERE itemId = :id")->execute([':id' => $id]);
    respond(['success' => true]);
}

// =============================================================================
// PRICES
// =============================================================================

if ($method === 'GET' && $action === 'prices') {
    $storeId = (int)($_GET['storeId'] ?? 0);

    applyDuePriceSchedules($pdo, $storeId);

    // Only filter by concepts when the store has explicit concept rows saved
    $cCount = $pdo->prepare("SELECT COUNT(*) FROM menuboard_store_concepts WHERE storeId = ?");
    $cCount->execute([$storeId]);
    $conceptFilter = '';
    $params        = [':storeId' => $storeId];
    if ((int)$cCount->fetchColumn() > 0) {
        $conceptFilter   = "AND (i.concept = '' OR i.concept IS NULL
                             OR EXISTS (
                                 SELECT 1 FROM menuboard_store_concepts sc
                                  WHERE sc.storeId = :storeId2
                                    AND sc.concept  = i.concept
                                    AND sc.isEnabled = 1
                             ))";
        $params[':storeId2'] = $storeId;
    }

    $stmt = $pdo->prepare(
        "SELECT i.itemId, i.name, i.concept, i.category,
                p.price, p.priceLabel, COALESCE(p.isAvailable, 1) AS isAvailable
           FROM menuboard_items i
           LEFT JOIN menuboard_prices p
                  ON p.itemId  = i.itemId
                 AND p.storeId = :storeId
          WHERE i.isActive = 1
          $conceptFilter
          ORDER BY i.concept, i.category, i.name"
    );
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

if ($method === 'PUT' && $action === 'prices') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    $body    = bodyJson();
    if (!is_array($body)) respond(['error' => 'Expected array of price objects'], 400);
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
         VALUES (:itemId, :storeId, :price, :label, :avail)
         ON DUPLICATE KEY UPDATE
             price       = VALUES(price),
             priceLabel  = VALUES(priceLabel),
             isAvailable = VALUES(isAvailable)"
    );
    $updated = 0;
    foreach ($body as $p) {
        $itemId = (int)($p['itemId'] ?? 0);
        if (!$itemId) continue;
        $stmt->execute([
            ':itemId'  => $itemId,
            ':storeId' => $storeId,
            ':price'   => isset($p['price'])      ? (float)$p['price']        : null,
            ':label'   => isset($p['priceLabel'])  ? (string)$p['priceLabel'] : null,
            ':avail'   => isset($p['isAvailable']) ? (int)(bool)$p['isAvailable'] : 1,
        ]);
        $updated++;
    }

    $changedItemIds   = array_map('intval', array_column($body, 'itemId'));
    $widgetIds        = widgetsByStoreAndItems($pdo, $storeId, $changedItemIds);
    $publishedLayouts = publishWidgets($pdo, $widgetIds, getLibraryPath($pdo));
    respond([
        'updated'         => $updated,
        'publishedLayouts'=> count($publishedLayouts),
    ]);
}

// =============================================================================
// STORES
// =============================================================================

// Ensure tables exist and seed from Xibo display groups on first use.
function ensureStoreTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menuboard_stores` (
            `storeId`   INT          NOT NULL AUTO_INCREMENT,
            `storeName` VARCHAR(255) NOT NULL,
            `isActive`  TINYINT(1)   NOT NULL DEFAULT 1,
            `createdAt` DATETIME              DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`storeId`),
            KEY `idx_active` (`isActive`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add extended columns if they were not present in the initial schema
    $extCols = [
        'contactName'  => "ALTER TABLE menuboard_stores ADD COLUMN contactName  VARCHAR(255) NOT NULL DEFAULT ''",
        'storeAddress' => "ALTER TABLE menuboard_stores ADD COLUMN storeAddress TEXT",
        'phoneNumber'  => "ALTER TABLE menuboard_stores ADD COLUMN phoneNumber  VARCHAR(50)  NOT NULL DEFAULT ''",
        'notes'        => "ALTER TABLE menuboard_stores ADD COLUMN notes        TEXT",
    ];
    foreach ($extCols as $sql) {
        try { $pdo->exec($sql); } catch (\Exception $e) { /* column already exists */ }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menuboard_store_concepts` (
            `storeId`   INT          NOT NULL,
            `concept`   VARCHAR(100) NOT NULL,
            `isEnabled` TINYINT(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (`storeId`, `concept`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

}

if ($method === 'GET' && $action === 'stores') {
    ensureStoreTables($pdo);
    $all  = !empty($_GET['all']);
    $where = $all ? '' : 'WHERE isActive = 1';
    $rows = $pdo->query(
        "SELECT storeId, storeName, contactName, storeAddress, phoneNumber, notes, isActive
           FROM menuboard_stores
          $where
          ORDER BY storeName"
    )->fetchAll();
    respond($rows);
}

if ($method === 'GET' && $action === 'store') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    if (!$storeId) respond(['error' => 'storeId required'], 400);
    ensureStoreTables($pdo);
    $stmt = $pdo->prepare(
        "SELECT storeId, storeName, contactName, storeAddress, phoneNumber, notes, isActive
           FROM menuboard_stores
          WHERE storeId = :sid"
    );
    $stmt->execute([':sid' => $storeId]);
    $store = $stmt->fetch();
    if (!$store) respond(['error' => 'Store not found'], 404);
    respond($store);
}

if ($method === 'PUT' && $action === 'store') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    $body    = bodyJson();
    if (!$storeId) respond(['error' => 'storeId required'], 400);

    $sets   = [];
    $params = [];

    if (array_key_exists('storeName', $body)) {
        $name = trim($body['storeName']);
        if ($name === '') respond(['error' => 'storeName cannot be empty'], 400);
        $sets[] = 'storeName = :storeName';
        $params[':storeName'] = $name;
    }
    foreach (['contactName', 'storeAddress', 'phoneNumber', 'notes'] as $col) {
        if (array_key_exists($col, $body)) {
            $sets[]        = "$col = :$col";
            $params[":$col"] = trim((string)$body[$col]);
        }
    }
    if (array_key_exists('isActive', $body)) {
        $sets[]          = 'isActive = :isActive';
        $params[':isActive'] = (int)$body['isActive'];
    }

    if (!empty($sets)) {
        $params[':sid'] = $storeId;
        $pdo->prepare(
            "UPDATE menuboard_stores SET " . implode(', ', $sets) . " WHERE storeId = :sid"
        )->execute($params);
    }

    if (isset($body['concepts']) && is_array($body['concepts'])) {
        $cStmt = $pdo->prepare(
            "INSERT INTO menuboard_store_concepts (storeId, concept, isEnabled)
             VALUES (:sid, :concept, :enabled)
             ON DUPLICATE KEY UPDATE isEnabled = VALUES(isEnabled)"
        );
        foreach ($body['concepts'] as $c) {
            $concept = trim($c['concept'] ?? '');
            if ($concept === '') continue;
            $cStmt->execute([
                ':sid'     => $storeId,
                ':concept' => $concept,
                ':enabled' => (int)($c['isEnabled'] ?? 1),
            ]);
        }
    }

    respond(['success' => true]);
}

if ($method === 'POST' && $action === 'store') {
    $body      = bodyJson();
    $storeName = trim($body['storeName'] ?? '');
    if ($storeName === '') respond(['error' => 'storeName is required'], 400);

    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_stores (storeName) VALUES (:name)"
    );
    $stmt->execute([':name' => $storeName]);
    $newStoreId = (int)$pdo->lastInsertId();

    // Save enabled concepts
    $concepts = $body['concepts'] ?? null;
    if (is_array($concepts) && count($concepts) > 0) {
        $cStmt = $pdo->prepare(
            "INSERT INTO menuboard_store_concepts (storeId, concept, isEnabled)
             VALUES (:sid, :concept, :enabled)
             ON DUPLICATE KEY UPDATE isEnabled = VALUES(isEnabled)"
        );
        foreach ($concepts as $c) {
            $cStmt->execute([
                ':sid'     => $newStoreId,
                ':concept' => trim($c['concept'] ?? ''),
                ':enabled' => (int)($c['isEnabled'] ?? 1),
            ]);
        }
    }

    // Copy prices from an existing store
    $copyFrom = (int)($body['copyPricesFrom'] ?? 0);
    if ($copyFrom > 0) {
        // Build concept filter for the new store
        $enabledConcepts = [];
        if (is_array($concepts)) {
            foreach ($concepts as $c) {
                if ((int)($c['isEnabled'] ?? 1)) {
                    $enabledConcepts[] = trim($c['concept'] ?? '');
                }
            }
        }

        if (count($enabledConcepts) > 0) {
            $placeholders = implode(',', array_fill(0, count($enabledConcepts), '?'));
            $params       = array_merge([$newStoreId, $copyFrom], $enabledConcepts);
            $pdo->prepare(
                "INSERT IGNORE INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
                 SELECT p.itemId, ?, p.price, p.priceLabel, p.isAvailable
                   FROM menuboard_prices p
                   JOIN menuboard_items  i ON i.itemId = p.itemId
                  WHERE p.storeId = ?
                    AND i.concept IN ($placeholders)"
            )->execute($params);
        } else {
            // No concept restrictions — copy all
            $pdo->prepare(
                "INSERT IGNORE INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
                 SELECT itemId, ?, price, priceLabel, isAvailable
                   FROM menuboard_prices
                  WHERE storeId = ?"
            )->execute([$newStoreId, $copyFrom]);
        }
    }

    respond(['storeId' => $newStoreId, 'storeName' => $storeName]);
}

if ($method === 'GET' && $action === 'store_concepts') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    if (!$storeId) respond(['error' => 'storeId required'], 400);

    // All known concepts from items
    $allConcepts = $pdo->query(
        "SELECT DISTINCT concept FROM menuboard_items WHERE concept != '' AND isActive = 1 ORDER BY concept"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Current enabled state for this store
    $saved = $pdo->prepare(
        "SELECT concept, isEnabled FROM menuboard_store_concepts WHERE storeId = :sid"
    );
    $saved->execute([':sid' => $storeId]);
    $savedMap = [];
    foreach ($saved->fetchAll() as $r) {
        $savedMap[$r['concept']] = (int)$r['isEnabled'];
    }

    $result = [];
    foreach ($allConcepts as $c) {
        $result[] = [
            'concept'   => $c,
            'isEnabled' => array_key_exists($c, $savedMap) ? $savedMap[$c] : 1,
            'isSaved'   => array_key_exists($c, $savedMap),
        ];
    }
    respond($result);
}

if ($method === 'PUT' && $action === 'store_concepts') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    $body    = bodyJson();
    if (!$storeId) respond(['error' => 'storeId required'], 400);
    if (!is_array($body)) respond(['error' => 'Expected array'], 400);

    $pdo->prepare("DELETE FROM menuboard_store_concepts WHERE storeId = ?")->execute([$storeId]);
    if (count($body) > 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO menuboard_store_concepts (storeId, concept, isEnabled) VALUES (?, ?, ?)"
        );
        foreach ($body as $c) {
            $stmt->execute([$storeId, trim($c['concept'] ?? ''), (int)($c['isEnabled'] ?? 1)]);
        }
    }
    respond(['ok' => true]);
}

// =============================================================================
// API KEY MANAGEMENT (editor only — no Bearer auth required)
// =============================================================================

if ($method === 'GET' && $action === 'api_keys') {
    ensureApiKeyTable($pdo);
    $rows = $pdo->query(
        "SELECT k.keyId, k.keyLabel, k.storeId, s.storeName, k.groupId, g.groupName,
                k.isActive, k.createdAt, k.lastUsedAt
           FROM menuboard_api_keys k
           LEFT JOIN menuboard_stores s ON s.storeId = k.storeId
           LEFT JOIN menuboard_store_groups g ON g.groupId = k.groupId
          ORDER BY k.createdAt DESC"
    )->fetchAll();
    respond($rows);
}

if ($method === 'POST' && $action === 'api_key') {
    ensureApiKeyTable($pdo);
    $body    = bodyJson();
    $label   = trim($body['keyLabel'] ?? '');
    $storeId = isset($body['storeId']) && $body['storeId'] !== null && $body['storeId'] !== ''
               ? (int)$body['storeId'] : null;
    $groupId = isset($body['groupId']) && $body['groupId'] !== null && $body['groupId'] !== ''
               ? (int)$body['groupId'] : null;
    if ($label === '') respond(['error' => 'keyLabel is required'], 400);
    if ($storeId && $groupId) respond(['error' => 'Specify storeId or groupId, not both'], 400);

    $raw  = 'mbk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $raw);

    $pdo->prepare("INSERT INTO menuboard_api_keys (keyLabel, keyHash, storeId, groupId) VALUES (:label, :hash, :sid, :gid)")
        ->execute([':label' => $label, ':hash' => $hash, ':sid' => $storeId, ':gid' => $groupId]);

    respond(['keyId' => (int)$pdo->lastInsertId(), 'keyLabel' => $label, 'key' => $raw,
             'storeId' => $storeId, 'groupId' => $groupId], 201);
}

if ($method === 'DELETE' && $action === 'api_key') {
    ensureApiKeyTable($pdo);
    $keyId = (int)($_GET['keyId'] ?? 0);
    if (!$keyId) respond(['error' => 'keyId required'], 400);
    $pdo->prepare("UPDATE menuboard_api_keys SET isActive = 0 WHERE keyId = :kid")
        ->execute([':kid' => $keyId]);
    respond(['success' => true]);
}

// =============================================================================
// STORE GROUPS
// =============================================================================

if ($method === 'GET' && $action === 'api_groups') {
    $rows = $pdo->query(
        "SELECT g.groupId, g.groupName, g.description,
                COUNT(m.storeId) AS storeCount,
                GROUP_CONCAT(s.storeName ORDER BY s.storeName SEPARATOR ', ') AS storeNames
           FROM menuboard_store_groups g
           LEFT JOIN menuboard_store_group_members m ON m.groupId = g.groupId
           LEFT JOIN menuboard_stores s ON s.storeId = m.storeId
          GROUP BY g.groupId
          ORDER BY g.groupName"
    )->fetchAll();
    respond($rows);
}

if ($method === 'GET' && $action === 'api_group') {
    $groupId = (int)($_GET['groupId'] ?? 0);
    if (!$groupId) respond(['error' => 'groupId required'], 400);
    $stmt = $pdo->prepare("SELECT * FROM menuboard_store_groups WHERE groupId = :gid");
    $stmt->execute([':gid' => $groupId]);
    $group = $stmt->fetch();
    if (!$group) respond(['error' => 'Group not found'], 404);
    $members = $pdo->prepare("SELECT storeId FROM menuboard_store_group_members WHERE groupId = :gid");
    $members->execute([':gid' => $groupId]);
    $group['storeIds'] = array_column($members->fetchAll(), 'storeId');
    respond($group);
}

if ($method === 'POST' && $action === 'api_group') {
    $body = bodyJson();
    $name = trim($body['groupName'] ?? '');
    if ($name === '') respond(['error' => 'groupName is required'], 400);
    $pdo->prepare("INSERT INTO menuboard_store_groups (groupName, description) VALUES (:name, :desc)")
        ->execute([':name' => $name, ':desc' => trim($body['description'] ?? '') ?: null]);
    $groupId = (int)$pdo->lastInsertId();
    if (!empty($body['storeIds']) && is_array($body['storeIds'])) {
        $ins = $pdo->prepare("INSERT IGNORE INTO menuboard_store_group_members (groupId, storeId) VALUES (?, ?)");
        foreach ($body['storeIds'] as $sid) $ins->execute([$groupId, (int)$sid]);
    }
    respond(['groupId' => $groupId, 'groupName' => $name], 201);
}

if ($method === 'PUT' && $action === 'api_group') {
    $groupId = (int)($_GET['groupId'] ?? 0);
    $body    = bodyJson();
    if (!$groupId) respond(['error' => 'groupId required'], 400);
    $sets = []; $params = [];
    if (array_key_exists('groupName', $body)) {
        $name = trim($body['groupName']);
        if ($name === '') respond(['error' => 'groupName cannot be empty'], 400);
        $sets[] = 'groupName = :name'; $params[':name'] = $name;
    }
    if (array_key_exists('description', $body)) {
        $sets[] = 'description = :desc';
        $params[':desc'] = trim($body['description']) ?: null;
    }
    if (!empty($sets)) {
        $params[':gid'] = $groupId;
        $pdo->prepare("UPDATE menuboard_store_groups SET " . implode(', ', $sets) . " WHERE groupId = :gid")
            ->execute($params);
    }
    if (array_key_exists('storeIds', $body) && is_array($body['storeIds'])) {
        $pdo->prepare("DELETE FROM menuboard_store_group_members WHERE groupId = ?")->execute([$groupId]);
        if (!empty($body['storeIds'])) {
            $ins = $pdo->prepare("INSERT IGNORE INTO menuboard_store_group_members (groupId, storeId) VALUES (?, ?)");
            foreach ($body['storeIds'] as $sid) $ins->execute([$groupId, (int)$sid]);
        }
    }
    respond(['success' => true]);
}

if ($method === 'DELETE' && $action === 'api_group') {
    $groupId = (int)($_GET['groupId'] ?? 0);
    if (!$groupId) respond(['error' => 'groupId required'], 400);
    $pdo->prepare("DELETE FROM menuboard_store_group_members WHERE groupId = ?")->execute([$groupId]);
    $pdo->prepare("DELETE FROM menuboard_store_groups WHERE groupId = ?")->execute([$groupId]);
    respond(['success' => true]);
}

// =============================================================================
// WIDGETS  (list all menuboard widgets for the Scheduler tab)
// =============================================================================

if ($method === 'GET' && $action === 'widgets') {
    $stmt = $pdo->query(
        "SELECT
             w.widgetId,
             CAST(wo_store.value AS UNSIGNED) AS storeId,
             CAST(wo_theme.value AS UNSIGNED) AS themeId,
             COALESCE(ms.storeName, CONCAT('Store #', wo_store.value)) AS storeName,
             COALESCE(mt.themeName, CONCAT('Theme #', wo_theme.value))  AS themeName,
             l.layoutId,
             l.layout AS layoutName,
             GROUP_CONCAT(d.display ORDER BY d.display SEPARATOR ', ') AS displayNames
           FROM widget w
           JOIN widgetoption wo_store
             ON wo_store.widgetId = w.widgetId AND wo_store.`option` = 'storeId'
           JOIN widgetoption wo_theme
             ON wo_theme.widgetId = w.widgetId AND wo_theme.`option` = 'themeId'
           JOIN playlist p  ON p.playlistId  = w.playlistId
           JOIN region r    ON r.regionId    = p.regionId
           JOIN layout l    ON l.layoutId    = r.layoutId
           LEFT JOIN menuboard_stores ms
             ON ms.storeId = CAST(wo_store.value AS UNSIGNED)
           LEFT JOIN menuboard_themes mt
             ON mt.themeId = CAST(wo_theme.value AS UNSIGNED)
           LEFT JOIN display d
             ON d.defaultlayoutid = l.layoutId
          WHERE w.type = 'menuboard'
            AND (l.parentId IS NULL OR l.parentId = '' OR l.parentId = 0)
          GROUP BY w.widgetId, wo_store.value, wo_theme.value,
                   ms.storeName, mt.themeName, l.layoutId, l.layout
          ORDER BY storeName, l.layout"
    );
    respond($stmt->fetchAll());
}

// =============================================================================
// SCHEDULES
// =============================================================================

if ($method === 'GET' && $action === 'schedules') {
    $storeId = isset($_GET['storeId']) ? (int)$_GET['storeId'] : null;
    $where   = $storeId !== null ? 'WHERE s.storeId = :sid' : '';
    $params  = $storeId !== null ? [':sid' => $storeId] : [];
    $stmt    = $pdo->prepare(
        "SELECT s.*,
                ft.themeName AS fromThemeName,
                tt.themeName AS toThemeName
           FROM menuboard_schedules s
           LEFT JOIN menuboard_themes ft ON ft.themeId = s.fromThemeId
           LEFT JOIN menuboard_themes tt ON tt.themeId = s.toThemeId
           $where
          ORDER BY s.storeId ASC, s.goLiveAt DESC"
    );
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

if ($method === 'POST' && $action === 'schedule') {
    $body      = bodyJson();
    $storeId   = (int)($body['storeId']   ?? 0);
    $toThemeId = (int)($body['toThemeId'] ?? 0);
    $goLiveAt  = trim($body['goLiveAt']   ?? '');
    if (!$storeId)   respond(['error' => 'storeId is required'],   400);
    if (!$toThemeId) respond(['error' => 'toThemeId is required'], 400);
    if (!$goLiveAt)  respond(['error' => 'goLiveAt is required'],  400);
    $widgetId    = (isset($body['widgetId'])    && $body['widgetId']    !== null) ? (int)$body['widgetId']    : null;
    $fromThemeId = (isset($body['fromThemeId']) && $body['fromThemeId'] !== null) ? (int)$body['fromThemeId'] : null;
    $notes       = trim($body['notes'] ?? '') ?: null;
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_schedules (storeId, widgetId, fromThemeId, toThemeId, goLiveAt, notes)
         VALUES (:sid, :wid, :from, :to, :live, :notes)"
    );
    $stmt->execute([
        ':sid'   => $storeId,
        ':wid'   => $widgetId,
        ':from'  => $fromThemeId,
        ':to'    => $toThemeId,
        ':live'  => $goLiveAt,
        ':notes' => $notes,
    ]);
    respond(['scheduleId' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'DELETE' && $action === 'schedule') {
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'id is required'], 400);
    $stmt = $pdo->prepare("DELETE FROM menuboard_schedules WHERE scheduleId = :id");
    $stmt->execute([':id' => $id]);
    respond(['success' => true]);
}

// Apply all past-due, unapplied theme schedules.
// Full publish cycle:
//   1. Update widgetoption themeId in DB
//   2. Sync lkwidgetmedia for new background
//   3. Delete cached widget HTML files so getResourceOrCache regenerates them
//   4. Patch themeId in the layout XLF on disk → changes file MD5 → player re-downloads
//   5. Update display.mediaInventoryStatus = 3 → player knows inventory is stale
//   6. Update layout.modifiedDt
//   7. Mark schedule appliedAt
if ($method === 'POST' && $action === 'apply_schedules') {

    // Read library path from DB (same source Xibo uses)
    $libRow = $pdo->query("SELECT value FROM setting WHERE setting = 'LIBRARY_LOCATION' LIMIT 1")->fetch();
    $libraryPath = $libRow ? rtrim($libRow['value'], '/') . '/' : '/var/www/cms/library/';

    $pending = $pdo->query(
        "SELECT s.scheduleId, s.storeId, s.widgetId, s.toThemeId,
                t.backgroundMediaId, t.backgroundUrl
           FROM menuboard_schedules s
           JOIN menuboard_themes t ON t.themeId = s.toThemeId
          WHERE s.goLiveAt <= NOW()
            AND s.appliedAt IS NULL
          ORDER BY s.goLiveAt ASC"
    )->fetchAll();

    $applied = [];

    foreach ($pending as $sched) {
        $scheduleId = (int)$sched['scheduleId'];
        $storeId    = (int)$sched['storeId'];
        $toThemeId  = (int)$sched['toThemeId'];
        $mediaId    = (int)($sched['backgroundMediaId'] ?? 0);

        if (!$mediaId && !empty($sched['backgroundUrl'])) {
            if (preg_match('#/library/download/(\d+)/#', $sched['backgroundUrl'], $m)) {
                $mediaId = (int)$m[1];
            }
        }

        // Collect affected widget IDs
        if ($sched['widgetId'] !== null) {
            $widgetIds = [(int)$sched['widgetId']];
        } else {
            $ws = $pdo->prepare(
                "SELECT w.widgetId FROM widget w
                 JOIN widgetoption wo ON wo.widgetId = w.widgetId
                   AND wo.`option` = 'storeId' AND CAST(wo.value AS UNSIGNED) = :sid
                 WHERE w.type = 'menuboard'"
            );
            $ws->execute([':sid' => $storeId]);
            $widgetIds = array_column($ws->fetchAll(), 'widgetId');
        }

        foreach ($widgetIds as $wid) {
            $wid = (int)$wid;

            // 1. Update widget themeId option in DB
            $pdo->prepare(
                "UPDATE widgetoption SET value = :val
                  WHERE widgetId = :wid AND `option` = 'themeId'"
            )->execute([':val' => $toThemeId, ':wid' => $wid]);

            // 2. Sync lkwidgetmedia so RequiredFiles includes the new background
            $pdo->prepare("DELETE FROM lkwidgetmedia WHERE widgetId = ?")->execute([$wid]);
            if ($mediaId) {
                $pdo->prepare("INSERT IGNORE INTO lkwidgetmedia (widgetId, mediaId) VALUES (?, ?)")
                    ->execute([$wid, $mediaId]);
            }

            // 3. Delete cached widget HTML — forces getResourceOrCache() to regenerate
            $widgetCache = $libraryPath . 'widget/' . $wid . '/';
            if (is_dir($widgetCache)) {
                foreach (glob($widgetCache . '*') as $f) {
                    @unlink($f);
                }
            }

            // 4. Find layout, patch XLF, update modifiedDt, notify displays
            $lRow = $pdo->prepare(
                "SELECT r.layoutId FROM region r
                 JOIN playlist p ON p.regionId = r.regionId
                 WHERE p.playlistId = (SELECT playlistId FROM widget WHERE widgetId = :wid LIMIT 1)
                 LIMIT 1"
            );
            $lRow->execute([':wid' => $wid]);
            $layoutData = $lRow->fetch();

            if ($layoutData) {
                $layoutId = (int)$layoutData['layoutId'];

                // Patch themeId in the XLF — changes file MD5, forcing player re-download
                $xlfPath = $libraryPath . $layoutId . '.xlf';
                if (file_exists($xlfPath)) {
                    $doc = new DOMDocument();
                    if ($doc->load($xlfPath)) {
                        $xpath = new DOMXPath($doc);
                        foreach ($xpath->query("//media[@id='{$wid}']/options/themeId") as $node) {
                            $node->nodeValue = (string)$toThemeId;
                        }
                        $doc->save($xlfPath);
                    }
                }

                // Update layout modifiedDt
                $pdo->prepare("UPDATE layout SET modifiedDt = NOW() WHERE layoutId = :lid")
                    ->execute([':lid' => $layoutId]);

                // 5. Mark displays as needing inventory refresh
                $pdo->prepare(
                    "UPDATE display SET mediaInventoryStatus = 3 WHERE defaultlayoutid = :lid"
                )->execute([':lid' => $layoutId]);
            }
        }

        $pdo->prepare("UPDATE menuboard_schedules SET appliedAt = NOW() WHERE scheduleId = :id")
            ->execute([':id' => $scheduleId]);

        $applied[] = $scheduleId;
    }

    respond(['applied' => $applied, 'count' => count($applied)]);
}

// Create individual schedule records for a list of widget IDs — used by the
// bulk scheduler UI to queue the same theme change across many boards at once.
if ($method === 'POST' && $action === 'bulk_schedule') {
    $body      = bodyJson();
    $widgetIds = $body['widgetIds'] ?? [];
    $toThemeId = (int)($body['toThemeId'] ?? 0);
    $goLiveAt  = trim($body['goLiveAt']   ?? '');
    $notes     = trim($body['notes']      ?? '') ?: null;

    if (!is_array($widgetIds) || empty($widgetIds)) respond(['error' => 'widgetIds is required'], 400);
    if (!$toThemeId) respond(['error' => 'toThemeId is required'], 400);
    if (!$goLiveAt)  respond(['error' => 'goLiveAt is required'],  400);

    $created = [];
    $insert  = $pdo->prepare(
        "INSERT INTO menuboard_schedules (storeId, widgetId, fromThemeId, toThemeId, goLiveAt, notes)
         VALUES (:sid, :wid, :from, :to, :live, :notes)"
    );

    foreach ($widgetIds as $wid) {
        $wid = (int)$wid;
        if (!$wid) continue;

        // Look up the widget's current store and theme from widgetoption
        $wRow = $pdo->prepare(
            "SELECT CAST(wo_s.value AS UNSIGNED) AS storeId,
                    CAST(wo_t.value AS UNSIGNED) AS fromThemeId
               FROM widget w
               JOIN widgetoption wo_s ON wo_s.widgetId = w.widgetId AND wo_s.`option` = 'storeId'
               JOIN widgetoption wo_t ON wo_t.widgetId = w.widgetId AND wo_t.`option` = 'themeId'
              WHERE w.widgetId = :wid LIMIT 1"
        );
        $wRow->execute([':wid' => $wid]);
        $wData = $wRow->fetch();
        if (!$wData) continue;

        $insert->execute([
            ':sid'   => $wData['storeId'],
            ':wid'   => $wid,
            ':from'  => $wData['fromThemeId'],
            ':to'    => $toThemeId,
            ':live'  => $goLiveAt,
            ':notes' => $notes,
        ]);
        $created[] = (int)$pdo->lastInsertId();
    }

    respond(['created' => $created, 'count' => count($created)], 201);
}

// =============================================================================
// PRICE SCHEDULES
// =============================================================================

// Apply past-due price schedules: upsert the winning (most-recent) row per
// item into menuboard_prices, then delete all past-due rows for the store.
function applyDuePriceSchedules(PDO $pdo, int $storeId): void {
    // Grab the most-recently-effective past-due row per item
    $due = $pdo->prepare(
        "SELECT ps.priceScheduleId, ps.itemId, ps.storeId,
                ps.price, ps.priceLabel, ps.isAvailable
           FROM menuboard_price_schedules ps
           JOIN (
               SELECT itemId, MAX(effectiveAt) AS maxEff
                 FROM menuboard_price_schedules
                WHERE storeId = :sid1
                  AND effectiveAt <= NOW()
                GROUP BY itemId
           ) latest ON latest.itemId = ps.itemId AND latest.maxEff = ps.effectiveAt
          WHERE ps.storeId = :sid2"
    );
    $due->execute([':sid1' => $storeId, ':sid2' => $storeId]);
    $winners = $due->fetchAll();

    if (!$winners) return;

    $upsert = $pdo->prepare(
        "INSERT INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
         VALUES (:itemId, :storeId, :price, :label, :avail)
         ON DUPLICATE KEY UPDATE
             price       = VALUES(price),
             priceLabel  = VALUES(priceLabel),
             isAvailable = VALUES(isAvailable)"
    );
    foreach ($winners as $r) {
        $upsert->execute([
            ':itemId'  => (int)$r['itemId'],
            ':storeId' => (int)$r['storeId'],
            ':price'   => $r['price'],
            ':label'   => $r['priceLabel'],
            ':avail'   => (int)$r['isAvailable'],
        ]);
    }

    // Remove every past-due row for this store (not just the winners)
    $pdo->prepare(
        "DELETE FROM menuboard_price_schedules
          WHERE storeId = ? AND effectiveAt <= NOW()"
    )->execute([$storeId]);

    // Notify Xibo players about the changed items
    $itemIds   = array_map(function($r) { return (int)$r['itemId']; }, $winners);
    $widgetIds = widgetsByStoreAndItems($pdo, $storeId, $itemIds);
    if ($widgetIds) publishWidgets($pdo, $widgetIds, getLibraryPath($pdo));
}

if ($method === 'GET' && $action === 'price_schedules') {
    $storeId = (int)($_GET['storeId'] ?? 0);
    if (!$storeId) respond(['error' => 'storeId is required'], 400);

    applyDuePriceSchedules($pdo, $storeId);

    $stmt = $pdo->prepare(
        "SELECT ps.*,
                i.name     AS itemName,
                i.category AS itemCategory
           FROM menuboard_price_schedules ps
           JOIN menuboard_items i ON i.itemId = ps.itemId
          WHERE ps.storeId = :storeId
            AND ps.effectiveAt > NOW()
          ORDER BY ps.effectiveAt ASC, i.category, i.name"
    );
    $stmt->execute([':storeId' => $storeId]);
    respond($stmt->fetchAll());
}

if ($method === 'POST' && $action === 'price_schedule') {
    $body      = bodyJson();
    $itemId    = (int)($body['itemId']    ?? 0);
    $storeId   = (int)($body['storeId']   ?? 0);
    $effectiveAt = trim($body['effectiveAt'] ?? '');
    if (!$itemId)      respond(['error' => 'itemId is required'],      400);
    if (!$storeId)     respond(['error' => 'storeId is required'],     400);
    if (!$effectiveAt) respond(['error' => 'effectiveAt is required'], 400);
    $price       = (isset($body['price'])      && $body['price']      !== null && $body['price']      !== '') ? (float)$body['price']        : null;
    $priceLabel  = (isset($body['priceLabel']) && $body['priceLabel'] !== null && trim($body['priceLabel']) !== '') ? trim($body['priceLabel']) : null;
    $isAvailable = isset($body['isAvailable']) ? (int)(bool)$body['isAvailable'] : 1;
    $notes       = trim($body['notes'] ?? '') ?: null;
    $stmt = $pdo->prepare(
        "INSERT INTO menuboard_price_schedules
             (itemId, storeId, price, priceLabel, isAvailable, effectiveAt, notes)
         VALUES (:item, :store, :price, :label, :avail, :eff, :notes)"
    );
    $stmt->execute([
        ':item'  => $itemId,
        ':store' => $storeId,
        ':price' => $price,
        ':label' => $priceLabel,
        ':avail' => $isAvailable,
        ':eff'   => $effectiveAt,
        ':notes' => $notes,
    ]);
    respond(['priceScheduleId' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'DELETE' && $action === 'price_schedule') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'id is required'], 400);
    $pdo->prepare("DELETE FROM menuboard_price_schedules WHERE priceScheduleId = :id")
        ->execute([':id' => $id]);
    respond(['success' => true]);
}

// =============================================================================
// POS API — external price updates via Bearer token
// =============================================================================

// Catalog endpoint: lets POS discover itemId/posCode mappings.
// Accepts storeId (returns store prices) or groupId (returns catalog only, no per-store prices).
if ($method === 'GET' && $action === 'pos_items') {
    $key     = requirePosAuth($pdo);
    $storeId = isset($_GET['storeId']) && $_GET['storeId'] !== '' ? (int)$_GET['storeId'] : null;
    $groupId = isset($_GET['groupId']) && $_GET['groupId'] !== '' ? (int)$_GET['groupId'] : null;

    if ($storeId) {
        if ($key['storeId'] !== null && (int)$key['storeId'] !== $storeId) {
            respond(['error' => 'API key not authorized for this store'], 403);
        }
        if ($key['groupId'] !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM menuboard_store_group_members WHERE groupId = ? AND storeId = ?");
            $chk->execute([$key['groupId'], $storeId]);
            if (!$chk->fetch()) respond(['error' => 'API key not authorized for this store'], 403);
        }
        $stmt = $pdo->prepare(
            "SELECT i.itemId, i.posCode, i.name, i.concept, i.category,
                    p.price, p.priceLabel, COALESCE(p.isAvailable, 1) AS isAvailable
               FROM menuboard_items i
               LEFT JOIN menuboard_prices p ON p.itemId = i.itemId AND p.storeId = :sid
              WHERE i.isActive = 1 ORDER BY i.concept, i.category, i.name"
        );
        $stmt->execute([':sid' => $storeId]);
        respond($stmt->fetchAll());
    }

    if ($groupId) {
        if ($key['storeId'] !== null) respond(['error' => 'Key is store-scoped; use storeId instead'], 403);
        if ($key['groupId'] !== null && (int)$key['groupId'] !== $groupId) {
            respond(['error' => 'API key not authorized for this group'], 403);
        }
        // Return catalog without per-store prices — applies uniformly to all stores in the group
        $stmt = $pdo->query(
            "SELECT itemId, posCode, name, concept, category FROM menuboard_items WHERE isActive = 1 ORDER BY concept, category, name"
        );
        respond($stmt->fetchAll());
    }

    respond(['error' => 'storeId or groupId is required'], 400);
}

// Update prices for one or more items (storeId or groupId)
if ($method === 'PUT' && $action === 'pos_prices') {
    $key   = requirePosAuth($pdo);
    $body  = bodyJson();
    $items = $body['items'] ?? [];
    if (!is_array($items) || !$items) respond(['error' => 'items array is required'], 400);

    $storeIds = resolveStoreIds($pdo, $body, $key);
    $res      = resolvePosItems($pdo, $items);
    $lib      = getLibraryPath($pdo);
    $upsert   = $pdo->prepare(
        "INSERT INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
         VALUES (:itemId, :storeId, :price, :label, :avail)
         ON DUPLICATE KEY UPDATE price = VALUES(price), priceLabel = VALUES(priceLabel), isAvailable = VALUES(isAvailable)"
    );
    $updatedIds  = array_values($res['resolved']);
    $allWidgetIds = [];
    foreach ($storeIds as $storeId) {
        foreach ($res['resolved'] as $idx => $itemId) {
            $i = $items[$idx];
            $upsert->execute([
                ':itemId'  => $itemId, ':storeId' => $storeId,
                ':price'   => isset($i['price']) && $i['price'] !== null && $i['price'] !== '' ? (float)$i['price'] : null,
                ':label'   => isset($i['priceLabel']) && $i['priceLabel'] !== null ? trim((string)$i['priceLabel']) : null,
                ':avail'   => isset($i['isAvailable']) ? (int)(bool)$i['isAvailable'] : 1,
            ]);
        }
        $allWidgetIds = array_merge($allWidgetIds, widgetsByStoreAndItems($pdo, $storeId, $updatedIds));
    }
    $published = $allWidgetIds ? publishWidgets($pdo, array_unique($allWidgetIds), $lib) : [];
    respond(['updated' => count($updatedIds), 'stores' => count($storeIds),
             'notFound' => $res['notFound'], 'publishedLayouts' => count($published)]);
}

// Update only availability (86 items) without changing prices
if ($method === 'PUT' && $action === 'pos_availability') {
    $key   = requirePosAuth($pdo);
    $body  = bodyJson();
    $items = $body['items'] ?? [];
    if (!is_array($items) || !$items) respond(['error' => 'items array is required'], 400);

    $storeIds = resolveStoreIds($pdo, $body, $key);
    $res      = resolvePosItems($pdo, $items);
    $lib      = getLibraryPath($pdo);
    $upsert   = $pdo->prepare(
        "INSERT INTO menuboard_prices (itemId, storeId, isAvailable) VALUES (:itemId, :storeId, :avail)
         ON DUPLICATE KEY UPDATE isAvailable = VALUES(isAvailable)"
    );
    $updatedIds   = array_values($res['resolved']);
    $allWidgetIds = [];
    foreach ($storeIds as $storeId) {
        foreach ($res['resolved'] as $idx => $itemId) {
            $upsert->execute([':itemId' => $itemId, ':storeId' => $storeId,
                              ':avail'  => isset($items[$idx]['isAvailable']) ? (int)(bool)$items[$idx]['isAvailable'] : 1]);
        }
        $allWidgetIds = array_merge($allWidgetIds, widgetsByStoreAndItems($pdo, $storeId, $updatedIds));
    }
    $published = $allWidgetIds ? publishWidgets($pdo, array_unique($allWidgetIds), $lib) : [];
    respond(['updated' => count($updatedIds), 'stores' => count($storeIds),
             'notFound' => $res['notFound'], 'publishedLayouts' => count($published)]);
}

// Full price-sheet replacement — items omitted from payload become isAvailable=0
if ($method === 'PUT' && $action === 'pos_prices_bulk') {
    $key   = requirePosAuth($pdo);
    $body  = bodyJson();
    $items = $body['items'] ?? [];
    if (!is_array($items)) respond(['error' => 'items must be an array'], 400);

    $storeIds    = resolveStoreIds($pdo, $body, $key);
    $res         = resolvePosItems($pdo, $items);
    $lib         = getLibraryPath($pdo);
    $upsert      = $pdo->prepare(
        "INSERT INTO menuboard_prices (itemId, storeId, price, priceLabel, isAvailable)
         VALUES (:itemId, :storeId, :price, :label, :avail)
         ON DUPLICATE KEY UPDATE price = VALUES(price), priceLabel = VALUES(priceLabel), isAvailable = VALUES(isAvailable)"
    );
    $updatedIds   = array_values($res['resolved']);
    $allWidgetIds = [];
    $totalUnavail = 0;

    foreach ($storeIds as $storeId) {
        foreach ($res['resolved'] as $idx => $itemId) {
            $i = $items[$idx];
            $upsert->execute([
                ':itemId'  => $itemId, ':storeId' => $storeId,
                ':price'   => isset($i['price']) && $i['price'] !== null && $i['price'] !== '' ? (float)$i['price'] : null,
                ':label'   => isset($i['priceLabel']) && $i['priceLabel'] !== null ? trim((string)$i['priceLabel']) : null,
                ':avail'   => isset($i['isAvailable']) ? (int)(bool)$i['isAvailable'] : 1,
            ]);
        }
        // Items tracked for this store but absent from payload go unavailable
        if (!empty($updatedIds)) {
            $ph   = implode(',', array_fill(0, count($updatedIds), '?'));
            $stmt = $pdo->prepare("SELECT itemId FROM menuboard_prices WHERE storeId = ? AND itemId NOT IN ($ph)");
            $stmt->execute(array_merge([$storeId], $updatedIds));
        } else {
            $stmt = $pdo->prepare("SELECT itemId FROM menuboard_prices WHERE storeId = ?");
            $stmt->execute([$storeId]);
        }
        $unavailIds = array_column($stmt->fetchAll(), 'itemId');
        if (!empty($unavailIds)) {
            $markUnavail = $pdo->prepare("UPDATE menuboard_prices SET isAvailable = 0 WHERE storeId = ? AND itemId = ?");
            foreach ($unavailIds as $id) $markUnavail->execute([$storeId, $id]);
            $totalUnavail += count($unavailIds);
        }
        $allAffected  = array_merge($updatedIds, array_map('intval', $unavailIds));
        $allWidgetIds = array_merge($allWidgetIds, widgetsByStoreAndItems($pdo, $storeId, $allAffected));
    }
    $published = $allWidgetIds ? publishWidgets($pdo, array_unique($allWidgetIds), $lib) : [];
    respond(['updated' => count($updatedIds), 'stores' => count($storeIds),
             'markedUnavailable' => $totalUnavail, 'notFound' => $res['notFound'],
             'publishedLayouts'  => count($published)]);
}

respond(['error' => 'Unknown action: ' . $action], 400);
