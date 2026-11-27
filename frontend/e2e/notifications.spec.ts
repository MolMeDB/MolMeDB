import { test, expect, Page } from "@playwright/test";
import jwt from "jsonwebtoken";

const MOCK_BACKEND = "http://127.0.0.1:4001";
// As in playwright.config.ts.
const JWT_SECRET = "e2e-test-secret-not-for-production";
const USER_COOKIE = "molmedb_fe_session_user";

async function fillLogin(page: Page, options: { remember?: boolean } = {}) {
  await page.locator('input[name="email"]').fill("user@example.test");
  await page.locator('input[name="password"]').fill("correct-password");

  if (options.remember) {
    await page.locator("#remember").check();
  }

  await page
    .getByRole("main")
    .getByRole("button", { name: "Login", exact: true })
    .click();
}

async function signIn(page: Page, options: { remember?: boolean } = {}) {
  await page.goto("/login");
  await fillLogin(page, options);
  await expect(page).toHaveURL(/\/lab$/);
}

async function browserCookie(page: Page, name: string) {
  return (await page.context().cookies()).find((c) => c.name === name);
}

/** Seconds from now until the cookie ends. */
function secondsLeft(cookie: { expires: number } | undefined) {
  return (cookie?.expires ?? 0) - Date.now() / 1000;
}

async function lastBody(page: Page, route: string) {
  const response = await page.request.get(
    `${MOCK_BACKEND}/__e2e/last-body?route=${encodeURIComponent(route)}`,
  );

  return response.status() === 200 ? (await response.json()).body : undefined;
}

test.describe("notifications", () => {
  test("a push opened while signed out shows the whole notification after signing in", async ({
    page,
  }) => {
    await page.goto("/account/notifications/5");
    await expect(page).toHaveURL(
      /\/login\?redirect=%2Faccount%2Fnotifications%2F5$/,
    );

    await fillLogin(page);

    await expect(page).toHaveURL(/\/account\/notifications\/5$/);
    await expect(
      page.getByRole("heading", { name: "Background job failure" }),
    ).toBeVisible();
    await expect(page.getByText("backup: disk full")).toBeVisible();
    await expect(page.getByText("export: timeout")).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Open the related page" }),
    ).toHaveAttribute("href", "https://molmedb.test/admin/activity");

    // Opening it marks it as read.
    await expect
      .poll(() => lastBody(page, "POST /api/notifications/5/read"))
      .not.toBeUndefined();

    // The markup of the message that could run does not.
    expect(
      await page.evaluate(
        () => (window as unknown as { __e2eInjected?: boolean }).__e2eInjected,
      ),
    ).toBeUndefined();
  });

  test("a notification that is gone says so", async ({ page }) => {
    await signIn(page);
    await page.goto("/account/notifications/404");

    await expect(
      page.getByText("This notification does not exist or was cleared."),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "All notifications" }),
    ).toHaveAttribute("href", "/account/notifications");
  });

  test("the list leads to each notification", async ({ page }) => {
    await signIn(page);
    await page.goto("/account/notifications");

    const notifications = page.getByRole("list", { name: "Notifications" });
    await expect(notifications.getByRole("link").first()).toHaveAttribute(
      "href",
      "/account/notifications/1",
    );
  });

  test("the bell leads to each notification and to all of them", async ({
    page,
  }) => {
    await signIn(page);

    await page.getByRole("button", { name: "Notifications" }).first().click();

    await expect(
      page.getByRole("link", { name: "All notifications" }),
    ).toHaveAttribute("href", "/account/notifications");
    await expect(
      page.locator('[role="menu"] a[href="/account/notifications/1"]').first(),
    ).toBeVisible();
  });

  test("a page opened from elsewhere still knows the signed-in user", async ({
    page,
  }) => {
    await signIn(page);

    // A link on another site (a webmail): localhost is another site than
    // 127.0.0.1, so a strict cookie stays out of this navigation.
    await page.goto("http://localhost:4001/");
    await page.evaluate(() => {
      window.location.href = "http://127.0.0.1:3100/account/settings";
    });

    await expect(page).toHaveURL(/\/account\/settings$/);
    expect((await browserCookie(page, USER_COOKIE))?.sameSite).toBe("Lax");
  });

  test("the sign-in lasts as long as the backend session, and with remember me for 400 days", async ({
    page,
    browser,
  }) => {
    await signIn(page);
    const session = secondsLeft(await browserCookie(page, USER_COOKIE));
    expect(session).toBeGreaterThan(110 * 60);
    expect(session).toBeLessThanOrEqual(120 * 60);

    const rememberingContext = await browser.newContext();
    const remembering = await rememberingContext.newPage();
    await signIn(remembering, { remember: true });
    expect(
      secondsLeft(await browserCookie(remembering, USER_COOKIE)),
    ).toBeGreaterThan(399 * 24 * 60 * 60);
    expect(await browserCookie(remembering, "remember_web_e2e")).toBeTruthy();
    await rememberingContext.close();
  });

  test("a login after the backend ended the session forgets the user", async ({
    page,
  }) => {
    await signIn(page);

    // Where a 401 of the backend leads.
    await page.goto("/login?redirect=%2Faccount%2Fsettings&expired=1");

    // The form, not "Continue as" the user the backend no longer knows.
    await expect(page.locator('input[name="email"]')).toBeVisible();
    expect(await browserCookie(page, USER_COOKIE)).toBeUndefined();
    expect(await browserCookie(page, "molmedb_fe_session")).toBeUndefined();

    await page.goto("/account/settings");
    await expect(page).toHaveURL(/\/login\?redirect=%2Faccount%2Fsettings$/);
  });

  test("using the site prolongs the sign-in", async ({ page, context }) => {
    await signIn(page);
    const user = await browserCookie(page, USER_COOKIE);
    const backendSession = await browserCookie(page, "molmedb_fe_session");

    // Signed in 100 minutes ago: 20 minutes of the two hours are left.
    const issuedAt = Math.floor(Date.now() / 1000) - 100 * 60;
    const aged = jwt.sign(
      {
        id: 1,
        name: "E2E Test User",
        email: "e2e@example.test",
        session: { remember: false, lifetime: 7200 },
        iat: issuedAt,
      },
      JWT_SECRET,
      // Counted from iat, as when it was signed.
      { expiresIn: 120 * 60 },
    );
    await context.addCookies([
      {
        ...user!,
        value: aged,
        expires: Math.floor(Date.now() / 1000) + 20 * 60,
      },
      { ...backendSession!, expires: Math.floor(Date.now() / 1000) + 20 * 60 },
    ]);

    await page.goto("/browse/membranes");

    expect(secondsLeft(await browserCookie(page, USER_COOKIE))).toBeGreaterThan(
      110 * 60,
    );
    expect(
      secondsLeft(await browserCookie(page, "molmedb_fe_session")),
    ).toBeGreaterThan(110 * 60);
    // Still the same backend session.
    expect((await browserCookie(page, "molmedb_fe_session"))?.value).toBe(
      backendSession!.value,
    );

    await page.goto("/account/settings");
    await expect(page).toHaveURL(/\/account\/settings$/);
  });
});
