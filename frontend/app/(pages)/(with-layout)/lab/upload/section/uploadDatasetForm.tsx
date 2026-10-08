"use client";

import Turnstile from "@/components/auth/Turnstile";
import { getJson } from "@/lib/api/admin";
import { PublicationLookupOption } from "@/lib/api/admin/interfaces/Publication";
import { IUploadQueue } from "@/lib/api/admin/interfaces/UploadQueue";
import {
  Autocomplete,
  AutocompleteItem,
  Alert,
  Button,
  Divider,
  Input,
  Select,
  SelectItem,
  Textarea,
  Tooltip,
} from "@heroui/react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type SyntheticEvent, useEffect, useRef, useState } from "react";
import { FiHelpCircle } from "react-icons/fi";
import {
  MdCloudUpload,
  MdMarkEmailRead,
  MdRefresh,
  MdSearch,
} from "react-icons/md";
import { useHandle401 } from "@/lib/api/admin/redirections";
import MyUploadsList from "./myUploads";
import UploadFormatGuide from "./uploadFormatGuide";
import UploadRecordPanel from "./uploadRecordPanel";

const turnstileSiteKey = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "";
const TRACK_AUTO_REFRESH_SECONDS = 10;

type SelectOption = {
  id: number;
  abbreviation?: string;
  name?: string;
};

type ActionState = {
  status: number;
  message: string;
  data?: Record<string, unknown> | null;
};

type UploadEmailStep = "email" | "code" | "verified";

type VerificationPayload = {
  email: string;
};

type ApiErrorPayload = {
  message?: string;
  errors?: Record<string, string[]>;
};

class ApiRequestError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

function useDebouncedValue(value: string, delay = 300): string {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => window.clearTimeout(timeout);
  }, [value, delay]);

  return debouncedValue;
}

export default function UploadDatasetForm(props: {
  isLoggedIn: boolean;
  initialToken?: string;
}) {
  const router = useRouter();
  const [actionState, setActionState] = useState<ActionState | null>(null);
  const [isPending, setIsPending] = useState(false);

  const [email, setEmail] = useState("");
  const [emailStep, setEmailStep] = useState<UploadEmailStep>(
    props.isLoggedIn ? "verified" : "email",
  );
  const [authenticatedViaEmail, setAuthenticatedViaEmail] = useState(false);
  const isAuthenticated = props.isLoggedIn || authenticatedViaEmail;
  const [emailCode, setEmailCode] = useState("");
  const [emailTurnstileToken, setEmailTurnstileToken] = useState<string | null>(
    null,
  );
  const [isEmailVerificationSubmitting, setIsEmailVerificationSubmitting] =
    useState(false);
  const [emailVerificationError, setEmailVerificationError] = useState<
    string | null
  >(null);
  const [isEmailVerificationOpen, setIsEmailVerificationOpen] = useState(true);
  const [guestUpload, setGuestUpload] = useState<IUploadQueue | null>(null);
  const [trackTokenInput, setTrackTokenInput] = useState(
    props.initialToken ?? "",
  );
  const [isTrackLoading, setIsTrackLoading] = useState(false);
  const [trackError, setTrackError] = useState<string | null>(null);
  const [trackAutoRefreshCountdown, setTrackAutoRefreshCountdown] = useState(
    TRACK_AUTO_REFRESH_SECONDS,
  );
  const trackAutoRefreshCountdownRef = useRef(TRACK_AUTO_REFRESH_SECONDS);
  const trackTokenInputRef = useRef(trackTokenInput);

  const [uploadTurnstileToken, setUploadTurnstileToken] = useState<
    string | null
  >(null);
  const [uploadCaptchaKey, setUploadCaptchaKey] = useState(0);
  const [datasetType, setDatasetType] = useState("1");
  const [datasetName, setDatasetName] = useState("");
  const [comment, setComment] = useState("");
  const [membraneQuery, setMembraneQuery] = useState("");
  const [methodQuery, setMethodQuery] = useState("");
  const [publicationQuery, setPublicationQuery] = useState("");

  const [membraneOptions, setMembraneOptions] = useState<SelectOption[]>([]);
  const [methodOptions, setMethodOptions] = useState<SelectOption[]>([]);
  const [publicationOptions, setPublicationOptions] = useState<
    PublicationLookupOption[]
  >([]);
  const [selectedMembrane, setSelectedMembrane] = useState<SelectOption | null>(
    null,
  );
  const [selectedMethod, setSelectedMethod] = useState<SelectOption | null>(
    null,
  );
  const [selectedPublication, setSelectedPublication] =
    useState<PublicationLookupOption | null>(null);
  const [isMembraneLoading, setIsMembraneLoading] = useState(false);
  const [isMethodLoading, setIsMethodLoading] = useState(false);
  const [isPublicationLoading, setIsPublicationLoading] = useState(false);

  const debouncedMembraneQuery = useDebouncedValue(membraneQuery);
  const debouncedMethodQuery = useDebouncedValue(methodQuery);
  const debouncedPublicationQuery = useDebouncedValue(publicationQuery);

  const [selectedMembraneId, setSelectedMembraneId] = useState("");
  const [selectedMethodId, setSelectedMethodId] = useState("");

  const [reloadKey, setReloadKey] = useState(0);

  const membraneLabel = (option: SelectOption): string =>
    `${option.abbreviation ?? ""} - ${option.name ?? ""}`;
  const methodLabel = (option: SelectOption): string =>
    `${option.abbreviation ?? ""} - ${option.name ?? ""}`;
  const publicationLabel = (option: PublicationLookupOption): string =>
    `PMID ${option.pmid} - ${option.citation ?? option.title ?? "Untitled"}`;

  const handle401 = useHandle401();
  const canShowUploadForm = isAuthenticated;

  useEffect(() => {
    setIsMembraneLoading(true);
    getJson("/api/lab/upload/membranes", {
      query: debouncedMembraneQuery,
    })
      .then((response) => {
        if(response?.code === 401) {
          handle401();
        }

        if (response?.code === 200) {
          const loadedOptions: SelectOption[] = response.data?.data ?? [];
          if (
            selectedMembrane &&
            !loadedOptions.some((option) => option.id === selectedMembrane.id)
          ) {
            setMembraneOptions([selectedMembrane, ...loadedOptions]);
            return;
          }

          setMembraneOptions(loadedOptions);
          return;
        }
        setMembraneOptions([]);
      })
      .catch(() => setMembraneOptions([]))
      .finally(() => setIsMembraneLoading(false));
  }, [debouncedMembraneQuery, selectedMembrane]);



  useEffect(() => {
    setIsMethodLoading(true);
    getJson("/api/lab/upload/methods", {
      query: debouncedMethodQuery,
    })
      .then((response) => {
        if(response?.code === 401) {
          handle401();
        }
        if (response?.code === 200) {
          const loadedOptions: SelectOption[] = response.data?.data ?? [];
          if (
            selectedMethod &&
            !loadedOptions.some((option) => option.id === selectedMethod.id)
          ) {
            setMethodOptions([selectedMethod, ...loadedOptions]);
            return;
          }

          setMethodOptions(loadedOptions);
          return;
        }
        setMethodOptions([]);
      })
      .catch(() => setMethodOptions([]))
      .finally(() => setIsMethodLoading(false));
  }, [debouncedMethodQuery, selectedMethod]);


  
  useEffect(() => {
    if (debouncedPublicationQuery.trim().length < 3) {
      setPublicationOptions(selectedPublication ? [selectedPublication] : []);
      return;
    }

    setIsPublicationLoading(true);
    getJson("/api/lab/upload/publications/lookup", {
      query: debouncedPublicationQuery,
    })
      .then((response) => {
        if(response?.code === 401) {
          handle401();
        }
        if (response?.code === 200) {
          const loadedOptions: PublicationLookupOption[] =
            response.data?.data ?? [];
          if (
            selectedPublication &&
            !loadedOptions.some(
              (option) => option.pmid === selectedPublication.pmid,
            )
          ) {
            setPublicationOptions([selectedPublication, ...loadedOptions]);
            return;
          }

          setPublicationOptions(loadedOptions);
          return;
        }
        setPublicationOptions([]);
      })
      .catch(() => setPublicationOptions([]))
      .finally(() => setIsPublicationLoading(false));
  }, [debouncedPublicationQuery, selectedPublication]);


  useEffect(() => {
    if (actionState?.status === 201) {
      setPublicationQuery("");
      setPublicationOptions([]);
      setMembraneQuery("");
      setMethodQuery("");
      setMembraneOptions([]);
      setMethodOptions([]);
      setSelectedMembrane(null);
      setSelectedMethod(null);
      setSelectedPublication(null);
      setSelectedMembraneId("");
      setSelectedMethodId("");
      setDatasetType("1");
      setDatasetName("");
      setComment("");
      setUploadTurnstileToken(null);
      setUploadCaptchaKey((current) => current + 1);
      setReloadKey(reloadKey + 1);

    }
  }, [actionState?.status]);

  async function requestJson<T>(
    uri: string,
    payload: Record<string, unknown>,
  ): Promise<T> {
    const response = await fetch(uri, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    });
    const json = (await response.json()) as ApiErrorPayload & T;

    if (!response.ok) {
      const validationError = json.errors
        ? Object.values(json.errors).flat()[0]
        : null;

      throw new ApiRequestError(
        validationError ?? json.message ?? "Request failed.",
        response.status,
      );
    }

    return json;
  }

  async function handleEmailSubmit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setEmailVerificationError(null);
    setIsEmailVerificationSubmitting(true);

    try {
      await requestJson("/api/lab/upload/email-verification", {
        email,
        turnstile_token: emailTurnstileToken,
      });
      setEmailStep("code");
    } catch (error) {
      setEmailVerificationError(
        error instanceof Error ? error.message : "Verification code failed.",
      );
    } finally {
      setIsEmailVerificationSubmitting(false);
    }
  }

  async function handleCodeSubmit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setEmailVerificationError(null);
    setIsEmailVerificationSubmitting(true);

    try {
      const verification = await requestJson<VerificationPayload>(
        "/api/lab/upload/email-verification/verify",
        { email, code: emailCode },
      );
      setEmail(verification.email ?? email);
      setEmailStep("verified");
      setAuthenticatedViaEmail(true);
      router.refresh();
    } catch (error) {
      setEmailVerificationError(
        error instanceof Error ? error.message : "Email verification failed.",
      );
    } finally {
      setIsEmailVerificationSubmitting(false);
    }
  }

  async function loadGuestUpload(token: string) {
    const trimmedToken = token.trim();

    if (!trimmedToken) {
      return;
    }

    setIsTrackLoading(true);
    setTrackError(null);

    try {
      const response = await getJson(
        `/api/lab/upload/track/${encodeURIComponent(trimmedToken)}`,
      );

      if (response?.code === 200) {
        setGuestUpload(response.data?.data ?? null);
        setIsEmailVerificationOpen(false);
        return;
      }

      setGuestUpload(null);
      setTrackError("Upload not found for this token.");
    } catch {
      setGuestUpload(null);
      setTrackError("Failed to load upload status.");
    } finally {
      setIsTrackLoading(false);
    }
  }

  useEffect(() => {
    if (props.initialToken) {
      void loadGuestUpload(props.initialToken);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    setIsPending(true);

    fetch("/api/lab/upload/submit", {
      method: "POST",
      body: formData,
    })
      .then(async (response) => {
        const json = await response.json();

        if (response.status === 201) {
          setActionState({
            status: 201,
            message:
              json?.message ??
              "Upload request has been accepted and queued for processing.",
            data: json?.data ?? null,
          });

          return;
        }

        setActionState({
          status: response.status,
          message:
            json?.message ??
            "Upload request failed. Please check the form and try again.",
          data: json?.errors ?? json?.data ?? null,
        });
      })
      .catch((error) => {
        console.error(error);
        setActionState({
          status: 500,
          message: "Unexpected error occurred while uploading data.",
          data: null,
        });
      })
      .finally(() => setIsPending(false));
  }

  return (
    <div className="w-full mx-auto bg-foreground-100 rounded-2xl p-6 lg:p-8">
      <div className="flex flex-row items-center justify-start gap-2">
        <h2 className="text-2xl font-bold">Upload data to MolMeDB</h2>
        <Tooltip content="Show more info" placement="top">
          <Link
            aria-label="Show more info about contributing data"
            className="inline-flex h-7 w-7 items-center justify-center rounded-full text-foreground-500 transition-colors hover:bg-default-200 hover:text-foreground"
            href="/docs/contributing-data"
            target="_blank"
            rel="noopener noreferrer"
          >
            <FiHelpCircle className="h-5 w-5" />
          </Link>
        </Tooltip>
      </div>
      <p className="text-sm text-warning-700 mt-1">
        All submissions will be reviewed by our team before being published on
        the platform. Please allow up to 5 business days for the review process.
        You will receive notifications about the status of your submission via
        email.
      </p>

      {!isAuthenticated && (
        <div className="mt-6 flex flex-col gap-4 rounded-xl border border-default-200 bg-background/70 p-4">
          <div className="flex flex-col gap-1">
            <h3 className="text-lg font-bold">Track an existing upload</h3>
            <p className="text-sm text-foreground-500">
              Paste the token from your tracking email, or open the tracking
              link directly.
            </p>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              value={trackTokenInput}
              onValueChange={setTrackTokenInput}
              placeholder="Tracking token"
            />
            <Button
              color="secondary"
              variant="flat"
              isLoading={isTrackLoading}
              startContent={<MdSearch />}
              onPress={() => loadGuestUpload(trackTokenInput)}
              className="w-fit"
            >
              Track
            </Button>
          </div>
          {trackError && <Alert color="danger" title={trackError} />}
          {guestUpload && (
            <UploadRecordPanel
              upload={guestUpload}
              guestToken={trackTokenInput.trim() || undefined}
              onRefresh={() => loadGuestUpload(trackTokenInput)}
            />
          )}
        </div>
      )}

      {!isAuthenticated &&
        emailStep !== "verified" &&
        guestUpload &&
        !isEmailVerificationOpen && (
          <div className="mt-4 flex justify-end">
            <Button
              size="sm"
              variant="flat"
              onPress={() => setIsEmailVerificationOpen(true)}
            >
              Start a new upload
            </Button>
          </div>
        )}

      {!isAuthenticated && emailStep !== "verified" && isEmailVerificationOpen && (
        <div className="mt-6 flex flex-col gap-5 rounded-xl border border-primary-200 bg-primary-50/70 p-4 dark:border-primary-500/40 dark:bg-primary-950/20">
          {guestUpload && (
            <div className="flex justify-end">
              <Button
                size="sm"
                variant="light"
                onPress={() => setIsEmailVerificationOpen(false)}
              >
                Hide new upload form
              </Button>
            </div>
          )}
          <Alert
            color="primary"
            title="Verify your email to start a new upload"
            description={
              <span>
                Verifying your email signs you in and lets us associate the
                upload with your account. You can also{" "}
                <Link
                  className="font-semibold underline"
                  href="/login?redirect=/lab/upload"
                >
                  log in
                </Link>{" "}
                instead; signed-in users can submit data immediately and see
                their previous uploads directly in this form.
              </span>
            }
          />

          {emailVerificationError && (
            <Alert color="danger" title={emailVerificationError} />
          )}

          {emailStep === "email" && (
            <form className="flex flex-col gap-4" onSubmit={handleEmailSubmit}>
              <Input
                label="Email"
                type="email"
                value={email}
                onValueChange={setEmail}
                isRequired
                maxLength={255}
              />
              <Turnstile
                name="upload_email_turnstile_token"
                siteKey={turnstileSiteKey}
                onVerify={setEmailTurnstileToken}
              />
              <div className="flex justify-end">
                <Button
                  type="submit"
                  color="primary"
                  isLoading={isEmailVerificationSubmitting}
                  isDisabled={
                    !turnstileSiteKey ||
                    !emailTurnstileToken ||
                    isEmailVerificationSubmitting
                  }
                  startContent={
                    !isEmailVerificationSubmitting ? <MdMarkEmailRead /> : null
                  }
                >
                  Send verification code
                </Button>
              </div>
            </form>
          )}

          {emailStep === "code" && (
            <form className="flex flex-col gap-4" onSubmit={handleCodeSubmit}>
              <Input isDisabled label="Email" value={email} />
              <Input
                label="Verification code"
                value={emailCode}
                onValueChange={setEmailCode}
                isRequired
                maxLength={6}
              />
              <div className="flex flex-col justify-end gap-2 sm:flex-row">
                <Button
                  variant="flat"
                  onPress={() => {
                    setEmailStep("email");
                    setEmailCode("");
                    setEmailVerificationError(null);
                  }}
                >
                  Change email
                </Button>
                <Button
                  type="submit"
                  color="primary"
                  isLoading={isEmailVerificationSubmitting}
                >
                  Verify email
                </Button>
              </div>
            </form>
          )}
        </div>
      )}

      {canShowUploadForm && (
      <form onSubmit={handleSubmit} className="pt-6 flex flex-col gap-5">
        {actionState?.message && (
          <Alert
            color={actionState.status === 201 ? "success" : "danger"}
            title={actionState.message}
          />
        )}

        <Select
          label="Dataset setting"
          name="dataset_type"
          selectedKeys={[datasetType]}
          disallowEmptySelection
          isRequired
          onSelectionChange={(keys) => {
            const value = Array.from(keys)[0];
            if (value) setDatasetType(String(value));
          }}
        >
          <SelectItem key="1">Passive interactions</SelectItem>
          <SelectItem key="2">Active interactions</SelectItem>
        </Select>

        <UploadFormatGuide datasetType={datasetType} />

        <Input
          label="Dataset name (optional)"
          name="dataset_name"
          placeholder="e.g. Lipophilicity dataset for DOPC"
          value={datasetName}
          onValueChange={setDatasetName}
          maxLength={255}
        />

        <Textarea
          label="Comment (optional)"
          name="comment"
          placeholder="Any context or notes for reviewers"
          value={comment}
          onValueChange={setComment}
          maxLength={1000}
        />

        <Divider />

        <div className={`flex flex-col gap-2 ${datasetType == '2' ? "hidden" : ""}`}>
          <Autocomplete
            label="Membrane"
            placeholder="Type to search membrane..."
            selectedKey={selectedMembraneId || null}
            inputValue={membraneQuery}
            onInputChange={(value) => {
              if (
                selectedMembrane &&
                value !== membraneLabel(selectedMembrane)
              ) {
                setSelectedMembrane(null);
                setSelectedMembraneId("");
              }
              setMembraneQuery(value);
            }}
            onSelectionChange={(key) => {
              const selected = membraneOptions.find(
                (option) => String(option.id) === String(key),
              );
              setSelectedMembraneId(key ? String(key) : "");
              setSelectedMembrane(selected ?? null);
              setMembraneQuery(selected ? membraneLabel(selected) : "");
            }}
            isLoading={isMembraneLoading}
            items={membraneOptions}
            isRequired={datasetType == '1'}
          >
            {(option) => (
              <AutocompleteItem
                key={String(option.id)}
                textValue={`${option.abbreviation ?? ""} ${option.name ?? ""}`.trim()}
              >
                {option.abbreviation} - {option.name}
              </AutocompleteItem>
            )}
          </Autocomplete>
          <input type="hidden" name="membrane_id" value={selectedMembraneId} />
        </div>

        <div className={`flex flex-col gap-2 ${datasetType == '2' ? "hidden" : ""}`}>
          <Autocomplete
            label="Method"
            placeholder="Type to search method..."
            selectedKey={selectedMethodId || null}
            inputValue={methodQuery}
            onInputChange={(value) => {
              if (selectedMethod && value !== methodLabel(selectedMethod)) {
                setSelectedMethod(null);
                setSelectedMethodId("");
              }
              setMethodQuery(value);
            }}
            onSelectionChange={(key) => {
              const selected = methodOptions.find(
                (option) => String(option.id) === String(key),
              );
              setSelectedMethodId(key ? String(key) : "");
              setSelectedMethod(selected ?? null);
              setMethodQuery(selected ? methodLabel(selected) : "");
            }}
            isLoading={isMethodLoading}
            items={methodOptions}
            isRequired={datasetType == '1'}
          >
            {(option) => (
              <AutocompleteItem
                key={String(option.id)}
                textValue={`${option.abbreviation ?? ""} ${option.name ?? ""}`.trim()}
              >
                {option.abbreviation} - {option.name}
              </AutocompleteItem>
            )}
          </Autocomplete>
          <input type="hidden" name="method_id" value={selectedMethodId} />
        </div>

        <Divider />

        <div className="flex flex-col gap-3">
          <Autocomplete
            label="Secondary reference"
            placeholder="Type PMID, title or citation..."
            selectedKey={selectedPublication?.pmid ?? null}
            inputValue={publicationQuery}
            onInputChange={(value) => {
              if (
                selectedPublication &&
                value !== publicationLabel(selectedPublication)
              ) {
                setSelectedPublication(null);
              }
              setPublicationQuery(value);
            }}
            onSelectionChange={(key) => {
              const selected = publicationOptions.find(
                (option) => option.pmid === String(key),
              );
              setSelectedPublication(selected ?? null);
              setPublicationQuery(selected ? publicationLabel(selected) : "");
            }}
            isLoading={isPublicationLoading}
            items={publicationOptions}
            isRequired
            description="Start typing (min. 3 characters). We store PMID and create publication on upload when missing."
          >
            {(option) => (
              <AutocompleteItem
                key={option.pmid}
                textValue={publicationLabel(option)}
              >
                {publicationLabel(option)}
              </AutocompleteItem>
            )}
          </Autocomplete>
          <input
            type="hidden"
            name="publication_pmid"
            value={selectedPublication?.pmid ?? ""}
          />
          <input
            type="hidden"
            name="publication_lookup_provider"
            value={selectedPublication?.provider ?? ""}
          />
          <input
            type="hidden"
            name="publication_lookup_source"
            value={selectedPublication?.identifier_source ?? "MED"}
          />
        </div>

        <Divider />

        <div className="flex flex-col gap-2">
          <label className="font-semibold text-sm">Upload file</label>
          <input
            type="file"
            name="file"
            required
            className="w-full rounded-lg border border-default-300 bg-background dark:bg-background-dark dark:text-white px-3 py-2 file:mr-3 file:rounded-md file:border-0 file:bg-default-200 file:px-3 file:py-1 file:text-foreground dark:file:bg-background-dark-2"
            accept=".csv"
          />
          <p className="text-xs text-foreground-500">
            Supported formats: CSV (max 20 MB).
          </p>
        </div>

        <Turnstile
          key={uploadCaptchaKey}
          name="turnstile_token"
          siteKey={turnstileSiteKey}
          onVerify={setUploadTurnstileToken}
        />

        {turnstileSiteKey && !uploadTurnstileToken ? (
          <Button
            type="button"
            color="primary"
            size="lg"
            variant="flat"
            startContent={<MdRefresh />}
            onPress={() => {
              setUploadTurnstileToken(null);
              setUploadCaptchaKey((current) => current + 1);
            }}
          >
            Reload captcha
          </Button>
        ) : (
          <Button
            type="submit"
            color="primary"
            size="lg"
            isLoading={isPending}
            isDisabled={!turnstileSiteKey || isPending}
            startContent={<MdCloudUpload />}
            className="text-white"
          >
            Submit upload request
          </Button>
        )}
      </form>
      )}

      <Divider className="my-8" />

      {isAuthenticated ? (
        <MyUploadsList reloadKey={reloadKey} />
      ) : (
        <p className="text-sm text-foreground-500">
          <Link className="text-primary" href={"/login"}>Sign in</Link>{" "}
          to see the complete history of your uploaded datasets here.
        </p>
      )}
    </div>
  );
}
