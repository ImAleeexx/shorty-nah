'use client';

import NextLink from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Copy, MagnifyingGlass, Plus, Trash } from '@/components/icons';
import { TransferControls } from '@/components/links/transfer-controls';
import { LinkSheet } from '@/components/links/link-sheet';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input, Select } from '@/components/ui/field';
import { apiRequest } from '@/lib/client-api';
import type { DomainRecord, LinkRecord } from '@/lib/links';

/**
 * The list, its filters and the editing surface.
 *
 * Filtering resolves against the page the server already sent rather than
 * round-tripping per keystroke — that latency is the one thing a server-first
 * interface does badly, and the API's own filters exist for the cases that
 * genuinely need the whole corpus.
 */
export function LinkTable({
  initial,
  domains,
  canWrite,
}: {
  initial: LinkRecord[];
  domains: DomainRecord[];
  canWrite: boolean;
}) {
  const router = useRouter();
  const params = useSearchParams();
  const [search, setSearch] = useState('');
  const [domain, setDomain] = useState('');
  const [tag, setTag] = useState('');
  const [editing, setEditing] = useState<LinkRecord | null>(null);
  // Opened on arrival when another page asked for it. The sheet lives here
  // because this is the list that has to refresh once a link exists.
  const [open, setOpen] = useState(params.get('new') === '1');

  const tags = useMemo(
    () => [...new Set(initial.flatMap((link) => link.tags))].sort((a, b) => a.localeCompare(b)),
    [initial],
  );

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase();

    return initial.filter((link) => {
      if (domain !== '' && link.domain !== domain) {
        return false;
      }

      if (tag !== '' && !link.tags.includes(tag)) {
        return false;
      }

      if (term === '') {
        return true;
      }

      return (
        link.slug.toLowerCase().includes(term) ||
        link.destination.toLowerCase().includes(term) ||
        link.tags.some((entry) => entry.toLowerCase().includes(term))
      );
    });
  }, [initial, search, domain, tag]);

  async function copy(link: LinkRecord) {
    if (link.short_url === null) {
      return;
    }

    await navigator.clipboard.writeText(link.short_url);

    // A fixed id, so hammering copy replaces the toast in place rather than
    // stacking a queue of identical ones or restarting a new timer each time.
    toast.success('Link copied', { id: `copy-${link.id}`, description: link.short_url });
  }

  async function remove(link: LinkRecord) {
    const result = await apiRequest(`/api/v1/links/${link.id}`, { method: 'DELETE' });

    if (!result.ok) {
      toast.error('That link could not be deleted', {
        id: `delete-${link.id}`,
        description: result.message,
      });

      return;
    }

    toast.success(`/${link.slug} deleted`, { id: `delete-${link.id}` });
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-ink text-xl font-semibold tracking-tight">Links</h1>
          <p className="text-ink-muted mt-1 text-sm">
            {initial.length === 0
              ? 'Nothing here yet.'
              : `${visible.length} of ${initial.length} shown.`}
          </p>
        </div>

        {canWrite ? (
          <div className="flex flex-wrap items-center gap-2">
            <TransferControls domains={domains} />

            <Button
              intent="primary"
              size="md"
              onClick={() => {
                setEditing(null);
                setOpen(true);
              }}
              data-testid="new-link"
            >
              <Plus size={15} />
              New link
            </Button>
          </div>
        ) : null}
      </div>

      {initial.length > 0 ? (
        <div className="flex flex-wrap gap-3">
          <div className="relative min-w-56 flex-1">
            <MagnifyingGlass
              size={15}
              className="text-ink-subtle pointer-events-none absolute top-1/2 left-3 -translate-y-1/2"
            />
            <Input
              className="pl-9"
              placeholder="Search slugs, destinations and tags"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              aria-label="Search links"
              data-testid="link-search"
            />
          </div>

          <Select
            className="w-auto"
            value={domain}
            onChange={(event) => setDomain(event.target.value)}
            aria-label="Filter by domain"
            data-testid="filter-domain"
          >
            <option value="">All domains</option>
            {domains.map((entry) => (
              <option key={entry.id} value={entry.host}>
                {entry.host}
              </option>
            ))}
          </Select>

          {tags.length > 0 ? (
            <Select
              className="w-auto"
              value={tag}
              onChange={(event) => setTag(event.target.value)}
              aria-label="Filter by tag"
              data-testid="filter-tag"
            >
              <option value="">All tags</option>
              {tags.map((entry) => (
                <option key={entry} value={entry}>
                  {entry}
                </option>
              ))}
            </Select>
          ) : null}
        </div>
      ) : null}

      {visible.length === 0 ? (
        <EmptyState
          title={initial.length === 0 ? 'No links yet' : 'Nothing matches'}
          description={
            initial.length === 0
              ? 'Create the first one and it will appear here with its click count.'
              : 'Try a shorter search, or clear the domain and tag filters.'
          }
          action={
            initial.length === 0 ? (
              canWrite ? (
                <Button
                  intent="primary"
                  size="md"
                  onClick={() => {
                    setEditing(null);
                    setOpen(true);
                  }}
                >
                  <Plus size={15} />
                  Create a link
                </Button>
              ) : null
            ) : (
              <Button
                intent="outline"
                size="md"
                onClick={() => {
                  setSearch('');
                  setDomain('');
                  setTag('');
                }}
              >
                Clear filters
              </Button>
            )
          }
        />
      ) : (
        <ul className="border-border divide-border divide-y rounded-(--radius-token) border">
          {visible.map((link) => (
            <li
              key={link.id}
              className="flex flex-wrap items-center gap-4 px-4 py-3"
              data-testid="link-row"
              data-slug={link.slug}
            >
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="tabular text-ink text-sm">/{link.slug}</span>
                  {link.disabled ? <span className="text-critical text-xs">disabled</span> : null}
                  {link.password_protected ? (
                    <span className="text-ink-subtle text-xs">password</span>
                  ) : null}
                  {link.effective_redirect_mode === 'direct' ? null : (
                    <span className="text-ink-subtle text-xs">{link.effective_redirect_mode}</span>
                  )}
                </div>
                <p className="text-ink-muted mt-0.5 truncate text-xs">{link.destination}</p>
                {link.tags.length > 0 ? (
                  <p className="text-ink-subtle mt-1 text-xs">{link.tags.join(' · ')}</p>
                ) : null}
              </div>

              <span className="tabular text-ink-muted text-xs">{link.click_count}</span>

              <div className="flex items-center gap-1">
                <NextLink
                  href={`/links/${link.id}`}
                  className="text-ink-muted hover:text-ink rounded-(--radius-token-sm) px-3 py-1.5 text-sm"
                  data-testid={`report-${link.slug}`}
                >
                  Report
                </NextLink>

                <Button
                  intent="ghost"
                  size="icon"
                  aria-label={`Copy ${link.slug}`}
                  onClick={() => void copy(link)}
                  disabled={link.short_url === null}
                >
                  <Copy size={15} />
                </Button>

                {canWrite ? (
                  <>
                    <Button
                      intent="ghost"
                      size="sm"
                      onClick={() => {
                        setEditing(link);
                        setOpen(true);
                      }}
                      data-testid={`edit-${link.slug}`}
                    >
                      Edit
                    </Button>
                    <Button
                      intent="ghost"
                      size="icon"
                      aria-label={`Delete ${link.slug}`}
                      onClick={() => void remove(link)}
                    >
                      <Trash size={15} />
                    </Button>
                  </>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}

      {canWrite ? (
        <LinkSheet open={open} onOpenChange={setOpen} domains={domains} link={editing} />
      ) : null}
    </div>
  );
}
