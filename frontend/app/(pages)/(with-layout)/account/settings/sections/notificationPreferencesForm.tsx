"use client";

import { getJson } from "@/lib/api/admin";
import { useHandle401 } from "@/lib/api/admin/redirections";
import saveNotificationPreferences from "../(actions)/saveNotificationPreferences";
import {
  getExistingPushSubscription,
  isPushSupported,
  subscribeToPush,
  unsubscribeFromPush,
} from "@/lib/api/frontend/pushNotifications";
import {
  addToast,
  Button,
  Card,
  CardBody,
  CardHeader,
  Divider,
  Spinner,
  Switch,
} from "@heroui/react";
import { useEffect, useMemo, useState, useSyncExternalStore } from "react";

/** Push support does not change while the page is open. */
function subscribeToNothing() {
  return () => {};
}

type Preference = {
  key: string;
  label: string;
  group: string;
  email_enabled: boolean;
  push_enabled: boolean;
};

export default function NotificationPreferencesForm() {
  const [preferences, setPreferences] = useState<Preference[] | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [pushEnabled, setPushEnabled] = useState(false);
  const [pushBusy, setPushBusy] = useState(false);
  const handle401 = useHandle401();
  // Known only in the browser: the server render says "not supported" and
  // the browser reads the real answer after hydration.
  const pushSupported = useSyncExternalStore(
    subscribeToNothing,
    isPushSupported,
    () => false,
  );

  useEffect(() => {
    (async () => {
      const response = await getJson("/api/notifications/preferences");

      if (response && response.code === 200) {
        setPreferences((response.data?.data as Preference[]) ?? []);
      } else if (response?.code === 401) {
        // The backend session ended while the frontend still holds the user.
        handle401();

        return;
      }

      const subscription = await getExistingPushSubscription();
      setPushEnabled(Boolean(subscription));
    })();
    // Loads once: handle401 is a new function on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const grouped = useMemo(() => {
    const groups = new Map<string, Preference[]>();

    for (const preference of preferences ?? []) {
      const list = groups.get(preference.group) ?? [];
      list.push(preference);
      groups.set(preference.group, list);
    }

    return groups;
  }, [preferences]);

  function updatePreference(
    key: string,
    field: "email_enabled" | "push_enabled",
    value: boolean,
  ) {
    setPreferences((prev) =>
      (prev ?? []).map((preference) =>
        preference.key === key ? { ...preference, [field]: value } : preference,
      ),
    );
  }

  async function handleSave() {
    setIsSaving(true);

    try {
      const response = await saveNotificationPreferences(preferences ?? []);

      addToast({
        title: response.title,
        description: response.message,
        color: response.status === 200 ? "success" : "danger",
      });
    } catch {
      addToast({
        title: "Error",
        description: "Could not save notification preferences.",
        color: "danger",
      });
    } finally {
      setIsSaving(false);
    }
  }

  async function handleTogglePush(enabled: boolean) {
    setPushBusy(true);

    try {
      if (enabled) {
        const success = await subscribeToPush();
        setPushEnabled(success);

        if (!success) {
          addToast({
            title: "Could not enable push",
            description: "Notification permission was not granted.",
            color: "warning",
          });
        }
      } else {
        await unsubscribeFromPush();
        setPushEnabled(false);
      }
    } finally {
      setPushBusy(false);
    }
  }

  return (
    <Card
      id="notifications"
      shadow="sm"
      className="border border-default-200/70 scroll-mt-24"
    >
      <CardHeader className="flex flex-col items-start gap-1">
        <h2 className="text-xl font-semibold">Notifications</h2>
        <p className="text-sm text-default-500">
          Choose which notifications you receive by email or push, and enable
          push notifications on this device.
        </p>
      </CardHeader>
      <Divider />
      <CardBody className="gap-6">
        <div className="flex items-center justify-between gap-4 rounded-md bg-default-100 px-4 py-3">
          <div>
            <p className="font-medium">Push notifications on this device</p>
            <p className="text-sm text-default-500">
              {pushSupported
                ? "Requires browser permission."
                : "Not supported in this browser."}
            </p>
          </div>
          <Switch
            isSelected={pushEnabled}
            isDisabled={!pushSupported || pushBusy}
            onValueChange={handleTogglePush}
          />
        </div>

        {preferences === null ? (
          <Spinner label="Loading preferences..." />
        ) : (
          <>
            {[...grouped.entries()].map(([group, items]) => (
              <div key={group} className="flex flex-col gap-3">
                <h3 className="text-sm font-semibold text-default-600">
                  {group}
                </h3>
                <div className="flex flex-col gap-2">
                  {items.map((preference) => (
                    <div
                      key={preference.key}
                      className="flex flex-col gap-2 rounded-md border border-default-200/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <span className="text-sm">{preference.label}</span>
                      <div className="flex items-center gap-6">
                        <label className="flex items-center gap-2 text-xs text-default-500">
                          Email
                          <Switch
                            size="sm"
                            isSelected={preference.email_enabled}
                            onValueChange={(value) =>
                              updatePreference(preference.key, "email_enabled", value)
                            }
                          />
                        </label>
                        <label className="flex items-center gap-2 text-xs text-default-500">
                          Push
                          <Switch
                            size="sm"
                            isSelected={preference.push_enabled}
                            onValueChange={(value) =>
                              updatePreference(preference.key, "push_enabled", value)
                            }
                          />
                        </label>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}

            <Button
              color="primary"
              className="self-start"
              isLoading={isSaving}
              onPress={handleSave}
            >
              Save preferences
            </Button>
          </>
        )}
      </CardBody>
    </Card>
  );
}
