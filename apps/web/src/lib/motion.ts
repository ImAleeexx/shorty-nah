/**
 * The motion contract, in one place.
 *
 * The governing rule is frequency: the more often a viewer sees an animation, the
 * shorter it must be, and past a threshold it should not exist at all. A dashboard
 * is opened dozens of times a day, so most of this file is about restraint rather
 * than expression.
 *
 * This is the only module allowed to name curves and durations literally. Every
 * other file reads them from here or from the CSS token layer, which lint
 * enforces.
 */

export const EASE = {
  /** Entrances and anything the viewer is waiting on: starts fast, feels answered. */
  out: 'cubic-bezier(0.23, 1, 0.32, 1)',
  /** Something already on screen moving to a new position. */
  inOut: 'cubic-bezier(0.77, 0, 0.175, 1)',
  /** Sheets and drawers, borrowed from iOS. */
  drawer: 'cubic-bezier(0.32, 0.72, 0, 1)',
} as const;

export const DURATION = {
  /** Press feedback. Long enough to notice, short enough to feel immediate. */
  press: 140,
  tooltip: 160,
  popover: 200,
  sheet: 260,
} as const;

/**
 * Surfaces that deliberately do not animate.
 *
 * A command palette is opened a hundred times a day; an animation there makes the
 * whole interface feel slow. Table rows re-render on every sort and filter, and
 * transitioning them turns a data change into a wait.
 */
export const NEVER_ANIMATED = [
  'command-palette',
  'keyboard-navigation',
  'table-sort',
  'table-filter',
  'table-pagination',
] as const;

/** Springs, for the few places a gesture carries velocity. */
export const SPRING = {
  /** Apple's formulation: easier to reason about than stiffness and damping. */
  gentle: { type: 'spring', duration: 0.4, bounce: 0.1 },
  /** Drag-to-dismiss, where a little overshoot reads as physical. */
  playful: { type: 'spring', duration: 0.5, bounce: 0.25 },
} as const;

/**
 * Exit is faster than enter, everywhere.
 *
 * A viewer waiting to see something tolerates a moment; a viewer who has decided
 * to dismiss it does not.
 */
export function exitDuration(enter: number): number {
  return Math.round(enter * 0.7);
}
