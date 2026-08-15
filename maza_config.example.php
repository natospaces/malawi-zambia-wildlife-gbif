<?php
/**
 * maza_config.example.php
 *
 * Copy this to  maza_config.php  and place it ONE FOLDER ABOVE the web root
 * (so the web server cannot serve it and the password stays private).
 *
 *   cp maza_config.example.php ../maza_config.php
 *   # then edit ../maza_config.php with your real credentials
 *
 * etl.php reads this file by default. Override the path with the MAZA_CONFIG
 * environment variable if you keep it elsewhere.
 *
 * Never commit the real maza_config.php — it is listed in .gitignore.
 */

return [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'your_db_user',
    'DB_PASS' => 'your_db_password',   // may be empty, but the key must exist
    'DB_NAME' => 'your_db_name',
];
