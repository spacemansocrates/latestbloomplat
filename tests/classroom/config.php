<?php
/**
 * tests/classroom/config.php
 *
 * Where the classroom tests are allowed to connect.
 *
 * Hardcoded to the throwaway MariaDB on port 33316 and never sourced from .env,
 * because this project's .env points at the live Hostinger database. Keeping these
 * constants in their own file makes it obvious in review if a test ever starts
 * reading connection details from somewhere else.
 */

declare(strict_types=1);

const CT_DB_HOST = '127.0.0.1';
const CT_DB_PORT = '33316';
const CT_DB_USER = 'root';
const CT_DB_PASS = '';
const CT_DB_NAME = 'tutsys_classroom_test';

/** A PDO for the disposable test database, configured like the app's own. */
function ct_pdo(): PDO {
    return new PDO(
        'mysql:host=' . CT_DB_HOST . ';port=' . CT_DB_PORT . ';dbname=' . CT_DB_NAME . ';charset=utf8mb4',
        CT_DB_USER, CT_DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}
