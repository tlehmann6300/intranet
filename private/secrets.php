<?php
/**
 * secrets.php – Ensures environment variables from .env are loaded into $_ENV.
 * Include this file at the top of any standalone script that needs access to
 * credentials without bootstrapping the full application stack.
 */

$_secretsEnvFile = __DIR__ . '/../.env';
if (file_exists($_secretsEnvFile)) {
    foreach (file($_secretsEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_secretsLine) {
        $_secretsLine = trim($_secretsLine);
        if ($_secretsLine === '' || $_secretsLine[0] === '#' || strpos($_secretsLine, '=') === false) {
            continue;
        }
        [$_secretsKey, $_secretsVal] = explode('=', $_secretsLine, 2);
        $_secretsKey = trim($_secretsKey);
        $_secretsVal = trim($_secretsVal);
        // Strip surrounding quotes
        if (strlen($_secretsVal) >= 2) {
            $q = $_secretsVal[0];
            if (($q === '"' || $q === "'") && substr($_secretsVal, -1) === $q) {
                $_secretsVal = substr($_secretsVal, 1, -1);
            }
        }
        // Only accept valid identifier-like keys; never overwrite an existing value
        if (preg_match('/^[A-Z][A-Z0-9_]*$/i', $_secretsKey) && !isset($_ENV[$_secretsKey])) {
            $_ENV[$_secretsKey] = $_secretsVal;
        }
    }
}
unset($_secretsEnvFile, $_secretsLine, $_secretsKey, $_secretsVal, $q);
