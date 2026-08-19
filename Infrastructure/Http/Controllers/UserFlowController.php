<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Project\Http\Controllers\Concerns\InteractsWithGraphSeo;

/**
 * Pageflow (SPA) controller for the User plugin — the server half of the pages
 * shipped in this plugin's ui/. It renders COMPONENT NAMES + props; the client
 * resolves them from the federated plugin pages:
 *
 *   admin face  →  ui/admin/Pages/User/{Index,Show}.tsx   (component "User/Index"/"User/Show")
 *   public face →  ui/site/Pages/User/{Register,Profile}.tsx
 *
 * Routes declare `requires: ["http.pageflow","user.management"]` so both the
 * responder and this service resolve for the request.
 *
 * SEO — every page passes the reserved `seoHead` prop the stock Pageflow layout
 * renders into the HTML shell. The public /register page gets the full rich
 * head (canonical, robots, OG/Twitter, JSON-LD graph) via seoFor(); the
 * auth-gated pages and the token-bearing /verify-email landing get seoPrivate()
 * — a correct <title> plus noindex,nofollow, with no graph cost. Both helpers
 * no-op ('') on Pageflow XHR navigations, so SPA hops pay nothing.
 */
final class UserFlowController
{
    use InteractsWithGraphSeo;

    public function __construct(
        private readonly PageflowResponder $pageflow,
        private readonly UserServiceContract $users,
    ) {
    }

    /** Admin: paginated, searchable, filterable user list → component "User/Index". */
    public function adminIndex(Request $request): Response
    {
        $query = ListUsersQuery::fromRequest($request);
        $page  = $this->users->list($query);

        return $this->pageflow->render($request, 'User/Index', 'admin', [
            'users'      => array_map([$this, 'row'], $page->items),
            'hasMore'    => $page->hasMore,
            'nextCursor' => $page->nextCursor(),
            // Echoed back so the page can prefill the search form and carry
            // the current filter across "load more" / pagination requests.
            'search'     => $query->search,
            'verified'   => $query->verified,
            'seoHead'    => $this->seoPrivate('Users', request: $request),
        ]);
    }

    /** Admin: single user → component "User/Show". */
    public function adminShow(Request $request, string $id): Response
    {
        $user = $this->users->find($id);

        // null = "don't know" (no user:unlock permission, or no such user) —
        // distinct from true/false so the client can hide the unlock control
        // entirely rather than showing a wrong state. A viewer without
        // user:unlock can still see the rest of the page.
        $locked = null;
        if ($user !== null) {
            try {
                $locked = $this->users->lockoutStatus($id);
            } catch (\Throwable) {
                // Caller lacks user:unlock — render the page without it.
            }
        }

        return $this->pageflow->render($request, 'User/Show', 'admin', [
            'user'    => $user !== null ? $this->row($user) : null,
            'locked'  => $locked,
            'seoHead' => $this->seoPrivate($user !== null ? "User {$user->username}" : 'User', request: $request),
        ]);
    }

    /** Public: registration form → component "User/Register". */
    public function register(Request $request): Response
    {
        return $this->pageflow->render($request, 'User/Register', 'site', [
            'seoHead' => $this->seoFor(
                title:       'Create your account',
                description: 'Sign up in seconds — create a free account and get instant access.',
                path:        '/register',
                breadcrumbs: [['Home', '/'], ['Create your account', '/register']],
                request:     $request,
            ),
        ]);
    }

    /**
     * Public: email-verification landing → component "User/VerifyEmail". The
     * emailed link points here (`/verify-email?token=...`); the token is passed
     * as a prop so the page can prefill and POST it to /ajx/users/verify.
     * noindex — a token-bearing URL must never enter a search index.
     */
    public function verifyEmail(Request $request): Response
    {
        return $this->pageflow->render($request, 'User/VerifyEmail', 'site', [
            'token'   => (string) $request->query('token', ''),
            'seoHead' => $this->seoPrivate('Verify your email', request: $request),
        ]);
    }

    /** Public: the signed-in user's own profile → component "User/Profile". */
    public function profile(Request $request): Response
    {
        $identity = $request->identity();
        $user     = $identity !== null && !$identity->isGuest()
            ? $this->users->find($identity->userId)
            : null;

        return $this->pageflow->render($request, 'User/Profile', 'site', [
            'user'    => $user !== null ? $this->row($user) : null,
            'seoHead' => $this->seoPrivate('Your profile', request: $request),
        ]);
    }

    /** Map a UserDTO to the plain props the client expects (no secrets). */
    private function row(object $u): array
    {
        return [
            'id'            => $u->id,
            'username'      => $u->username,
            'email'         => $u->email,
            'emailVerified' => $u->emailVerified,
            'createdAt'     => $u->createdAt,
        ];
    }
}
