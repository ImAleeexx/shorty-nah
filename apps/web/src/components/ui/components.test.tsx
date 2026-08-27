import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Stat } from '@/components/ui/stat';

/**
 * The page itself is an async server component and is covered by the browser
 * suite; these are the client components, where a unit test is the right tool.
 */
describe('Button', () => {
  it('presses on every variant', () => {
    for (const intent of ['primary', 'accent', 'outline', 'ghost', 'critical'] as const) {
      const { container, unmount } = render(<Button intent={intent}>Act</Button>);
      const className = container.firstElementChild?.className ?? '';

      // The one animation that belongs on a surface used constantly: without it
      // the interface does not feel like it heard the viewer.
      expect(className, intent).toContain('active:scale-[0.98]');
      unmount();
    }
  });

  it('names the properties it transitions', () => {
    const { container } = render(<Button>Act</Button>);
    const className = container.firstElementChild?.className ?? '';

    // box-shadow is named too, because elevation is now part of what a control
    // changes on hover and press — an unnamed property is a property that does
    // not animate.
    expect(className).toContain(
      'transition-[transform,background-color,border-color,color,box-shadow]',
    );
    /* eslint-disable-next-line no-restricted-syntax -- the assertion that keeps
       transition-all out of the components has to name it to look for it. */
    expect(className).not.toContain('transition-all');
  });

  it('takes a duration from the token layer, through parentheses', () => {
    const { container } = render(<Button>Act</Button>);

    // The bracket form is Tailwind v3 and resolves to 0s in v4, so asserting it
    // pinned every transition in the interface at zero. A lint rule now refuses
    // it; this asserts the form that actually applies.
    expect(container.firstElementChild?.className).toContain('duration-(--duration-press)');
  });
});

describe('Card', () => {
  it('separates itself by elevation from the token layer, not by a border', () => {
    const { container } = render(
      <Card>
        <CardHeader title="Links" description="All of them" />
        <CardBody>Body</CardBody>
      </Card>,
    );

    const className = container.firstElementChild?.className ?? '';

    // This test used to assert the opposite — a hairline border and no shadow.
    // The direction changed: a card is now separated by elevation, and carrying
    // both a line and a shadow is what makes one look like an outline drawn
    // round a photograph of itself.
    expect(className).toContain('shadow-(--shadow-card)');
    expect(className).not.toContain('border-border');

    // The ban on Tailwind's stock shadows survives the change, and means more
    // now than it did: those are untinted black at low opacity, which is the
    // grey haze this system exists to avoid. Every shadow here comes from a
    // token that carries the canvas hue.
    expect(className).not.toMatch(/shadow-(sm|md|lg|xl|2xl)\b/);

    expect(screen.getByText('Links')).toBeInTheDocument();
    expect(screen.getByText('All of them')).toBeInTheDocument();
  });
});

describe('Stat', () => {
  it('renders a static figure without animating on load', () => {
    render(<Stat label="Clicks" value={1234} />);

    // A count-up on load is decoration applied to the number the viewer came to
    // read, and it delays the one thing the page exists to show.
    expect(screen.getByText('1,234')).toBeInTheDocument();
  });

  it('shows a hint when given one', () => {
    render(<Stat label="Clicks" value={0} hint="Last 30 days" />);

    expect(screen.getByText('Last 30 days')).toBeInTheDocument();
  });
});

describe('EmptyState', () => {
  it('always states the next action', () => {
    render(
      <EmptyState
        title="No links yet"
        description="Create your first short link."
        action={<Button>Create</Button>}
      />,
    );

    // Reporting absence without offering a way forward leaves the viewer stuck.
    expect(screen.getByRole('heading', { name: 'No links yet' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create' })).toBeInTheDocument();
  });

  it('uses the editorial face, which the dashboard otherwise avoids', () => {
    const { container } = render(<EmptyState title="Nothing" description="Yet" />);

    expect(container.querySelector('h3')?.className).toContain('font-serif');
  });
});
