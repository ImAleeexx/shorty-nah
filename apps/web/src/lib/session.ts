import { apiGet } from '@/lib/server-api';

export type Viewer = {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'admin' | 'member' | 'viewer';
  two_factor?: { required: boolean; enrolled: boolean };
};

/**
 * Whether this account is confined to enrolling a second factor.
 *
 * Every route past the requirement answers 403, which tells a page something is
 * wrong but not what to do about it. Reading it from the session means a page
 * can send the operator somewhere useful instead of rendering a refusal.
 */
export function mustEnrolSecondFactor(viewer: Viewer): boolean {
  return viewer.two_factor?.required === true && viewer.two_factor.enrolled === false;
}

/**
 * The signed-in operator, or null.
 *
 * Resolved on the server from the forwarded session cookie so a page knows what
 * the viewer may do before it renders anything, rather than rendering a shell
 * and then correcting it.
 */
export async function currentViewer(): Promise<Viewer | null> {
  const result = await apiGet<{ user: Viewer }>('/api/v1/auth/user');

  return result.ok ? result.data.user : null;
}

export function administrates(viewer: Viewer | null): boolean {
  return viewer !== null && (viewer.role === 'owner' || viewer.role === 'admin');
}

export function owns(viewer: Viewer | null): boolean {
  return viewer !== null && viewer.role === 'owner';
}

export function mayWrite(viewer: Viewer | null): boolean {
  return viewer !== null && viewer.role !== 'viewer';
}
