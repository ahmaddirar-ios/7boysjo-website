# Controllers (Phase 2 scaffold)
Monolith admin/index.php (101K) will be split here step-by-step without downtime.
- QuoteController.php -> handles save_quote / edit_quote / quotes list
- VisitorController.php -> save_visitor / del_visitor
- EmployeeController.php -> save_employee / del_employee
- ProductController.php -> save_product / save_brand / cats
Each controller reuses helpers from ../config.php (db(), csrf_token()).
Dual-write (JSON+SQLite) kept as fallback until Phase 3.
