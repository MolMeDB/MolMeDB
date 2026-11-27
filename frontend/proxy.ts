import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { wantsRdf } from "./lib/rdfNegotiation";
import {
  DEFAULT_COOKIES_CONFIG,
  readUserSession,
  signUserSession,
  userSessionKey,
} from "./lib/api/userSessionCookie";

const LOGOUT_PATH = "/api/logout";

/**
 * Whether a browser only loads the address in advance (a page it expects to
 * be opened). The router's own requests in advance cannot be told apart
 * here, their headers do not get this far: those are prevented by linking
 * to the logout with a plain anchor, never with next/link.
 */
function isPrefetch(request: NextRequest): boolean {
  return (
    request.headers.get("purpose") === "prefetch" ||
    (request.headers.get("sec-purpose") ?? "").includes("prefetch")
  );
}

/** The login's mark of a session the backend ended (answered 401). */
function isExpiredLogin(request: NextRequest): boolean {
  return (
    request.method === "GET" &&
    request.nextUrl.pathname === "/login" &&
    request.nextUrl.searchParams.get("expired") === "1"
  );
}

/**
 * The backend no longer knows the session, so the frontend forgets the user
 * too: otherwise the login offered to continue as them, and the header kept
 * showing them signed in. Taken out of this request as well, for the login
 * page rendered for it.
 */
function forgetSignedInUser(request: NextRequest): string[] {
  const names = [
    userSessionKey(),
    process.env.COOKIES_FRONTEND_SESSION_KEY,
  ].filter(
    (name): name is string =>
      Boolean(name) && request.cookies.has(name as string),
  );

  for (const name of names) {
    request.cookies.delete(name);
  }

  return names;
}

/**
 * Prolongs the sign-in of a user who keeps using the site. The cookies were
 * set to end when the backend session would end without a visit, so they
 * ended that long after signing in, however busy the user was since: the
 * user and the browser's copy of the backend session are renewed here, like
 * the backend prolongs its session with each request. Whether the backend
 * session still holds stays the backend's decision (an expired one answers
 * 401 and leads to the login).
 */
function renewSignIn(request: NextRequest, response: NextResponse): void {
  // Requests that set cookies themselves (signing in and out, server
  // actions) are left alone.
  if (
    request.method !== "GET" ||
    request.nextUrl.pathname.startsWith("/api/")
  ) {
    return;
  }

  const key = userSessionKey();
  const token = key ? request.cookies.get(key)?.value : undefined;

  if (!key || !token || !process.env.JWT_SECRET) {
    return;
  }

  const session = readUserSession(token);

  // A cookie signed before the sign-in period was stored keeps its end.
  if (!session?.meta) {
    return;
  }

  // Renewed once half of the backend session's lifetime has passed since the
  // last renewal, not on every request: fewer cookies to write, and a page
  // answering late (after a logout in another tab) rarely brings them back.
  if (
    session.issuedAt !== null &&
    Date.now() / 1000 - session.issuedAt < session.meta.lifetime / 2
  ) {
    return;
  }

  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- left out of the renewed token
  const { iat, exp, session: _meta, ...user } = session.payload;
  const renewed = signUserSession(user, session.meta);

  response.cookies.set(key, renewed.token, {
    ...DEFAULT_COOKIES_CONFIG,
    maxAge: renewed.maxAge,
  });

  for (const name of [
    process.env.COOKIES_FRONTEND_SESSION_KEY,
    process.env.COOKIES_BACKEND_XSRF_KEY,
  ]) {
    const value = name ? request.cookies.get(name)?.value : undefined;

    if (name && value) {
      response.cookies.set(name, value, {
        ...DEFAULT_COOKIES_CONFIG,
        maxAge: session.meta.lifetime,
      });
    }
  }
}

// This function can be marked `async` if using `await` inside
export async function proxy(request: NextRequest) {
  // Signing out happens on a GET, so loading that address in advance would
  // end the session without the user asking for it. Such a request gets
  // nothing.
  if (request.nextUrl.pathname === LOGOUT_PATH) {
    return isPrefetch(request)
      ? // Not to be kept and reused for the click that follows.
        new NextResponse(null, {
          status: 204,
          headers: { "Cache-Control": "no-store" },
        })
      : NextResponse.next();
  }

  // A molecule's persistent IRI (https://identifiers.org/molmedb/MM00040)
  // resolves to its page here; RDF clients get its RDF description from the
  // backend instead (see App\Http\Controllers\RdfController).
  const molecule = request.nextUrl.pathname.match(/^\/mol\/(MM\d+(?:\.\d+)?)$/);

  if (
    molecule &&
    wantsRdf(
      request.headers.get("accept"),
      request.nextUrl.searchParams.get("format"),
    )
  ) {
    return NextResponse.rewrite(
      new URL(
        `/api/rdf/molecule/${molecule[1]}${request.nextUrl.search}`,
        process.env.NEXT_BACKEND_URL,
      ),
    );
  }

  const forgotten = isExpiredLogin(request) ? forgetSignedInUser(request) : [];

  const requestHeaders = new Headers(request.headers);
  const response = NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });

  if (forgotten.length > 0) {
    for (const name of forgotten) {
      response.cookies.set(name, "", { ...DEFAULT_COOKIES_CONFIG, maxAge: 0 });
    }

    return response;
  }

  renewSignIn(request, response);

  return response;
}

// See "Matching Paths" below to learn more
export const config = {
  matcher: [
    // With no exception for requests made in advance: those are the ones to stop.
    "/api/logout",
    {
      source: "/((?!_next/static|_next/image|assets|favicon.ico|sw.js).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};
