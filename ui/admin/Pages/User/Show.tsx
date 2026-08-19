import type { ReactNode } from "react";
import { usePage, useForm, Link } from "@pageflow/react";
import { AdminLayout, useSetPageHeader } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Card, CardContent, CardHeader } from "@ui/card";
import { Badge } from "@ui/badge";
import { UserBadge, type UserSummary } from "@user";

type ShowProps = {
  user: (UserSummary & { createdAt: string }) | null;
  /**
   * null = unknown — either no such user, or the viewer lacks the `user:unlock`
   * permission (distinct from the narrower `user:read-any` this page otherwise
   * needs). Rendering hides the lockout control entirely in that case rather
   * than guessing at a state the server wouldn't disclose.
   */
  locked: boolean | null;
};

export default function UserShow() {
  const { props } = usePage<ShowProps>();
  const form = useForm({});
  const unlockForm = useForm({});
  const u = props.user;

  // useSetPageHeader must run unconditionally — every hook in this component
  // does, so it stays before the "not found" early return below rather than
  // after it (a hook called only on some renders violates the Rules of Hooks
  // and throws the moment this page re-renders across that boundary).
  useSetPageHeader(
    {
      title: u ? u.username : "User",
      description: u ? `Joined ${new Date(u.createdAt).toLocaleDateString()}` : undefined,
    },
    [u?.id, u?.createdAt]
  );

  if (!u) {
    return (
      <main className="mx-auto max-w-2xl p-8">
        <p>User not found.</p>
        <Button variant="link" className="px-0" asChild>
          <Link href="/admin/users">← Back to users</Link>
        </Button>
      </main>
    );
  }

  function verify() {
    form.post(`/ajx/users/${u.id}/verify-email`, { preserveScroll: true });
  }
  function unlock() {
    unlockForm.delete(`/ajx/users/${u.id}/lockout`, { preserveScroll: true });
  }

  return (
    <main className="mx-auto max-w-2xl p-8">
      <Button variant="link" className="px-0" asChild>
        <Link href="/admin/users">← Back to users</Link>
      </Button>
      <Card className="mt-4">
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <UserBadge user={u} />
          {!u.emailVerified && (
            <Button size="sm" onClick={verify} disabled={form.processing}>
              {form.processing ? "Verifying…" : "Mark verified"}
            </Button>
          )}
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-3 gap-2 text-sm">
            <dt className="text-muted-foreground">ID</dt>
            <dd className="col-span-2">{u.id}</dd>
            <dt className="text-muted-foreground">Email</dt>
            <dd className="col-span-2">{u.email}</dd>
            <dt className="text-muted-foreground">Joined</dt>
            <dd className="col-span-2">{new Date(u.createdAt).toLocaleString()}</dd>
          </dl>

          {props.locked !== null && (
            <div className="mt-4 flex items-center justify-between rounded-md border p-3">
              <span className="flex items-center gap-2 text-sm">
                Login lockout
                {props.locked ? (
                  <Badge variant="outline" className="border-transparent bg-red-100 text-red-700">
                    locked
                  </Badge>
                ) : (
                  <Badge variant="outline" className="border-transparent bg-green-100 text-green-700">
                    clear
                  </Badge>
                )}
              </span>
              {props.locked && (
                <Button size="sm" variant="outline" onClick={unlock} disabled={unlockForm.processing}>
                  {unlockForm.processing ? "Unlocking…" : "Unlock"}
                </Button>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </main>
  );
}

UserShow.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
