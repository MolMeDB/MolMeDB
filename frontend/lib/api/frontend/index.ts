"use server";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import HttpJsonResponse from "../admin/interfaces/http/jsonResponse";
import logger from "@/lib/logger";

const DOMAIN = (process.env.NEXT_SELF_URL ?? process.env.FRONTEND_URL) as string;
const XSRF_KEY = process.env.COOKIES_BACKEND_XSRF_KEY as string;
const FE_SESSION_KEY = process.env.COOKIES_FRONTEND_SESSION_KEY as string;

export async function getApiRequestHeaders(path: string): Promise<RequestInit> {
  const cookiesStore = await cookies();
  const SESSION = cookiesStore.get(FE_SESSION_KEY)?.value as string;
  const XSRF_TOKEN = cookiesStore.get(XSRF_KEY)?.value as string;
  // With "remember me" the backend signs the user in again from these once
  // its session has ended.
  const remember = cookiesStore
    .getAll()
    .filter((cookie) => cookie.name.startsWith("remember_"))
    .map((cookie) => `; ${cookie.name}=${cookie.value}`)
    .join("");

  return {
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      Referer: process.env.FRONTEND_URL as string,
      Cookie: `${XSRF_KEY}=${XSRF_TOKEN}; ${FE_SESSION_KEY}=${SESSION}${remember}`,
      "Forward-To": path,
    },
  };
}

/**
 * @param returnTo the page to come back to after signing in again, when the
 *   backend says the session has ended
 */
export async function getViewData(
  path: string,
  params = {},
  returnTo?: string,
): Promise<HttpJsonResponse | null> {
  const queryString = new URLSearchParams(params).toString();
  let endpoint = "/api/wrapper/viewData";
  if (queryString) {
    endpoint = `${endpoint}?${queryString}`;
  }

  const url = `${DOMAIN}${endpoint}`;
  logger.info(`getViewData → GET ${url}`, { path, params });

  let redirectTo = null;

  try {
    const response = await fetch(url, {
      ...(await getApiRequestHeaders(path)),
      method: "get",
    });

    logger.info(`getViewData ← ${response.status}`, { path });

    if (response.status == 401) {
      logger.warn(`getViewData → 401 unauthenticated`, { path });
      redirectTo = returnTo
        ? `/login?redirect=${encodeURIComponent(returnTo)}&expired=1`
        : "/login?expired=1";
    } else if (response.status == 200) {
      const d = await response.json();
      return {
        code: response.status,
        data: d,
      };
    } else {
      const text = await response.text();
      logger.error(`getViewData → unexpected status ${response.status}`, { path, body: text });
      return {
        code: response.status,
        message: text,
      };
    }
  } catch (e) {
    logger.error(`getViewData → fetch failed`, { path, url, error: e });
    return {
      code: 500,
      message: "Neplatná odpověď serveru. Zkuste to znovu.",
    };
  }

  if (redirectTo) redirect(redirectTo);

  return null;
}

