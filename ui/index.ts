// Public barrel for the User plugin's UI — reachable from any surface as
// `@user` / `@user/components/...` once `hkm ui sync` has federated it.
// The plugin exposes shared building blocks here; its PAGES live under
// admin/Pages (admin surface) and site/Pages (public surface).
export { UserBadge } from "./components/UserBadge";
export type { UserSummary } from "./components/UserBadge";

// The sign-in form's field markup, shared by Register and VerifyEmail so the
// two halves of the sign-up flow cannot drift apart.
export { AuthField, AuthPasswordField, AuthCrest } from "./components/AuthField";
export type { AuthFieldProps } from "./components/AuthField";
