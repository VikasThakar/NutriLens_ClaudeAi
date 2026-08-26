/**
 * Single place where environment variables are read and validated.
 * Importing anything else from `process.env` in app code is a smell.
 */

function required(name: string, value: string | undefined, fallback: string): string {
  if (!value || value.trim() === "") {
    if (process.env.NODE_ENV === "development") {
      console.warn(
        `[nutrilens] ${name} is not set — falling back to "${fallback}". ` +
          `Add it to .env.local.`,
      );
    }
    return fallback;
  }
  return value.replace(/\/+$/, "");
}

export const env = {
  /** Base URL of the Laravel API, e.g. http://localhost:8000/api */
  apiUrl: required(
    "NEXT_PUBLIC_API_URL",
    process.env.NEXT_PUBLIC_API_URL,
    "http://localhost:8000/api",
  ),
  /** Public origin of this Next.js app. */
  appUrl: required(
    "NEXT_PUBLIC_APP_URL",
    process.env.NEXT_PUBLIC_APP_URL,
    "http://localhost:3000",
  ),
} as const;

/**
 * Swagger UI for the partner API.
 *
 * Derived from `apiUrl` rather than configured separately: the documentation is
 * served by the same Laravel deployment the app is already talking to, so a
 * second variable could only ever drift out of step with the first.
 */
export const apiDocsUrl = `${env.apiUrl}/documentation`;
