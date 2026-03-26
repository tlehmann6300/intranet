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
  ],
  plugins: [],
};
