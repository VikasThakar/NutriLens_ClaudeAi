import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Fail the production build on type errors rather than shipping them.
  // (Linting runs separately via `npm run lint`.)
  typescript: { ignoreBuildErrors: false },

  // Don't auto-generate AGENTS.md / CLAUDE.md into the frontend root.
  agentRules: false,

  // Meal photos will be served from the Laravel storage disk in the next phase.
  images: {
    remotePatterns: [
      { protocol: "http", hostname: "localhost", port: "8000", pathname: "/storage/**" },
      { protocol: "http", hostname: "127.0.0.1", port: "8000", pathname: "/storage/**" },
    ],
  },
};

export default nextConfig;
