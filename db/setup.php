<?php

/**
 * (Re)initialises etc/db.db from db/schema.sql and seeds a default admin user
 * if one doesn't already exist. Safe to run repeatedly. Invoked by
 * `./build.sh db:init`.
 *
 * Override the seeded credentials with ASCENSION_ADMIN_USER / ASCENSION_ADMIN_PASSWORD.
 */

$root = dirname(__DIR__);
$dbPath = $root . '/etc/db.db';
$schemaPath = $root . '/db/schema.sql';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents($schemaPath));

$defaultUsername = getenv('ASCENSION_ADMIN_USER') ?: 'Administrator';
$defaultPassword = getenv('ASCENSION_ADMIN_PASSWORD') ?: 'ChangeMe';

$existing = $pdo->prepare('SELECT COUNT(*) FROM users WHERE Username = :username');
$existing->execute([':username' => $defaultUsername]);

if ((int)$existing->fetchColumn() === 0) {
    $insert = $pdo->prepare('INSERT INTO users (Username, PasswordHash) VALUES (:username, :hash)');
    $insert->execute([
        ':username' => $defaultUsername,
        ':hash' => password_hash($defaultPassword, PASSWORD_DEFAULT),
    ]);

    fwrite(STDERR, "Seeded default admin user '{$defaultUsername}'." . PHP_EOL);
    if (!getenv('ASCENSION_ADMIN_PASSWORD')) {
        fwrite(STDERR, "  Default password is 'ChangeMe' - change it immediately after first login." . PHP_EOL);
    }
} else {
    fwrite(STDERR, "User '{$defaultUsername}' already exists, skipping seed." . PHP_EOL);
}

fwrite(STDERR, 'Database ready at ' . $dbPath . PHP_EOL);
