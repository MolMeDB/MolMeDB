import { test, expect, Page } from "@playwright/test";

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
  test("the page offering to continue as the signed-in user leaves them signed in", async ({
    page,
  }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;
    expect(session).toBeTruthy();

    await page.goto("/login");
    await expect(page.getByText("Continue as")).toBeVisible();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();

    await page.locator("main a", { hasText: "Continue as" }).click();
    await expect(page).toHaveURL(/127\.0\.0\.1:\d+\/$/);

    await page.goto("/login");
    await expect(page.getByText("Continue as")).toBeVisible();
    expect(await backendSessionEnded(page, session)).toBe(false);
  });

  test("opening the user menu and the account settings leaves the user signed in", async ({
    page,
  }) => {
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;

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
    await expect(page.getByText("Manage your sign-in password for")).toContainText(
      "e2e@example.test",
    );
    await page.waitForLoadState("networkidle");

    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();
  });

  test("opening the menu on a phone leaves the user signed in", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    const session = (await cookie(page, "molmedb_fe_session")) as string;

    await page.getByRole("button", { name: "Open menu" }).click();
    await expect(page.getByRole("link", { name: "Log out" })).toBeVisible();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

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
      headers: { "sec-purpose": "prefetch;prerender" },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(204);
    expect(await backendSessionEnded(page, session)).toBe(false);
    expect(await cookie(page, "molmedb_fe_session_user")).toBeTruthy();
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
