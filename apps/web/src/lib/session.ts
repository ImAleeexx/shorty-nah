import { apiGet } from '@/lib/server-api';

export type Viewer = {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'admin' | 'member' | 'viewer';
};

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

export function mayWrite(viewer: Viewer | null): boolean {
  return viewer !== null && viewer.role !== 'viewer';
}
