import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,

  // Override default ignores of eslint-config-next.
  globalIgnores([
    '.next/**',
    'out/**',
    'build/**',
    'next-env.d.ts',
    '.pnpm-store/**',
    '**/*.css',
    'playwright-report/**',
    'test-results/**',
  ]),

  {
    rules: {
      /*
       * Icons come from one module at one weight. Importing Phosphor directly
       * bypasses that, and every other icon set is a different visual language.
       */
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: 'lucide-react',
              message:
                'Icons come from @/components/icons — Phosphor at one weight is the instance set.',
            },
            { name: 'react-icons', message: 'Icons come from @/components/icons.' },
            { name: '@heroicons/react', message: 'Icons come from @/components/icons.' },
            {
              name: '@phosphor-icons/react',
              message: 'Import from @/components/icons so the weight stays consistent.',
            },
            {
              name: '@phosphor-icons/react/dist/ssr',
              message: 'Import from @/components/icons so the weight stays consistent.',
            },
            {
              name: 'tailwind-merge',
              message: 'Express variants with cva rather than defeating earlier classes.',
            },
          ],
          patterns: [
            {
              group: ['next/font/google'],
              message:
                'Typefaces are self-hosted from npm so a build needs no network. See src/lib/fonts.ts.',
            },
          ],
        },
      ],

      /*
       * The motion contract, enforced where lint can see it. Each of these is a
       * defect a review would otherwise have to catch by eye every time.
       */
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Literal[value=/transition:\\s*all/]',
          message: '`transition: all` animates layout properties too. Name the exact properties.',
        },
        {
          selector: 'Literal[value=/transition-all/]',
          message: 'Name the exact properties rather than using transition-all.',
        },
        {
          // Tailwind v4 reads a CSS variable through parentheses. In brackets it
          // is taken as a literal value, so the declaration is invalid and the
          // duration silently resolves to zero — which is how every transition
          // in this interface came to be instant without anything failing.
          selector: 'Literal[value=/duration-\\[--/]',
          message:
            'Read a duration token through parentheses: duration-(--name). In brackets it resolves to 0s in Tailwind v4.',
        },
        {
          selector: 'Literal[value=/scale\\(0(\\.0+)?\\)/]',
          message: 'Entrances start from scale(0.95) with opacity 0. Nothing appears from nothing.',
        },
        {
          selector: 'Literal[value=/(^|\\s)scale-0($|\\s)/]',
          message: 'Entrances start from scale-95, not scale-0.',
        },
        {
          selector: 'Literal[value=/(^|\\s|:)ease-in($|\\s|"|\')/]',
          message: 'ease-in delays the first moment a viewer is watching. Use ease-out.',
        },
        {
          selector: 'Literal[value=/cubic-bezier\\(/]',
          message: 'Curves come from the token layer: --ease-out, --ease-in-out, --ease-drawer.',
        },
        {
          selector:
            'Literal[value=/transition:[^;]*\\b(width|height|top|left|right|bottom|margin|padding)\\b/]',
          message: 'Animate transform and opacity only — those skip layout and paint.',
        },
        {
          selector: 'Literal[value=/duration-\\[\\d+ms\\]/]',
          message: 'Durations come from the token layer, not from an arbitrary value.',
        },
      ],
    },
  },

  {
    // The one module allowed to import the icon library: everything else imports
    // from here, which is the point of the rule above.
    files: ['src/components/icons.ts'],
    rules: { 'no-restricted-imports': 'off' },
  },

  {
    // The token layer is where curves and durations are defined, so it is the one
    // place they may appear literally.
    // The token layer defines the curves; its test asserts which ones exist and
    // therefore has to name the one that must not.
    files: ['src/lib/motion.ts', 'src/lib/motion.test.ts'],
    rules: { 'no-restricted-syntax': 'off' },
  },

  {
    // This file states the forbidden patterns, so it necessarily contains them.
    files: ['eslint.config.mjs'],
    rules: { 'no-restricted-syntax': 'off' },
  },
]);

export default eslintConfig;
