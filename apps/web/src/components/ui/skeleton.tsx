import { cn } from '@/lib/cn';

/**
 * A placeholder shaped like the thing it stands in for.
 *
 * No pulse. A shimmer is motion on a surface an operator passes through dozens of
 * times a day, which the motion contract rules out. Holding the layout still is
 * the part that actually helps.
 *
 * Used inside a page, never as a route-level `loading.tsx`. A route-level one
 * puts the segment behind a Suspense boundary, which makes Next stream the
 * response — and once the shell has flushed with a 200, a `redirect()` or
 * `notFound()` later in the render cannot change the status. Every page here
 * checks the session while rendering, so a root `loading.tsx` turned a
 * signed-out request for the dashboard into a 200.
 */
export function Skeleton({ className }: { className?: string }) {
  return (
    <div aria-hidden className={cn('bg-accent-muted rounded-(--radius-token-sm)', className)} />
  );
}
