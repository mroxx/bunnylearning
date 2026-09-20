================================================================
BUNNY LEARNING — Chinese & Spanish PWA with accounts + cloud sync
================================================================

WHAT'S IN THE BOX
-----------------
index.html          The whole learning app (hub + Chinese + Spanish + Me tab)
manifest.webmanifest, sw.js, icon-*.png   PWA files (installable on phones)
install.php         Setup wizard (requirements → DB → admin → SMTP → done)
config.sample.php   Configuration template (install.php writes config.php)
schema.sql          MySQL tables (created automatically by the wizard)
admin.php           Admin console (users, reset passwords, audit log, mail test)
reset.php           Password-reset landing page (link from email)
verify_email.php    Email-verification landing page (link from email)
api/                JSON API (register, login, logout, me, progress,
                    forgot, reset_password, profile, admin)
lib/                Shared bootstrap (DB, sessions, CSRF, tokens) + SMTP client

REQUIREMENTS
------------
- PHP 8.0+ with pdo_mysql and openssl
- MySQL 5.7+ / MariaDB 10.3+
- Any web server (Apache or nginx); HTTPS recommended

INSTALL (5 minutes)
-------------------
1. Upload this folder to your web space (e.g. into /var/www/bunny-learning
   or your shared-hosting public_html/bunny folder).
2. Create an empty MySQL database + user in your hosting panel.
3. Open https://your-domain.tld/bunny-learning/install.php and follow
   the 5 steps (database → admin account → SMTP settings + test mail).
4. Delete install.php when the wizard says so.
5. Done: app = index.html, admin console = admin.php.

CONFIGURATION
-------------
All settings live in config.php (created by the wizard, never edit
config.sample.php). To change mail settings later, either edit
config.php or delete config.php + install.lock and re-run install.php.

SECURITY NOTES
--------------
- config.php, install.lock and schema.sql are blocked by .htaccess
  (Apache). On nginx, add: location ~ (config|schema|install\.lock)
  { deny all; } and never put config.php in a public listing.
- Passwords are hashed (PASSWORD_DEFAULT = bcrypt/argon2id depending
  on your PHP build). Sessions rotate a 30-day refresh cookie.
- Login attempts are rate-limited (5 fails / 10 min, exponential
  backoff up to 15 min).

RUNNING WITHOUT PHP
-------------------
index.html also works on any static host. Accounts and cloud sync
then disable themselves automatically (Me tab explains this) and
progress stays in the browser's localStorage.

TROUBLESHOOTING
---------------
- "not_installed" from the API  → run install.php.
- Test mail fails               → check SMTP host/port/encryption;
                                  for Gmail use an *app password*
                                  (host smtp.gmail.com, port 587, STARTTLS).
- Emails land in spam           → set up SPF/DKIM for your sender domain,
                                  or send via a trusted mailbox provider.
