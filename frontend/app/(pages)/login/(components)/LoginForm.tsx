"use client";

import { Alert, Button, Input } from "@heroui/react";
import submitLogin from "../(actions)/submitLogin";
import resendVerification from "../(actions)/resendVerification";
import { useActionState, useEffect, useRef, useState } from "react";

const RESEND_COOLDOWN_SECONDS = 60;

export default function LoginForm(props: { defaultEmail?: string; redirectTo?: string }) {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const [actionState, action, isPending] = useActionState(submitLogin, null);
  const [resendState, resendAction, isResendPending] = useActionState(
    resendVerification,
    null,
  );

  const [email, setEmail] = useState(props.defaultEmail || "");
  const [password, setPassword] = useState("");
  const [resendCooldown, setResendCooldown] = useState(0);

  const isUnverified = Boolean(actionState?.data?.email_verification);

  useEffect(() => {
    if (resendState?.status === 200 || resendState?.status === 429) {
      setResendCooldown(RESEND_COOLDOWN_SECONDS);
    }
  }, [resendState]);

  useEffect(() => {
    if (resendCooldown <= 0) return;

    const timer = setInterval(() => {
      setResendCooldown((seconds) => Math.max(0, seconds - 1));
    }, 1000);

    return () => clearInterval(timer);
  }, [resendCooldown]);

  const redirectInput = useRef<HTMLInputElement>(null);

  /**
   * The anchor of the page the visitor came for (/account/settings#notifications)
   * stays in the browser through the redirect to the login and never reaches
   * the server, so it is added to the target as the form is sent. Without
   * JavaScript the form still sends the target, only without the anchor.
   */
  function addAnchorToTarget() {
    const hash = window.location.hash;

    if (
      redirectInput.current &&
      props.redirectTo &&
      !props.redirectTo.includes("#") &&
      hash.length > 1
    ) {
      redirectInput.current.value = props.redirectTo + hash;
    }
  }

  return (
    <div className="w-full max-w-96 flex flex-col items-center">
      <form className="w-full" action={action} onSubmit={addAnchorToTarget}>
        <input
          // A new target replaces the input, not only its default value.
          key={props.redirectTo}
          ref={redirectInput}
          type="hidden"
          name="redirectTo"
          defaultValue={props.redirectTo}
        />
        {actionState?.message && (
          <div>
            <Alert
              color="danger"
              className="mb-4"
              title={actionState.message}
            />
          </div>
        )}
        {isUnverified && (
          <div className="mb-4 flex flex-col gap-2">
            {resendState?.message && (
              <Alert
                color={resendState.status === 200 ? "success" : "warning"}
                title={resendState.message}
              />
            )}
            {resendCooldown <= 0 && (
              <Button
                type="submit"
                formAction={resendAction}
                formNoValidate
                color="warning"
                variant="flat"
                size="sm"
                className="w-full"
                isLoading={isResendPending}
              >
                Resend verification email
              </Button>
            )}
          </div>
        )}
        <Input
          size="sm"
          classNames={{
            base: "mb-2",
            input: "text-[16px] lg:text-md",
          }}
          type="email"
          label="Email"
          name="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoFocus
        />
        <Input
          className="mb-4"
          type="password"
          size="sm"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          classNames={{
            input: "text-[16px] lg:text-md",
          }}
          label="Password"
          name="password"
          autoComplete="password"
        />
        <div className="flex justify-between mb-4">
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="remember"
              name="remember"
              className="h-4 w-4 text-primary border-gray-300 rounded focus:ring-primary"
            />
            <label htmlFor="remember" className="text-sm text-gray-600">
              Keep logged in
            </label>
          </div>
          <a
            href="/reset-password"
            className="text-sm text-primary hover:underline dark:text-primary"
          >
            Forgotten password?
          </a>
        </div>
        <Button
          className="w-full mt-4"
          type="submit"
          color="primary"
          size="lg"
          isLoading={isPending}
        >
          Login
        </Button>
        <p className="text-sm text-gray-600 mt-4 text-center">
          Don&apos;t have an account?{" "}
          <a
            href="/register"
            className="text-primary hover:underline dark:text-primary"
          >
            Sign up for free
          </a>
        </p>
      </form>
    </div>
  );
}
