import type { ReactNode } from "react";
import { useForm, Link } from "@pageflow/react";
import { AuthLayout } from "@pageflow/admin";
import { AuthCrest, AuthField } from "@user";
import { Button } from "@ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@ui/card";
import { CheckCircle2, KeyRound, Loader2, MailCheck, ShieldCheck } from "lucide-react";

/**
 * PUBLIC page contributed by the User plugin — component `"User/VerifyEmail"`.
 *
 * Server: `UserFlowController@verifyEmail` (`GET /verify-email`). The emailed
 * link points at `/verify-email?token=…` and the controller passes `token`
 * through as a prop, so arriving from the email prefills the field and the user
 * only has to press the button. Posts to `POST /ajx/users/verify`.
 *
 * Same chrome as Register and the platform's sign-in page — these three are one
 * flow, and looked like three separate products.
 *
 * Title comes from the server's `seoHead` (here `seoPrivate`, so the page is
 * noindex — a token-bearing URL must never enter a search index), which is why
 * this sets no `<Head title>`.
 */
interface VerifyEmailProps {
  /** Prefilled from `?token=` by the controller. */
  token?: string;
  appName?: string;
  logoUrl?: string | null;
  loginUrl?: string;
  registerUrl?: string;
}

export default function VerifyEmail({
  token = "",
  appName,
  logoUrl,
  loginUrl = "/login",
  registerUrl = "/register",
}: VerifyEmailProps) {
  const form = useForm({ token });

  function submit(event: React.FormEvent) {
    event.preventDefault();
    form.post("/ajx/users/verify");
  }

  // Arriving from the emailed link means the field is already filled and the
  // only thing left is to confirm — so say that, rather than instructing
  // someone to paste a token they can see is already there.
  const arrivedWithToken = token !== "";

  return (
    <Card className="w-full max-w-md shadow-lg">
      <CardHeader className="space-y-1">
        <AuthCrest
          logoUrl={logoUrl}
          icon={form.wasSuccessful ? CheckCircle2 : ShieldCheck}
          alt={appName ?? ""}
        />
        <CardTitle className="text-center text-2xl font-bold">
          {form.wasSuccessful ? "Email verified" : "Verify your email"}
        </CardTitle>
        <CardDescription className="text-center">
          {form.wasSuccessful
            ? "Your address is confirmed — you can sign in now."
            : arrivedWithToken
              ? "Confirm the address this link was sent to."
              : "Paste the token from your email, or follow the link we sent you."}
        </CardDescription>
      </CardHeader>

      <CardContent>
        {form.wasSuccessful ? (
          <div className="space-y-4 text-center">
            <div className="flex justify-center">
              <MailCheck className="h-10 w-10 text-muted-foreground" aria-hidden={true} />
            </div>
            <Button asChild className="w-full">
              <Link href={loginUrl}>Sign in</Link>
            </Button>
          </div>
        ) : (
          /* See Register.tsx for why this is `noValidate` and carries no toast. */
          <form onSubmit={submit} className="space-y-4" noValidate>
            <AuthField
              id="token"
              label="Verification token"
              icon={KeyRound}
              placeholder="Paste the token from your email"
              autoComplete="off"
              autoFocus={!arrivedWithToken}
              disabled={form.processing}
              value={form.data.token}
              onChange={(value) => form.setData("token", value)}
              error={form.errors.token}
              className="pl-10 font-mono"
            />

            <Button
              type="submit"
              className="w-full"
              disabled={form.processing || form.data.token === ""}
            >
              {form.processing ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden={true} />
              ) : (
                <ShieldCheck className="mr-2 h-4 w-4" aria-hidden={true} />
              )}
              {form.processing ? "Verifying…" : "Verify email"}
            </Button>
          </form>
        )}
      </CardContent>

      {!form.wasSuccessful && (
        <CardFooter className="justify-center">
          <p className="text-sm text-muted-foreground">
            Need a new account?{" "}
            <Link href={registerUrl} className="text-foreground hover:underline">
              Sign up
            </Link>
          </p>
        </CardFooter>
      )}
    </Card>
  );
}

VerifyEmail.layout = (page: ReactNode) => <AuthLayout>{page}</AuthLayout>;
