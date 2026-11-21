import { defineConfig, devices } from "@playwright/test";

// e2e tests run against a real `next start` server for a real browser, with
// NEXT_BACKEND_URL pointed at a minimal mock server (e2e/mock-backend.mjs)
// instead of the actual Laravel backend — no Postgres/bingo/redis stack
// needed for these tests. `npm run build` must run before `playwright test`
// (the CI workflow does this as a separate step; webServer only starts it).
const PORT = 3100;
const MOCK_BACKEND_PORT = 4001;
const baseURL = `http://127.0.0.1:${PORT}`;
const mockBackendURL = `http://127.0.0.1:${MOCK_BACKEND_PORT}`;

export default defineConfig({
  testDir: "./e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [["list"], ["html", { open: "never" }]],
  use: {
    baseURL,
    trace: "on-first-retry",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  webServer: [
    {
      command: `node e2e/mock-backend.mjs`,
      url: mockBackendURL,
      reuseExistingServer: !process.env.CI,
      env: { MOCK_BACKEND_PORT: String(MOCK_BACKEND_PORT) },
    },
    {
      // Without the fetch cache of an earlier run, which would keep answers of
      // the mock backend that have changed since.
      command: `rm -rf .next/cache/fetch-cache && npx next start -p ${PORT}`,
      url: baseURL,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
      env: {
        NEXT_BACKEND_URL: mockBackendURL,
        FRONTEND_URL: baseURL,
        NEXT_SELF_URL: baseURL,
        JWT_SECRET: "e2e-test-secret-not-for-production",
        COOKIES_BACKEND_XSRF_KEY: "XSRF-TOKEN",
        COOKIES_BACKEND_SESSION_KEY: "molmedb_session",
        COOKIES_FRONTEND_SESSION_KEY: "molmedb_fe_session",
        COOKIES_FRONTEND_SESSION_USER_KEY: "molmedb_fe_session_user",
      },
    },
  ],
});
