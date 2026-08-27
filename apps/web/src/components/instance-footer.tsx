/**
 * The operator's line at the foot of every page.
 *
 * Rendered from the root layout rather than the signed-in shell, because a
 * footer that only appears once you are signed in is not a footer — setup and
 * sign-in are pages too. Empty text renders nothing at all.
 */
export function InstanceFooter({ text }: { text: string }) {
  if (text === '') {
    return null;
  }

  return (
    <footer className="border-border mt-auto border-t">
      <p
        className="text-ink-subtle mx-auto w-full max-w-7xl px-4 py-4 text-xs md:px-8"
        data-testid="instance-footer"
      >
        {text}
      </p>
    </footer>
  );
}
