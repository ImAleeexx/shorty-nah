export type LinkRecord = {
  id: string;
  slug: string;
  destination: string;
  domain: string | null;
  short_url: string | null;
  redirect_mode: 'direct' | 'interstitial' | null;
  effective_redirect_mode: 'direct' | 'interstitial';
  password_protected: boolean;
  expires_at: string | null;
  max_clicks: number | null;
  click_count: number;
  disabled: boolean;
  resolvable: boolean;
  tags: string[];
  created_at: string;
};

export type LinkPage = {
  links: LinkRecord[];
  meta: { page: number; per_page: number; total: number };
};

export type DomainRecord = {
  id: string;
  host: string;
  verified: boolean;
  primary: boolean;
};

/**
 * The payload the create and edit forms send.
 *
 * Optional values are sent as null rather than omitted: the API distinguishes
 * "leave this alone" from "clear it", and an omitted key reads as the former.
 */
export type LinkInput = {
  destination: string;
  domain: string | null;
  slug: string | null;
  redirect_mode: string | null;
  password: string | null;
  expires_at: string | null;
  max_clicks: number | null;
  tags: string[];
};
