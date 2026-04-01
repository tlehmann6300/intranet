<?php

/**
 * Central Route Definitions
 *
 * Maps HTTP-method + URL path combinations to controller actions.
 * All routes are processed by the Front-Controller in index.php via
 * nikic/fast-route.
 *
 * Convention:  [ControllerClass::class, 'methodName']
 *
 * Note: Existing .php pages (pages/, api/) remain accessible directly via
 * Apache – the .htaccess only forwards paths that do not match an existing
 * file/directory, so backward-compatibility is fully preserved.
 */

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use FastRoute\RouteCollector;

return static function (RouteCollector $r): void {

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------
    $r->addRoute('GET',  '/',             [AuthController::class,      'redirectRoot']);
    $r->addRoute('GET',  '/login',        [AuthController::class,      'showLogin']);
    $r->addRoute('POST', '/login',        [AuthController::class,      'handleLogin']);
    $r->addRoute('GET',  '/logout',       [AuthController::class,      'logout']);
    $r->addRoute('GET',  '/register',     [AuthController::class,      'showRegister']);

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------
    $r->addRoute('GET',  '/dashboard',    [DashboardController::class, 'index']);

};
