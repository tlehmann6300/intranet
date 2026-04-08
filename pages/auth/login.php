<?php
// 1. Konfiguration laden (sets all security headers via security_headers.php)
require_once __DIR__ . '/../../config/config.php';

// 2. Error reporting is configured in config.php based on ENVIRONMENT constant
// Detailed error display is only enabled in non-production environments
// This prevents information leakage (file paths, stack traces) in production

// 3. Weitere Abhängigkeiten laden
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

try {
    // Redirect if already authenticated
    if (Auth::check()) {
        header('Location: ../dashboard/index.php');
        exit;
    }

    // Check for error message from OAuth
    $error = isset($_GET['error']) ? urldecode($_GET['error']) : '';

} catch (Throwable $e) {
    // 5. Display error information based on environment
    // Detailed error information is only shown in non-production environments to prevent information leakage
    echo '<div style="background-color: #fee2e2; border: 2px solid #ef4444; color: #991b1b; padding: 20px; font-family: sans-serif; margin: 20px; border-radius: 8px;">';
    echo '<h2 style="margin-top:0">Kritischer Fehler aufgetreten</h2>';
    
    if (ENVIRONMENT !== 'production') {
        // Show detailed error information only in non-production environments
        echo '<p><strong>Fehlermeldung:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>Datei:</strong> ' . htmlspecialchars($e->getFile()) . ' (Zeile ' . $e->getLine() . ')</p>';
        echo '<pre style="background: #fff; padding: 10px; overflow: auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        // Log error details to server logs in production, but show generic message to user
        // This prevents information leakage while still allowing developers to debug issues
        error_log(sprintf(
            'Login error: %s in %s:%d. Stack trace: %s',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
        
        // Show generic error message in production to prevent information leakage
        echo '<p>Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es später erneut oder wenden Sie sich an den Administrator.</p>';
    }
    
    echo '</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0a0f1e">
    <title>IBC Intranet Login</title>
    <link rel="icon" type="image/webp" href="<?php echo asset('assets/img/flaticon.webp'); ?>">
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 for responsive utilities -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css'); ?>">
    <!-- Tailwind CSS utilities -->
    <link rel="stylesheet" href="<?php echo asset('assets/css/tailwind.css'); ?>">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0f1e;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow-x: hidden;
            overflow-y: auto;
            position: relative;
        }

        /* ULTRA ANIMATED BACKGROUND */
        .background-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            overflow: hidden;
        }

        /* Gradient Animation */
        .gradient-bg {
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(108, 183, 62, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(30, 76, 156, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(118, 75, 162, 0.15) 0%, transparent 50%),
                linear-gradient(135deg, #0a0f1e 0%, #1a1f3e 100%);
            -webkit-animation: gradientShift 25s ease-in-out infinite;
            animation: gradientShift 25s ease-in-out infinite;
        }

        @-webkit-keyframes gradientShift {
            0% { 
                transform: translate(0, 0) scale(1);
                opacity: 1;
            }
            25% { 
                transform: translate(-3%, 3%) scale(1.05);
                opacity: 0.95;
            }
            50% { 
                transform: translate(0, 5%) scale(1.1);
                opacity: 0.9;
            }
            75% { 
                transform: translate(3%, 3%) scale(1.05);
                opacity: 0.95;
            }
            100% { 
                transform: translate(0, 0) scale(1);
                opacity: 1;
            }
        }

        @keyframes gradientShift {
            0% { 
                transform: translate(0, 0) scale(1);
                opacity: 1;
            }
            25% { 
                transform: translate(-3%, 3%) scale(1.05);
                opacity: 0.95;
            }
            50% { 
                transform: translate(0, 5%) scale(1.1);
                opacity: 0.9;
            }
            75% { 
                transform: translate(3%, 3%) scale(1.05);
                opacity: 0.95;
            }
            100% { 
                transform: translate(0, 0) scale(1);
                opacity: 1;
            }
        }

        /* Floating Geometric Shapes */
        .shape {
            position: absolute;
            opacity: 0.08;
            -webkit-animation: floatShape 25s infinite;
            animation: floatShape 25s infinite;
        }

        .shape.circle {
            border-radius: 50%;
            background: linear-gradient(135deg, #6cb73e, #1e4c9c);
        }

        .shape.square {
            background: linear-gradient(135deg, #1e4c9c, #764ba2);
            transform: rotate(45deg);
        }

        .shape.triangle {
            width: 0;
            height: 0;
            background: transparent;
            border-left: 50px solid transparent;
            border-right: 50px solid transparent;
            border-bottom: 87px solid rgba(108, 183, 62, 0.15);
        }

        .shape:nth-child(1) { width: 100px; height: 100px; top: 10%; left: 10%; -webkit-animation-delay: 0s; animation-delay: 0s; -webkit-animation-duration: 30s; animation-duration: 30s; }
        .shape:nth-child(2) { width: 150px; height: 150px; top: 60%; left: 80%; -webkit-animation-delay: 5s; animation-delay: 5s; -webkit-animation-duration: 35s; animation-duration: 35s; }
        .shape:nth-child(3) { width: 80px; height: 80px; top: 80%; left: 15%; -webkit-animation-delay: 2s; animation-delay: 2s; -webkit-animation-duration: 32s; animation-duration: 32s; }
        .shape:nth-child(4) { width: 120px; height: 120px; top: 20%; left: 75%; -webkit-animation-delay: 7s; animation-delay: 7s; -webkit-animation-duration: 38s; animation-duration: 38s; }
        .shape:nth-child(5) { top: 40%; left: 50%; -webkit-animation-delay: 10s; animation-delay: 10s; -webkit-animation-duration: 40s; animation-duration: 40s; }
        .shape:nth-child(6) { width: 90px; height: 90px; top: 70%; left: 60%; -webkit-animation-delay: 3s; animation-delay: 3s; -webkit-animation-duration: 34s; animation-duration: 34s; }

        @-webkit-keyframes floatShape {
            0%, 100% { 
                transform: translate(0, 0) rotate(0deg) scale(1);
                opacity: 0.08;
            }
            20% { 
                transform: translate(20px, -30px) rotate(72deg) scale(1.1);
                opacity: 0.1;
            }
            40% { 
                transform: translate(-15px, -60px) rotate(144deg) scale(0.9);
                opacity: 0.06;
            }
            60% { 
                transform: translate(25px, -90px) rotate(216deg) scale(1.15);
                opacity: 0.12;
            }
            80% { 
                transform: translate(-10px, -120px) rotate(288deg) scale(0.85);
                opacity: 0.07;
            }
        }

        @keyframes floatShape {
            0%, 100% { 
                transform: translate(0, 0) rotate(0deg) scale(1);
                opacity: 0.08;
            }
            20% { 
                transform: translate(20px, -30px) rotate(72deg) scale(1.1);
                opacity: 0.1;
            }
            40% { 
                transform: translate(-15px, -60px) rotate(144deg) scale(0.9);
                opacity: 0.06;
            }
            60% { 
                transform: translate(25px, -90px) rotate(216deg) scale(1.15);
                opacity: 0.12;
            }
            80% { 
                transform: translate(-10px, -120px) rotate(288deg) scale(0.85);
                opacity: 0.07;
            }
        }

        /* Particle System */
        .particle-container {
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .particle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: rgba(108, 183, 62, 0.6);
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(108, 183, 62, 0.8);
            -webkit-animation: particleFloat 15s infinite;
            animation: particleFloat 15s infinite;
        }

        @-webkit-keyframes particleFloat {
            0% {
                transform: translateY(100vh) translateX(0) scale(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) scale(1);
                opacity: 0;
            }
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) translateX(0) scale(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) scale(1);
                opacity: 0;
            }
        }

        /* Grid Lines Animation */
        .grid-lines {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(108, 183, 62, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(108, 183, 62, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            -webkit-animation: gridMove 20s linear infinite;
            animation: gridMove 20s linear infinite;
            opacity: 0.5;
        }

        @-webkit-keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        /* Light Rays */
        .light-ray {
            position: absolute;
            width: 2px;
            height: 100%;
            background: linear-gradient(180deg, 
                transparent 0%, 
                rgba(108, 183, 62, 0.15) 30%,
                rgba(108, 183, 62, 0.3) 50%, 
                rgba(108, 183, 62, 0.15) 70%,
                transparent 100%);
            -webkit-animation: lightRayMove 10s ease-in-out infinite;
            animation: lightRayMove 10s ease-in-out infinite;
            filter: blur(1px);
        }

        .light-ray:nth-child(1) { left: 20%; -webkit-animation-delay: 0s; animation-delay: 0s; }
        .light-ray:nth-child(2) { left: 50%; -webkit-animation-delay: 3.3s; animation-delay: 3.3s; }
        .light-ray:nth-child(3) { left: 80%; -webkit-animation-delay: 6.6s; animation-delay: 6.6s; }

        @-webkit-keyframes lightRayMove {
            0%, 100% { 
                opacity: 0; 
                transform: translateY(100%);
            }
            10% {
                opacity: 0.5;
                transform: translateY(0);
            }
            50% { 
                opacity: 1; 
                transform: translateY(-20%);
            }
            90% {
                opacity: 0.5;
                transform: translateY(-100%);
            }
        }

        @keyframes lightRayMove {
            0%, 100% { 
                opacity: 0; 
                transform: translateY(100%);
            }
            10% {
                opacity: 0.5;
                transform: translateY(0);
            }
            50% { 
                opacity: 1; 
                transform: translateY(-20%);
            }
            90% {
                opacity: 0.5;
                transform: translateY(-100%);
            }
        }

        /* LOGIN CONTAINER */
        .login-container {
            background: rgba(15, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 60px;
            border-radius: 30px;
            box-shadow: 
                0 30px 90px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(108, 183, 62, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            margin: 0 auto;
            position: relative;
            z-index: 10;
            -webkit-animation: containerAppear 1.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: containerAppear 1.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid rgba(108, 183, 62, 0.1);
            overflow-wrap: break-word;
            word-break: break-word;
        }

        @-webkit-keyframes containerAppear {
            0% {
                opacity: 0;
                transform: scale(0.8) translateY(50px);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        @keyframes containerAppear {
            0% {
                opacity: 0;
                transform: scale(0.8) translateY(50px);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* MEGA COOL LOGO ANIMATION */
        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 50px;
            position: relative;
        }

        .logo-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            -webkit-animation: logoFloat 6s ease-in-out infinite;
            animation: logoFloat 6s ease-in-out infinite;
            will-change: transform;
        }

        @-webkit-keyframes logoFloat {
            0%, 100% { 
                transform: translateY(0px) rotate(0deg);
            }
            16.6% {
                transform: translateY(-6px) rotate(0.5deg);
            }
            33.3% {
                transform: translateY(-10px) rotate(1deg);
            }
            50% { 
                transform: translateY(-12px) rotate(0deg);
            }
            66.6% {
                transform: translateY(-10px) rotate(-1deg);
            }
            83.3% {
                transform: translateY(-6px) rotate(-0.5deg);
            }
        }

        @keyframes logoFloat {
            0%, 100% { 
                transform: translateY(0px) rotate(0deg);
            }
            16.6% {
                transform: translateY(-6px) rotate(0.5deg);
            }
            33.3% {
                transform: translateY(-10px) rotate(1deg);
            }
            50% { 
                transform: translateY(-12px) rotate(0deg);
            }
            66.6% {
                transform: translateY(-10px) rotate(-1deg);
            }
            83.3% {
                transform: translateY(-6px) rotate(-0.5deg);
            }
        }

        .logo-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(108, 183, 62, 0.3) 0%, transparent 70%);
            -webkit-animation: glowPulse 6s ease-in-out infinite;
            animation: glowPulse 6s ease-in-out infinite;
            border-radius: 50%;
            will-change: transform, opacity;
        }

        @-webkit-keyframes glowPulse {
            0%, 100% { 
                transform: translate(-50%, -50%) scale(0.8);
                opacity: 0.3;
            }
            25% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.5;
            }
            50% { 
                transform: translate(-50%, -50%) scale(1.2);
                opacity: 0.7;
            }
            75% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.5;
            }
        }

        @keyframes glowPulse {
            0%, 100% { 
                transform: translate(-50%, -50%) scale(0.8);
                opacity: 0.3;
            }
            25% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.5;
            }
            50% { 
                transform: translate(-50%, -50%) scale(1.2);
                opacity: 0.7;
            }
            75% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.5;
            }
        }

        .ibc-logo {
            width: 150px;
            max-width: 100%;
            height: auto;
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
            -webkit-animation: logoEntrance 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: logoEntrance 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @-webkit-keyframes logoEntrance {
            0% {
                opacity: 0;
                transform: scale(0) rotate(-360deg);
                filter: drop-shadow(0 0 80px rgba(108, 183, 62, 1)) blur(20px);
            }
            40% {
                opacity: 0.5;
                transform: scale(0.5) rotate(-180deg);
                filter: drop-shadow(0 0 60px rgba(108, 183, 62, 0.8)) blur(10px);
            }
            70% {
                transform: scale(1.15) rotate(15deg);
                filter: drop-shadow(0 15px 40px rgba(108, 183, 62, 0.7));
            }
            85% {
                transform: scale(0.95) rotate(-5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0deg);
                filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
            }
        }

        @keyframes logoEntrance {
            0% {
                opacity: 0;
                transform: scale(0) rotate(-360deg);
                filter: drop-shadow(0 0 80px rgba(108, 183, 62, 1)) blur(20px);
            }
            40% {
                opacity: 0.5;
                transform: scale(0.5) rotate(-180deg);
                filter: drop-shadow(0 0 60px rgba(108, 183, 62, 0.8)) blur(10px);
            }
            70% {
                transform: scale(1.15) rotate(15deg);
                filter: drop-shadow(0 15px 40px rgba(108, 183, 62, 0.7));
            }
            85% {
                transform: scale(0.95) rotate(-5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0deg);
                filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
            }
        }

        /* CLEAN & SMOOTH BACKGROUND ANIMATION */
        
        /* Base gradient with pulse */
        .gradient-wave {
            position: absolute;
            width: 200%;
            height: 200%;
            top: -50%;
            left: -50%;
            background: 
                radial-gradient(ellipse at 30% 20%, rgba(108, 183, 62, 0.15) 0%, transparent 50%),
                radial-gradient(ellipse at 70% 80%, rgba(30, 76, 156, 0.15) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(118, 75, 162, 0.1) 0%, transparent 50%);
            -webkit-animation: waveMove 20s ease-in-out infinite;
            animation: waveMove 20s ease-in-out infinite;
        }

        @-webkit-keyframes waveMove {
            0%, 100% {
                transform: translate(0, 0) rotate(0deg);
            }
            33% {
                transform: translate(5%, -5%) rotate(120deg);
            }
            66% {
                transform: translate(-5%, 5%) rotate(240deg);
            }
        }

        @keyframes waveMove {
            0%, 100% {
                transform: translate(0, 0) rotate(0deg);
            }
            33% {
                transform: translate(5%, -5%) rotate(120deg);
            }
            66% {
                transform: translate(-5%, 5%) rotate(240deg);
            }
        }

        /* Floating Orbs - Fixed positioning */
        .floating-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            -webkit-animation: orbMove 25s ease-in-out infinite;
            animation: orbMove 25s ease-in-out infinite;
        }

        .floating-orb:nth-child(1) {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(108, 183, 62, 0.3), transparent);
            top: 10%;
            left: 10%;
            -webkit-animation-delay: 0s;
            animation-delay: 0s;
        }

        .floating-orb:nth-child(2) {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(30, 76, 156, 0.3), transparent);
            bottom: 10%;
            right: 10%;
            -webkit-animation-delay: 8s;
            animation-delay: 8s;
        }

        .floating-orb:nth-child(3) {
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(118, 75, 162, 0.25), transparent);
            top: calc(50% - 225px);
            left: calc(50% - 225px);
            -webkit-animation-delay: 16s;
            animation-delay: 16s;
        }

        @-webkit-keyframes orbMove {
            0%, 100% {
                transform: translate(0, 0);
                opacity: 0.3;
            }
            50% {
                transform: translate(30px, -50px);
                opacity: 0.5;
            }
        }

        @keyframes orbMove {
            0%, 100% {
                transform: translate(0, 0);
                opacity: 0.3;
            }
            50% {
                transform: translate(30px, -50px);
                opacity: 0.5;
            }
        }

        /* Animated Grid */
        .animated-grid {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(108, 183, 62, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(108, 183, 62, 0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            -webkit-animation: gridSlide 30s linear infinite;
            animation: gridSlide 30s linear infinite;
        }

        @-webkit-keyframes gridSlide {
            0% {
                transform: translate(0, 0);
            }
            100% {
                transform: translate(60px, 60px);
            }
        }

        @keyframes gridSlide {
            0% {
                transform: translate(0, 0);
            }
            100% {
                transform: translate(60px, 60px);
            }
        }

        /* Light Beams */
        .light-beam {
            position: absolute;
            width: 2px;
            height: 100%;
            background: linear-gradient(180deg, 
                transparent 0%,
                rgba(108, 183, 62, 0.2) 40%,
                rgba(108, 183, 62, 0.3) 50%,
                rgba(108, 183, 62, 0.2) 60%,
                transparent 100%);
            -webkit-animation: beamMove 12s ease-in-out infinite;
            animation: beamMove 12s ease-in-out infinite;
        }

        .light-beam:nth-child(1) {
            left: 25%;
            -webkit-animation-delay: 0s;
            animation-delay: 0s;
        }

        .light-beam:nth-child(2) {
            left: 50%;
            -webkit-animation-delay: 4s;
            animation-delay: 4s;
        }

        .light-beam:nth-child(3) {
            left: 75%;
            -webkit-animation-delay: 8s;
            animation-delay: 8s;
        }

        @-webkit-keyframes beamMove {
            0%, 100% {
                opacity: 0;
                transform: translateY(100%);
            }
            50% {
                opacity: 1;
                transform: translateY(-100%);
            }
        }

        @keyframes beamMove {
            0%, 100% {
                opacity: 0;
                transform: translateY(100%);
            }
            50% {
                opacity: 1;
                transform: translateY(-100%);
            }
        }

        /* Rising Particles */
        .smooth-particle {
            position: absolute;
            width: 3px;
            height: 3px;
            background: rgba(108, 183, 62, 0.6);
            border-radius: 50%;
            box-shadow: 0 0 10px rgba(108, 183, 62, 0.8);
            -webkit-animation: particleRise 20s linear infinite;
            animation: particleRise 20s linear infinite;
        }

        @-webkit-keyframes particleRise {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-10vh) translateX(50px);
                opacity: 0;
            }
        }

        @keyframes particleRise {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-10vh) translateX(50px);
                opacity: 0;
            }
        }

        .smooth-particle:nth-child(1) { left: 5%; -webkit-animation-duration: 18s; animation-duration: 18s; -webkit-animation-delay: 0s; animation-delay: 0s; }
        .smooth-particle:nth-child(2) { left: 10%; -webkit-animation-duration: 22s; animation-duration: 22s; -webkit-animation-delay: 2s; animation-delay: 2s; }
        .smooth-particle:nth-child(3) { left: 15%; -webkit-animation-duration: 20s; animation-duration: 20s; -webkit-animation-delay: 4s; animation-delay: 4s; }
        .smooth-particle:nth-child(4) { left: 20%; -webkit-animation-duration: 19s; animation-duration: 19s; -webkit-animation-delay: 1s; animation-delay: 1s; }
        .smooth-particle:nth-child(5) { left: 25%; -webkit-animation-duration: 21s; animation-duration: 21s; -webkit-animation-delay: 3s; animation-delay: 3s; }
        .smooth-particle:nth-child(6) { left: 30%; -webkit-animation-duration: 23s; animation-duration: 23s; -webkit-animation-delay: 5s; animation-delay: 5s; }
        .smooth-particle:nth-child(7) { left: 35%; -webkit-animation-duration: 17s; animation-duration: 17s; -webkit-animation-delay: 2.5s; animation-delay: 2.5s; }
        .smooth-particle:nth-child(8) { left: 40%; -webkit-animation-duration: 24s; animation-duration: 24s; -webkit-animation-delay: 4.5s; animation-delay: 4.5s; }
        .smooth-particle:nth-child(9) { left: 45%; -webkit-animation-duration: 19s; animation-duration: 19s; -webkit-animation-delay: 1.5s; animation-delay: 1.5s; }
        .smooth-particle:nth-child(10) { left: 50%; -webkit-animation-duration: 21s; animation-duration: 21s; -webkit-animation-delay: 3.5s; animation-delay: 3.5s; }
        .smooth-particle:nth-child(11) { left: 55%; -webkit-animation-duration: 20s; animation-duration: 20s; -webkit-animation-delay: 1.8s; animation-delay: 1.8s; }
        .smooth-particle:nth-child(12) { left: 60%; -webkit-animation-duration: 22s; animation-duration: 22s; -webkit-animation-delay: 4.2s; animation-delay: 4.2s; }
        .smooth-particle:nth-child(13) { left: 65%; -webkit-animation-duration: 18s; animation-duration: 18s; -webkit-animation-delay: 0.5s; animation-delay: 0.5s; }
        .smooth-particle:nth-child(14) { left: 70%; -webkit-animation-duration: 23s; animation-duration: 23s; -webkit-animation-delay: 3.8s; animation-delay: 3.8s; }
        .smooth-particle:nth-child(15) { left: 75%; -webkit-animation-duration: 19s; animation-duration: 19s; -webkit-animation-delay: 2.2s; animation-delay: 2.2s; }
        .smooth-particle:nth-child(16) { left: 80%; -webkit-animation-duration: 21s; animation-duration: 21s; -webkit-animation-delay: 5.5s; animation-delay: 5.5s; }
        .smooth-particle:nth-child(17) { left: 85%; -webkit-animation-duration: 20s; animation-duration: 20s; -webkit-animation-delay: 1.2s; animation-delay: 1.2s; }
        .smooth-particle:nth-child(18) { left: 90%; -webkit-animation-duration: 24s; animation-duration: 24s; -webkit-animation-delay: 4.8s; animation-delay: 4.8s; }
        .smooth-particle:nth-child(19) { left: 95%; -webkit-animation-duration: 18s; animation-duration: 18s; -webkit-animation-delay: 2.8s; animation-delay: 2.8s; }
        .smooth-particle:nth-child(20) { left: 12%; -webkit-animation-duration: 22s; animation-duration: 22s; -webkit-animation-delay: 5.2s; animation-delay: 5.2s; }
        .smooth-particle:nth-child(21) { left: 28%; -webkit-animation-duration: 19s; animation-duration: 19s; -webkit-animation-delay: 0.8s; animation-delay: 0.8s; }
        .smooth-particle:nth-child(22) { left: 42%; -webkit-animation-duration: 21s; animation-duration: 21s; -webkit-animation-delay: 3.2s; animation-delay: 3.2s; }
        .smooth-particle:nth-child(23) { left: 58%; -webkit-animation-duration: 23s; animation-duration: 23s; -webkit-animation-delay: 4.6s; animation-delay: 4.6s; }
        .smooth-particle:nth-child(24) { left: 72%; -webkit-animation-duration: 20s; animation-duration: 20s; -webkit-animation-delay: 1.4s; animation-delay: 1.4s; }
        .smooth-particle:nth-child(25) { left: 88%; -webkit-animation-duration: 22s; animation-duration: 22s; -webkit-animation-delay: 3.6s; animation-delay: 3.6s; }

        /* WELCOME TEXT */
        .welcome-text {
            text-align: center;
            margin-bottom: 40px;
            -webkit-animation: textSlideUp 1s ease-out 0.5s both;
            animation: textSlideUp 1s ease-out 0.5s both;
        }

        @-webkit-keyframes textSlideUp {
            0% {
                opacity: 0;
                transform: translateY(30px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes textSlideUp {
            0% {
                opacity: 0;
                transform: translateY(30px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-title {
            font-size: 32px;
            color: #ffffff !important;
            margin-bottom: 12px;
            font-weight: 700;
            letter-spacing: -0.5px;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        .welcome-subtitle {
            color: #ffffff !important;
            font-size: 16px;
            font-weight: 400;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        /* MICROSOFT BUTTON - MEGA IMPRESSIVE */
        .microsoft-button {
            text-decoration: none;
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%);
            border: 2px solid rgba(108, 183, 62, 0.2);
            border-radius: 16px;
            font-size: 17px;
            font-weight: 600;
            color: #2d2d2d;
            cursor: pointer;
            display: flex !important;
            align-items: center;
            justify-content: center;
            gap: 15px;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            -webkit-animation: buttonSlideUp 1s ease-out 0.8s both;
            animation: buttonSlideUp 1s ease-out 0.8s both;
        }

        @-webkit-keyframes buttonSlideUp {
            0% {
                opacity: 0;
                transform: translateY(30px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes buttonSlideUp {
            0% {
                opacity: 0;
                transform: translateY(30px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Shimmer Effect */
        .microsoft-button::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(108, 183, 62, 0.3) 50%,
                transparent 70%
            );
            transform: rotate(45deg);
            -webkit-animation: shimmer 3s infinite;
            animation: shimmer 3s infinite;
        }

        @-webkit-keyframes shimmer {
            0% { transform: translateX(-100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }

        /* Hover Effect */
        .microsoft-button:hover {
            transform: translateY(-5px) scale(1.02);
            border-color: rgba(108, 183, 62, 0.6);
            box-shadow: 
                0 20px 50px rgba(108, 183, 62, 0.3),
                0 0 0 1px rgba(108, 183, 62, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 1);
        }

        .microsoft-button:active {
            transform: translateY(-2px) scale(0.98);
        }

        .microsoft-button:focus {
            outline: none;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.8),
                0 0 0 2px #ffffff,
                0 0 0 4px rgba(108, 183, 62, 0.8);
        }

        /* Ripple Effect */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(108, 183, 62, 0.4);
            transform: scale(0);
            -webkit-animation: rippleEffect 0.6s ease-out;
            animation: rippleEffect 0.6s ease-out;
            pointer-events: none;
        }

        @-webkit-keyframes rippleEffect {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        @keyframes rippleEffect {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Microsoft Logo */
        .microsoft-logo {
            width: 24px;
            height: 24px;
            display: grid;
            grid-template-columns: repeat(2, 11px);
            grid-template-rows: repeat(2, 11px);
            gap: 2px;
            position: relative;
            z-index: 2;
            -webkit-animation: logoSpin 1s ease-out 1s;
            animation: logoSpin 1s ease-out 1s;
        }

        @-webkit-keyframes logoSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes logoSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .microsoft-logo div {
            width: 11px;
            height: 11px;
            transition: all 0.3s ease;
        }

        .microsoft-button:hover .microsoft-logo div {
            transform: scale(1.1);
        }

        .microsoft-logo div:nth-child(1) { background: #f25022; }
        .microsoft-logo div:nth-child(2) { background: #7fba00; }
        .microsoft-logo div:nth-child(3) { background: #00a4ef; }
        .microsoft-logo div:nth-child(4) { background: #ffb900; }

        .microsoft-button span {
            position: relative;
            z-index: 2;
        }

        /* Loading Animation */
        .loading-spinner {
            display: none;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(45, 45, 45, 0.2);
            border-radius: 50%;
            border-top-color: #2d2d2d;
            -webkit-animation: spin 0.8s linear infinite;
            animation: spin 0.8s linear infinite;
        }

        @-webkit-keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .microsoft-button.loading .loading-spinner {
            display: inline-block;
        }

        .microsoft-button.loading span,
        .microsoft-button.loading .microsoft-logo {
            display: none;
        }

        /* Success Animation */
        .success-checkmark {
            display: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #6cb73e;
            position: relative;
        }

        .success-checkmark::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 6px;
            height: 12px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: translate(-50%, -60%) rotate(45deg);
        }

        .microsoft-button.success .success-checkmark {
            display: block;
            -webkit-animation: successPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: successPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @-webkit-keyframes successPop {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }

        @keyframes successPop {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }

        /* PHP Message Alert Styles */
        .alert-message {
            margin-bottom: 30px;
            padding: 16px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            -webkit-animation: alertSlideDown 0.5s ease-out;
            animation: alertSlideDown 0.5s ease-out;
            font-size: 15px;
            font-weight: 500;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        @-webkit-keyframes alertSlideDown {
            0% {
                opacity: 0;
                transform: translateY(-20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes alertSlideDown {
            0% {
                opacity: 0;
                transform: translateY(-20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-timeout {
            background: rgba(255, 193, 7, 0.15);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffffff;
        }

        .alert-success {
            background: rgba(108, 183, 62, 0.15);
            border: 1px solid rgba(108, 183, 62, 0.4);
            color: #ffffff;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.15);
            border: 1px solid rgba(244, 67, 54, 0.4);
            color: #ffffff;
        }

        .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 40px;
            color: #ffffff;
            font-size: 14px;
            -webkit-animation: textSlideUp 1s ease-out 1.1s both;
            animation: textSlideUp 1s ease-out 1.1s both;
        }

        /* RESPONSIVE DESIGN - Perfekt für ALLE Größen */
        
        /* Large Desktop */
        @media (min-width: 1600px) {
            .login-container {
                padding: 70px;
            }

            .ibc-logo {
                width: 170px;
            }

            .logo-glow {
                width: 230px;
                height: 230px;
            }

            .welcome-title {
                font-size: 36px;
            }

            .microsoft-button {
                padding: 22px;
                font-size: 18px;
            }
        }

        /* Tablets & Medium screens (up to 1024px) */
        @media (max-width: 1024px) {
            .login-container {
                padding: 50px 40px;
            }

            .ibc-logo {
                width: 140px;
            }

            .logo-glow {
                width: 190px;
                height: 190px;
            }
        }

        /* Tablets Portrait & Large Phones (up to 768px) */
        @media (max-width: 768px) {
            .login-container {
                padding: 45px 35px;
                border-radius: 25px;
            }

            .ibc-logo {
                width: 130px;
                max-width: 100%;
                height: auto;
            }

            .logo-glow {
                width: 180px;
                height: 180px;
            }

            .welcome-title {
                font-size: 28px;
            }

            .welcome-subtitle {
                font-size: 15px;
            }

            .microsoft-button {
                padding: 18px;
                font-size: 16px;
            }

            .shape {
                opacity: 0.06;
            }
        }

        /* Smartphones (up to 480px) */
        @media (max-width: 480px) {
            .login-container {
                padding: 40px 28px;
                border-radius: 22px;
            }

            .logo-container {
                margin-bottom: 35px;
            }

            .ibc-logo {
                width: 110px;
            }

            .logo-glow {
                width: 160px;
                height: 160px;
            }

            .welcome-text {
                margin-bottom: 30px;
            }

            .welcome-title {
                font-size: 26px;
                margin-bottom: 10px;
            }

            .welcome-subtitle {
                font-size: 14px;
            }

            .microsoft-button {
                padding: 17px;
                font-size: 15px;
                gap: 12px;
                white-space: normal;
            }

            .microsoft-logo {
                width: 22px;
                height: 22px;
                grid-template-columns: repeat(2, 10px);
                grid-template-rows: repeat(2, 10px);
            }

            .microsoft-logo div {
                width: 10px;
                height: 10px;
            }

            .login-footer {
                font-size: 14px;
                margin-top: 35px;
            }

            .shape {
                opacity: 0.05;
            }
        }

        /* Small Smartphones (280px - 360px) */
        @media (max-width: 360px) {
            .login-container {
                padding: 35px 22px;
                border-radius: 20px;
            }

            .logo-container {
                margin-bottom: 25px;
            }

            .ibc-logo {
                width: 95px;
            }

            .logo-glow {
                width: 140px;
                height: 140px;
            }

            .welcome-text {
                margin-bottom: 35px;
            }

            .welcome-title {
                font-size: 23px;
                margin-bottom: 8px;
            }

            .welcome-subtitle {
                font-size: 14px;
            }

            .microsoft-button {
                padding: 13px 10px;
                font-size: 14px;
                gap: 8px;
                white-space: normal;
            }

            .microsoft-logo {
                width: 20px;
                height: 20px;
                grid-template-columns: repeat(2, 9px);
                grid-template-rows: repeat(2, 9px);
            }

            .microsoft-logo div {
                width: 9px;
                height: 9px;
            }

            .login-footer {
                font-size: 14px;
                margin-top: 30px;
            }

            .shape {
                opacity: 0.04;
            }

            .particle {
                width: 2px;
                height: 2px;
            }
        }

        /* Extra Small Devices (< 280px) */
        @media (max-width: 280px) {
            .login-container {
                padding: 30px 18px;
            }

            .ibc-logo {
                width: 85px;
            }

            .logo-glow {
                width: 120px;
                height: 120px;
            }

            .welcome-title {
                font-size: 20px;
            }

            .welcome-subtitle {
                font-size: 14px;
            }

            .microsoft-button {
                padding: 13px;
                font-size: 14px;
            }

            .microsoft-logo {
                width: 18px;
                height: 18px;
                grid-template-columns: repeat(2, 8px);
                grid-template-rows: repeat(2, 8px);
            }

            .microsoft-logo div {
                width: 8px;
                height: 8px;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .login-container {
                border: 2px solid #6cb73e;
            }

            .microsoft-button {
                border: 2px solid #2d2d2d;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                -webkit-animation-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
                -webkit-animation-iteration-count: 1 !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* Safe area support for notched devices, keep container centered */
        @media (max-width: 640px) {
            body {
                padding: max(1.5rem, env(safe-area-inset-top)) max(0px, env(safe-area-inset-right)) max(1.5rem, env(safe-area-inset-bottom)) max(0px, env(safe-area-inset-left));
            }
            .login-container {
                margin-left: auto;
                margin-right: auto;
            }
        }

        /* Landscape mobile: tighten spacing to fit content */
        @media (max-width: 768px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding-top: 1rem;
                padding-bottom: 1rem;
            }
            .login-container {
                margin: 1rem auto;
            }
            .logo-container {
                margin-bottom: 20px;
            }
            .welcome-text {
                margin-bottom: 20px;
            }
        }

        /* Override theme.css !important color rules for login page */
        body .welcome-text h1.welcome-title { color: #ffffff !important; }
        body .welcome-text p.welcome-subtitle { color: #ffffff !important; }
        .text-white { color: #ffffff !important; }

        /* Extra small phones (375-479px): fine-tune spacing */
        @media (max-width: 479px) and (min-width: 361px) {
            .login-container {
                padding: 36px 24px;
                border-radius: 20px;
            }
            .logo-container {
                margin-bottom: 28px;
            }
            .ibc-logo {
                width: 105px;
            }
            .logo-glow {
                width: 155px;
                height: 155px;
            }
            .welcome-title {
                font-size: 24px;
                margin-bottom: 8px;
            }
            .welcome-subtitle {
                font-size: 14px;
            }
            .microsoft-button {
                padding: 15px 16px;
                font-size: 14px;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- SIMPLE & SMOOTH ANIMATED BACKGROUND -->
    <div class="background-wrapper">
        <!-- Base Gradient -->
        <div class="gradient-bg"></div>
        
        <!-- Gradient Wave -->
        <div class="gradient-wave"></div>
        
        <!-- Floating Orbs (3) -->
        <div class="floating-orb"></div>
        <div class="floating-orb"></div>
        <div class="floating-orb"></div>
        
        <!-- Animated Grid -->
        <div class="animated-grid"></div>
        
        <!-- Light Beams (3) -->
        <div class="light-beam"></div>
        <div class="light-beam"></div>
        <div class="light-beam"></div>
        
        <!-- Smooth Particles (25) -->
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
        <div class="smooth-particle"></div>
    </div>

    <!-- LOGIN CONTAINER -->
    <div class="login-container max-w-md w-full">
        <!-- MEGA COOL LOGO ANIMATION -->
        <div class="logo-container">
            <div class="logo-glow"></div>
            <div class="logo-wrapper">
                <svg class="ibc-logo" viewBox="0 0 5016 2287" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1234.9" y="30.021" width="240.167" height="1839.458" fill="#6cb73e"/>
                    <path d="M2069.658,1864.021c79.146,10.917 204.688,21.833 368.438,21.833c300.208,0 507.625,-54.583 633.167,-171.938c95.521,-87.333 158.292,-210.146 158.292,-368.438c0,-272.917 -204.688,-417.563 -379.354,-458.5l0,-8.188c191.042,-68.229 311.125,-223.792 311.125,-403.917c0,-144.646 -60.042,-253.812 -155.562,-324.771c-111.896,-92.792 -264.729,-133.729 -502.167,-133.729c-163.75,0 -330.229,16.375 -433.938,40.938l0,1806.708Zm237.438,-1648.417c38.208,-8.187 100.979,-16.375 210.146,-16.375c240.167,0 401.188,87.333 401.188,300.208c0,177.396 -147.375,311.125 -395.729,311.125l-215.604,0l0,-594.958Zm0,772.354l196.5,0c259.271,0 474.875,106.438 474.875,354.792c0,267.458 -226.521,357.521 -472.146,357.521c-84.604,0 -150.104,-2.729 -199.229,-10.917l0,-701.396Z" fill="#6cb73e"/>
                    <path d="M4963.756,1621.125c-95.521,46.396 -242.896,76.417 -390.271,76.417c-444.854,0 -704.125,-286.563 -704.125,-739.604c0,-483.062 286.562,-758.708 717.771,-758.708c152.833,0 281.104,32.75 368.438,76.417l60.042,-193.771c-62.771,-32.75 -210.146,-81.875 -436.667,-81.875c-570.396,0 -960.667,387.542 -960.667,966.125c0,605.875 387.542,933.375 906.083,933.375c223.792,0 401.188,-43.667 485.792,-87.333l-46.396,-191.042Z" fill="#6cb73e"/>
                    <path d="M1018.765,844.401l-1018.765,1018.773l1018.765,0l0,-1018.773Z" fill="#1e4c9c"/>
                    <path d="M1018.765,347.539l-836.007,836.009l237.49,237.492l598.517,-598.525" fill="#6cb73e"/>
                    <path d="M1018.765,53.816l-562.483,562.485l135.722,136.093l426.761,-426.767" fill="#646464"/>
                </svg>
            </div>
        </div>

        <!-- Welcome Text -->
        <div class="welcome-text">
            <h1 class="welcome-title text-white">Willkommen zurück</h1>
            <p class="welcome-subtitle text-white">Melde dich mit deinem Microsoft-Konto an</p>
        </div>

        <?php if (isset($_GET['timeout']) && $_GET['timeout'] == 1): ?>
        <div class="alert-message alert-timeout">
            <span class="alert-icon text-white">⏱️</span>
            <span class="text-white">Aus Sicherheitsgründen wurdest du automatisch ausgeloggt.</span>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['logout']) && $_GET['logout'] == 1): ?>
        <div class="alert-message alert-success">
            <span class="alert-icon text-white">✓</span>
            <span class="text-white">Erfolgreich abgemeldet.</span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert-message alert-error">
            <span class="alert-icon text-white">⚠</span>
            <span class="text-white"><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <!-- Microsoft Login Button -->
        <a href="<?php echo BASE_URL; ?>/auth/login_start.php" class="microsoft-button w-full py-3" id="loginButton" onclick="return handleLogin(event)">
            <div class="microsoft-logo">
                <div></div>
                <div></div>
                <div></div>
                <div></div>
            </div>
            <span>Mit Microsoft Konto anmelden</span>
            <div class="loading-spinner"></div>
            <div class="success-checkmark"></div>
        </a>

        <!-- Footer -->
        <div class="login-footer">
            <p class="text-white">&copy; <?php echo date('Y'); ?> Institut für Business Consulting Furtwangen. Alle Rechte vorbehalten.</p>
        </div>
    </div>

    <script>
        // Ripple Effect on Button Click
        function createRipple(event) {
            const button = event.currentTarget;
            const ripple = document.createElement('span');
            const rect = button.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = event.clientX - rect.left - size / 2;
            const y = event.clientY - rect.top - size / 2;

            ripple.className = 'ripple';
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';

            button.appendChild(ripple);

            setTimeout(() => ripple.remove(), 600);
        }


        // Microsoft Login Handler with proper animation timing
        function handleLogin(event) {
            event.preventDefault();
            createRipple(event);
            
            const button = document.getElementById('loginButton');
            if (button.classList.contains('loading') || button.classList.contains('success')) {
                return false;
            }
            
            button.classList.add('loading');
            
            // Show loading, then success, then navigate
            setTimeout(() => {
                button.classList.remove('loading');
                button.classList.add('success');
                
                setTimeout(() => {
                    window.location.href = button.href;
                }, 800);
            }, 1500);
            
            return false;
        }

        // Prevent double-click
        document.addEventListener('DOMContentLoaded', function() {
            const button = document.getElementById('loginButton');
            button.addEventListener('click', function(event) {
                if (this.classList.contains('loading') || this.classList.contains('success')) {
                    event.preventDefault();
                    return false;
                }
            });
        });
    </script>
</body>
</html>
