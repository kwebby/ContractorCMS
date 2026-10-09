/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

import { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    ArrowLeft,
    ArrowRight,
    HardHat,
    ShieldCheck,
    Wrench,
} from "lucide-react";
import { api } from "../api";
import { Button, ErrorNotice, Field, Saved } from "../components/ui";
type Mode = "login" | "register" | "forgot" | "reset" | "mfa" | "verify";
export default function Auth({
    mode = "login",
    token,
    email,
    status,
    business,
}: {
    mode?: Mode;
    token?: string;
    email?: string;
    status?: string;
    business?: any;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const page = usePage<any>();
    const errors = page.props.errors ?? {};
    const title = {
        login: "A good day starts here.",
        register: "Your project, in one place.",
        forgot: "Reset your password.",
        reset: "Choose a new password.",
        mfa: "One more step.",
        verify: "Check your inbox.",
    }[mode];
    const description = {
        login: "Sign in to keep your customers, team, and work moving.",
        register:
            "Create your client account to follow your jobs, review estimates, and stay in touch.",
        forgot: "We'll email you a link to get back into your account.",
        reset: "Use a strong password you have not used elsewhere.",
        mfa: "Enter the six-digit code from your authenticator app.",
        verify: "Verify your email address to activate your client account.",
    }[mode];
    function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setBusy(true);
        setError("");
        const values = Object.fromEntries(
            new FormData(e.currentTarget).entries(),
        );
        const path = {
            login: "/login",
            register: "/register",
            forgot: "/forgot-password",
            reset: "/reset-password",
            mfa: "/mfa",
            verify: "/email/verification-notification",
        }[mode];
        router.post(path, values, {
            onError: (errs) => setError(Object.values(errs).join(" ")),
            onSuccess: () => {
                if (mode === "forgot")
                    setSuccess(
                        "If an account exists, a password reset email is on its way.",
                    );
            },
            onFinish: () => setBusy(false),
        });
    }
    return (
        <div className="auth-shell">
            <Head
                title={
                    mode === "login"
                        ? "Sign in"
                        : mode === "register"
                          ? "Create account"
                          : title
                }
            />
            <section className="auth-story">
                <a href="/" className="brand">
                    <span className="brand-mark">
                        <HardHat size={23} />
                    </span>
                    <span>ContractorCMS</span>
                </a>
                <div className="auth-story-body">
                    <div className="auth-grid-art" aria-hidden="true">
                        <div className="art-window">
                            <span />
                            <span />
                            <span />
                            <span />
                        </div>
                        <div className="art-line a" />
                        <div className="art-line b" />
                        <div className="art-circle">
                            <Wrench size={42} strokeWidth={1.3} />
                        </div>
                        <div className="art-label">
                            <span />A better day on the job.
                        </div>
                    </div>
                    <h2>
                        Great work.
                        <br />
                        Less busywork.
                    </h2>
                    <p>
                        One workspace for the people, projects, and details that
                        keep your business running.
                    </p>
                </div>
                <span className="auth-story-footer">
                    Built for the trades. Made for your team.
                </span>
            </section>
            <section className="auth-form-side">
                <a className="auth-home" href="/">
                    <ArrowLeft size={16} />
                    Back to website
                </a>
                <div className="auth-form-wrap">
                    <span className="auth-company">
                        {business?.name ?? "Your business workspace"}
                    </span>
                    <h1>{title}</h1>
                    <p>{description}</p>
                    <ErrorNotice
                        message={error || Object.values(errors).join(" ")}
                    />
                    {(success || status) && <Saved>{success || status}</Saved>}
                    <form onSubmit={submit}>
                        {mode === "register" && (
                            <Field
                                label="Your name"
                                name="name"
                                autoComplete="name"
                                required
                            />
                        )}
                        {["login", "register", "forgot"].includes(mode) && (
                            <Field
                                label="Email address"
                                type="email"
                                name="email"
                                autoComplete="email"
                                defaultValue={email ?? ""}
                                required
                            />
                        )}
                        {["login", "register", "reset"].includes(mode) && (
                            <Field
                                label="Password"
                                type="password"
                                name="password"
                                autoComplete={
                                    mode === "login"
                                        ? "current-password"
                                        : "new-password"
                                }
                                minLength={mode === "login" ? undefined : 12}
                                required
                                hint={
                                    mode === "register"
                                        ? "Use at least 12 characters."
                                        : undefined
                                }
                            />
                        )}
                        {["register", "reset"].includes(mode) && (
                            <Field
                                label="Confirm password"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                            />
                        )}
                        {mode === "reset" && (
                            <input
                                type="hidden"
                                name="token"
                                value={token ?? ""}
                            />
                        )}
                        {mode === "mfa" && (
                            <Field
                                label="Authenticator code"
                                name="code"
                                inputMode="numeric"
                                pattern="[0-9]{6}"
                                maxLength={6}
                                autoComplete="one-time-code"
                                required
                                autoFocus
                            />
                        )}
                        {mode === "login" && (
                            <div className="auth-options">
                                <label className="check-field">
                                    <input type="checkbox" name="remember" />
                                    Remember me
                                </label>
                                <Link href="/forgot-password">
                                    Forgot password?
                                </Link>
                            </div>
                        )}
                        <Button className="auth-submit" busy={busy}>
                            {mode === "login"
                                ? "Sign in"
                                : mode === "register"
                                  ? "Create client account"
                                  : mode === "forgot"
                                    ? "Send reset link"
                                    : mode === "reset"
                                      ? "Reset password"
                                      : mode === "mfa"
                                        ? "Verify and sign in"
                                        : "Resend verification email"}
                            <ArrowRight size={17} />
                        </Button>
                    </form>
                    {mode === "login" ? (
                        <p className="auth-switch">
                            New client?{" "}
                            <Link href="/register">Create an account</Link>
                        </p>
                    ) : (
                        <p className="auth-switch">
                            Already have an account?{" "}
                            <Link href="/login">Sign in</Link>
                        </p>
                    )}
                    <div className="auth-security">
                        <ShieldCheck size={16} />
                        <span>Your business, securely connected.</span>
                    </div>
                </div>
                <span className="auth-footer">
                    © {new Date().getFullYear()} ContractorCMS
                </span>
            </section>
        </div>
    );
}
