import { useState, type ComponentType, type ReactNode } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { cn } from "@lib/utils";

/**
 * The icon-prefixed field the platform's sign-in form uses.
 *
 * Extracted rather than copied into each page: Register and VerifyEmail both
 * need it, and the Auth plugin's `LoginForm` had already established the exact
 * markup — icon absolutely positioned at `left-3`, input padded `pl-10`, error
 * as `text-xs text-destructive` wired through `aria-describedby`. Three
 * hand-written copies of that is how the two sides drift until only one of them
 * announces its errors to a screen reader.
 *
 * Deliberately NOT built on `@ui/form`: that wrapper is bound to
 * `react-hook-form`, which this plugin does not depend on and should not start
 * depending on to render three inputs. HKM 0.3's login pulled in
 * `react-hook-form`, `@hookform/resolvers`, `zod` and `axios` to post two
 * fields; the current form does the same job with the platform's own helpers,
 * and this follows it.
 */
export interface AuthFieldProps {
  id: string;
  label: string;
  /** A lucide icon, rendered inside the input's left padding. */
  icon: ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  type?: string;
  placeholder?: string;
  autoComplete?: string;
  autoFocus?: boolean;
  disabled?: boolean;
  /** Rendered opposite the label — "Forgot password?", a hint, a counter. */
  labelAside?: ReactNode;
  className?: string;
}

export function AuthField({
  id,
  label,
  icon: Icon,
  value,
  onChange,
  error,
  type = "text",
  placeholder,
  autoComplete,
  autoFocus,
  disabled,
  labelAside,
  className,
}: AuthFieldProps) {
  const errorId = `${id}-error`;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between">
        <Label htmlFor={id}>{label}</Label>
        {labelAside}
      </div>

      <div className="relative">
        <Icon
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden={true}
        />
        <Input
          id={id}
          name={id}
          type={type}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className={cn("pl-10", className)}
          placeholder={placeholder}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          disabled={disabled}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? errorId : undefined}
        />
      </div>

      {error && (
        <p id={errorId} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * The same field with a reveal toggle.
 *
 * Separate from {@see AuthField} rather than a `password` flag on it, because
 * the toggle owns state and the input needs `pr-10` to keep its text clear of
 * the button — a flag would make both conditional on a prop that is false
 * almost everywhere.
 */
export function AuthPasswordField({
  id,
  label,
  icon: Icon,
  value,
  onChange,
  error,
  placeholder = "••••••••",
  autoComplete = "new-password",
  disabled,
  labelAside,
}: Omit<AuthFieldProps, "type" | "className">) {
  const [visible, setVisible] = useState(false);
  const errorId = `${id}-error`;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between">
        <Label htmlFor={id}>{label}</Label>
        {labelAside}
      </div>

      <div className="relative">
        <Icon
          className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden={true}
        />
        <Input
          id={id}
          name={id}
          type={visible ? "text" : "password"}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className="pl-10 pr-10"
          placeholder={placeholder}
          autoComplete={autoComplete}
          disabled={disabled}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? errorId : undefined}
        />
        <button
          type="button"
          onClick={() => setVisible((shown) => !shown)}
          aria-label={visible ? "Hide password" : "Show password"}
          aria-pressed={visible}
          className="absolute right-0 top-0 flex h-full items-center px-3 text-muted-foreground hover:text-foreground"
        >
          {visible ? (
            <EyeOff className="h-4 w-4" aria-hidden={true} />
          ) : (
            <Eye className="h-4 w-4" aria-hidden={true} />
          )}
        </button>
      </div>

      {error && (
        <p id={errorId} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * The card's crest: a logo when the deployment supplies one, a neutral glyph
 * when it does not.
 *
 * HKM 0.3's login rendered `<img src={appInfo.logo}>` unconditionally. Neither
 * of this plugin's page controllers sends anything of the sort — `register`
 * passes only `seoHead` — so an unconditional `<img>` here would resolve to a
 * broken-image icon on every deployment that has not wired the prop, which is
 * worse than the plain mark it replaces.
 */
export function AuthCrest({
  logoUrl,
  icon: Icon,
  alt = "",
}: {
  logoUrl?: string | null;
  icon: ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  alt?: string;
}) {
  return (
    <div className="mb-2 flex justify-center">
      <div className="flex h-20 w-20 items-center justify-center rounded-full bg-muted">
        {logoUrl ? (
          <img src={logoUrl} alt={alt} className="h-[70px] w-[70px] rounded-full object-contain" />
        ) : (
          <Icon className="h-8 w-8 text-muted-foreground" aria-hidden={true} />
        )}
      </div>
    </div>
  );
}
