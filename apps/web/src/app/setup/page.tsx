import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

import { SetupWizard } from '@/components/setup/setup-wizard';
import { fetchPublicConfiguration } from '@/lib/api';
import { sanitiseBranding } from '@/lib/branding';

export const metadata: Metadata = {
  title: 'Setup',
};

// The wizard exists only before installation, and installation state is a
// runtime setting, so this page can never be prerendered.
export const dynamic = 'force-dynamic';

export default async function SetupPage() {
  const configuration = await fetchPublicConfiguration();

  // Setup closes permanently, and a closed door says nothing about what was
  // behind it.
  if (configuration?.installed === true) {
    notFound();
  }

  const branding = sanitiseBranding(configuration);

  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-6 py-16">
      <SetupWizard instanceName={branding.name} />
    </main>
  );
}
