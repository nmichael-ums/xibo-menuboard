# Xibo MenuBoard Module

A custom Xibo CMS module that provides dynamic digital menu boards with per-store pricing and a drag-and-drop theme editor. Built for **Xibo CMS 2.3.x** running in Docker.

---

## Features

- Per-store, per-item pricing management
- Drag-and-drop theme editor — place price fields on a background image
- Multi-select with alignment, distribution, and size-matching tools
- Menu item and category management
- Store management
- Auto-provisioned database tables on first install
- No container restart needed — files are bind-mounted from the host

---

## Prerequisites

- Xibo CMS **2.3.x** deployed via Docker Compose (the official [xibosignage/xibo-cms](https://github.com/xibosignage/xibo-docker) stack)
- The standard Docker Compose setup bind-mounts a `shared/` directory from the host into the container. This module relies on that mount.

This repo includes a ready-to-use `docker-compose.yml` and `config.env.example` if you are setting up a new Xibo instance from scratch.

---

## Installation

### 0. (New installs only) Start the Docker stack

If you are deploying Xibo from scratch, use the `docker-compose.yml` in this repo:

```bash
cp config.env.example config.env
# Edit config.env — set MYSQL_PASSWORD and CMS_SERVER_NAME at minimum
nano config.env

docker compose up -d
```

The web UI will be available at `http://<host>:65501` once the containers are healthy. Change the host port in `docker-compose.yml` if `65501` is already in use.

> **Note:** `config.env` is in `.gitignore` — never commit it.

---

### 1. Copy files to the host shared directory

On the server running Xibo, copy the contents of this repo's `custom/` directory into your Xibo shared folder:

```bash
cp -r custom/ /opt/xibo/shared/cms/custom/
```

> **Default path:** `/opt/xibo/shared/cms/custom/`  
> If your Xibo Docker Compose stack lives somewhere other than `/opt/xibo/`, adjust the path accordingly. The `custom/` directory is always located inside the `shared/cms/` folder next to your `docker-compose.yml`.

Inside the container, this maps to `/var/www/cms/custom/` — Xibo already knows to look there for custom modules.

### Expected directory layout after copy

```
/opt/xibo/shared/cms/custom/
├── menuboard.json                    ← module manifest
├── settings-custom.php               ← Xibo placeholder (no changes needed)
└── MenuBoard/
    ├── MenuBoard.php                 ← module class (auto-creates DB tables)
    ├── menuboard-form-edit.twig      ← widget edit form
    └── editor/
        ├── index.html                ← drag-and-drop theme editor UI
        └── api.php                   ← REST API for themes, items, prices, stores
```

No container restart is required — the bind mount means changes take effect immediately.

### 2. Register the module in Xibo CMS

1. Log in to the Xibo CMS admin panel.
2. Go to **Modules** (under the Admin menu).
3. Click **Install Module** (or **Verify** if prompted).
4. Look for **Menu Board** in the module list and click **Install**.

Xibo will call `installOrUpdate()` in `MenuBoard.php`, which automatically creates the required database tables:

| Table | Purpose |
|---|---|
| `menuboard_items` | Menu items (name, category, description, image URL) |
| `menuboard_prices` | Per-store pricing (item × store × price) |
| `menuboard_themes` | Theme layouts (background image + slot positions) |

### 3. Add the widget to a layout

1. Open or create a **Layout** in Xibo.
2. Add a region, then add the **Menu Board** widget to it.
3. In the widget edit form, click **Open Theme Editor** to build your menu board layout.

---

## Theme Editor

The editor is available at:

```
http://<your-xibo-host>:<port>/custom/MenuBoard/editor/
```

### Controls

| Action | How |
|---|---|
| Select a slot | Click it |
| Multi-select | Shift+click or rubber-band drag |
| Select all | Ctrl+A |
| Move slot(s) | Drag, or arrow keys (1px); Shift+arrow = 10px |
| Delete slot(s) | Delete key |
| Align (2+ selected) | Alignment toolbar → Left / Center / Right / Top / Middle / Bottom |
| Distribute evenly (3+) | Space H (horizontal) / Space V (vertical) |
| Match size (2+) | = Width / = Height / = Both (matches to first selected) |

### Tabs

- **Theme Editor** — drag-and-drop layout builder
- **Menu Items** — add/edit menu items and categories
- **Store Prices** — set per-store prices for each item

---

## Database credentials

`api.php` reads credentials from PHP's `$_SERVER` superglobal, which Xibo's Docker Compose setup populates automatically via environment variables:

```
MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD
```

No manual configuration is needed.

---

## Upgrading / re-deploying to a new instance

1. Copy `custom/` to the new server's shared directory (same path as above).
2. Register the module in Xibo admin.
3. That's it — `MenuBoard.php` uses `CREATE TABLE IF NOT EXISTS` so re-running migrations on an existing install is safe.

> **Data:** Each Xibo instance maintains its own database. Items, prices, and themes are not bundled in this repo — they live in the database and are entered via the editor after install.

---

## Compatibility

| Component | Version |
|---|---|
| Xibo CMS | 2.3.x |
| Deployment | Docker Compose (official xibosignage stack) |
| PHP | 7.4+ (container default) |
| Database | MySQL 5.7+ / MariaDB 10.3+ |

---

## Project

Built for **United Media Solutions** to support digital signage across multiple store locations with centralized content management and per-location pricing.
