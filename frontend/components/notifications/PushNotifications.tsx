"use client";

import { useEffect } from "react";
import { subscribeToPush } from "@/lib/api/frontend/pushNotifications";

function urlBase64ToUint8Array(base64String: string) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, "+")
        .replace(/_/g, "/");

    const rawData = window.atob(base64);

    return Uint8Array.from(
        [...rawData].map((char) => char.charCodeAt(0))
    );
}

export default function PushNotifications() {
    useEffect(() => {
        async function registerPush() {
            if (!("serviceWorker" in navigator)) {
                console.log("Service Worker is not supported.");
                return;
            }

            if (!("PushManager" in window)) {
                console.log("Push notifications are not supported.");
                return;
            }

            // Register the service worker
            const registration =
                await navigator.serviceWorker.register("/sw.js");

            console.log("Service worker registered:", registration);

            // Wait until the service worker is active
            await navigator.serviceWorker.ready;

            console.log("Service worker ready:", registration);

            const existingSubscription =
                await registration.pushManager.getSubscription();

            let subscription = existingSubscription;

            if (subscription) {
                console.log(
                    "Push subscription already exists:",
                    subscription
                );
            } else {
                const publicKey =
                    process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY;

                if (!publicKey) {
                    console.error(
                        "NEXT_PUBLIC_VAPID_PUBLIC_KEY is missing."
                    );
                    return;
                }

                subscription =
                    await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey:
                            urlBase64ToUint8Array(publicKey),
                    });

                console.log(
                    "Push subscription created:",
                    subscription
                );
            }

            const subscriptionJson = subscription.toJSON();

            console.log(
                "Sending subscription to backend:",
                subscriptionJson
            );

            if (
                !subscriptionJson.endpoint ||
                !subscriptionJson.keys?.p256dh ||
                !subscriptionJson.keys?.auth
            ) {
                console.error(
                    "Invalid push subscription data:",
                    subscriptionJson
                );
                return;
            }

            const result = await subscribeToPush({
                endpoint: subscriptionJson.endpoint,
                keys: {
                    p256dh: subscriptionJson.keys.p256dh,
                    auth: subscriptionJson.keys.auth,
                },
            });

            console.log("Backend response:", result);
        }

        registerPush().catch((error) => {
            console.error("Push registration failed:", error);
        });
    }, []);

    return null;
}