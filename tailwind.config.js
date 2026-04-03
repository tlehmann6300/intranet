/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './pages/**/*.php',
    './includes/**/*.php',
    './auth/**/*.php',
    './api/**/*.php',
    './index.php',
  ],
  corePlugins: { preflight: false },
  darkMode: ['class', '.dark-mode'],
  theme: {
    extend: {
      colors: {
        'ibc-green': 'var(--ibc-green)',
        'ibc-green-light': 'var(--ibc-green-light)',
        'ibc-green-dark': 'var(--ibc-green-dark)',
        'ibc-blue': 'var(--ibc-blue)',
        'ibc-blue-light': 'var(--ibc-blue-light)',
        'ibc-blue-dark': 'var(--ibc-blue-dark)',
        'ibc-accent': 'var(--ibc-accent)',
        'ibc-accent-light': 'var(--ibc-accent-light)',
        'ibc-accent-dark': 'var(--ibc-accent-dark)',
      },
      fontFamily: {
        'sans': ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      boxShadow: {
        'glow': 'var(--shadow-glow-green)',
        'premium': 'var(--shadow-premium)',
      },
    },
  },
  safelist: [
    'break-words',
    'break-all',
    'hyphens-auto',
    'hyphens-manual',
    'hyphens-none',
    'leading-relaxed',
    'leading-loose',
    // Sidebar mobile menu toggle classes added via JavaScript
    'translate-x-0',
    '-translate-x-full',
    // User dropdown toggle class
    'open',
    // Enterprise glassmorphism / skeleton / card utilities
    'glass-sidebar',
    'glass-topbar',
    'glass-bottom-nav',
    'glass-panel',
    'glass-panel-dark',
    'card-enterprise',
    'skeleton-enterprise',
    'skeleton',
    'btn-enterprise',
    'role-badge',
    'sidebar-nav-item',
    'mobile-bottom-nav-item',
    'user-dropdown-item',
    'gradient-sidebar',
    'gradient-sidebar-dark',
    'glow-blue',
    'glow-green',
    // Transition utilities used on dynamic elements
    'transition-all',
    'duration-300',
    'ease-in-out',
    // Backdrop blur (used in glassmorphism CSS)
    'backdrop-blur-sm',
    'backdrop-blur-md',
    'backdrop-blur-lg',
    'backdrop-blur-xl',
    'backdrop-blur-2xl',
    // Dark-mode body/layout classes applied via JS
    'dark:bg-gray-900',
    'dark:text-white',
  ],
  plugins: [],
};
