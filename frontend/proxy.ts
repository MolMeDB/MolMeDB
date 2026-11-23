import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { wantsRdf } from "./lib/rdfNegotiation";

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

// This function can be marked `async` if using `await` inside
export async function proxy(request: NextRequest) {
  // Signing out happens on a GET, so loading that address in advance would
  // end the session without the user asking for it. Such a request gets
  // nothing.
  if (request.nextUrl.pathname === LOGOUT_PATH) {
    return isPrefetch(request)
      ? new NextResponse(null, { status: 204 })
      : NextResponse.next();
  }

  // A molecule's persistent IRI (https://identifiers.org/molmedb/MM00040)
  // resolves to its page here; RDF clients get its RDF description from the
  // backend instead (see App\Http\Controllers\RdfController).
  const molecule = request.nextUrl.pathname.match(/^\/mol\/(MM\d+(?:\.\d+)?)$/);

  if (molecule && wantsRdf(request.headers.get("accept"), request.nextUrl.searchParams.get("format"))) {
    return NextResponse.rewrite(
      new URL(`/api/rdf/molecule/${molecule[1]}${request.nextUrl.search}`, process.env.NEXT_BACKEND_URL),
    );
  }

  const requestHeaders = new Headers(request.headers);
  return NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });
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
