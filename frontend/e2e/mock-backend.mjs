// Stand-in for the Laravel backend, used only by Playwright e2e tests. The
// frontend fetches its data in Next.js Server Components and Server Actions
// (server-to-server), which Playwright cannot intercept in the browser — so
// NEXT_BACKEND_URL is pointed at this real (but fake) HTTP server instead.
//
// The answers are not written by hand: they are built from the API contract
// fixtures the backend tests record (tests/Fixtures/api-contracts), which
// hold the shape of every endpoint's response. A backend change that alters
// a response therefore reaches these tests with the re-recorded fixture.
// SAMPLE_VALUES below gives the few fields a page needs a real value for.
import { randomUUID } from "node:crypto";
import { createServer } from "node:http";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const port = process.env.MOCK_BACKEND_PORT
  ? Number(process.env.MOCK_BACKEND_PORT)
  : 4001;

const contractsDir = join(
  dirname(fileURLToPath(import.meta.url)),
  "../../tests/Fixtures/api-contracts",
);

// Values by field name; anything else gets a generic value of its type.
const SAMPLE_VALUES = {
  identifier: "MM00001",
  canonical_smiles: "CC(=O)Oc1ccccc1C(=O)O",
  smiles: "CC(=O)Oc1ccccc1C(=O)O",
  inchikey: "BSYNRYMUTXBXSQ-UHFFFAOYSA-N",
  uniprot_id: "P08183",
  doi: "10.1000/e2e.sample",
  hash: "e2e0000000000000000000000000hash",
  mime: "text/csv",
  link: "/browse/membranes",
  slug: "sample-article",
  content: "<p>Sample content</p>",
  description: "<p>Sample description</p>",
  // Answered by the tests themselves (page.route), with drawings of any shape.
  structure_2d_url: "/e2e/structure-2d.svg",
  structure_2d_url_big: "/e2e/structure-2d-big.svg",
};

function sampleString(key) {
  if (key in SAMPLE_VALUES) {
    return SAMPLE_VALUES[key];
  }
  if (/(_at|date|timestamp)$/i.test(key)) {
    return "2026-01-15T10:00:00.000000Z";
  }
  if (/url$/i.test(key) || ["first", "last", "next", "prev"].includes(key)) {
    return "/assets/layout/logo/molmedb-white.svg";
  }
  if (/email/i.test(key)) {
    return "e2e@example.test";
  }

  return `Sample ${key.replaceAll("_", " ")}`;
}

// A contract leaf names the JSON type of the value ("string", "number",
// "null|string", ...); the first type that is not null is used.
function materialize(shape, key = "value") {
  if (Array.isArray(shape)) {
    return shape.map((item) => materialize(item, key));
  }
  if (shape !== null && typeof shape === "object") {
    return Object.fromEntries(
      Object.entries(shape).map(([name, value]) => [
        name,
        materialize(value, name),
      ]),
    );
  }
  if (typeof shape !== "string") {
    return shape;
  }

  const type = shape.split("|").find((part) => part !== "null") ?? "null";

  switch (type) {
    case "number":
      return 1;
    case "boolean":
      return true;
    case "null":
      return null;
    default:
      return sampleString(key);
  }
}

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

// "GET api/membrane/{membrane}" -> a matcher; routes with fewer parameters
// win, so /api/membrane/categories is not read as /api/membrane/{membrane}.
const routes = readdirSync(contractsDir)
  .filter((file) => file.endsWith(".json"))
  .map((file) => JSON.parse(readFileSync(join(contractsDir, file), "utf8")))
  .filter(
    (contract) =>
      typeof contract.route === "string" &&
      contract.content_type === "application/json",
  )
  .map((contract) => {
    const [method, path] = contract.route.split(" ");
    const pattern = path
      .split("/")
      .map((segment) =>
        segment.startsWith("{") ? "[^/]+" : escapeRegExp(segment),
      )
      .join("/");

    return {
      method,
      parameters: (path.match(/\{/g) ?? []).length,
      regex: new RegExp(`^/${pattern}/?$`),
      status: contract.status,
      body: JSON.stringify(materialize(contract.body)),
    };
  })
  .sort((a, b) => a.parameters - b.parameters);

/** Sessions started by a login, by the value of their cookie. */
const sessions = new Map();

function sessionOf(req) {
  return /(?:^|;\s*)molmedb_session=([^;]+)/.exec(req.headers.cookie ?? "")?.[1];
}

function send(res, status, body) {
  res.writeHead(status, { "Content-Type": "application/json" });
  res.end(typeof body === "string" ? body : JSON.stringify(body));
}

const server = createServer((req, res) => {
  req.on("data", () => {});
  req.on("end", () => {
    const { pathname } = new URL(req.url, "http://mock-backend");

    // Playwright's webServer readiness check does a GET on this URL and
    // expects a non-error status before starting tests.
    if (req.method === "GET" && pathname === "/") {
      res.writeHead(200, { "Content-Type": "text/plain" });
      res.end("mock-backend ok");

      return;
    }

    // Like the real backend, a login starts a session and hands out its
    // cookies; the frontend keeps them and sends them back with every call.
    if (req.method === "POST" && pathname === "/login") {
      const session = randomUUID();
      sessions.set(session, { signedOut: false });
      res.writeHead(200, {
        "Content-Type": "application/json",
        "Set-Cookie": [
          `XSRF-TOKEN=${randomUUID()}; Max-Age=7200; Path=/; SameSite=Lax`,
          `molmedb_session=${session}; Max-Age=7200; Path=/; HttpOnly; SameSite=Lax`,
        ],
      });
      res.end(
        JSON.stringify({
          data: { id: 1, name: "E2E Test User", email: "e2e@example.test" },
          meta: {},
        }),
      );

      return;
    }

    if (req.method === "POST" && pathname === "/logout") {
      const session = sessions.get(sessionOf(req));

      if (session) {
        session.signedOut = true;
      }

      res.writeHead(204);
      res.end();

      return;
    }

    // For the tests only: whether the backend was asked to end a session.
    if (req.method === "GET" && pathname.startsWith("/__e2e/sessions/")) {
      const session = sessions.get(pathname.split("/").pop());
      send(res, session ? 200 : 404, session ?? { message: "No such session." });

      return;
    }

    // The smoke tests visit the pages as an anonymous visitor.
    if (req.method === "GET" && pathname === "/api/user") {
      send(res, 401, { message: "Unauthenticated." });

      return;
    }

    // An identifier the backend does not know.
    if (pathname.split("/").includes("missing")) {
      send(res, 404, { message: "Not found." });

      return;
    }

    const route = routes.find(
      (candidate) =>
        candidate.method === req.method && candidate.regex.test(pathname),
    );

    if (route) {
      send(res, route.status, route.body);

      return;
    }

    console.error(`[mock-backend] not mocked: ${req.method} ${req.url}`);
    send(res, 404, { message: `Not mocked: ${req.method} ${req.url}` });
  });
});

server.listen(port, () => {
  console.log(
    `[mock-backend] listening on http://127.0.0.1:${port} with ${routes.length} contract routes`,
  );
});
