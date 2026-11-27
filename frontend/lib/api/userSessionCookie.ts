import { ResponseCookie } from "next/dist/compiled/@edge-runtime/cookies";
import jwt from "jsonwebtoken";

/*
 * The signed cookie telling the frontend who is signed in, without Next.js
 * request APIs, so that the proxy can renew it too (see proxy.ts).
 */

export const DEFAULT_COOKIE_MAX_AGE = 60 * 60 * 24;

/** "Remember me": as long as browsers keep a cookie (Chrome caps it at 400 days). */
export const REMEMBER_MAX_AGE = 60 * 60 * 24 * 400;

export const DEFAULT_COOKIES_CONFIG = {
  httpOnly: true, // Not accesible from javascript if true
  secure: process.env.NODE_ENV === "production", // Pouze přes HTTPS
  // Lax, not strict: a strict cookie is left out when the site is opened
  // from elsewhere (a link in an e-mail, a push notification), so the page
  // rendered for that visit showed the user signed out. Lax still keeps it
  // out of requests other sites make in the background and of their forms.
  sameSite: "lax",
  path: "/",
  maxAge: DEFAULT_COOKIE_MAX_AGE, // 1 den
} as ResponseCookie;

/**
 * How long the sign-in lasts: the backend session's lifetime (it is
 * prolonged by every visit), or the remember-me period.
 */
export type UserSessionMeta = {
  remember: boolean;
  /** Seconds the backend session lasts after the last visit. */
  lifetime: number;
};

// Read lazily (not at module scope) so importing this file doesn't require
// JWT_SECRET to be set — e.g. Next.js's production build statically
// analyzes/imports route modules without runtime env vars present.
export function secretKey(): string {
  const key = process.env.JWT_SECRET;
  if (!key) {
    throw new Error(
      "JWT_SECRET environment variable must be set to sign/verify the user session cookie.",
    );
  }
  return key;
}

// Read when asked for, like the secret above.
export function userSessionKey(): string | undefined {
  return process.env.COOKIES_FRONTEND_SESSION_USER_KEY || undefined;
}

export function userCookieMaxAge(meta: UserSessionMeta): number {
  return meta.remember ? REMEMBER_MAX_AGE : meta.lifetime;
}

/**
 * The cookie value for the user, valid as long as the cookie itself.
 */
export function signUserSession(
  user: object,
  meta: UserSessionMeta,
): { token: string; maxAge: number } {
  const maxAge = userCookieMaxAge(meta);

  return {
    token: jwt.sign({ ...user, session: meta }, secretKey(), {
      expiresIn: maxAge,
    }),
    maxAge,
  };
}

/**
 * The verified payload of the cookie, its sign-in period (missing in
 * cookies signed before it was stored) and when it was signed.
 */
export function readUserSession(token: string): {
  payload: Record<string, unknown>;
  meta: UserSessionMeta | null;
  issuedAt: number | null;
} | null {
  try {
    const payload = jwt.verify(token, secretKey());

    if (!payload || typeof payload !== "object") {
      return null;
    }

    const session = (payload as Record<string, unknown>).session as
      Partial<UserSessionMeta> | undefined;
    const meta =
      session &&
      typeof session.remember === "boolean" &&
      typeof session.lifetime === "number" &&
      Number.isFinite(session.lifetime) &&
      session.lifetime > 0
        ? { remember: session.remember, lifetime: Math.floor(session.lifetime) }
        : null;

    return {
      payload: payload as Record<string, unknown>,
      meta,
      issuedAt: typeof payload.iat === "number" ? payload.iat : null,
    };
  } catch {
    return null;
  }
}
