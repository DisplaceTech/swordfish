/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        surface: {
          base: '#030712',    // gray-950 — page background
          raised: '#111827',  // gray-900 — cards, nav
          overlay: '#1f2937', // gray-800 — hover surfaces, borders
        },
        border: {
          DEFAULT: '#1f2937', // gray-800
          subtle: '#374151',  // gray-700
        },
        content: {
          primary: '#f3f4f6',  // gray-100
          secondary: '#9ca3af', // gray-400
          muted: '#6b7280',    // gray-500
        },
        accent: {
          DEFAULT: '#6366f1',  // indigo-500 — interactive elements
          muted: '#818cf8',    // indigo-400 — icons, step numbers
          surface: '#1e1b4b',  // indigo-950 — accent backgrounds
          border: '#4338ca',   // indigo-700 — accent borders
          text: '#a5b4fc',     // indigo-300 — accent headings
        },
        danger: {
          DEFAULT: '#ef4444',  // red-500
          surface: '#450a0a',  // red-950
          border: '#991b1b',   // red-800
          text: '#fca5a5',     // red-300
        },
        success: {
          DEFAULT: '#22c55e',  // green-500
          surface: '#052e16',  // green-950
          border: '#166534',   // green-800
          text: '#86efac',     // green-300
        },
      },
      fontFamily: {
        sans: [
          'Inter',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'BlinkMacSystemFont',
          'Segoe UI',
          'sans-serif',
        ],
        mono: [
          'JetBrains Mono',
          'ui-monospace',
          'SFMono-Regular',
          'Menlo',
          'Monaco',
          'Consolas',
          'monospace',
        ],
      },
      borderRadius: {
        card: '0.75rem', // 12px — consistent card rounding
      },
      spacing: {
        'page-x': '1rem',     // horizontal page padding
        'section': '3rem',    // vertical section spacing
        'card': '1.5rem',     // card internal padding
      },
      boxShadow: {
        card: '0 1px 3px 0 rgb(0 0 0 / 0.4), 0 1px 2px -1px rgb(0 0 0 / 0.4)',
        'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.5), 0 2px 4px -2px rgb(0 0 0 / 0.5)',
        'input-focus': '0 0 0 3px rgb(99 102 241 / 0.25)',
      },
    },
  },
  plugins: [],
}
