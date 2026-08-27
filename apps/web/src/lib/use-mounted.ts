'use client';

import { useSyncExternalStore } from 'react';

const subscribe = () => () => {};

/**
 * Whether the component has hydrated.
 *
 * `useSyncExternalStore` rather than a `useState` + `useEffect` pair: the server
 * snapshot is what React uses for both the server render and the hydration
 * render, so the two agree by construction. The effect version renders `true`
 * during hydration on the client and `false` on the server, which is the
 * mismatch it is meant to prevent.
 */
export function useMounted(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );
}
