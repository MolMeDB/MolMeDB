import { test, expect, Page } from "@playwright/test";

// Smoke tests of the public pages: each one is opened in a real browser
// against the production build and must show its content. The backend is
// e2e/mock-backend.mjs, which answers with the shapes recorded in the
// backend's API contract fixtures — a page that stops coping with what the
// backend sends fails here.

// A hydration mismatch (React error #418) is reported intermittently by the
// detail pages, in this build as well as on the production site, and React
// recovers from it by rendering on the client. Until its cause is fixed it
// is the only page error these tests let through.
// Any other one fails the test — such as error #419, which /browse/membranes
// and /browse/methods reported while their server rendering threw.
const KNOWN_PAGE_ERRORS = [/Minified React error #418/];

async function openPage(page: Page, path: string): Promise<string[]> {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => {
    if (!KNOWN_PAGE_ERRORS.some((known) => known.test(error.message))) {
      pageErrors.push(error.message);
    }
  });

  // The captcha is a third-party script; the tests stay off the network.
  await page.route(/challenges\.cloudflare\.com/, (route) => route.abort());

  const response = await page.goto(path);
  expect(response?.status(), `HTTP status of ${path}`).toBe(200);

  return pageErrors;
}

const pages: { path: string; heading: string; text?: string }[] = [
  { path: "/", heading: "MolMeDB is an open chemical database" },
  { path: "/browse/membranes", heading: "Membranes" },
  { path: "/browse/methods", heading: "Methods" },
  { path: "/browse/proteins", heading: "Proteins" },
  { path: "/browse/datasets", heading: "Datasets" },
  { path: "/membrane/1", heading: "Sample name", text: "Sample description" },
  { path: "/method/1", heading: "Sample name", text: "Sample description" },
  { path: "/protein/1", heading: "P08183" },
  { path: "/publication/1", heading: "Sample title" },
  { path: "/mol/MM00001", heading: "Sample name", text: "BASIC PROPERTIES" },
  { path: "/stats", heading: "Statistics" },
  { path: "/docs", heading: "Documentation", text: "Sample title" },
  { path: "/downloader", heading: "Downloader" },
  { path: "/lab", heading: "Laboratory" },
  { path: "/login", heading: "Welcome back" },
  { path: "/register", heading: "Create new account" },
  { path: "/reset-password", heading: "Forgot password?" },
];

test.describe("public pages", () => {
  for (const { path, heading, text } of pages) {
    test(`${path} shows its content`, async ({ page }) => {
      const pageErrors = await openPage(page, path);

      await expect(
        page.getByRole("heading", { name: heading }).first(),
      ).toBeVisible();

      if (text) {
        await expect(page.getByText(text).first()).toBeVisible();
      }

      // Let hydration and the first client-side requests finish, so their
      // errors are caught as well.
      await page.waitForLoadState("load");
      await page.waitForTimeout(1000);

      expect(pageErrors).toEqual([]);
    });
  }

  test("an unknown compound says it was not found", async ({ page }) => {
    const pageErrors = await openPage(page, "/mol/missing");

    await expect(page.getByRole("heading", { name: "Not found" })).toBeVisible();
    await expect(page.getByText("Cannot find compound with id missing")).toBeVisible();
    expect(pageErrors).toEqual([]);
  });
});
