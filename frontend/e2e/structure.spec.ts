import { test, expect, Page } from "@playwright/test";

// The 2D drawing of a compound comes from the backend in whatever proportions
// the molecule has: a long chain gives a tall or a wide picture. Whatever it
// is, it has to stay inside its section of the compound's page.

function drawing(width: number, height: number): string {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="${width}" height="${height}" fill="#888"/></svg>`;
}

async function openCompound(
  page: Page,
  size: { width: number; height: number },
): Promise<void> {
  await page.route(/challenges\.cloudflare\.com/, (route) => route.abort());
  await page.route(/\/e2e\/structure-2d(-big)?\.svg$/, (route) =>
    route.fulfill({
      contentType: "image/svg+xml",
      body: drawing(size.width, size.height),
    }),
  );

  await page.goto("/mol/MM00001");
}

const shapes = [
  { name: "a tall", width: 400, height: 1600 },
  { name: "a wide", width: 3000, height: 300 },
  { name: "a large square", width: 2000, height: 2000 },
  { name: "a small", width: 60, height: 40 },
];

const viewports = [
  { name: "desktop", width: 1280, height: 900 },
  { name: "phone", width: 375, height: 812 },
];

for (const viewport of viewports) {
  test.describe(`2D structure of a compound on a ${viewport.name}`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const shape of shapes) {
      test(`${shape.name} drawing stays inside its section`, async ({
        page,
      }) => {
        await openCompound(page, shape);

        const section = page.getByTestId("structure-2d");
        const image = section.getByAltText("2D Structure");

        await expect(image).toBeVisible();
        await expect
          .poll(() =>
            image.evaluate((img: HTMLImageElement) => img.naturalWidth),
          )
          .toBeGreaterThan(0);

        const outer = (await section.boundingBox())!;
        const inner = (await image.boundingBox())!;

        expect(inner.width).toBeGreaterThan(0);
        expect(inner.x).toBeGreaterThanOrEqual(outer.x - 0.5);
        expect(inner.y).toBeGreaterThanOrEqual(outer.y - 0.5);
        expect(inner.x + inner.width).toBeLessThanOrEqual(
          outer.x + outer.width + 0.5,
        );
        expect(inner.y + inner.height).toBeLessThanOrEqual(
          outer.y + outer.height + 0.5,
        );

        // Scaled, not squeezed.
        expect(inner.width / inner.height).toBeCloseTo(
          shape.width / shape.height,
          1,
        );
      });
    }

    test("the enlarged drawing fits the window", async ({ page }) => {
      await openCompound(page, { width: 1600, height: 6400 });

      await page.getByTestId("structure-2d").getByAltText("2D Structure").click();

      const enlarged = page.getByRole("dialog").getByAltText("2D Structure");
      await expect(enlarged).toBeVisible();
      await expect
        .poll(() =>
          enlarged.evaluate((img: HTMLImageElement) => img.naturalWidth),
        )
        .toBeGreaterThan(0);
      // The dialog slides in.
      await page.waitForTimeout(500);

      const box = (await enlarged.boundingBox())!;

      expect(box.y).toBeGreaterThanOrEqual(0);
      expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
      expect(box.x).toBeGreaterThanOrEqual(0);
      expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    });
  });
}
