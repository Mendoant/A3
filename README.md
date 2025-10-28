```markdown
# Group ERP / SCM Starter Scaffold

This repository contains a starter scaffold to implement the assignment requested. The scaffold is adapted to the provided create_db.sql schema.

Files included:
- `index.php` - landing page (group photos + login form)
- `login.php` - login handler, creates PHP session (uses `User` table)
- `config.php` - database connection helper (PDO), session settings
- `dashboard_scm.php` - supply chain manager module skeleton (Chart.js placeholders)
- `dashboard_erp.php` - senior manager module skeleton (Chart.js placeholders)
- `nav.php`, `logout.php` - small navigation and logout handler
- `assets/styles.css` - centralized CSS
- `assets/app.js` - small JavaScript helpers
- `sql_examples.sql` - SQL snippets for disruption metrics & KPIs adapted to provided schema (replace placeholders appropriately)
- `testing_template.tex` - LaTeX testing table template required in assignment

Important:
- Update DB constants in `config.php` (DB_HOST, DB_NAME, DB_USER, DB_PASS) before running.
- `login.php` attempts `password_verify()` first; if your `User`.`Password` column contains plaintext, the script will fall back to plaintext comparison (not recommended). Migrate to password_hash + password_verify for secure passwords.

Charting:
- Chart.js is used via CDN in the dashboards. The current charts are placeholders and demonstrate how to integrate Chart.js; you should implement server-side endpoints (AJAX) returning JSON for the actual KPI values.

Next steps to complete the project:
1. Set DB credentials in config.php and test DB connectivity.
2. Implement server-side endpoints (e.g., api/get_on_time.php, api/get_disruptions.php) that run the SQL queries in `sql_examples.sql` (adjusting placeholders).
3. Replace Chart.js placeholder datasets with AJAX-loaded real data.
4. Implement company add/update/save forms and proper validation.
5. Implement the required testing logs (LaTeX tables) and full UI polish.
```
