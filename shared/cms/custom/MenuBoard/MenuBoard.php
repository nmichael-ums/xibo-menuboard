<?php
namespace Xibo\Custom\MenuBoard;

use Xibo\Widget\ModuleWidget;

class MenuBoard extends ModuleWidget
{
    public $codeSchemaVersion = 2;

    public function installOrUpdate($moduleFactory)
    {
        if ($this->module == null) {
            $module = $moduleFactory->createEmpty();
            $module->type            = 'menuboard';
            $module->name            = 'Menu Board';
            $module->class           = 'Xibo\Custom\MenuBoard\MenuBoard';
            $module->description     = 'Dynamic menu board';
            $module->enabled         = 1;
            $module->previewEnabled  = 1;
            $module->assignable      = 1;
            $module->regionSpecific  = 1;
            $module->render_as       = 'html';
            $module->schemaVersion   = $this->codeSchemaVersion;
            $module->settings        = [];
            $module->viewPath        = '../custom/MenuBoard';
            $module->defaultDuration = 60;
            $module->installName     = 'menuboard';

            $this->setModule($module);
            $this->installModule();
        }

        $this->runDatabaseMigrations();
    }

    private function runDatabaseMigrations()
    {
        $pdo = $this->getStore()->getConnection();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `menuboard_items` (
                `itemId`      INT          NOT NULL AUTO_INCREMENT,
                `clientId`    INT          NOT NULL DEFAULT 0,
                `name`        VARCHAR(255) NOT NULL,
                `description` TEXT,
                `category`    VARCHAR(100) NOT NULL DEFAULT '',
                `imageUrl`    VARCHAR(500)          DEFAULT NULL,
                `isActive`    TINYINT(1)   NOT NULL DEFAULT 1,
                `createdAt`   DATETIME              DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`itemId`),
                KEY `idx_client_cat` (`clientId`, `category`, `isActive`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `menuboard_prices` (
                `priceId`     INT           NOT NULL AUTO_INCREMENT,
                `itemId`      INT           NOT NULL,
                `storeId`     INT           NOT NULL,
                `price`       DECIMAL(10,2)          DEFAULT NULL,
                `priceLabel`  VARCHAR(100)           DEFAULT NULL,
                `isAvailable` TINYINT(1)    NOT NULL DEFAULT 1,
                PRIMARY KEY (`priceId`),
                UNIQUE KEY `uq_item_store` (`itemId`, `storeId`),
                KEY `idx_store` (`storeId`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `menuboard_themes` (
                `themeId`         INT          NOT NULL AUTO_INCREMENT,
                `clientId`        INT          NOT NULL DEFAULT 0,
                `themeName`       VARCHAR(255) NOT NULL,
                `backgroundUrl`   VARCHAR(500)          DEFAULT NULL,
                `backgroundMediaId` INT                 DEFAULT NULL,
                `layoutJson`      MEDIUMTEXT            DEFAULT NULL,
                `createdAt`       DATETIME              DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`themeId`),
                KEY `idx_client` (`clientId`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `menuboard_schedules` (
                `scheduleId`  INT          NOT NULL AUTO_INCREMENT,
                `storeId`     INT          NOT NULL,
                `widgetId`    INT                   DEFAULT NULL,
                `fromThemeId` INT                   DEFAULT NULL,
                `toThemeId`   INT          NOT NULL,
                `goLiveAt`    DATETIME     NOT NULL,
                `notes`       VARCHAR(500)           DEFAULT NULL,
                `createdAt`   DATETIME              DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`scheduleId`),
                KEY `idx_store`  (`storeId`),
                KEY `idx_widget` (`widgetId`),
                KEY `idx_golive` (`goLiveAt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `menuboard_price_schedules` (
                `priceScheduleId` INT           NOT NULL AUTO_INCREMENT,
                `itemId`          INT           NOT NULL,
                `storeId`         INT           NOT NULL,
                `price`           DECIMAL(10,2)          DEFAULT NULL,
                `priceLabel`      VARCHAR(100)           DEFAULT NULL,
                `isAvailable`     TINYINT(1)    NOT NULL DEFAULT 1,
                `effectiveAt`     DATETIME      NOT NULL,
                `notes`           VARCHAR(500)           DEFAULT NULL,
                `createdAt`       DATETIME               DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`priceScheduleId`),
                KEY `idx_item_store` (`itemId`, `storeId`),
                KEY `idx_effective`  (`effectiveAt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        try {
            $pdo->exec("ALTER TABLE menuboard_items ADD COLUMN concept VARCHAR(100) NOT NULL DEFAULT '' AFTER clientId");
        } catch (\Exception $e) { /* already exists */ }

        try {
            $pdo->exec("ALTER TABLE menuboard_themes ADD COLUMN backgroundMediaId INT DEFAULT NULL AFTER backgroundUrl");
        } catch (\Exception $e) { /* already exists */ }

        try {
            $pdo->exec("ALTER TABLE menuboard_schedules ADD COLUMN appliedAt DATETIME DEFAULT NULL AFTER notes");
        } catch (\Exception $e) { /* already exists */ }

        $count = $pdo->query("SELECT COUNT(*) FROM `menuboard_themes`")->fetchColumn();
        if ((int)$count === 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO `menuboard_themes` (clientId, themeName, backgroundUrl, layoutJson)
                 VALUES (0, 'Default', NULL, '[]')"
            );
            $stmt->execute();
        }
    }

    public function editForm()
    {
        return 'menuboard-form-edit';
    }

    public function setTemplateData($data)
    {
        $data['displayGroups'] = $this->getStore()->select(
            "SELECT displayGroupId, displayGroup
               FROM displaygroup
              WHERE isDisplaySpecific = 0
              ORDER BY displayGroup",
            []
        );

        $data['themes'] = $this->getStore()->select(
            "SELECT themeId, themeName FROM menuboard_themes ORDER BY themeName",
            []
        );

        return $data;
    }

    public function edit()
    {
        $this->setOption('storeId', $this->getSanitizer()->getInt('storeId'));
        $this->setOption('themeId', $this->getSanitizer()->getInt('themeId', 1));
        $this->setDuration($this->getSanitizer()->getInt('duration', $this->getDuration()));
        $this->setUseDuration($this->getSanitizer()->getCheckbox('useDuration'));
        $this->saveWidget();

        // Keep lkwidgetmedia in sync so the player downloads the background image
        $this->syncBackgroundMedia();
    }

    private function syncBackgroundMedia()
    {
        $widgetId = $this->getWidgetId();
        $themeId  = (int)$this->getOption('themeId', 0);
        if (!$widgetId || !$themeId) return;
        $this->syncBackgroundMediaForTheme($widgetId, $themeId);
    }

    private function syncBackgroundMediaForTheme($widgetId, $themeId)
    {
        if (!$widgetId || !$themeId) return;

        $rows = $this->getStore()->select(
            "SELECT backgroundMediaId, backgroundUrl FROM menuboard_themes WHERE themeId = :id LIMIT 1",
            ['id' => $themeId]
        );
        if (!$rows) return;

        $mediaId = (int)($rows[0]['backgroundMediaId'] ?? 0);
        if (!$mediaId && !empty($rows[0]['backgroundUrl'])) {
            if (preg_match('#/library/download/(\d+)/#', $rows[0]['backgroundUrl'], $m)) {
                $mediaId = (int)$m[1];
            }
        }

        $pdo = $this->getStore()->getConnection();
        $pdo->prepare("DELETE FROM lkwidgetmedia WHERE widgetId = ?")->execute([$widgetId]);
        if ($mediaId) {
            $pdo->prepare("INSERT IGNORE INTO lkwidgetmedia (widgetId, mediaId) VALUES (?, ?)")
                ->execute([$widgetId, $mediaId]);
        }
    }

    public function isValid()
    {
        return 1;
    }

    public function getCacheDuration()
    {
        // Long cache — getModifiedDate() signals invalidation when schedules go live
        return 3600;
    }

    public function getModifiedDate($displayId)
    {
        $storeId  = (int)$this->getOption('storeId', 0);
        $widgetId = (int)$this->getWidgetId();

        // Baseline: widget's own modifiedDt (NULL for widgets saved before this was tracked)
        $baseDt = $this->widget->modifiedDt
            ? $this->getDate()->parse($this->widget->modifiedDt, 'U')
            : $this->getDate()->parse('2000-01-01 00:00:00', 'Y-m-d H:i:s');

        if ($storeId <= 0) {
            return $baseDt;
        }

        // The most recent past-due theme schedule for this widget/store
        $rows = $this->getStore()->select(
            "SELECT MAX(goLiveAt) AS lastGoLive
               FROM menuboard_schedules
              WHERE goLiveAt <= NOW()
                AND (widgetId = :wid OR (widgetId IS NULL AND storeId = :sid))",
            ['wid' => $widgetId, 'sid' => $storeId]
        );
        if ($rows && !empty($rows[0]['lastGoLive'])) {
            $schedDt = $this->getDate()->parse($rows[0]['lastGoLive'], 'Y-m-d H:i:s');
            if ($schedDt->greaterThan($baseDt)) {
                $baseDt = $schedDt;
            }
        }

        // The most recent past-due price schedule for this store
        $priceRows = $this->getStore()->select(
            "SELECT MAX(effectiveAt) AS lastEffective
               FROM menuboard_price_schedules
              WHERE storeId = :sid
                AND effectiveAt <= NOW()",
            ['sid' => $storeId]
        );
        if ($priceRows && !empty($priceRows[0]['lastEffective'])) {
            $priceDt = $this->getDate()->parse($priceRows[0]['lastEffective'], 'Y-m-d H:i:s');
            if ($priceDt->greaterThan($baseDt)) {
                $baseDt = $priceDt;
            }
        }

        return $baseDt;
    }

    public function getResource($displayId = 0)
    {
        $storeId = (int)$this->getOption('storeId', 0);
        $themeId = (int)$this->getOption('themeId', 1);

        // Apply scheduled theme override if one has gone live.
        // Widget-specific schedules take priority over store-level ones.
        // When a schedule is detected but not yet formally applied (appliedAt IS NULL),
        // also sync lkwidgetmedia so the player's next RequiredFiles poll includes
        // the new background — self-healing fallback in case apply_schedules hasn't run.
        if ($storeId > 0) {
            $wid      = (int)$this->getWidgetId();
            $schedRows = $this->getStore()->select(
                "SELECT toThemeId, appliedAt FROM menuboard_schedules
                 WHERE goLiveAt <= NOW()
                   AND (widgetId = :wid OR (widgetId IS NULL AND storeId = :sid))
                 ORDER BY CASE WHEN widgetId IS NOT NULL THEN 0 ELSE 1 END ASC,
                          goLiveAt DESC
                 LIMIT 1",
                ['wid' => $wid, 'sid' => $storeId]
            );
            if ($schedRows) {
                $themeId = (int)$schedRows[0]['toThemeId'];
                if (empty($schedRows[0]['appliedAt'])) {
                    $this->syncBackgroundMediaForTheme($wid, $themeId);
                }
            }
        }

        // Load theme
        $themes = $this->getStore()->select(
            "SELECT * FROM menuboard_themes WHERE themeId = :themeId LIMIT 1",
            ['themeId' => $themeId]
        );
        $theme = count($themes) ? $themes[0] : null;
        $slots = ($theme && $theme['layoutJson'])
            ? json_decode($theme['layoutJson'], true)
            : [];

        // Load prices for this store keyed by itemId
        $priceRows = $this->getStore()->select(
            "SELECT itemId, price, priceLabel, isAvailable
               FROM menuboard_prices
              WHERE storeId = :storeId",
            ['storeId' => $storeId]
        );
        $prices = [];
        foreach ($priceRows as $p) {
            $prices[$p['itemId']] = $p;
        }

        // Overlay the most recent past-due scheduled price change per item
        if ($storeId > 0) {
            $schedPriceRows = $this->getStore()->select(
                "SELECT s.itemId, s.price, s.priceLabel, s.isAvailable
                   FROM menuboard_price_schedules s
                  WHERE s.storeId = :storeId
                    AND s.effectiveAt = (
                        SELECT MAX(s2.effectiveAt)
                          FROM menuboard_price_schedules s2
                         WHERE s2.storeId = s.storeId
                           AND s2.itemId  = s.itemId
                           AND s2.effectiveAt <= NOW()
                    )",
                ['storeId' => $storeId]
            );
            foreach ($schedPriceRows as $sp) {
                $prices[$sp['itemId']] = $sp;
            }
        }

        // Load item names and descriptions
        $nameRows = $this->getStore()->select(
            "SELECT itemId, name, description FROM menuboard_items WHERE isActive = 1",
            []
        );
        $itemNames        = [];
        $itemDescriptions = [];
        foreach ($nameRows as $n) {
            $itemNames[$n['itemId']]        = $n['name'];
            $itemDescriptions[$n['itemId']] = $n['description'] ?? '';
        }

        // Resolve background URL - use authenticated URL in preview, storedAs for player
        $bgStyle = 'background-color:#1a1a1a;';
        if ($theme) {
            // If backgroundMediaId is missing but backgroundUrl looks like a library URL, extract it
            $mediaId = (int)($theme['backgroundMediaId'] ?? 0);
            if (!$mediaId && !empty($theme['backgroundUrl'])) {
                if (preg_match('#/library/download/(\d+)/#', $theme['backgroundUrl'], $m)) {
                    $mediaId = (int)$m[1];
                }
            }

            if ($mediaId) {
                $isPreview = $this->getSanitizer()->getCheckbox('preview');
                if ($isPreview) {
                    $bgUrl = $this->getApp()->urlFor('library.download', [
                        'id'   => $mediaId,
                        'type' => 'image'
                    ]);
                } else {
                    try {
                        $rows  = $this->getStore()->select(
                            "SELECT storedAs FROM media WHERE mediaId = :id LIMIT 1",
                            ['id' => $mediaId]
                        );
                        $bgUrl = ($rows && $rows[0]['storedAs']) ? $rows[0]['storedAs'] : ($theme['backgroundUrl'] ?? '');
                    } catch (\Exception $e) {
                        $bgUrl = $theme['backgroundUrl'] ?? '';
                    }
                }
                $bgStyle = "background-image:url('" . htmlspecialchars($bgUrl, ENT_QUOTES) . "');background-size:cover;background-position:center;";
            } elseif (!empty($theme['backgroundUrl'])) {
                $bgStyle = "background-image:url('" . htmlspecialchars($theme['backgroundUrl'], ENT_QUOTES) . "');background-size:cover;background-position:center;";
            }
        }

        // Render slots (price and name types)
        $slotsHtml = '';
        foreach ($slots as $slot) {
            $type   = $slot['type'] ?? 'price';
            $itemId = (int)($slot['itemId'] ?? 0);

            $x          = (int)($slot['x']          ?? 0);
            $y          = (int)($slot['y']          ?? 0);
            $w          = (int)($slot['w']          ?? 200);
            $h          = (int)($slot['h']          ?? 60);
            $fontSize   = (int)($slot['fontSize']   ?? 32);
            $color      = htmlspecialchars($slot['color']      ?? '#ffffff', ENT_QUOTES);
            $fontWeight = htmlspecialchars($slot['fontWeight'] ?? 'bold',    ENT_QUOTES);
            $fontStyle  = htmlspecialchars($slot['fontStyle']  ?? 'normal',  ENT_QUOTES);
            $fontFamily = htmlspecialchars($slot['fontFamily'] ?? 'Arial, sans-serif', ENT_QUOTES);
            $textAlign  = htmlspecialchars($slot['textAlign']  ?? 'left',    ENT_QUOTES);

            if ($type === 'rect' || $type === 'line') {
                $fillColor    = htmlspecialchars($slot['fillColor']   ?? '#333333', ENT_QUOTES);
                $opacity      = number_format((float)($slot['opacity'] ?? 1.0), 2);
                $borderRadius = (int)($slot['borderRadius']           ?? 0);
                $strokeColor  = htmlspecialchars($slot['strokeColor'] ?? '#ffffff', ENT_QUOTES);
                $strokeWidth  = (int)($slot['strokeWidth']            ?? 2);
                $style = "position:absolute;"
                       . "left:{$x}px;top:{$y}px;"
                       . "width:{$w}px;height:{$h}px;"
                       . "background:{$fillColor};"
                       . "opacity:{$opacity};";
                if ($type === 'rect') {
                    $style .= "border:{$strokeWidth}px solid {$strokeColor};"
                            . "border-radius:{$borderRadius}px;";
                }
                $slotsHtml .= "<div class='mb-slot' style='{$style}'></div>\n";
                continue;
            }

            if ($type === 'text') {
                $content        = htmlspecialchars($slot['content'] ?? '', ENT_QUOTES);
                $justifyContent = $textAlign === 'right' ? 'flex-end' : ($textAlign === 'center' ? 'center' : 'flex-start');
                $style = "position:absolute;"
                       . "left:{$x}px;top:{$y}px;"
                       . "width:{$w}px;height:{$h}px;"
                       . "font-size:{$fontSize}px;"
                       . "font-family:{$fontFamily};"
                       . "color:{$color};"
                       . "font-weight:{$fontWeight};"
                       . "font-style:{$fontStyle};"
                       . "text-align:{$textAlign};"
                       . "display:flex;align-items:center;justify-content:{$justifyContent};"
                       . "overflow:hidden;";
                $slotsHtml .= "<div class='mb-slot' style='{$style}'>{$content}</div>\n";
                continue;
            }

            if ($type === 'name') {
                $displayText = htmlspecialchars($itemNames[$itemId] ?? '', ENT_QUOTES);
                if ($displayText === '') continue;
                $opacity = '';
            } elseif ($type === 'description') {
                $displayText = htmlspecialchars($itemDescriptions[$itemId] ?? '', ENT_QUOTES);
                if ($displayText === '') continue;
                $opacity = '';
            } else {
                $price   = $prices[$itemId] ?? null;
                $opacity = ($price && !$price['isAvailable']) ? 'opacity:0.35;' : '';
                if ($price && $price['priceLabel'] !== null && $price['priceLabel'] !== '') {
                    $displayText = htmlspecialchars($price['priceLabel'], ENT_QUOTES);
                } elseif ($price && $price['price'] !== null) {
                    $displayText = number_format((float)$price['price'], 2);
                } else {
                    $displayText = '';
                }
                if ($displayText === '') continue;
            }

            $justifyContent = $textAlign === 'right' ? 'flex-end' : ($textAlign === 'center' ? 'center' : 'flex-start');

            $style = "position:absolute;"
                   . "left:{$x}px;top:{$y}px;"
                   . "width:{$w}px;height:{$h}px;"
                   . "font-size:{$fontSize}px;"
                   . "font-family:{$fontFamily};"
                   . "color:{$color};"
                   . "font-weight:{$fontWeight};"
                   . "font-style:{$fontStyle};"
                   . "text-align:{$textAlign};"
                   . "display:flex;align-items:center;justify-content:{$justifyContent};"
                   . "overflow:hidden;"
                   . $opacity;

            $slotsHtml .= "<div class='mb-slot' style='{$style}'>{$displayText}</div>\n";
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: #000;
    font-family: Arial, sans-serif;
}
.mb-scaler {
    width: 1920px;
    height: 1080px;
    position: absolute;
    top: 0;
    left: 0;
    transform-origin: top left;
}
.mb-board {
    width: 1920px;
    height: 1080px;
    position: relative;
    ' . $bgStyle . '
}
</style>
</head>
<body>
<div class="mb-scaler" id="mbScaler">
<div class="mb-board">
' . $slotsHtml . '
</div>
</div>
<script>
(function() {
    function applyScale() {
        var s = document.getElementById("mbScaler");
        var sx = window.innerWidth  / 1920;
        var sy = window.innerHeight / 1080;
        s.style.transform = "scale(" + sx + "," + sy + ")";
    }
    applyScale();
    window.addEventListener("resize", applyScale);
})();
</script>
</body>
</html>';
    }
}
