import { test, expect, Page } from "@playwright/test";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

const MOCK_BACKEND = "http://127.0.0.1:4001";

async function signIn(page: Page) {
  await page.goto("/login");
  await page.locator('input[name="email"]').fill("user@example.test");
  await page.locator('input[name="password"]').fill("correct-password");
  await page
    .getByRole("main")
    .getByRole("button", { name: "Login", exact: true })
    .click();
  await expect(page).toHaveURL(/\/lab$/);
}

async function cookie(page: Page, name: string) {
  return (await page.context().cookies()).find((c) => c.name === name)?.value;
}

/** Every request the page makes for the logout, whoever starts it. */
function logoutRequests(page: Page): string[] {
  const requests: string[] = [];
  page.on("request", (request) => {
    if (new URL(request.url()).pathname === "/api/logout") {
      requests.push(request.url());
    }
  });

  return requests;
}

/** The cookies of the browser as a header: page.request leaves Secure ones out over http. */
async function cookieHeader(page: Page) {
  return (await page.context().cookies())
    .map((c) => `${c.name}=${c.value}`)
    .join("; ");
}

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) {
      return ["node_modules", ".next", "e2e"].includes(entry.name)
        ? []
        : sourceFiles(path);
    }

    return /\.tsx?$/.test(entry.name) ? [path] : [];
  });
}

/** Whether the backend was asked to end the session this browser holds. */
async function backendSessionEnded(page: Page, session: string) {
  const response = await page.request.get(
    `${MOCK_BACKEND}/__e2e/sessions/${session}`,
  );
  expect(response.status()).toBe(200);

  return (await response.json()).signedOut as boolean;
}

/**
 * The frontend signs out through GET /api/logout. Anything that requests
 * that address without the user asking for it (the router loading a visible
 * link in advance, above all) ends the session behind the user's back.
 */
test.describe("session", () => {
  for (const path of ["/login", "/register"]) {
    test(`${path} offering to continue as the signed-in user leaves them signed in`, async ({
      page,
    }) => {
      await signIn(page);
      const session = (await cookie(page, "molmedb_fe_session")) as string;
      expect(session).toBeTruthy();
      const requests = logoutRequests(page);

      await page.goto(path);
      await expect(page.getByText("Continue as")).toBeVisible();
      await page.waitForLoadState("networkidle");
      await page.waitForTimeout(1500);

      expect(requests).toEqual([]);
      expect(await backendSessionEnded(page, session)).toBe(false);
      expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();

      await page.locator("main a", { hasText: "Continue as" }).click();
      await expect(page).toHaveURL(/127\.0\.0\.1:\d+\/$/);

      await page.goto(path);
      await expect(page.getByText("Continue as")).toBeVisible();
      expect(await backendSessionEnded(page, session)).toBe(false);
    });
  }

  test("opening the user menu and the account settings leaves the user signed in", async ({
    page,
  }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;
    const requests = logoutRequests(page);

    // Rendered for this visitor, not once for everybody at build time.
    const userButton = page.getByRole("navigation").getByText("E2E Test User");
    await expect(userButton).toBeVisible();

    await userButton.click();
    await expect(page.getByText("Logged in as")).toBeVisible();
    await page.waitForTimeout(1500);
    expect(await backendSessionEnded(page, session)).toBe(false);

    await page.getByRole("menuitem", { name: "Account settings" }).click();
    await expect(page).toHaveURL(/\/account\/settings$/);
    await expect(
      page.getByRole("heading", { name: "Account settings" }),
    ).toBeVisible();
    await expect(
      page.getByText("Manage your sign-in password for"),
    ).toContainText("e2e@example.test");
    await page.waitForLoadState("networkidle");

    expect(requests).toEqual([]);
    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();
  });

  test("opening the menu on a phone leaves the user signed in", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;
    const requests = logoutRequests(page);

    await page.getByRole("button", { name: "Open menu" }).click();
    await expect(page.getByRole("link", { name: "Log out" })).toBeVisible();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    expect(requests).toEqual([]);
    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();
  });

  test("the account settings send a visitor who is not signed in to the login", async ({
    page,
  }) => {
    await page.goto("/account/settings");

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test("a browser loading the logout address in advance does not sign out", async ({
    page,
  }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;

    const response = await page.request.get("/api/logout", {
      headers: {
        "sec-purpose": "prefetch;prerender",
        cookie: await cookieHeader(page),
      },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(204);
    expect(response.headers()["set-cookie"]).toBeUndefined();
    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();
  });

  test("the same request without the mark of loading in advance does sign out", async ({
    page,
  }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;

    const response = await page.request.get("/api/logout", {
      headers: { cookie: await cookieHeader(page) },
      maxRedirects: 0,
    });

    // Proves that the test above reaches the backend with the session.
    expect(response.status()).toBe(307);
    expect(await backendSessionEnded(page, session)).toBe(true);
  });

  // The router requests the target of a next/link ahead of the click, and
  // nothing on the server can tell that request from a real one. So the
  // logout must only ever be linked with a plain anchor.
  test("no next/link points at the logout", () => {
    const root = join(__dirname, "..");
    const offenders: string[] = [];
    let links = 0;

    for (const file of sourceFiles(root)) {
      const source = readFileSync(file, "utf8");

      for (const match of source.matchAll(
        /<([A-Za-z.]+)\b[^<>]*?"\/api\/logout"[^<>]*?>/g,
      )) {
        const [tag, name] = match;
        links++;
        const plain =
          name === "a" ||
          name === "DropdownItem" ||
          /\bas="a"/.test(tag) ||
          /\bplainAnchor\b/.test(tag);

        if (!plain) {
          offenders.push(`${file.slice(root.length + 1)}: <${name}>`);
        }
      }
    }

    // The search itself still finds the links there are.
    expect(links).toBeGreaterThanOrEqual(3);
    expect(offenders).toEqual([]);
  });

  test("logging out ends the session", async ({ page }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;

    await page.goto("/login");
    await page.locator('main a[href="/api/logout"]').click();

    await expect(page.locator('input[name="email"]')).toBeVisible();
    expect(await backendSessionEnded(page, session)).toBe(true);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeFalsy();
    expect(await cookie(page, "molmedb_fe_session")).toBeFalsy();
  });
});
