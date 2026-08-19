import type { FormEvent, ReactNode } from "react";
import { useState, useEffect } from "react";
import { usePage, Link, router } from "@pageflow/react";
import { AdminLayout, useSetPageHeader } from "@pageflow/admin";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@ui/table";
import { UserBadge, type UserSummary } from "@user";

// ADMIN page contributed by the User PLUGIN. The admin surface globs
// plugins/*/admin/Pages/**, so this resolves as component "User/Index" even
// though it lives in the plugin. Server: UserFlowController@adminIndex.
type UserRow = UserSummary & { createdAt: string };

type IndexProps = {
  users: UserRow[];
  hasMore: boolean;
  nextCursor: string | null;
  /** Current search/filter state, echoed back by the server so it survives navigation. */
  search: string | null;
  verified: boolean | null;
};

export default function UserIndex() {
  const { props } = usePage<IndexProps>();
  const [q, setQ] = useState(props.search ?? "");

  // Resync search input when props change (after preserveState navigation)
  useEffect(() => {
    setQ(props.search ?? "");
  }, [props.search]);

  useSetPageHeader(
    {
      title: "Users",
      description: props.search
        ? `Search results for "${props.search}"`
        : undefined,
    },
    [props.search]
  );

  function currentVerifiedParam(): string | undefined {
    return props.verified === null ? undefined : props.verified ? "1" : "0";
  }

  /** Re-request the list with a changed q and/or verified, keeping the other as-is. */
  function applyFilters(next: { q?: string; verified?: string }) {
    const params: Record<string, string> = {};

    const nextQ = (next.q ?? props.search ?? "").trim();
    if (nextQ !== "") params.q = nextQ;

    const nextVerified = "verified" in next ? next.verified : currentVerifiedParam();
    if (nextVerified !== undefined) params.verified = nextVerified;

    router.get("/admin/users", params, { preserveState: true });
  }

  function submitSearch(e: FormEvent) {
    e.preventDefault();
    applyFilters({ q });
  }

  function loadMore() {
    if (!props.nextCursor) return;
    const params: Record<string, string> = { after: props.nextCursor };
    if (props.search) params.q = props.search;
    const verified = currentVerifiedParam();
    if (verified !== undefined) params.verified = verified;
    router.get("/admin/users", params, { preserveState: true });
  }

  function clearFilters() {
    setQ("");
    router.get("/admin/users", {}, { preserveState: true });
  }

  const hasActiveFilters = (props.search ?? "") !== "" || props.verified !== null;

  return (
    <main className="mx-auto max-w-4xl p-8">
      <header className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Users</h1>
        <span className="text-sm text-muted-foreground">{props.users.length} shown</span>
      </header>

      <form onSubmit={submitSearch} className="mb-4 flex flex-wrap items-center gap-2">
        <Input
          aria-label="Search username or email"
          placeholder="Search username or email…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          className="max-w-xs"
        />
        <Select
          value={props.verified === null ? "any" : props.verified ? "verified" : "unverified"}
          onValueChange={(v) =>
            applyFilters({ verified: v === "any" ? undefined : v === "verified" ? "1" : "0" })
          }
        >
          <SelectTrigger className="w-40" aria-label="Filter by verification status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="any">Any status</SelectItem>
            <SelectItem value="verified">Verified</SelectItem>
            <SelectItem value="unverified">Unverified</SelectItem>
          </SelectContent>
        </Select>
        <Button type="submit" variant="outline" size="sm">
          Search
        </Button>
        {hasActiveFilters && (
          <Button type="button" variant="ghost" size="sm" onClick={clearFilters}>
            Clear
          </Button>
        )}
      </form>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>User</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Joined</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {props.users.length === 0 && (
            <TableRow>
              <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">
                {hasActiveFilters ? "No users match this search." : "No users yet."}
              </TableCell>
            </TableRow>
          )}
          {props.users.map((u) => (
            <TableRow key={u.id}>
              <TableCell>
                <UserBadge user={u} />
              </TableCell>
              <TableCell>{u.email}</TableCell>
              <TableCell>{new Date(u.createdAt).toLocaleDateString()}</TableCell>
              <TableCell className="text-right">
                <Button variant="ghost" size="sm" asChild>
                  <Link href={`/admin/users/${u.id}`}>View</Link>
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      {props.hasMore && props.nextCursor && (
        <div className="mt-6 text-center">
          <Button variant="outline" onClick={loadMore}>
            Load more
          </Button>
        </div>
      )}
    </main>
  );
}

UserIndex.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
