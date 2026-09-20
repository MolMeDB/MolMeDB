"use server";

import { postJson, deleteJson } from "@/lib/api/admin";

export async function subscribeToPush(subscription: {
  endpoint: string;
  keys: {
    p256dh: string;
    auth: string;
  };
}) {
  return postJson("/api/push-subscriptions", subscription);
}

export async function unsubscribeFromPush(endpoint: string) {
  return deleteJson("/api/push-subscriptions", {
    endpoint,
  });
}