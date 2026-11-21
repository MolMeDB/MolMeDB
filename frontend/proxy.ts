import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { post } from "./lib/api/admin";
import { wantsRdf } from "./lib/rdfNegotiation";

const SESSION_KEY = process.env.COOKIES_FRONTEND_SESSION_KEY as string;
const USER_KEY = process.env.COOKIES_FRONTEND_SESSION_USER_KEY as string;
const XSRF_KEY = process.env.COOKIES_BACKEND_XSRF_KEY as string;

// This function can be marked `async` if using `await` inside
export async function proxy(request: NextRequest) {
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
    {
      source: "/((?!_next/static|_next/image|assets|favicon.ico|sw.js).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};
