<?php

declare(strict_types=1);

/**
 * English copy for the User plugin's screens and its verification email.
 *
 * Reached as 'user::user.*'.
 *
 * NOT translated, deliberately: the API endpoints shown as developer hints
 * ("GET /ajx/users", "POST /ajx/users/verify"). Those are identifiers, and a
 * screen describing an endpoint that does not exist is worse than an English
 * one that does.
 */
return [
    // Shared labels, so the same word cannot drift between ten screens.
    'common' => [
        'loading'      => 'Loading…',
        'reload'       => 'Reload',
        'cancel'       => 'Cancel',
        'create'       => 'Create',
        'edit'         => 'Edit',
        'delete'       => 'Delete',
        'view'         => 'View',
        'status'       => 'Status',
        'email'        => 'Email',
        'username'     => 'Username',
        'password'     => 'Password',
        'created'      => 'Created',
        'save_changes' => 'Save changes',
        'back_to_list' => 'Back to list',
        'loaded_from'  => 'Loaded from',
        'submits_to'   => 'Submits to',
    ],

    'nav' => [
        'brand'     => 'User Management',
        'all_users' => 'All users',
        'create'    => 'Create',
        'settings'  => 'Settings',
    ],

    'users' => [
        'title'          => 'Users',
        'empty'          => 'No users yet.',
        'create_user'    => 'Create user',
        'create_account' => 'Create account',
        'detail'         => 'User detail',
        'edit_user'      => 'Edit user',
        'new_password'   => 'New password',
        // Says plainly that an empty field means "unchanged" rather than
        // "clear it" — the opposite reading would lock a user out.
        'partial_update' => 'Partial update — leave password blank to keep it unchanged.',
    ],

    'verify' => [
        'title'  => 'Verify your email',
        'token'  => 'Verification token',
        'submit' => 'Verify email',
        // Subject line and heading of the verification email.
        'email_heading' => 'Confirm your email',
    ],

    'settings' => [
        'profile'            => 'Profile',
        'first_name'         => 'First name',
        'last_name'          => 'Last name',
        'phone'              => 'Phone',
        'avatar_url'         => 'Avatar URL (http/https)',
        'save_profile'       => 'Save profile',
        'preferences'        => 'Preferences',
        'locale'             => 'Locale',
        'language'           => 'Language',
        'timezone'           => 'Timezone',
        'currency'           => 'Currency',
        'theme'              => 'Theme',
        'save_preferences'   => 'Save preferences',
        'notifications'      => 'Notifications',
        'save_notifications' => 'Save notifications',
        'privacy'            => 'Privacy',
        'profile_visibility' => 'Profile visibility',
        'save_privacy'       => 'Save privacy',
    ],

    // The scaffolded placeholder view a new project sees before it ships its own.
    'placeholder' => [
        'title'   => 'User plugin',
        'heading' => 'User',
        'edit_at' => 'Edit this view at',
    ],
];
