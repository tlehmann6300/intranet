/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './pages/**/*.php',
    './includes/**/*.php',
    './auth/**/*.php',
    './api/**/*.php',
    './js/**/*.js',
    './index.php',
  ],
  corePlugins: { preflight: false },
  darkMode: ['class', '.dark-mode'],
  theme: {
    screens: {
      'xs':  '360px',   // very small phones (Galaxy S, Pixel)
      'sm':  '640px',   // large phones / small tablets
      'md':  '768px',   // tablets (iPad portrait)
      'lg':  '1024px',  // large tablets / small desktops
      'xl':  '1280px',  // desktops
      '2xl': '1536px',  // large desktops
      '3xl': '1920px',  // Full HD
      '4xl': '2560px',  // QHD / 4K
    },
    extend: {
      colors: {
        'ibc-green':       'var(--ibc-green)',
        'ibc-green-light': 'var(--ibc-green-light)',
        'ibc-green-dark':  'var(--ibc-green-dark)',
        'ibc-blue':        'var(--ibc-blue)',
        'ibc-blue-light':  'var(--ibc-blue-light)',
        'ibc-blue-dark':   'var(--ibc-blue-dark)',
        'ibc-accent':      'var(--ibc-accent)',
        'ibc-accent-light':'var(--ibc-accent-light)',
        'ibc-accent-dark': 'var(--ibc-accent-dark)',
        'bg-card':         'var(--bg-card)',
        'bg-body':         'var(--bg-body)',
        'text-main':       'var(--text-main)',
        'text-muted':      'var(--text-muted)',
        'border-color':    'var(--border-color)',
      },
      fontFamily: {
        'sans': ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      boxShadow: {
        'glow':    'var(--shadow-glow-green)',
        'premium': 'var(--shadow-premium)',
        'card':    'var(--shadow-card)',
        'soft':    'var(--shadow-soft)',
      },
      borderRadius: {
        '4xl': '2rem',
        '5xl': '2.5rem',
      },
      spacing: {
        '18': '4.5rem',
        '22': '5.5rem',
        '26': '6.5rem',
        '30': '7.5rem',
        '34': '8.5rem',
        '38': '9.5rem',
      },
      minHeight: {
        'touch': '44px',   // minimum touch target
        'topbar': 'var(--topbar-height)',
      },
      minWidth: {
        'touch': '44px',
      },
      zIndex: {
        '60': '60',
        '70': '70',
        '80': '80',
        '90': '90',
        '100': '100',
      },
      transitionTimingFunction: {
        'spring': 'cubic-bezier(0.34, 1.56, 0.64, 1)',
        'smooth': 'cubic-bezier(0.4, 0, 0.2, 1)',
        'snappy': 'cubic-bezier(0.2, 0, 0, 1)',
      },
      transitionDuration: {
        '250': '250ms',
        '350': '350ms',
        '400': '400ms',
        '600': '600ms',
      },
      keyframes: {
        'fade-up': {
          '0%':   { opacity: '0', transform: 'translateY(16px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'fade-in': {
          '0%':   { opacity: '0' },
          '100%': { opacity: '1' },
        },
        'fade-down': {
          '0%':   { opacity: '0', transform: 'translateY(-12px) scale(0.97)' },
          '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
        },
        'scale-in': {
          '0%':   { opacity: '0', transform: 'scale(0.92)' },
          '100%': { opacity: '1', transform: 'scale(1)' },
        },
        'slide-in-left': {
          '0%':   { opacity: '0', transform: 'translateX(-20px)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
        'slide-in-right': {
          '0%':   { opacity: '0', transform: 'translateX(20px)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
        'bounce-in': {
          '0%':   { opacity: '0', transform: 'scale(0.3)' },
          '50%':  { opacity: '1', transform: 'scale(1.05)' },
          '70%':  { transform: 'scale(0.9)' },
          '100%': { transform: 'scale(1)' },
        },
        'pulse-ring': {
          '0%':   { transform: 'scale(1)', opacity: '0.8' },
          '50%':  { transform: 'scale(1.08)', opacity: '0.5' },
          '100%': { transform: 'scale(1)', opacity: '0.8' },
        },
        'shimmer': {
          '0%':   { backgroundPosition: '-200% 0' },
          '100%': { backgroundPosition: '200% 0' },
        },
        'spin-slow': {
          '0%':   { transform: 'rotate(0deg)' },
          '100%': { transform: 'rotate(360deg)' },
        },
      },
      animation: {
        'fade-up':        'fade-up 0.42s cubic-bezier(0.22, 0.61, 0.36, 1) both',
        'fade-in':        'fade-in 0.35s ease both',
        'fade-down':      'fade-down 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) both',
        'scale-in':       'scale-in 0.32s cubic-bezier(0.34, 1.56, 0.64, 1) both',
        'slide-in-left':  'slide-in-left 0.4s cubic-bezier(0.22, 0.61, 0.36, 1) both',
        'slide-in-right': 'slide-in-right 0.4s cubic-bezier(0.22, 0.61, 0.36, 1) both',
        'bounce-in':      'bounce-in 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both',
        'pulse-ring':     'pulse-ring 2.5s ease-in-out infinite',
        'shimmer':        'shimmer 2s linear infinite',
        'spin-slow':      'spin-slow 3s linear infinite',
      },
    },
  },
  safelist: [
    // Text utilities
    'break-words', 'break-all', 'break-keep',
    'hyphens-auto', 'hyphens-manual', 'hyphens-none',
    'leading-relaxed', 'leading-loose', 'leading-tight', 'leading-snug',
    'truncate', 'line-clamp-1', 'line-clamp-2', 'line-clamp-3',

    // Sidebar toggle classes (added via JavaScript)
    'translate-x-0', '-translate-x-full',

    // Display toggling via JS
    'hidden', 'block', 'flex', 'grid', 'inline', 'inline-flex', 'inline-block',

    // Visibility toggling via JS
    'opacity-0', 'opacity-100', 'invisible', 'visible',
    'pointer-events-none', 'pointer-events-auto',

    // Animation classes
    'animate-fade-up', 'animate-fade-in', 'animate-fade-down',
    'animate-scale-in', 'animate-slide-in-left', 'animate-slide-in-right',
    'animate-bounce-in', 'animate-pulse-ring', 'animate-shimmer', 'animate-spin-slow',
    'animate-pulse', 'animate-spin', 'animate-bounce', 'animate-ping',
    'animate-none',

    // Transition & timing
    'transition', 'transition-all', 'transition-colors', 'transition-opacity',
    'transition-transform', 'transition-shadow',
    'duration-150', 'duration-200', 'duration-250', 'duration-300',
    'duration-350', 'duration-400', 'duration-500', 'duration-600',
    'ease-in', 'ease-out', 'ease-in-out', 'ease-spring', 'ease-smooth', 'ease-snappy',

    // Colors used dynamically
    { pattern: /^text-(red|green|blue|yellow|orange|purple|gray|slate|emerald|indigo|violet|pink|rose|teal|cyan|sky|lime|amber|fuchsia|zinc|neutral|stone)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
    { pattern: /^bg-(red|green|blue|yellow|orange|purple|gray|slate|emerald|indigo|violet|pink|rose|teal|cyan|sky|lime|amber|fuchsia|zinc|neutral|stone)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
    { pattern: /^border-(red|green|blue|yellow|orange|purple|gray|slate|emerald|indigo|violet|pink|rose|teal|cyan|sky|lime|amber|fuchsia|zinc|neutral|stone)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
    { pattern: /^(from|to|via)-(red|green|blue|yellow|orange|purple|gray|slate|emerald|indigo|violet|pink|rose|teal|cyan|sky|lime|amber|fuchsia|zinc|neutral|stone)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
    { pattern: /^(text|bg|border)-ibc-(blue|blue-light|blue-dark|green|green-light|green-dark|accent|accent-light|accent-dark)$/ },

    // Responsive grid helpers – use variants format for Tailwind v3
    { pattern: /^grid-cols-(1|2|3|4|5|6|7|8|9|10|11|12)$/, variants: ['xs', 'sm', 'md', 'lg', 'xl', '2xl'] },
    { pattern: /^col-span-(1|2|3|4|5|6|7|8|9|10|11|12|full)$/, variants: ['xs', 'sm', 'md', 'lg', 'xl', '2xl'] },

    // Flex helpers used dynamically
    { pattern: /^(flex|items|justify|self|content|place)-(start|end|center|between|around|evenly|stretch|baseline)$/ },
    { pattern: /^flex-(row|col|row-reverse|col-reverse|wrap|nowrap|wrap-reverse|1|auto|none|grow|shrink)$/ },

    // Spacing helpers used dynamically – use variants format for responsive
    { pattern: /^(p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml|gap|gap-x|gap-y)-(0|0\.5|1|1\.5|2|2\.5|3|3\.5|4|5|6|7|8|9|10|11|12|14|16|18|20|22|24|28|32|36|40|44|48|52|56|60|64|72|80|96)$/ },
    { pattern: /^(p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml|gap)-(0|1|2|3|4|5|6|7|8|9|10|11|12|14|16|18|20|24|28|32)$/, variants: ['xs', 'sm', 'md', 'lg', 'xl', '2xl'] },

    // Sizing
    { pattern: /^(w|h|min-w|min-h|max-w|max-h)-(0|1|2|3|4|5|6|7|8|9|10|11|12|14|16|20|24|28|32|36|40|44|48|52|56|60|64|72|80|96|px|0\.5|1\.5|2\.5|3\.5|auto|full|screen|min|max|fit|touch|topbar)$/ },
    // Responsive w/h helpers used in JS
    { pattern: /^w-(\d+|auto|full|screen|fit|min|max)$/, variants: ['sm', 'md', 'lg', 'xl', '2xl'] },
    { pattern: /^h-(\d+|auto|full|screen|fit|min|max)$/, variants: ['sm', 'md', 'lg', 'xl', '2xl'] },

    // Rounded
    { pattern: /^rounded(-none|-sm|-md|-lg|-xl|-2xl|-3xl|-4xl|-5xl|-full)?$/ },

    // Shadow
    { pattern: /^shadow(-sm|-md|-lg|-xl|-2xl|-inner|-none|-card|-soft|-glow|-premium)?$/ },

    // Z-index
    { pattern: /^z-(0|10|20|30|40|50|60|70|80|90|100|auto)$/ },

    // Overflow
    { pattern: /^overflow(-x|-y)?-(auto|hidden|visible|scroll|clip)$/ },

    // Position
    'relative', 'absolute', 'fixed', 'sticky',
    { pattern: /^(inset|top|right|bottom|left)-(0|0\.5|1|2|3|4|5|6|7|8|9|10|11|12|auto|full|px)$/ },

    // Border & ring
    { pattern: /^border(-[0248])?$/ },
    { pattern: /^ring(-[01248])?$/ },
    { pattern: /^ring-offset(-[124])?$/ },

    // Cursor
    'cursor-pointer', 'cursor-not-allowed', 'cursor-default', 'cursor-wait',

    // User select
    'select-none', 'select-text', 'select-all', 'select-auto',

    // Whitespace
    'whitespace-normal', 'whitespace-nowrap', 'whitespace-pre', 'whitespace-pre-wrap',

    // Misc
    'sr-only', 'not-sr-only', 'appearance-none', 'outline-none',
    'resize-none', 'resize', 'resize-x', 'resize-y',
    'list-none', 'list-disc', 'list-decimal',
    'object-cover', 'object-contain', 'object-fill', 'object-center',
    'aspect-square', 'aspect-video',

    // dark-mode is a class, not a Tailwind variant prefix
    'dark-mode',
  ],
  plugins: [],
};
