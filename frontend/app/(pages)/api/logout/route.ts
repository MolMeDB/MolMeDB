import { post } from "@/lib/api/admin";
import { NextResponse } from "next/server";
import { cookies } from "next/headers";

const SESSION_KEY = process.env.COOKIES_FRONTEND_SESSION_KEY as string;
const USER_KEY = process.env.COOKIES_FRONTEND_SESSION_USER_KEY as string;
const XSRF_KEY = process.env.COOKIES_BACKEND_XSRF_KEY as string;
const FRONTEND_URL = process.env.FRONTEND_URL as string;

export async function GET(request: Request) {
  // A browser loading this address in advance is answered in proxy.ts and
  // never gets here. Link to this address with a plain anchor only: the
  // router requests the target of a next/link ahead of the click, which
  // signed the user out as soon as such a link was on the screen.
  await post("/logout");

  const res = NextResponse.redirect(new URL("/login", FRONTEND_URL || request.url));

  const cookieStore = await cookies();
  const rememberCookies = cookieStore
    .getAll()
    .filter((cookie) => cookie.name.startsWith("remember_"));

  cookieStore.delete(SESSION_KEY);
  cookieStore.delete(USER_KEY);
  cookieStore.delete(XSRF_KEY);
  rememberCookies.forEach((cookie) => cookieStore.delete(cookie.name));

  res.cookies.set(SESSION_KEY, "", { maxAge: 0, path: "/" });
  res.cookies.set(USER_KEY, "", { maxAge: 0, path: "/" });
  res.cookies.set(XSRF_KEY, "", { maxAge: 0, path: "/" });
  res.cookies.set("molmedb_guest", "", { maxAge: 0, path: "/" });
  res.cookies.set("molmedb_guest_access", "", {
    httpOnly: true,
    maxAge: 0,
    path: "/",
  });
  rememberCookies.forEach((cookie) => {
    res.cookies.set(cookie.name, "", { maxAge: 0, path: "/" });
  });

  return res;
}
