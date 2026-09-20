"use server";
import { cookies as Cookies } from "next/headers";
import { DEFAULT_COOKIES_CONFIG } from "../cookies";
import { selectedValuesToSearchParamsString } from "@/utils/searchParams";
import HttpJsonResponse from "./interfaces/http/jsonResponse";

const baseUrl = process.env.NEXT_PUBLIC_BACKEND_URL as string;
const XSRF_KEY = process.env.COOKIES_BACKEND_XSRF_KEY as string;
const SESSION_KEY = process.env.COOKIES_BACKEND_SESSION_KEY as string;

async function updateCookies(res: Response) {
  const setCookie = res.headers.get("set-cookie") || "";

  const xsrfMatch = setCookie.match(
    new RegExp(`${XSRF_KEY}=([^;]+)`)
  );

  const sessionMatch = setCookie.match(
    new RegExp(`${SESSION_KEY}=([^;]+)`)
  );

  if (!xsrfMatch || !sessionMatch) {
    console.error("No match in set-cookies response");
    console.error("Expected XSRF cookie:", XSRF_KEY);
    console.error("Expected session cookie:", SESSION_KEY);
    console.error("Set-Cookie:", setCookie);

    return {};
  }

  const XSRF_TOKEN = decodeURIComponent(xsrfMatch[1]);
  const SESSION = sessionMatch[1];

  const cookiesStore = await Cookies();

  cookiesStore.set(
    XSRF_KEY,
    XSRF_TOKEN,
    DEFAULT_COOKIES_CONFIG
  );

  cookiesStore.set(
    SESSION_KEY,
    SESSION,
    DEFAULT_COOKIES_CONFIG
  );

  return {
    xsrf: XSRF_TOKEN,
    session: SESSION,
  };
}

async function refreshCSRF() {
  console.log("Refreshing CSRF");
  const res = await fetch(`${baseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
  });

  await updateCookies(res);
}

async function _post(uri: string, data = {}, method = "POST") {
  const cks = await Cookies();

  const SESSION = cks.get(SESSION_KEY)?.value as string;
  const XSRF_TOKEN = cks.get(XSRF_KEY)?.value as string;

  // console.log("POST", uri);
  // console.log("SESSION KEY:", SESSION);
  // console.log("SESSION exists:", !!SESSION);
  // console.log("XSRF_TOKEN exists:", !!XSRF_TOKEN);
  // console.log("POST", baseUrl + uri, data);
  // console.log("ALL NEXT COOKIES:", cks.getAll());

  // Přidáme credentials a X-XSRF-TOKEN
  const result = await fetch(`${baseUrl}${uri}`, {
    method,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      Referer: process.env.FRONTEND_URL as string,
      Cookie: `${XSRF_KEY}=${XSRF_TOKEN}; ${SESSION_KEY}=${SESSION}`,
      "X-XSRF-TOKEN": XSRF_TOKEN,
    },
    body: JSON.stringify(data),
  });

  if (result.status == 419) {
    return false;
  }

  await updateCookies(result);

  return result;
}

export async function post(uri: string, data = {}, method = "POST") {
  let result = await _post(uri, data, method);
  if (result === false) {
    // refresh CSRF
    await refreshCSRF();
    result = await _post(uri, data, method);
    if (result === false) {
      // Cannot refresch CSRF? Error!
      throw new Error("Cannot refresh CSRF."); // TODO RemoteServerError?
    }
  }
  return result;
}

export async function postJson(
  uri: string,
  data = {}
): Promise<HttpJsonResponse | null> {
  const result = await post(uri, data);

  try {
    if (result.status == 204) {
      return {
        code: result.status,
        data: null,
      };
    }
    return handleBackendException(await result.json(), result);
  } catch (e) {
    console.error(e);
    return null;
  }
}

export async function deleteJson(
  uri: string,
  data = {}
): Promise<HttpJsonResponse | null> {
  const result = await post(uri, data, "DELETE");

  try {
    if (result.status == 204) {
      return {
        code: result.status,
        data: null,
      };
    }
    return handleBackendException(await result.json(), result);
  } catch (e) {
    console.error(e);
    return null;
  }
}

function handleBackendException(
  jsonContent: any,
  response: Response
): HttpJsonResponse {
  if (jsonContent.error && response.status !== 200) {
    return {
      code: response.status,
      message: jsonContent.error,
      data: null,
    };
  }
  if (jsonContent.errors && response.status !== 200) {
    return {
      code: response.status,
      message: jsonContent.message,
      errors: jsonContent.errors,
      data: null,
    };
  }
  if (jsonContent.exception && response.status !== 200) {
    return {
      code: response.status,
      message: jsonContent.exception,
      data: null,
    };
  }
  if (response.status !== 200 && response.status !== 201) {
    return {
      code: response.status,
      message: jsonContent.message,
      data: null,
    };
  }

  return {
    code: response.status,
    data: jsonContent,
  };
}

async function _get(
  uri: string,
  data:
    | string
    | {
      [key: string]: Set<string | number>;
    } = {},
  signal?: AbortSignal
) {
  const cks = await Cookies();

  const SESSION = cks.get(SESSION_KEY)?.value as string;
  const XSRF_TOKEN = cks.get(XSRF_KEY)?.value as string;
  // Filter data
  // data = Object.fromEntries(
  //   Object.entries(data).filter(
  //     ([_, value]) =>
  //       value !== undefined &&
  //       value !== "undefined" &&
  //       value?.toString().trim() !== ""
  //   )
  // );

  // Add params
  let queryString = data;
  if (data instanceof Object)
    queryString = selectedValuesToSearchParamsString(data);
  if (queryString) {
    uri = `${uri}?${queryString}`;
  }

  console.log("TO BE", uri);

  // Přidáme credentials a X-XSRF-TOKEN
  const result = await fetch(`${baseUrl}${uri}`, {
    method: "get",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      Referer: process.env.FRONTEND_URL as string,
      Cookie: `${XSRF_KEY}=${XSRF_TOKEN}; ${SESSION_KEY}=${SESSION}`,
      "X-XSRF-TOKEN": XSRF_TOKEN,
    },
  });

  if (result.status == 419) {
    return false;
  }

  // await updateCookies(result);

  return result;
}

export async function get(
  uri: string,
  data:
    | string
    | {
      [key: string]: Set<string | number>;
    } = {},
  signal?: AbortSignal
) {
  "use server";
  let result = await _get(uri, data, signal);
  if (result === false) {
    console.log("Repeating request");
    // refresh CSRF
    await refreshCSRF();
    result = await _get(uri, data, signal);
    if (result === false) {
      // Cannot refresch CSRF? Error!
      throw new Error("Cannot refresh CSRF."); // TODO RemoteServerError?
    }
  }
  return result;
}

export async function getJson(
  uri: string,
  data = {},
  signal?: AbortSignal
): Promise<HttpJsonResponse | null> {
  const result = await get(uri, data, signal);

  try {
    return handleBackendException(await result.json(), result);
  } catch {
    return null;
  }
}
