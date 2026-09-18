# 7 Boys® — Rubu Al Quds (7boysjo.com)

Premium Food Trading & Distribution — Since 1966

## Stack
- PHP 8.3 (Hostinger, LiteSpeed)
- Dynamic rendering: `admin/config.php` → `render_home()` / `render_category()` etc.
- No static build — `index.php` renders live on every request
- LiteSpeed cache disabled (always fresh)

## Structure
```
public_html/
├── index.php              # Home (dynamic)
├── brands.php / category.php / product.php / story.php / contact.php / quote.php
├── search.php / chat.php / api/products.php
├── assets/
│   ├── css/hdr3.css       # Single canonical stylesheet (v via filemtime)
│   ├── js/ (main.js, carousel.js, chat.js, quote_cart.js, header_search.js, lang.js, search.js)
│   ├── img/ (prod-*.jpg, brand-*, logo.png)
│   └── data/ (mirror of admin/data)
├── admin/                 # Canonical admin panel
│   ├── index.php / login.php / config.php / do_publish.php
│   └── data/ (products.json, brands.json, categories.json, settings.json)
├── panel/                 # Legacy redirect → /admin/ (do not use)
└── backups/               # Pre-cleanup archives
```

## Admin
- URL: `/admin/` (302 → login.php if not authenticated)
- Data: `admin/data/*.json`
- Publish: `admin/do_publish.php` (auto via shell_exec, no 500)

## Deploy
- Host: `u144908550@82.25.83.1:65002` (sshpass)
- Docroot: `/home/u144908550/domains/7boysjo.com/public_html`
- CSS versioning: `hdr3.css?v=filemtime` (auto cache-bust)
- After CSS/JS change: `touch assets/css/hdr3.css` to bump version

## Backups
- `backups/backup_before_cleanup_*.tar.gz` — full pre-cleanup snapshot
- `backups/panel_archive/` — legacy panel files
