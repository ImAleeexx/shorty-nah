import { GeistMono } from 'geist/font/mono';
import { GeistSans } from 'geist/font/sans';
import localFont from 'next/font/local';

/**
 * The typeface trio.
 *
 * Self-hosted from npm rather than fetched from a font service: a build must not
 * need the network, and a viewer must not be announced to a third party on first
 * paint.
 *
 * Inter, Roboto, Helvetica and Open Sans are deliberately absent. A lint rule
 * enforces that; this is where it would otherwise creep in.
 */
const editorial = localFont({
  src: [
    {
      path: '../../node_modules/@fontsource/instrument-serif/files/instrument-serif-latin-400-normal.woff2',
      weight: '400',
      style: 'normal',
    },
    {
      path: '../../node_modules/@fontsource/instrument-serif/files/instrument-serif-latin-400-italic.woff2',
      weight: '400',
      style: 'italic',
    },
  ],
  variable: '--font-editorial',
  display: 'swap',
  // Measured against Instrument Serif so a late swap does not shift the line.
  fallback: ['Georgia', 'ui-serif', 'serif'],
});

export const fontVariables = [GeistSans.variable, GeistMono.variable, editorial.variable].join(' ');
