"use client";

import { useDockAlign } from "@/components/_core/layout/FloatingDock";
import {
  DownloaderCategory,
  useDownloader,
} from "@/components/_core/providers/downloader";
import { Badge, Button, Tooltip } from "@heroui/react";
import { motion } from "framer-motion";
import Link from "next/link";
import { useEffect, useState } from "react";
import {
  FiArrowRight,
  FiCheck,
  FiDownload,
  FiPlus,
  FiX,
} from "react-icons/fi";

const VISIBLE_ITEMS_PER_CATEGORY = 3;

const CATEGORY_LABELS: Record<DownloaderCategory, string> = {
  membrane: "Membranes",
  method: "Methods",
  molecule: "Molecules",
  protein: "Proteins",
};

const CATEGORY_ITEM_LABELS: Record<DownloaderCategory, string> = {
  membrane: "membrane",
  method: "method",
  molecule: "molecule",
  protein: "protein",
};

export default function DownloaderWidget() {
  const align = useDockAlign();
  const downloader = useDownloader();
  const { isAdded, addItem, removeItem } = downloader;
  const [isOpen, setIsOpen] = useState(false);
  const [bounceKey, setBounceKey] = useState(0);
  const [isMounted, setIsMounted] = useState(false);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- mounting is the whole point
    setIsMounted(true);
  }, []);

  // The dock streams in late: by the time it hydrates, the stored items and
  // the page's suggestion are already in the context, while the server HTML
  // has neither. The first render therefore repeats the server's empty
  // state, otherwise React reports a hydration mismatch (error #418).
  const items = isMounted ? downloader.items : [];
  const count = isMounted ? downloader.count : 0;
  const suggestedItem = isMounted ? downloader.suggestedItem : null;

  useEffect(() => {
    if (count > 0) {
      setBounceKey((current) => current + 1);
    }
  }, [count]);

  const categories: DownloaderCategory[] = [
    "membrane",
    "method",
    "molecule",
    "protein",
  ];
  const isSuggestedItemAdded = suggestedItem
    ? isAdded(suggestedItem.category, suggestedItem.id)
    : false;

  return (
    <div className="pointer-events-auto relative hidden sm:block">
      <div
          aria-hidden={!isOpen}
          className={[
            "absolute bottom-[calc(100%+12px)]",
            align === "left"
              ? "left-0 origin-bottom-left"
              : "right-0 origin-bottom-right",
            "max-h-[calc(100dvh-6rem)] w-[340px] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-default-200 bg-white shadow-2xl transition-all duration-200 ease-out motion-reduce:transition-none dark:border-default-100 dark:bg-zinc-950",
            isOpen
              ? "pointer-events-auto translate-y-0 scale-100 opacity-100"
              : "pointer-events-none translate-y-3 scale-95 opacity-0",
          ].join(" ")}
          inert={!isOpen ? true : undefined}
        >
          <div className="flex items-center justify-between gap-3 border-b border-default-200 px-4 py-3 dark:border-default-100">
            <span className="text-sm font-semibold text-foreground">
              Downloader ({count})
            </span>
            <Button
              isIconOnly
              aria-label="Close downloader"
              size="sm"
              variant="light"
              onPress={() => setIsOpen(false)}
            >
              <FiX />
            </Button>
          </div>

          <div className="flex flex-col gap-3 p-3">
            {count === 0 ? (
              <p className="text-sm text-foreground-500">
                Nothing added yet. Add records from Search or while browsing
                their detail pages.
              </p>
            ) : (
              categories.map((category) => {
                const categoryItems = items.filter(
                  (item) => item.category === category,
                );

                if (categoryItems.length === 0) {
                  return null;
                }

                const visibleItems = categoryItems.slice(
                  0,
                  VISIBLE_ITEMS_PER_CATEGORY,
                );
                const hiddenItemsCount =
                  categoryItems.length - visibleItems.length;

                return (
                  <div key={category} className="flex flex-col gap-1.5">
                    <span className="text-xs font-semibold uppercase tracking-wide text-foreground-500">
                      {CATEGORY_LABELS[category]}
                    </span>
                    <div className="flex flex-col gap-1">
                      {visibleItems.map((item) => (
                        <div
                          key={`${item.category}-${item.id}`}
                          className="flex min-h-8 items-center justify-between gap-2 rounded-md bg-default-100 px-2 py-1 dark:bg-default-50/10"
                        >
                          <span className="truncate text-xs">
                            {item.label}
                          </span>
                          <Button
                            isIconOnly
                            aria-label={`Remove ${item.label}`}
                            className="h-6 min-h-6 w-6 min-w-6"
                            size="sm"
                            variant="light"
                            onPress={() => removeItem(item.category, item.id)}
                          >
                            <FiX className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      ))}
                      {hiddenItemsCount > 0 ? (
                        <Button
                          as={Link}
                          className="h-7 justify-between px-2 text-xs text-foreground-500"
                          endContent={<FiArrowRight className="h-3.5 w-3.5" />}
                          href="/downloader"
                          size="sm"
                          variant="light"
                          onPress={() => setIsOpen(false)}
                        >
                          + {hiddenItemsCount} more
                        </Button>
                      ) : null}
                    </div>
                  </div>
                );
              })
            )}

            <Button
              as={Link}
              color="primary"
              href="/downloader"
              startContent={<FiDownload />}
              onPress={() => setIsOpen(false)}
            >
              Go to downloader
            </Button>
          </div>
      </div>

      {!isOpen && suggestedItem ? (
        <motion.div
            initial={{ opacity: 0, scale: 0.94, y: 6 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            className={[
              "absolute bottom-[calc(100%+11px)]",
              align === "left" ? "left-0" : "right-0",
            ].join(" ")}
          >
            <Button
              className="whitespace-nowrap border-default-200 bg-white shadow-lg dark:border-default-100 dark:bg-zinc-950"
              color={isSuggestedItemAdded ? "success" : "primary"}
              size="sm"
              startContent={isSuggestedItemAdded ? <FiCheck /> : <FiPlus />}
              variant="bordered"
              onPress={() =>
                isSuggestedItemAdded
                  ? removeItem(suggestedItem.category, suggestedItem.id)
                  : addItem(suggestedItem)
              }
            >
              {isSuggestedItemAdded
                ? "Added"
                : `Add ${CATEGORY_ITEM_LABELS[suggestedItem.category]}`}
            </Button>
            <span
              className={[
                "pointer-events-none absolute -bottom-1.5 h-3 w-3 rotate-45 border-b border-r border-default-200 bg-white dark:border-default-100 dark:bg-zinc-950",
                align === "left" ? "left-[18px]" : "right-[18px]",
              ].join(" ")}
            />
        </motion.div>
      ) : null}

      <Tooltip content="Downloader" placement="top">
        <Button
            isIconOnly
            aria-label="Open downloader"
            className="pointer-events-auto h-12 w-12 shadow-lg"
            color="primary"
            radius="full"
            onPress={() => setIsOpen((current) => !current)}
          >
            <motion.span
              key={bounceKey}
              animate={{ scale: [1, 1.4, 1] }}
              transition={{ duration: 0.35 }}
            >
              <Badge
                color="danger"
                content={count}
                isInvisible={count === 0}
                shape="circle"
              >
                <FiDownload className="h-5 w-5" />
              </Badge>
            </motion.span>
        </Button>
      </Tooltip>
    </div>
  );
}
