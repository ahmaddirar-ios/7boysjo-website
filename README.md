# 🐴 7 Boys® — Rubu Al Quds

> Premium Food Trading & Distribution Since 1966

<div align="center">

[![7 Boys](https://img.shields.io/badge/7%20Boys-Rubu%20Al%20Quds-2d6a4f?style=for-the-badge)](https://7boysjo.com)
[![PHP](https://img.shields.io/badge/PHP-8.3-777bb4?style=flat-square&logo=php)](https://php.net)
[![LiteSpeed](https://img.shields.io/badge/LiteServer-Hostinger-00b894?style=flat-square&logo=litespeed)](#)
[![Stars](https://img.shields.io/github/stars/7boys/website?style=social)](https://github.com/7boys/website)

</div>

## 📋 About

7 Boys is a Jordanian premium food trading and distribution company based in Amman, established in 1966. This repository contains the full source code for our corporate website and product catalog.

## 🚀 Features

- 🛒 **Product Catalog** — Dynamic product listing with categories and brands
- 🔍 **Search** — Full-text search across all products
- 📊 **Quote System** — Request and manage quotes
- 💬 **Live Chat** — Real-time customer support
- 🌐 **Multi-language** — Arabic & English support
- 📱 **Responsive** — Mobile-first design
- ⚡ **API** — RESTful API for integrations
- 🔒 **Security** — CSP, HSI, and more

## 🛠️ Tech Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.3 |
| **Server** | LiteSpeed (Hostinger) |
| **Frontend** | Vanilla HTML/CSS/JS |
| **Data** | JSON (migration to MySQL in progress) |
| **Cache** | File-based with filemtime versioning |

## 📁 Structure

```
public_html/
├── index.php          # Home page (dynamic)
├── assets/
│   ├── css/           # Stylesheets (hdr3.css)
│   ├── js/            # JavaScript modules
│   └── img/           # Product images
├── admin/             # Admin panel
│   ├── config.php     # Configuration (gitignored)
│   ├── index.php      # Dashboard
│   └── data/          # Data files (gitignored)
├── api/               # REST API
│   └── v1/            # API v1 endpoints
└── ...
```

## 🔐 Security

- HTTPS forced with HSTS
- Content Security Policy (CSP) headers
- X-Frame-Options: SAMEORIGIN
- SQL injection prevention via PDO prepared statements
- XSS prevention via output encoding
- CSRF protection on forms
- Rate limiting on API endpoints

## ⚙️ Setup

1. Clone the repository
2. Create a new MySQL database on your hostinger panel
3. Update the database credentials in your hosting control panel
4. Import the data schema (migration scripts included)

## 📜 License

© 1966–2026 Rubu Al Quds for Trading & Food Industries (7 Boys®). All rights reserved.

---

<div align="center">

**🐴 Premium Quality Since 1966**

[![Visit Us](https://img.shields.io/badge/Visit-7boysjo.com-2d6a4f?style=for-the-badge)](https://7boysjo.com)

</div>
