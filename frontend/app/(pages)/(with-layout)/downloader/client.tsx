"use client";

import {
  DownloaderCategory,
  useDownloader,
} from "@/components/_core/providers/downloader";
import { getJson, postJson } from "@/lib/api/admin";
import { downloadFile } from "@/utils/downloadFile";
import {
  addToast,
  Alert,
  Button,
  Checkbox,
  Kbd,
  Progress,
  Spinner,
} from "@heroui/react";
import { useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { FiDownload, FiSearch, FiTrash2 } from "react-icons/fi";

const CATEGORY_LABELS: Record<DownloaderCategory, string> = {
  membrane: "Membranes",
  method: "Methods",
  molecule: "Molecules",
  protein: "Proteins",
};

type VerifyResult = {
  passive: number;
  active: number;
  total: number;
};

type ExportState = "idle" | "creating" | "running" | "done" | "error";

type DownloadStatus = {
  uuid: string;
  state: "pending" | "running" | "done" | "error";
  progress: { processed: number; total: number; percent: number } | null;
  error_message: string | null;
  restarted: boolean;
};

const POLL_INTERVAL_MS = 3000;

export default function DownloaderClient() {
  const { items, removeItem, setIncluded, setCategoryIncluded } =
    useDownloader();

  const [verifyResult, setVerifyResult] = useState<VerifyResult | null>(null);
  const [isVerifying, setIsVerifying] = useState(false);
  const [exportState, setExportState] = useState<ExportState>("idle");
  const [progress, setProgress] = useState<{ processed: number; total: number; percent: number } | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [downloadUuid, setDownloadUuid] = useState<string | null>(null);
  const [wasRestarted, setWasRestarted] = useState(false);
  const pollRef = useRef<number | null>(null);
  const searchParams = useSearchParams();

  const selection = useMemo(() => {
    return {
      membrane_ids: items
        .filter((item) => item.category === "membrane" && item.included)
        .map((item) => Number(item.id)),
      method_ids: items
        .filter((item) => item.category === "method" && item.included)
        .map((item) => Number(item.id)),
      protein_ids: items
        .filter((item) => item.category === "protein" && item.included)
        .map((item) => Number(item.id)),
      structure_identifiers: items
        .filter((item) => item.category === "molecule" && item.included)
        .map((item) => item.id),
    };
  }, [items]);

  const hasSelection = Object.values(selection).some(
    (selectedItems) => selectedItems.length > 0,
  );

  const selectionKey = JSON.stringify(selection);

  useEffect(() => {
    setVerifyResult(null);
    setExportState("idle");
    setErrorMessage(null);
    setWasRestarted(false);
  }, [selectionKey]);

  useEffect(() => {
    return () => {
      if (pollRef.current) {
        window.clearInterval(pollRef.current);
      }
    };
  }, []);

  const startPolling = useCallback((uuid: string) => {
    if (pollRef.current) {
      window.clearInterval(pollRef.current);
    }

    pollRef.current = window.setInterval(async () => {
      const response = await getJson(`/api/downloader/${uuid}`);

      if (!response || response.code !== 200) {
        return;
      }

      const status = response.data?.data as DownloadStatus;

      if (status.restarted) {
        setWasRestarted(true);
      }

      if (status.progress) {
        setProgress(status.progress);
      }

      if (status.state === "done") {
        setExportState("done");

        if (pollRef.current) {
          window.clearInterval(pollRef.current);
        }
      } else if (status.state === "error") {
        setExportState("error");
        setErrorMessage(status.error_message ?? "Export failed.");

        if (pollRef.current) {
          window.clearInterval(pollRef.current);
        }
      }
    }, POLL_INTERVAL_MS);
  }, []);

  // Resume tracking an export started earlier (e.g. from a "your export is
  // ready" notification link) without requiring the original selection to
  // still be in the downloader context.
  useEffect(() => {
    const uuid = searchParams.get("uuid");

    if (!uuid) {
      return;
    }

    setDownloadUuid(uuid);
    setExportState("running");
    startPolling(uuid);
  }, [searchParams, startPolling]);

  async function handleVerify() {
    setIsVerifying(true);
    setErrorMessage(null);

    try {
      const response = await postJson("/api/downloader/verify", selection);

      if (!response || response.code !== 200) {
        throw new Error(response?.message ?? "Interaction search failed.");
      }

      setVerifyResult(response.data?.data as VerifyResult);
    } catch {
      addToast({
        title: "Error",
        description: "Could not find interactions. Please, try again.",
        color: "danger",
        shouldShowTimeoutProgress: true,
        timeout: 8000,
      });
    } finally {
      setIsVerifying(false);
    }
  }

  async function handleStartExport() {
    setExportState("creating");
    setErrorMessage(null);
    setProgress(null);
    setWasRestarted(false);

    try {
      const response = await postJson("/api/downloader/", selection);

      if (!response || ![200, 201].includes(response.code)) {
        throw new Error(response?.message ?? "Could not start the export.");
      }

      const uuid = (response.data?.data as { uuid: string }).uuid;
      setDownloadUuid(uuid);
      setExportState("running");
      startPolling(uuid);
    } catch {
      setExportState("error");
      setErrorMessage("Could not start the export. Please, try again.");
    }
  }

  async function handleDownload() {
    if (!downloadUuid) {
      return;
    }

    try {
      await downloadFile(
        `/api/downloader/file/${downloadUuid}`,
        "molmedb_export.zip",
      );
    } catch (e) {
      console.log(e)
      addToast({
        title: "Error",
        description: "Download failed. Please, try again.",
        color: "danger",
        shouldShowTimeoutProgress: true,
        timeout: 8000,
      });
    }
  }

  function renderCategory(category: DownloaderCategory) {
    const categoryItems = items.filter((item) => item.category === category);
    const allIncluded =
      categoryItems.length > 0 &&
      categoryItems.every((item) => item.included);

    return (
      <div className="flex min-h-0 flex-col gap-2">
        <Checkbox
          isDisabled={categoryItems.length === 0}
          isSelected={allIncluded}
          onValueChange={(checked) =>
            setCategoryIncluded(category, checked)
          }
        >
          <span className="font-semibold">
            {CATEGORY_LABELS[category]} ({categoryItems.length})
          </span>
        </Checkbox>
        <div className="max-h-56 overflow-y-auto pl-7 pr-1">
          {categoryItems.length > 0 ? (
            <div className="flex flex-col gap-1">
              {categoryItems.map((item) => (
                <div
                  key={`${item.category}-${item.id}`}
                  className="flex min-h-9 items-center justify-between gap-2 rounded-md px-2 py-1 hover:bg-default-100 dark:hover:bg-default-50/10"
                >
                  <Checkbox
                    className="min-w-0"
                    isSelected={item.included}
                    onValueChange={(checked) =>
                      setIncluded(item.category, item.id, checked)
                    }
                  >
                    <span className="line-clamp-2 text-sm">{item.label}</span>
                  </Checkbox>
                  <Button
                    isIconOnly
                    aria-label={`Remove ${item.label}`}
                    className="shrink-0"
                    size="sm"
                    variant="light"
                    onPress={() => removeItem(item.category, item.id)}
                  >
                    <FiTrash2 className="h-4 w-4" />
                  </Button>
                </div>
              ))}
            </div>
          ) : (
            <p className="py-2 text-sm text-foreground-400">
              No records selected.
            </p>
          )}
        </div>
      </div>
    );
  }

  const infobox = (
    <Alert
      color="primary"
      title="How the Downloader works"
      description={
        <>
          Add membranes, methods, molecules, and proteins from the main Search
          or their detail pages. Choose which collected records should be
          included, find matching interactions, and export them as a CSV/ZIP
          archive.
        </>
      }
    />
  );
  const searchHint = (
    <Alert
      color="success"
      title="Hint!"
      description={
        <span>
          Press <Kbd keys={["command"]}>K</Kbd> to open Search, find a record,
          and add it to the Downloader directly from the results.
        </span>
      }
    />
  );

  if (items.length === 0) {
    return (
      <div className="flex flex-col gap-8">
        {infobox}
        {searchHint}
        <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-default-300 bg-default-50 px-8 py-16 text-center dark:bg-background-dark-2">
          <p className="text-foreground-600">
            Your downloader is empty. Use the main Search to add records, or
            add them while browsing their detail pages.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-8">
      {infobox}
      {searchHint}

      <div className="flex flex-col gap-8 rounded-lg border border-default-200 p-4 sm:p-6 dark:border-white/30">
        <section className="mx-auto flex w-full max-w-3xl flex-col gap-3">
          <div className="text-center">
            <h2 className="text-lg font-semibold">Shared molecule filter</h2>
            <p className="text-sm text-foreground-500">
              Selected molecules are applied to both interaction types.
            </p>
          </div>
          {renderCategory("molecule")}
        </section>

        <div className="grid gap-8 lg:grid-cols-2">
        <section className="flex min-w-0 flex-col gap-5 lg:border-r lg:border-default-200 lg:pr-8 dark:lg:border-white/15">
          <div className="flex flex-col gap-1">
            <h2 className="text-lg font-semibold">Passive interactions</h2>
            <p className="text-sm text-foreground-500">
              Molecules AND membranes AND methods. Values within each group
              are matched as OR; empty groups are ignored.
            </p>
          </div>
          {renderCategory("membrane")}
          {renderCategory("method")}
          <div className="flex items-center justify-between gap-4 border-t border-default-200 pt-3 text-sm dark:border-white/15">
            <span className="text-foreground-500">Available data</span>
            <strong>
              {verifyResult
                ? `${verifyResult.passive.toLocaleString()} interactions`
                : "Not calculated"}
            </strong>
          </div>
        </section>

          <section className="flex min-w-0 flex-col gap-5">
          <div className="flex flex-col gap-1">
            <h2 className="text-lg font-semibold">Active interactions</h2>
            <p className="text-sm text-foreground-500">
              Molecules AND proteins. Values within each group are matched as
              OR; empty groups are ignored.
            </p>
          </div>
          {renderCategory("protein")}
          <div className="flex items-center justify-between gap-4 border-t border-default-200 pt-3 text-sm dark:border-white/15 lg:mt-auto">
            <span className="text-foreground-500">Available data</span>
            <strong>
              {verifyResult
                ? `${verifyResult.active.toLocaleString()} interactions`
                : "Not calculated"}
            </strong>
          </div>
          </section>
        </div>
      </div>

      <div className="flex flex-col gap-4 rounded-lg border border-default-200 p-4 dark:border-white/30">
        <div className="flex flex-wrap items-center gap-3">
          <Button
            color="secondary"
            isDisabled={!hasSelection}
            isLoading={isVerifying}
            startContent={!isVerifying ? <FiSearch /> : null}
            onPress={handleVerify}
          >
            Find interactions
          </Button>

          {verifyResult && verifyResult.total > 0 ? (
            <span className="text-sm text-foreground-600">
              {verifyResult.total.toLocaleString()} matching interactions
            </span>
          ) : null}
        </div>

        {verifyResult?.total === 0 ? (
          <Alert
            color="warning"
            title="No interactions found"
            description="No passive or active interactions match the selected branch filters. Try removing or changing one of them."
          />
        ) : null}

        {verifyResult && verifyResult.total > 0 ? (
          <div className="flex flex-col gap-3">
            {exportState === "idle" || exportState === "error" ? (
              <Button
                color="primary"
                isDisabled={verifyResult.total === 0}
                startContent={<FiDownload />}
                onPress={handleStartExport}
              >
                Start export
              </Button>
            ) : null}

            {exportState === "creating" ? (
              <div className="flex items-center gap-2 text-sm text-foreground-600">
                <Spinner size="sm" /> Starting export...
              </div>
            ) : null}

            {exportState === "running" ? (
              <div className="flex flex-col gap-2">
                {wasRestarted ? (
                  <Alert
                    color="warning"
                    title="Export restarted"
                    description="The previous worker stopped responding. A new export attempt has been queued."
                  />
                ) : null}
                <Progress
                  aria-label="Export progress"
                  color="primary"
                  value={progress?.percent ?? 0}
                />
                <span className="text-sm text-foreground-600">
                  {progress
                    ? `${progress.processed} / ${progress.total} rows (${progress.percent}%)`
                    : "Preparing..."}
                </span>
              </div>
            ) : null}

            {exportState === "done" ? (
              <Button
                color="success"
                startContent={<FiDownload />}
                onPress={handleDownload}
              >
                Download ZIP
              </Button>
            ) : null}

            {errorMessage ? (
              <p className="text-sm text-danger">{errorMessage}</p>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}
