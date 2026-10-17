# ============================================================
# QUEUEFLOW TMC — PRODUCTION DEPLOYMENT
# ============================================================
# For shared cPanel hosting (Hostinger, Namecheap, GoDaddy, etc.)
# with PHP 8.1+ and MySQL 5.7+/8.0.
#
# Order of operations below matters: the database has to exist before
# you migrate, and .env has to be correct before artisan will run at all.
# ============================================================


1. BUY A PLAN
-------------
   Any cheap shared plan with PHP >= 8.1 works. Free tiers usually cap
   PHP too low for Laravel 10. Look for PHP 8.1 or 8.2.


2. UPLOAD THE CODE
------------------
   The document root MUST be the `public/` folder, never the project
   root. Uploading everything exposes .env to the internet.

   In cPanel: File Manager -> go to public_html -> upload a ZIP of
   the project with these EXCLUDED:

       vendor/        node_modules/  .env
       storage/logs/  .git/

   Then in cPanel Terminal (or SSH):

       cd ~/public_html
       composer install --no-dev --optimize-autoloader
       php artisan storage:link     # PWD evidence uploads need this

   If Composer fails on shared hosting because PHP is too old, ask
   support to switch your PHP version, then run it again.


3. CREATE THE DATABASE
----------------------
   cPanel -> MySQL Databases:

       Create Database:   queueflow_tmc
       Add User To DB:    queueflow_tmc_user  (set a strong password)
       Check ALL PRIVILEGES

   Note the exact names. cPanel often prefixes both, for example
   youraccount_queueflow_tmc. Use whatever it displays.


4. EDIT .env
------------
   File Manager -> edit public_html/.env:

       APP_ENV=production
       APP_DEBUG=false                 # never ship a public host with this true
       APP_URL=https://api.yourdomain.com

       DB_CONNECTION=mysql
       DB_HOST=127.0.0.1               # almost always localhost on cPanel
       DB_PORT=3306
       DB_DATABASE=<pdb-prefix>_queueflow_tmc
       DB_USERNAME=<pdb-prefix>_queueflow_tmc_user
       DB_PASSWORD=<the password you just set>

   Leave SESSION_DRIVER=file. If you later scale to multiple servers,
   switch it to redis or database.

   Do NOT add SANCTUM_STATEFUL_DOMAINS to .env. The staff and student
   mobile APIs authenticate with a bearer token, and Sanctum checks the
   session guard before the token. Listing your own API domain as a
   stateful domain would let a logged-in browser session outrank the
   token and answer a mobile request as the wrong user. The browser
   portal is unaffected because it uses the plain `auth` middleware, not
   `auth:sanctum`.

   Then regenerate the key, once, on the server:

       php artisan key:generate --force

   Never copy the APP_KEY from your local .env. A shared key means
   encrypted values and session cookies are portable between installs.


5. IMPORT THE SCHEMA
--------------------
   If you are starting from an empty database, migrations are enough:

       php artisan migrate --force
       php artisan db:seed --force      # creates the six official offices

   To bring across your existing local data instead, export it first
   on your machine:

       mysqldump -u root -p queueflow_tmc > queueflow.sql

   Then cPanel -> phpMyAdmin -> select your database -> Import ->
   upload queueflow.sql.

   Either path works, but do NOT do both. Migrating on top of an
   imported schema leaves the migrations table out of sync and future
   `php artisan migrate` calls will try to re-run everything.


6. GET AN HTTPS CERTIFICATE
---------------------------
   Most hosts have a free one-click Let's Encrypt tool in cPanel under
   "SSL/TLS Status". Issue the cert and tick "Force HTTPS".

   This is not optional. The release APK refuses plain HTTP by design
   (see the note below), so without a working certificate the app will
   not reach the server at all.


7. FLUSH LARAVEL'S CACHES
-------------------------
       php artisan config:cache
       php artisan route:cache
       php artisan view:cache

   Do this AFTER editing .env, because config:cache freezes the values
   into a file. If you change .env later and nothing seems to happen,
   run config:clear first.


8. BUILD THE APP AGAINST YOUR HOST
----------------------------------
   From the Flutter project directory:

       flutter build apk --release --dart-define=API_BASE_URL=https://api.yourdomain.com/api

   API_BASE_URL is compiled into the binary. A build pointed at one host
   will not magically follow the app to another, so rebuild if you ever
   change domains.

   Test on mobile data, not just WiFi. That is the whole point of this
   exercise, and it is the only way to catch a missing SSL certificate
   or a firewall rule that only blocks cellular traffic.


9. VERIFY BEFORE YOU TRUST IT
-----------------------------
   In a browser, on a network that is NOT your WiFi:

       https://api.yourdomain.com/student/queues

   Then in the app, with mobile data on: sign in, take a ticket, and
   confirm the number appears in the web portal at the same moment.

   If sign-in fails instantly, the usual causes in order:
     - APP_URL still says http:// or 127.0.0.1
     - the SSL certificate did not issue, or is not covering your domain
     - DB credentials are wrong (check phpMyAdmin can connect)
     - APP_DEBUG=true is still set and is leaking the real error


============================================================
WHY RELEASE BUILDS REFUSE PLAIN HTTP
============================================================

The main Android manifest no longer sets android:usesCleartextTraffic,
and the iOS plist sets NSAllowsArbitraryLoads to false. Only debug
builds, and localhost, are excepted.

Reason: the app carries a Sanctum bearer token and student passwords.
Over plain HTTP, anyone on the same WiFi, or any network between the
phone and the server, can read them in transit. On a public host that is
not a theoretical risk.

If a release build refuses to connect, do not switch cleartext back on.
Fix the certificate.


============================================================
STILL ON WI-FI ONLY?
============================================================

If you only need this for a demo on the same Wi-Fi (no hosting, no
tunnel), run Laravel on the LAN and point the app at this PC:

    php artisan serve --host=0.0.0.0 --port=8000

Then in the Flutter app use About > Server connection > Change and enter
`http://<this-PC-LAN-IP>:8000/api` (check with `ipconfig`), or rebuild
with `--dart-define=API_BASE_URL=http://<this-PC-LAN-IP>:8000/api`.

The phone and the laptop must stay on the same Wi-Fi network. For access
over different Wi-Fi or mobile data, deploy this project to a hosted
production server with HTTPS (sections 1-8 above) - there is no tunnel
configured in this project, and a LAN address is not reachable from
outside its own network. Fine for a class demonstration, not for a
release.
