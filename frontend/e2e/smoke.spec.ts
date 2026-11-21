import { test, expect, Page } from "@playwright/test";

// Smoke tests of the public pages: each one is opened in a real browser
// against the production build and must show its content. The backend is
// e2e/mock-backend.mjs, which answers with the shapes recorded in the
// backend's API contract fixtures — a page that stops coping with what the
// backend sends fails here.

// Page errors include React's hydration (#418) and server rendering (#419)
// errors, which a visitor does not see but which cost the server rendering.
async function openPage(page: Page, path: string): Promise<string[]> {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));

  // The captcha is a third-party script; the tests stay off the network.
  await page.route(/challenges\.cloudflare\.com/, (route) => route.abort());

  const response = await page.goto(path);
  expect(response?.status(), `HTTP status of ${path}`).toBe(200);

  return pageErrors;
}

// Hydration and the first client-side requests have to finish before the
// collected page errors say anything.
async function settle(page: Page): Promise<void> {
  // Not "networkidle": some pages keep polling.
  await page.waitForLoadState("load");
  await page.waitForTimeout(1000);
}

// `text` is something the page can only show from the backend's answer; the
// pages without it draw the data as numbers and charts only.
const pages: { path: string; heading: string; text?: string }[] = [
  { path: "/", heading: "MolMeDB is an open chemical database" },
  { path: "/browse/membranes", heading: "Membranes", text: "Sample title" },
  { path: "/browse/methods", heading: "Methods", text: "Sample title" },
  { path: "/browse/membranes?id=1", heading: "Sample name", text: "Sample description" },
  { path: "/browse/methods?id=1", heading: "Sample name", text: "Sample description" },
  { path: "/browse/proteins", heading: "Proteins", text: "Sample title" },
  { path: "/browse/datasets", heading: "Datasets", text: "Sample journal" },
  { path: "/membrane/1", heading: "Sample name", text: "Sample description" },
  { path: "/method/1", heading: "Sample name", text: "Sample description" },
  { path: "/protein/1", heading: "P08183", text: "Sample value" },
  { path: "/publication/1", heading: "Sample title", text: "Sample citation" },
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
        // Attached, not visible: the browsers keep it in a collapsed tree.
        await expect(page.getByText(text).first()).toBeAttached();
      }

      await settle(page);

      expect(pageErrors).toEqual([]);
    });
  }

  test("an unknown compound says it was not found", async ({ page }) => {
    const pageErrors = await openPage(page, "/mol/missing");

    await expect(page.getByRole("heading", { name: "Not found" })).toBeVisible();
    await expect(page.getByText("Cannot find compound with id missing")).toBeVisible();
    await settle(page);

    expect(pageErrors).toEqual([]);
  });
});
