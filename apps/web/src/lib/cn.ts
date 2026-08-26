import { type ClassValue, clsx } from 'clsx';

/**
 * Conditional class strings.
 *
 * Deliberately not a Tailwind merge helper: this project's components express
 * their variants through cva, so a later class does not need to defeat an earlier
 * one. Reaching for a merge is usually a sign a variant is missing.
 */
export function cn(...inputs: ClassValue[]): string {
  return clsx(inputs);
}
