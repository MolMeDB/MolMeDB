"use client";

import {
  Badge,
  Button,
  Dropdown,
  DropdownItem,
  DropdownMenu,
  DropdownTrigger,
  Spinner,
  Tooltip,
} from "@heroui/react";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { FiBell, FiTrash2 } from "react-icons/fi";
import { htmlToText } from "@/utils/htmlToText";

type UserNotification = {
  id: number;
  state: "new" | "read";
  title: string;
  body: string;
  created_at?: string | null;
  read_at?: string | null;
};

type NotificationsResponse = {
  data?: UserNotification[];
  meta?: {
    unread_count?: number;
  };
};

export default function SiteNotificationsBell() {
  const [notifications, setNotifications] = useState<UserNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const [isClearing, setIsClearing] = useState(false);

  const loadNotifications = useCallback(async () => {
    setIsLoading(true);

    try {
      const response = await fetch("/api/notifications", {
        headers: {
          Accept: "application/json",
        },
      });

      if (!response.ok) {
        return;
      }

      const json = (await response.json()) as NotificationsResponse;
      setNotifications(json.data ?? []);
      setUnreadCount(json.meta?.unread_count ?? 0);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadNotifications();
  }, [loadNotifications]);

  async function clearAll() {
    setIsClearing(true);
    setNotifications([]);
    setUnreadCount(0);

    try {
      await fetch("/api/notifications/clear", { method: "DELETE" });
    } finally {
      setIsClearing(false);
    }
  }

  async function markAsRead() {
    if (unreadCount === 0) {
      return;
    }

    setUnreadCount(0);
    setNotifications((current) =>
      current.map((notification) => ({
        ...notification,
        state: "read",
      })),
    );

    await fetch("/api/notifications/read", {
      method: "POST",
      headers: {
        Accept: "application/json",
      },
    });
  }

  return (
    <Dropdown
      placement="bottom-end"
      onOpenChange={(isOpen) => {
        if (isOpen) {
          markAsRead();
        }
      }}
    >
      <DropdownTrigger>
        <Button
          isIconOnly
          aria-label="Notifications"
          radius="full"
          variant="light"
        >
          <Badge
            color="danger"
            content={unreadCount > 9 ? "9+" : unreadCount}
            isInvisible={unreadCount === 0}
            shape="circle"
          >
            <FiBell className="h-5 w-5" />
          </Badge>
        </Button>
      </DropdownTrigger>
      <DropdownMenu
        aria-label="Notifications"
        className="w-80 p-0"
        classNames={{ list: "max-h-96 overflow-y-auto" }}
        emptyContent={isLoading ? "Loading notifications..." : "No notifications"}
        disabledKeys={["loading", "header"]}
        topContent={
          <div className="flex items-center justify-between px-3 py-2 border-b border-divider">
            <Link
              href="/account/notifications"
              className="text-sm font-semibold text-foreground hover:text-primary"
            >
              All notifications
            </Link>
            <Tooltip content="Clear all">
              <Button
                isIconOnly
                size="sm"
                variant="light"
                isLoading={isClearing}
                isDisabled={notifications.length === 0}
                onPress={clearAll}
                className="text-default-400 hover:text-danger"
              >
                {!isClearing && <FiTrash2 size={15} />}
              </Button>
            </Tooltip>
          </div>
        }
      >
        {isLoading ? (
          <DropdownItem key="loading" textValue="Loading">
            <div className="flex items-center justify-center py-4">
              <Spinner size="sm" />
            </div>
          </DropdownItem>
        ) : (
          notifications.map((notification) => (
            <DropdownItem
              key={notification.id}
              textValue={notification.title}
              href={`/account/notifications/${notification.id}`}
              className={notification.state === "new" ? "bg-primary-50 dark:bg-primary-900/20" : ""}
            >
              <div className="flex flex-col gap-1 whitespace-normal py-1">
                <div className="text-sm font-semibold">
                  {notification.title}
                </div>
                {/* The beginning only: the whole message is on its page. */}
                <div className="text-xs text-foreground-600 line-clamp-3">
                  {htmlToText(notification.body)}
                </div>
                {notification.created_at ? (
                  <div className="text-[11px] text-foreground-400">
                    {new Date(notification.created_at).toLocaleString()}
                  </div>
                ) : null}
              </div>
            </DropdownItem>
          ))
        )}
      </DropdownMenu>
    </Dropdown>
  );
}
