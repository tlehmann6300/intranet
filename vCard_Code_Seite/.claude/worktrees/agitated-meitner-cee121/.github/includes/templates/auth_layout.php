<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../helpers.php';
$_tailwindCssVersion = filemtime(__DIR__ . '/../../assets/css/tailwind.css');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0a0f1e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo $title ?? 'IBC Intranet'; ?></title>
    <link rel="icon" type="image/webp" href="<?php echo asset('assets/img/cropped_maskottchen_32x32.webp'); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset('assets/img/cropped_maskottchen_180x180.webp'); ?>">
    <link rel="manifest" href="<?php echo asset('manifest.json'); ?>">
    <!-- DNS prefetch for performance -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;0,14..32,800;1,14..32,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset('assets/css/tailwind.css') . '?v=' . $_tailwindCssVersion; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif;
            background: #0a0f1e;
            color: #f8fafc; /* Light text fallback for dark background */
            min-height: 100vh;
            min-height: 100dvh;
            min-height: var(--app-height, 100vh);
            display: flex;
            justify-content: center;
            align-items: center;
            overflow-x: hidden;
            overflow-y: auto;
            position: relative;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            -webkit-text-size-adjust: 100%;
            -moz-text-size-adjust: 100%;
            text-size-adjust: 100%;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.2) rgba(255, 255, 255, 0.05);
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

        /* Floating Orbs */
        .floating-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            -webkit-filter: blur(80px);
            opacity: 0.3;
            -webkit-animation: float 20s ease-in-out infinite;
            animation: float 20s ease-in-out infinite;
        }

        .floating-orb:nth-child(2) {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(108, 183, 62, 0.4) 0%, transparent 70%);
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-orb:nth-child(3) {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(30, 76, 156, 0.4) 0%, transparent 70%);
            bottom: 15%;
            right: 15%;
            -webkit-animation-delay: 7s;
            animation-delay: 7s;
        }

        .floating-orb:nth-child(4) {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(118, 75, 162, 0.3) 0%, transparent 70%);
            top: 60%;
            left: 50%;
            -webkit-animation-delay: 14s;
            animation-delay: 14s;
        }

        @-webkit-keyframes float {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }
            25% {
                transform: translate(30px, -30px) scale(1.1);
            }
            50% {
                transform: translate(-20px, 20px) scale(0.9);
            }
            75% {
                transform: translate(20px, 10px) scale(1.05);
            }
        }

        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }
            25% {
                transform: translate(30px, -30px) scale(1.1);
            }
            50% {
                transform: translate(-20px, 20px) scale(0.9);
            }
            75% {
                transform: translate(20px, 30px) scale(1.05);
            }
        }

        /* Gradient Wave */
        .gradient-wave {
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent 30%, rgba(108, 183, 62, 0.05) 50%, transparent 70%);
            -webkit-animation: wave 15s ease-in-out infinite;
            animation: wave 15s ease-in-out infinite;
        }

        @-webkit-keyframes wave {
            0%, 100% {
                transform: translateX(-100%) translateY(-50%) rotate(0deg);
            }
            50% {
                transform: translateX(100%) translateY(50%) rotate(180deg);
            }
        }

        @keyframes wave {
            0%, 100% {
                transform: translateX(-100%) translateY(-50%) rotate(0deg);
            }
            50% {
                transform: translateX(100%) translateY(50%) rotate(180deg);
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
            background-size: 50px 50px;
            -webkit-animation: gridMove 20s linear infinite;
            animation: gridMove 20s linear infinite;
        }

        @-webkit-keyframes gridMove {
            0% {
                transform: translate(0, 0);
            }
            100% {
                transform: translate(50px, 50px);
            }
        }

        @keyframes gridMove {
            0% {
                transform: translate(0, 0);
            }
            100% {
                transform: translate(50px, 50px);
            }
        }

        /* Light Beams */
        .light-beam {
            position: absolute;
            width: 2px;
            height: 200px;
            background: linear-gradient(to bottom, transparent, rgba(108, 183, 62, 0.3), transparent);
            -webkit-animation: beam 8s ease-in-out infinite;
            animation: beam 8s ease-in-out infinite;
        }

        .light-beam:nth-child(6) {
            left: 20%;
            -webkit-animation-delay: 0s;
            animation-delay: 0s;
        }

        .light-beam:nth-child(7) {
            left: 50%;
            -webkit-animation-delay: 2.5s;
            animation-delay: 2.5s;
        }

        .light-beam:nth-child(8) {
            left: 80%;
            -webkit-animation-delay: 5s;
            animation-delay: 5s;
        }

        @-webkit-keyframes beam {
            0%, 100% {
                transform: translateY(-100%);
                opacity: 0;
            }
            50% {
                transform: translateY(100vh);
                opacity: 1;
            }
        }

        @keyframes beam {
            0%, 100% {
                transform: translateY(-100%);
                opacity: 0;
            }
            50% {
                transform: translateY(100vh);
                opacity: 1;
            }
        }

        /* Smooth Particles */
        .smooth-particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            -webkit-animation: particleFloat 15s linear infinite;
            animation: particleFloat 15s linear infinite;
            bottom: -10px;
        }

        /* Staggered delays for particles */
        .smooth-particle:nth-child(n+9) {
            -webkit-animation-delay: calc((var(--particle-index, 0) * 0.6s));
            animation-delay: calc((var(--particle-index, 0) * 0.6s));
        }

        @-webkit-keyframes particleFloat {
            0% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(50px);
                opacity: 0;
            }
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(0) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(50px);
                opacity: 0;
            }
        }

        /* LOGIN CONTAINER */
        .login-container {
            background: rgba(15, 20, 35, 0.85);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            backdrop-filter: blur(20px) saturate(180%);
            padding: clamp(2rem, 6vw, 3.75rem);
            border-radius: clamp(16px, 4vw, 30px);
            box-shadow: 
                0 30px 90px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(108, 183, 62, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            width: calc(100% - 2rem);
            max-width: 28rem;
            position: relative;
            z-index: 10;
            -webkit-animation: containerAppear 1.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: containerAppear 1.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            border: 1px solid rgba(108, 183, 62, 0.1);
            margin: 0 auto;
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

        /* LOGO SECTION */
        .logo-container {
            text-align: center;
            margin-bottom: 50px;
            position: relative;
        }

        .logo-wrapper {
            position: relative;
            display: inline-block;
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
            -webkit-filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
            -webkit-animation: logoEntrance 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: logoEntrance 1.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @-webkit-keyframes logoEntrance {
            0% {
                opacity: 0;
                transform: scale(0) rotate(-360deg);
                filter: drop-shadow(0 0 80px rgba(108, 183, 62, 1)) blur(20px);
                -webkit-filter: drop-shadow(0 0 80px rgba(108, 183, 62, 1)) blur(20px);
            }
            40% {
                opacity: 0.5;
                transform: scale(0.5) rotate(-180deg);
                filter: drop-shadow(0 0 60px rgba(108, 183, 62, 0.8)) blur(10px);
                -webkit-filter: drop-shadow(0 0 60px rgba(108, 183, 62, 0.8)) blur(10px);
            }
            70% {
                transform: scale(1.15) rotate(15deg);
                filter: drop-shadow(0 15px 40px rgba(108, 183, 62, 0.7));
                -webkit-filter: drop-shadow(0 15px 40px rgba(108, 183, 62, 0.7));
            }
            85% {
                transform: scale(0.95) rotate(-5deg);
            }
            100% {
                opacity: 1;
                transform: scale(1) rotate(0deg);
                filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
                -webkit-filter: drop-shadow(0 10px 30px rgba(108, 183, 62, 0.5));
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
            color: #ffffff;
            margin-bottom: 12px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .welcome-subtitle {
            color: rgba(255, 255, 255, 0.6);
            font-size: 16px;
            font-weight: 400;
        }

        /* MICROSOFT BUTTON - MEGA IMPRESSIVE */
        .microsoft-button,
        .microsoft-btn {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%);
            border: 2px solid rgba(108, 183, 62, 0.2);
            border-radius: 16px;
            font-size: 17px;
            font-weight: 600;
            color: #2d2d2d;
            cursor: pointer;
            display: flex;
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
            text-decoration: none;
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
        .microsoft-button::before,
        .microsoft-btn::before {
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

        .microsoft-button:hover,
        .microsoft-btn:hover {
            transform: translateY(-5px) scale(1.02);
            border-color: rgba(108, 183, 62, 0.6);
            box-shadow: 
                0 20px 50px rgba(108, 183, 62, 0.3),
                0 0 0 1px rgba(108, 183, 62, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 1);
        }

        .microsoft-button:active,
        .microsoft-btn:active {
            transform: translateY(-2px) scale(0.98);
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

        .microsoft-button:hover .microsoft-logo div,
        .microsoft-btn:hover .microsoft-logo div {
            transform: scale(1.1);
        }

        .microsoft-logo div {
            width: 11px;
            height: 11px;
            transition: all 0.3s ease;
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

        .microsoft-button.loading .loading-spinner,
        .microsoft-btn.loading .loading-spinner {
            display: inline-block;
        }

        .microsoft-button.loading span,
        .microsoft-button.loading .microsoft-logo,
        .microsoft-btn.loading span,
        .microsoft-btn.loading .microsoft-logo {
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

        .microsoft-button.success .success-checkmark,
        .microsoft-btn.success .success-checkmark {
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

        .microsoft-button.success span,
        .microsoft-button.success .microsoft-logo,
        .microsoft-btn.success span,
        .microsoft-btn.success .microsoft-logo {
            display: none;
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

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 40px;
            color: rgba(255, 255, 255, 0.4);
            font-size: 14px;
            -webkit-animation: textSlideUp 1s ease-out 1.1s both;
            animation: textSlideUp 1s ease-out 1.1s both;
        }

        /* Responsive Design */
        
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

            .microsoft-button,
            .microsoft-btn {
                padding: 22px;
                font-size: 18px;
            }
        }

        /* Tablets & Medium screens (768px - 1024px) */
        @media (max-width: 1024px) and (min-width: 769px) {
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

        /* Tablets Portrait & Large Phones (481px - 768px) */
        @media (max-width: 768px) and (min-width: 481px) {
            .login-container {
                padding: 45px 35px;
                width: 90%;
                max-width: 450px;
                border-radius: 25px;
            }

            .ibc-logo {
                width: 130px;
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

            .microsoft-button,
            .microsoft-btn {
                padding: 18px;
                font-size: 16px;
            }
        }

        /* Smartphones (361px - 480px) */
        @media (max-width: 480px) and (min-width: 361px) {
            .login-container {
                padding: 40px 28px;
                width: 92%;
                border-radius: 22px;
            }

            .ibc-logo {
                width: 110px;
            }

            .logo-glow {
                width: 160px;
                height: 160px;
            }

            .welcome-title {
                font-size: 26px;
                margin-bottom: 10px;
            }

            .welcome-subtitle {
                font-size: 14px;
            }

            .microsoft-button,
            .microsoft-btn {
                padding: 17px;
                font-size: 15px;
                gap: 12px;
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
        }

        /* Small Smartphones (280px - 360px) */
        @media (max-width: 360px) {
            .login-container {
                padding: 35px 22px;
                width: 94%;
                border-radius: 20px;
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

            .microsoft-button,
            .microsoft-btn {
                padding: 15px;
                font-size: 14px;
                gap: 10px;
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
        }

        /* Extra Small Devices (< 280px) */
        @media (max-width: 280px) {
            .login-container {
                padding: 30px 18px;
                width: 96%;
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

            .microsoft-button,
            .microsoft-btn {
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

        /* Text shadow utilities */
        .text-shadow-strong {
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.5), 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        
        .text-shadow-medium {
            text-shadow: 0 1px 8px rgba(0, 0, 0, 0.5);
        }
        
        .text-shadow-light {
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
        }
        
        .text-shadow-footer {
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.8), 0 4px 24px rgba(0, 0, 0, 0.6), 0 1px 3px rgba(0, 0, 0, 1);
        }

        /* Old styles for compatibility - hidden/overridden */
        body::before, body::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -20%;
            width: 60%;
            height: 80%;
            background: radial-gradient(circle, rgba(0, 102, 179, 0.18) 0%, rgba(0, 102, 179, 0.08) 40%, transparent 70%);
            pointer-events: none;
            animation: pulse 10s ease-in-out infinite, drift 30s ease-in-out infinite;
            animation-delay: -5s, -15s;
        }
        
        @keyframes pulse {
            0%, 100% { 
                opacity: 1;
                transform: scale(1);
            }
            50% { 
                opacity: 0.7;
                transform: scale(1.1);
            }
        }
        
        @keyframes drift {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }
            33% {
                transform: translate(20px, -20px) scale(1.05);
            }
            66% {
                transform: translate(-20px, 20px) scale(0.95);
            }
        }
        
        /* Premium Glassmorphism Card */
        .auth-card {
            background: linear-gradient(145deg, 
                rgba(255, 255, 255, 0.09) 0%, 
                rgba(255, 255, 255, 0.05) 50%,
                rgba(255, 255, 255, 0.07) 100%
            );
            backdrop-filter: blur(30px) saturate(180%);
            -webkit-backdrop-filter: blur(30px) saturate(180%);
            border: 2px solid transparent;
            background-clip: padding-box;
            position: relative;
            border-radius: 28px;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.4),
                0 30px 60px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                inset 0 -1px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: cardEntrance 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        /* Animated gradient border */
        .auth-card::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(
                60deg,
                #00a651,
                #0066b3,
                #00a651,
                #0066b3
            );
            background-size: 300% 300%;
            border-radius: 28px;
            z-index: -1;
            animation: gradientRotate 8s ease infinite;
            opacity: 0.6;
        }
        
        @keyframes gradientRotate {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        @keyframes cardEntrance {
            0% {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* Shimmer Effect on Card */
        .auth-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                transparent 30%,
                rgba(255, 255, 255, 0.03) 50%,
                transparent 70%
            );
            animation: shimmer 6s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes shimmer {
            0%, 100% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            50% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .auth-card .bg-white {
            background: rgba(255, 255, 255, 0.15) !important;
            backdrop-filter: blur(20px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(180%) !important;
            border-radius: 24px !important;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.2),
                0 2px 8px rgba(255, 255, 255, 0.1) inset,
                0 -2px 8px rgba(0, 0, 0, 0.1) inset !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
        }
        
        /* Enhanced Floating Elements with 3D Effect */
        .floating-dot {
            position: absolute;
            border-radius: 50%;
            opacity: 0.18;
            animation: float3D 20s ease-in-out infinite;
            will-change: transform;
            filter: blur(40px);
        }
        
        .delay-0 { animation-delay: 0s; }
        .delay-3 { animation-delay: -3s; }
        .delay-7 { animation-delay: -7s; }
        .delay-14 { animation-delay: -14s; }
        
        @keyframes float3D {
            0%, 100% { 
                transform: translate(0, 0) scale(1) rotate(0deg);
            }
            25% { 
                transform: translate(30px, -40px) scale(1.1) rotate(90deg);
            }
            50% { 
                transform: translate(-20px, 20px) scale(0.9) rotate(180deg);
            }
            75% { 
                transform: translate(40px, 30px) scale(1.05) rotate(270deg);
            }
        }
        
        /* Particle System */
        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            pointer-events: none;
            animation: particleFloat 15s linear infinite;
        }
        
        .particle-1 { width: 3px; height: 3px; left: 10%; animation-delay: 0s; }
        .particle-2 { width: 2px; height: 2px; left: 20%; animation-delay: 2s; }
        .particle-3 { width: 4px; height: 4px; left: 30%; animation-delay: 4s; }
        .particle-4 { width: 2px; height: 2px; left: 40%; animation-delay: 6s; }
        .particle-5 { width: 3px; height: 3px; left: 50%; animation-delay: 8s; }
        .particle-6 { width: 2px; height: 2px; left: 60%; animation-delay: 10s; }
        .particle-7 { width: 4px; height: 4px; left: 70%; animation-delay: 12s; }
        .particle-8 { width: 3px; height: 3px; left: 80%; animation-delay: 14s; }
        .particle-9 { width: 2px; height: 2px; left: 90%; animation-delay: 16s; }
        
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
        
        /* Logo Animation */
        .logo-container {
            animation: logoFloat 3s ease-in-out infinite;
            filter: drop-shadow(0 8px 30px rgba(0, 166, 81, 0.4));
            transition: filter 0.3s ease, transform 0.3s ease;
        }
        
        .logo-container:hover {
            filter: drop-shadow(0 12px 40px rgba(0, 166, 81, 0.6));
            transform: scale(1.05);
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(2deg); }
        }
        
        /* Perfect Responsive Auth Layout */
        @media (max-width: 640px) {
            body::before,
            body::after {
                opacity: 0.4;
                width: 100%;
                height: 100%;
            }
            
            .floating-dot {
                width: 60px !important;
                height: 60px !important;
                filter: blur(30px);
            }
            
            .auth-card {
                border-radius: 16px;
                padding: 0.5rem !important;
                margin: 0 0.5rem;
            }
            
            .auth-card .bg-white {
                padding: 1.25rem !important;
                border-radius: 14px !important;
            }
            
            .particle {
                display: none;
            }
            
            .logo-container img {
                height: 4rem !important;
            }
        }
        
        /* iPhone SE and small devices - 375px */
        @media (max-width: 375px) {
            .auth-card {
                border-radius: 12px;
                padding: 0.5rem !important;
                margin: 0 0.5rem;
            }
            
            .auth-card .bg-white {
                padding: 1rem !important;
                border-radius: 12px !important;
            }
            
            .logo-container img {
                height: 3rem !important;
            }
            
            .logo-container {
                margin-bottom: 1.5rem;
            }
        }
        
        @media (min-width: 641px) and (max-width: 768px) {
            .auth-card {
                padding: 1rem !important;
            }
            
            .auth-card .bg-white {
                padding: 1.75rem !important;
                border-radius: 18px !important;
            }
            
            .floating-dot {
                width: 100px !important;
                height: 100px !important;
            }
            
            .logo-container img {
                height: 5rem !important;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .auth-card .bg-white {
                padding: 2.25rem !important;
            }
        }
        
        /* Ultra-wide screens and 4K displays */
        @media (min-width: 1920px) {
            .auth-card {
                max-width: 34rem;
            }
            
            .auth-card .bg-white {
                padding: 3rem !important;
            }
            
            .logo-container img {
                height: 8rem !important;
            }
            
            .floating-dot {
                width: 160px !important;
                height: 160px !important;
            }
        }
        
        @media (min-width: 2560px) {
            .auth-card {
                max-width: 40rem;
            }
            
            .auth-card .bg-white {
                padding: 3.5rem !important;
            }
            
            .logo-container img {
                height: 10rem !important;
            }
        }
        
        /* Touch device optimizations */
        @media (hover: none) and (pointer: coarse) {
            .auth-card:hover {
                transform: none;
            }
            
            .floating-dot {
                animation-duration: 30s;
            }
        }
        
        /* Landscape mobile optimization */
        @media (max-width: 640px) and (orientation: landscape) {
            .logo-container {
                margin-bottom: 1rem;
            }
            
            .logo-container img {
                height: 2.5rem !important;
            }
            
            .relative.z-10 {
                padding: 1rem;
            }
            
            .auth-card .bg-white {
                padding: 1rem !important;
            }
        }
        
        /* Tablet landscape optimization */
        @media (min-width: 641px) and (max-width: 1024px) and (orientation: landscape) {
            .logo-container {
                margin-bottom: 2rem;
            }
            
            .auth-card .bg-white {
                padding: 1.5rem !important;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            body {
                background: #000000;
            }
            
            .login-container,
            .auth-card {
                border: 2px solid #6cb73e;
                background: rgba(0, 0, 0, 0.95);
            }

            .microsoft-button,
            .microsoft-btn {
                border: 2px solid #2d2d2d;
            }
            
            .microsoft-button:hover,
            .microsoft-btn:hover,
            .microsoft-button:active,
            .microsoft-btn:active {
                border: 3px solid #2d2d2d;
                background: #ffffff;
            }
        }

        /* Reduced motion support for accessibility */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                -webkit-animation: none !important;
                animation: none !important;
                -webkit-transition: none !important;
                transition: none !important;
                scroll-behavior: auto !important;
            }
            
            .gradient-bg,
            .floating-orb,
            .gradient-wave,
            .animated-grid,
            .light-beam,
            .smooth-particle,
            .logo-wrapper,
            .logo-glow,
            .ibc-logo,
            .welcome-text,
            .microsoft-button,
            .microsoft-btn,
            .login-container {
                -webkit-animation: none !important;
                animation: none !important;
            }
        }
        /* Backdrop-filter fallback for browsers without support (older Firefox/Safari) */
        @supports not (backdrop-filter: blur(1px)) {
            .auth-card {
                background: rgba(10, 15, 30, 0.97) !important;
            }
            .auth-card .bg-white {
                background: rgba(15, 20, 40, 0.98) !important;
            }
            .login-container {
                background: rgba(10, 15, 30, 0.97) !important;
            }
        }

        /* ── PERFORMANCE: REDUCE ANIMATIONS ON MOBILE ──────────── */
        @media (hover: none) and (pointer: coarse) {
            /* Simplify background animation on touch devices for performance */
            .gradient-bg {
                animation-duration: 40s !important;
            }

            .floating-shape {
                animation-duration: 20s !important;
            }

            /* Disable expensive particle animations on low-power devices */
            .smooth-particle {
                display: none !important;
            }

            /* Keep logo float but simplify it */
            .logo-wrapper {
                animation-duration: 8s !important;
            }

            /* Touch: no hover effects on Microsoft button */
            .microsoft-button:hover,
            .microsoft-btn:hover {
                transform: none !important;
            }

            /* Touch: tap feedback instead */
            .microsoft-button:active,
            .microsoft-btn:active {
                transform: scale(0.97) !important;
                opacity: 0.9 !important;
            }
        }

        /* ── PERFORMANCE: HONOR prefers-reduced-motion ─────────── */
        @media (prefers-reduced-motion: reduce) {
            .gradient-bg,
            .gradient-wave,
            .animated-grid,
            .light-beam,
            .smooth-particle,
            .floating-shape {
                animation: none !important;
            }

            .logo-wrapper {
                animation: none !important;
            }

            .logo-glow {
                animation: none !important;
            }

            .ibc-logo {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
            }

            .login-container {
                animation: none !important;
                opacity: 1 !important;
                transform: none !important;
            }

            .welcome-text {
                animation: none !important;
                opacity: 1 !important;
            }

            .microsoft-button,
            .microsoft-btn {
                animation: none !important;
                opacity: 1 !important;
            }

            .microsoft-button::before,
            .microsoft-btn::before {
                animation: none !important;
            }

            .microsoft-logo {
                animation: none !important;
            }

            .auth-card {
                animation: none !important;
            }
        }

        /* ── IMPROVED VIEWPORT HEIGHT FOR MOBILE BROWSERS ──────── */
        @supports (height: 100dvh) {
            body {
                min-height: 100dvh !important;
            }
        }

        /* ── OVERSCROLL BEHAVIOR ON MOBILE ──────────────────────── */
        @media (max-width: 767px) {
            body {
                overscroll-behavior: none;
                -webkit-overflow-scrolling: touch;
            }

            /* Ensure content is always scrollable when login form is tall */
            .login-container {
                margin-top: max(1rem, env(safe-area-inset-top, 0));
                margin-bottom: max(1rem, env(safe-area-inset-bottom, 0));
            }
        }

        /* ── LIGHT BEAM STAGGER DELAY FOR PERFORMANCE ───────────── */
        @media (max-width: 767px) {
            /* Reduce number of active light beams on mobile */
            .light-beam:nth-child(7),
            .light-beam:nth-child(8) {
                display: none !important;
            }
        }

        /* ── MICROSOFT BUTTON FOCUS RING ─────────────────────────── */
        .microsoft-button:focus-visible,
        .microsoft-btn:focus-visible {
            outline: 3px solid rgba(108, 183, 62, 0.8);
            outline-offset: 3px;
        }

        /* ── SELECTION COLOR ─────────────────────────────────────── */
        /* Uses green to match the dark auth page's IBC green accents.
           Authenticated pages use IBC blue (see theme.css). */
        ::selection {
            background: rgba(108, 183, 62, 0.25);
            color: inherit;
        }

    </style>
</head>
<body class="min-h-screen dark-mode dark">
    <!-- ANIMATED BACKGROUND -->
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
        <div class="smooth-particle" style="--particle-index: 0; left: 4%;"></div>
        <div class="smooth-particle" style="--particle-index: 1; left: 8%;"></div>
        <div class="smooth-particle" style="--particle-index: 2; left: 12%;"></div>
        <div class="smooth-particle" style="--particle-index: 3; left: 16%;"></div>
        <div class="smooth-particle" style="--particle-index: 4; left: 20%;"></div>
        <div class="smooth-particle" style="--particle-index: 5; left: 24%;"></div>
        <div class="smooth-particle" style="--particle-index: 6; left: 28%;"></div>
        <div class="smooth-particle" style="--particle-index: 7; left: 32%;"></div>
        <div class="smooth-particle" style="--particle-index: 8; left: 36%;"></div>
        <div class="smooth-particle" style="--particle-index: 9; left: 40%;"></div>
        <div class="smooth-particle" style="--particle-index: 10; left: 44%;"></div>
        <div class="smooth-particle" style="--particle-index: 11; left: 48%;"></div>
        <div class="smooth-particle" style="--particle-index: 12; left: 52%;"></div>
        <div class="smooth-particle" style="--particle-index: 13; left: 56%;"></div>
        <div class="smooth-particle" style="--particle-index: 14; left: 60%;"></div>
        <div class="smooth-particle" style="--particle-index: 15; left: 64%;"></div>
        <div class="smooth-particle" style="--particle-index: 16; left: 68%;"></div>
        <div class="smooth-particle" style="--particle-index: 17; left: 72%;"></div>
        <div class="smooth-particle" style="--particle-index: 18; left: 76%;"></div>
        <div class="smooth-particle" style="--particle-index: 19; left: 80%;"></div>
        <div class="smooth-particle" style="--particle-index: 20; left: 84%;"></div>
        <div class="smooth-particle" style="--particle-index: 21; left: 88%;"></div>
        <div class="smooth-particle" style="--particle-index: 22; left: 92%;"></div>
        <div class="smooth-particle" style="--particle-index: 23; left: 96%;"></div>
        <div class="smooth-particle" style="--particle-index: 24; left: 100%;"></div>
    </div>

    <!-- Content area -->
    <?php echo $content ?? ''; ?>
    <script>
        // iOS viewport height fix
        (function() {
            function setAppHeight() {
                document.documentElement.style.setProperty('--app-height', window.innerHeight + 'px');
            }
            setAppHeight();
            window.addEventListener('resize', setAppHeight);
            window.addEventListener('orientationchange', function() {
                setTimeout(setAppHeight, 200);
            });
        })();
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo asset('sw.js'); ?>');
            });
        }
    </script>
</body>
</html>
