<?php
declare(strict_types=1);

/**
 * Production preflight for Project Control Dashboard.
 * Run from CLI before deployment; this script never creates or modifies a DB.
 */

function result(string $label, bool $ok, string $detail): void {
    printf("[%s] %s: %s\n", $ok ? "PASS" : "FAIL", $label, $detail);
}

$failed = false;
$phpOk = PHP_VERSION_ID >= 80100;
result("PHP version", $phpOk, PHP_VERSION . ($phpOk ? " (8.1+ required)" : " (upgrade to PHP 8.1+)"));
$failed = $failed || !$phpOk;

$sqliteOk = extension_loaded("pdo_sqlite") && in_array("sqlite", PDO::getAvailableDrivers(), true);
result("PDO_SQLite", $sqliteOk, $sqliteOk ? "extension and driver available" : "enable PHP PDO_SQLite");
$failed = $failed || !$sqliteOk;

$dbPath = getenv("PROJECT_DASHBOARD_DB") ?: "";
$dbAbsolute = $dbPath !== "" && ($dbPath[0] === "/" || preg_match('/^[A-Za-z]:[\\\\\/]/', $dbPath) === 1);
result("Database path", $dbAbsolute, $dbAbsolute ? "absolute path configured" : "set PROJECT_DASHBOARD_DB to an absolute path");
$failed = $failed || !$dbAbsolute;

$publicRoot = getenv("PROJECT_DASHBOARD_PUBLIC_ROOT") ?: ($_SERVER["DOCUMENT_ROOT"] ?? dirname(__DIR__));
$publicReal = realpath($publicRoot);
$parentReal = $dbAbsolute ? realpath(dirname($dbPath)) : false;
$parentOk = $parentReal !== false && is_dir($parentReal) && is_writable($parentReal);
result("Database directory", $parentOk, $parentOk ? "parent directory exists and is writable" : "create the database parent directory and grant write access to the PHP account");
$failed = $failed || !$parentOk;

$parentPermissions = $parentReal !== false ? fileperms($parentReal) : false;
$privateDirectory = DIRECTORY_SEPARATOR !== "/" || ($parentPermissions !== false && ($parentPermissions & 0077) === 0);
result("Database directory permissions", $privateDirectory, $privateDirectory ? "directory is private to its owner" : "restrict the database directory to owner-only permissions (0700)");
$failed = $failed || !$privateDirectory;

$outsidePublic = false;
if ($parentOk && $publicReal !== false) {
    $candidate = is_file($dbPath) ? realpath($dbPath) : $parentReal . DIRECTORY_SEPARATOR . basename($dbPath);
    $rootPrefix = rtrim($publicReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $outsidePublic = $candidate !== false
        && $candidate !== $publicReal
        && !str_starts_with($candidate, $rootPrefix);
}
result("Database outside public root", $outsidePublic, $outsidePublic ? "database path is outside " . ($publicReal ?: "the public root") : "set PROJECT_DASHBOARD_DB outside PROJECT_DASHBOARD_PUBLIC_ROOT / DOCUMENT_ROOT");
$failed = $failed || !$outsidePublic;

$cookieSecure = getenv("PROJECT_DASHBOARD_COOKIE_SECURE") === "1";
result("Secure session cookie", $cookieSecure, $cookieSecure ? "secure cookie flag enabled" : "set PROJECT_DASHBOARD_COOKIE_SECURE=1 when serving production over HTTPS");
$failed = $failed || !$cookieSecure;

$setupKey = getenv("PROJECT_DASHBOARD_SETUP_KEY");
if ($setupKey === false || $setupKey === "") {
    result("First-admin setup key", true, "not set; expected after the first admin has been created");
} else {
    $keyOk = strlen($setupKey) >= 32;
    result("First-admin setup key", $keyOk, $keyOk ? "present with at least 32 characters; remove it after first-admin setup" : "use a random key of at least 32 characters");
    $failed = $failed || !$keyOk;
}

if ($failed) {
    fwrite(STDERR, "Preflight failed. Fix the FAIL items before production deployment.\n");
    exit(1);
}
echo "Preflight passed. This checks configuration only; it does not deploy or certify the application.\n";
