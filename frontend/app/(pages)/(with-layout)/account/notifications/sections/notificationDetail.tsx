"use client";

import SanitizedHtml from "@/components/_core/SanitizedHtml";
import { getJson, postJson } from "@/lib/api/admin";
import { useHandle401 } from "@/lib/api/admin/redirections";
import { isWebAddress } from "@/utils/htmlToText";
import {
  Card,
  CardBody,
  CardHeader,
  Divider,
  Link as HeroLink,
  Spinner,
} from "@heroui/react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { UserNotificationDetail } from "../types";

/**
 * One notification with its whole message (a push or the bell shows only
 * its beginning). Opening it marks it as read.
 */
export default function NotificationDetail(props: { id: number }) {
  const [notification, setNotification] =
    useState<UserNotificationDetail | null>(null);
  const [status, setStatus] = useState<
    "loading" | "ready" | "missing" | "failed"
  >("loading");
  const handle401 = useHandle401();

  useEffect(() => {
    (async () => {
      const response = await getJson(`/api/notifications/${props.id}`);

      if (response?.code === 200 && response.data?.data) {
        const loaded = response.data.data as UserNotificationDetail;
        setNotification(loaded);
        setStatus("ready");

        if (loaded.state === "new") {
          await postJson(`/api/notifications/${props.id}/read`);
        }
      } else if (response?.code === 401) {
        handle401();
      } else if (response?.code === 404) {
        setStatus("missing");
      } else {
        setStatus("failed");
      }
    })();
    // Loads once per notification: handle401 is a new function on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [props.id]);

  if (status === "loading") {
    return (
      <div className="flex justify-center py-8">
        <Spinner aria-label="Loading the notification" />
      </div>
    );
  }

  if (status !== "ready" || !notification) {
    return (
      <div className="flex flex-col items-start gap-4">
        <p
          className={
            status === "missing" ? "text-foreground-600" : "text-danger"
          }
        >
          {status === "missing"
            ? "This notification does not exist or was cleared."
            : "The notification could not be loaded."}
        </p>
        <AllNotificationsLink />
      </div>
    );
  }

  return (
    <Card shadow="sm">
      <CardHeader className="flex flex-col items-start gap-1">
        <h2 className="text-xl font-semibold">{notification.title}</h2>
        {notification.created_at ? (
          <span className="text-sm text-foreground-500">
            {new Date(notification.created_at).toLocaleString()}
          </span>
        ) : null}
      </CardHeader>
      <Divider />
      <CardBody className="flex flex-col gap-6">
        <SanitizedHtml
          html={notification.message}
          className="notification-message break-words [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mb-2 [&_a]:text-primary [&_a]:underline"
        />
        <div className="flex flex-wrap gap-3">
          {isWebAddress(notification.url) ? (
            <HeroLink
              href={notification.url}
              isBlock
              showAnchorIcon
              color="primary"
            >
              Open the related page
            </HeroLink>
          ) : null}
          <AllNotificationsLink />
        </div>
      </CardBody>
    </Card>
  );
}

function AllNotificationsLink() {
  return (
    <Link
      href="/account/notifications"
      className="text-primary underline-offset-4 hover:underline px-2 py-1"
    >
      All notifications
    </Link>
  );
}
