import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  // Traced dependency output keeps the runtime image small and lets it run
  // without pnpm or the workspace present.
  output: 'standalone',

  // The edge owns transport and framing policy; the CSP is emitted per request
  // in middleware because it carries a nonce.
  poweredByHeader: false,
};

export default nextConfig;
