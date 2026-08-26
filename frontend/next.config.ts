import type { NextConfig } from "next";

/**
 * Meal photos are served by Laravel itself (a signed
 * `/api/meal-images/{id}/file` URL), so the API's own origin has to be
 * allow-listed before `next/image` will load one.
 *
 * It is derived from NEXT_PUBLIC_API_URL rather than hard-coded because the API
 * lives on a different host in every environment — on Railway that is a
 * generated `*.up.railway.app` domain no static list could know in advance.
 * Read at build time, which is when Next.js bakes this config in.
 */
function apiImagePattern(): NonNullable<NonNullable<NextConfig["images"]>["remotePatterns"]> {
  const raw = process.env.NEXT_PUBLIC_API_URL;
  if (!raw) return [];

  try {
    const { protocol, hostname, port } = new URL(raw);
    return [
      {
        protocol: protocol.replace(":", "") as "http" | "https",
        hostname,
        port,
        pathname: "/**",
      },
    ];
  } catch {
    // A malformed URL is the env-var problem reported by lib/env.ts, not a
    // reason to fail the build here.
    return [];
  }
}

const nextConfig: NextConfig = {
  // Fail the production build on type errors rather than shipping them.
  // (Linting runs separately via `npm run lint`.)
  typescript: { ignoreBuildErrors: false },

  // Don't auto-generate AGENTS.md / CLAUDE.md into the frontend root.
  agentRules: false,

  images: {
    remotePatterns: [
      // Local development: `php artisan serve` on the default port.
      { protocol: "http", hostname: "localhost", port: "8000", pathname: "/**" },
      { protocol: "http", hostname: "127.0.0.1", port: "8000", pathname: "/**" },
      ...apiImagePattern(),
    ],
  },
};

export default nextConfig;
