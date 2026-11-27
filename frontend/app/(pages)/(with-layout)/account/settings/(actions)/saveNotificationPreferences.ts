"use server";

import { post } from "@/lib/api/admin";
import ApiResponse from "@/lib/api/response";

export type NotificationPreferenceChoice = {
  key: string;
  email_enabled: boolean;
  push_enabled: boolean;
};

/**
 * Saves the notification preferences of the signed-in user. Runs on the
 * server and answers with plain data: the raw response of the backend cannot
 * be handed to the browser.
 */
export default async function saveNotificationPreferences(
  preferences: NotificationPreferenceChoice[],
): Promise<ApiResponse> {
  try {
    // Anyone can call a server action with any arguments; the backend
    // validates the values, this only keeps the fields it expects.
    const payload = {
      preferences: (Array.isArray(preferences) ? preferences : []).map(
        (preference) => ({
          key: preference?.key,
          email_enabled: preference?.email_enabled,
          push_enabled: preference?.push_enabled,
        }),
      ),
    };

    const response = await post(
      "/api/notifications/preferences",
      payload,
      "PUT",
    );

    if (response.status === 200) {
      return {
        status: 200,
        title: "Saved",
        message: "Notification preferences updated.",
        data: {},
      };
    }

    const json = await response.json().catch(() => null);

    if (response.status === 401) {
      return {
        status: 401,
        title: "Unauthorized",
        message: "Your session expired. Please log in again.",
        data: {},
      };
    }

    return {
      status: response.status,
      title: "Error",
      message: json?.message ?? "Could not save notification preferences.",
      data: {},
    };
  } catch (error) {
    console.error(error);

    return {
      status: 500,
      title: "Error",
      message: "Could not save notification preferences.",
      data: {},
    };
  }
}
