import type { ReactNode } from "react";
import { useForm, Link } from "@pageflow/react";
import { AuthLayout } from "@pageflow/admin";
import { AuthCrest, AuthField, AuthPasswordField } from "@user";
import { Button } from "@ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@ui/card";
import { AtSign, KeyRound, Loader2, Mail, MailCheck, UserPlus } from "lucide-react";

/**
 * PUBLIC page contributed by the User plugin — component `"User/Register"`.
 *
 * Server: `UserFlowController@register` (`GET /register`). Posts to the
 * plugin's own `POST /ajx/users`.
 *
 * The chrome matches the platform's sign-in page (`Auth/Login` +
 * `LoginForm`): `AuthLayout`, one `max-w-md` card, icon-prefixed fields, a
 * full-width submit that swaps its icon for a spinner. Registration and
 * sign-in are the same moment in a user's life and used to look like two
 * different products.
 *
 * The tab title comes from the server's `seoHead` prop, so this sets no
 * `<Head title>` — the client syncs `document.title` on every navigation, and
 * a second title here would fight it.
 */
interface RegisterProps {
  /** Product name in the heading. The controller does not send it yet. */
  appName?: string;
  /** Logo for the crest. Absent → a neutral glyph, never a broken image. */
  logoUrl?: string | null;
  /** Sign-in link. Omit to hide it on invite-only deployments. */
  loginUrl?: string;
  /** Where the "verify" call to action points after a successful sign-up. */
  verifyUrl?: string;
}

export default function Register({
  appName,
  logoUrl,
  loginUrl = "/login",
  verifyUrl = "/verify-email",
}: RegisterProps) {
  const form = useForm({ username: "", email: "", password: "" });

  function submit(event: React.FormEvent) {
    event.preventDefault();
    form.post("/ajx/users");
  }

  return (
    <Card className="w-full max-w-md shadow-lg">
      <CardHeader className="space-y-1">
        <AuthCrest logoUrl={logoUrl} icon={UserPlus} alt={appName ?? ""} />
        <CardTitle className="text-center text-2xl font-bold">
          {appName ?? "Create your account"}
        </CardTitle>
        <CardDescription className="text-center">
          {form.wasSuccessful
            ? "One more step before you can sign in."
            : "Sign up in seconds — no card, no waiting."}
        </CardDescription>
      </CardHeader>

      <CardContent>
        {form.wasSuccessful ? (
          <div className="space-y-4 text-center">
            <div className="flex justify-center">
              <MailCheck className="h-10 w-10 text-muted-foreground" aria-hidden={true} />
            </div>
            <p className="text-sm text-foreground">
              We&rsquo;ve emailed you a verification link. Follow it, or enter the
              token to confirm your address.
            </p>
            <Button asChild className="w-full">
              <Link href={verifyUrl}>Verify email</Link>
            </Button>
          </div>
        ) : (
          /*
           * `noValidate` on purpose: the SERVER validates, and its 422 field
           * errors are what `form.errors` renders. Leaving the browser's own
           * bubbles on would mean two validators disagreeing about the same
           * field, with the native one winning first and saying less.
           *
           * There is deliberately NO toast here. The Pageflow client dispatches
           * a 422 down both the inline-error path and the global error event, so
           * a toast on top of these messages reports every failed submission
           * twice.
           */
          <form onSubmit={submit} className="space-y-4" noValidate>
            <AuthField
              id="username"
              label="Username"
              icon={AtSign}
              placeholder="yourname"
              autoComplete="username"
              autoFocus
              disabled={form.processing}
              value={form.data.username}
              onChange={(value) => form.setData("username", value)}
              error={form.errors.username}
            />

            <AuthField
              id="email"
              label="Email"
              icon={Mail}
              type="email"
              placeholder="you@example.com"
              autoComplete="email"
              disabled={form.processing}
              value={form.data.email}
              onChange={(value) => form.setData("email", value)}
              error={form.errors.email}
            />

            <AuthPasswordField
              id="password"
              label="Password"
              icon={KeyRound}
              autoComplete="new-password"
              disabled={form.processing}
              value={form.data.password}
              onChange={(value) => form.setData("password", value)}
              error={form.errors.password}
            />

            <Button type="submit" className="w-full" disabled={form.processing}>
              {form.processing ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden={true} />
              ) : (
                <UserPlus className="mr-2 h-4 w-4" aria-hidden={true} />
              )}
              {form.processing ? "Creating account…" : "Create account"}
            </Button>
          </form>
        )}
      </CardContent>

      {!form.wasSuccessful && loginUrl && (
        <CardFooter className="justify-center">
          <p className="text-sm text-muted-foreground">
            Already have an account?{" "}
            <Link href={loginUrl} className="text-foreground hover:underline">
              Sign in
            </Link>
          </p>
        </CardFooter>
      )}
    </Card>
  );
}

Register.layout = (page: ReactNode) => <AuthLayout>{page}</AuthLayout>;
