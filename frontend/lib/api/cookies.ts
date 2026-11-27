import jwt from "jsonwebtoken";
import { cookies } from "next/headers";
import { UserSession } from "./admin/interfaces/User";
import {
  DEFAULT_COOKIE_MAX_AGE,
  DEFAULT_COOKIES_CONFIG,
  secretKey,
  signUserSession,
  userSessionKey,
  UserSessionMeta,
} from "./userSessionCookie";

export { DEFAULT_COOKIES_CONFIG };

type UserCookieOptions = {
  expiresAt?: string;
  remember?: boolean;
};

function maxAgeFromExpiresAt(expiresAt?: string): number | null {
  if (!expiresAt) {
    return null;
  }

  const expiresAtTime = Date.parse(expiresAt);
  if (!Number.isFinite(expiresAtTime)) {
    return null;
  }

  return Math.max(1, Math.floor((expiresAtTime - Date.now()) / 1000));
}

/**
 * The backend tells when its session ends; that length is its lifetime,
 * prolonged by each visit (see proxy.ts). "Remember me" keeps the user
 * signed in for 400 days: when the backend session ends, the backend signs
 * them in again from its own remember cookie.
 */
function sessionMeta(options: UserCookieOptions = {}): UserSessionMeta {
  return {
    remember: Boolean(options.remember),
    lifetime: maxAgeFromExpiresAt(options.expiresAt) ?? DEFAULT_COOKIE_MAX_AGE,
  };
}

function sign(data: object, maxAge = DEFAULT_COOKIE_MAX_AGE) {
  return jwt.sign(data, secretKey(), { expiresIn: maxAge });
}

function unsign(token: string) {
  return jwt.verify(token, secretKey());
}

function normalizeUserData(data: unknown): UserSession | null {
  if (!data || typeof data !== "object") {
    return null;
  }

  const payload = data as Record<string, unknown>;
  const source =
    payload.user && typeof payload.user === "object"
      ? (payload.user as Record<string, unknown>)
      : payload;

  const rawId = source.id;
  const id =
    typeof rawId === "number"
      ? rawId
      : typeof rawId === "string"
        ? Number(rawId)
        : undefined;

  if (!id || !Number.isFinite(id)) {
    return null;
  }

  return {
    id,
    first_name:
      typeof source.first_name === "string" ? source.first_name : undefined,
    last_name:
      typeof source.last_name === "string" ? source.last_name : undefined,
    name: typeof source.name === "string" ? source.name : undefined,
    email: typeof source.email === "string" ? source.email : undefined,
  };
}

async function setUserData(data: object, options: UserCookieOptions = {}) {
  "use server";
  const user = normalizeUserData(data);
  const key = userSessionKey();

  if (!user || !key) {
    return;
  }

  const cookiesStore = await cookies();
  const { token, maxAge } = signUserSession(user, sessionMeta(options));

  cookiesStore.set(key, token, {
    ...DEFAULT_COOKIES_CONFIG,
    maxAge,
  });
}

async function getUserData() {
  "use server";
  // The request is read before anything else. A page that asks who is
  // signed in must be rendered for each request; returning early here let
  // the build render such pages once, for nobody, and serve that to
  // everybody: a header without the user, and account settings that only
  // ever redirected to the login.
  const cookiesStore = await cookies();
  const key = userSessionKey();

  if (!key) {
    return null;
  }

  const cookieValue = cookiesStore.get(key)?.value as string;
  if (!cookieValue) {
    return null;
  }

  try {
    return normalizeUserData(unsign(cookieValue));
  } catch {
    return null;
  }
}

export const Cookie = {
  sign,
  unsign,
  setUserData,
  getUserData,
};
