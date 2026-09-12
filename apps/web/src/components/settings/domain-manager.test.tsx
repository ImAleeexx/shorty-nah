import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DomainManager } from '@/components/settings/domain-manager';
import type { DomainRecord } from '@/lib/links';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: vi.fn() }),
}));

function domain(overrides: Partial<DomainRecord>): DomainRecord {
  return {
    id: '01J0000000000000000000000',
    host: 'go.example.com',
    verified: true,
    primary: false,
    serves_links: true,
    link_count: 0,
    last_checked_at: null,
    last_failure: null,
    verification: {
      type: 'TXT',
      name: '_shortynah-verify.go.example.com',
      value: 'shortynah-verify=tok3n',
    },
    ...overrides,
  };
}

/**
 * Verification is a DNS record the operator has to publish, so the record has
 * to be on the screen for as long as the domain is unverified — a token shown
 * once at registration and never again is a token that gets lost.
 */
describe('DomainManager', () => {
  afterEach(cleanup);

  it('shows the record an unverified domain is waiting for', () => {
    render(<DomainManager domains={[domain({ verified: false, serves_links: false })]} />);

    const record = screen.getByTestId('verification-record-go.example.com');

    expect(record).toHaveTextContent('TXT');
    expect(record).toHaveTextContent('_shortynah-verify.go.example.com');
    expect(record).toHaveTextContent('shortynah-verify=tok3n');
  });

  it('does not clutter a verified domain with it', () => {
    render(<DomainManager domains={[domain({ verified: true })]} />);

    expect(screen.queryByTestId('verification-record-go.example.com')).toBeNull();
  });
});
